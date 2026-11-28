<?php

declare(strict_types=1);

namespace PaymosFossBilling;

use Paymos\Client;
use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\EventInProgressException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\InvoiceReverseVerifier;
use Paymos\Plugin\StatusMapper;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;
use Paymos\Webhook\WebhookEvent;

final class CallbackProcessor
{
    private const DOCTRINE_INVOICE_ENTITY = 'Box\\Mod\\Invoice\\Entity\\Invoice';
    private const DOCTRINE_CLIENT_ENTITY = 'Box\\Mod\\Client\\Entity\\Client';
    private const DOCTRINE_TRANSACTION_ENTITY = 'Box\\Mod\\Invoice\\Entity\\Transaction';

    private Config $config;
    private PdoEventStore $eventStore;
    private SnapshotStore $snapshotStore;

    /** @var callable(int): (?array<string, mixed>) */
    private $loadInvoice;

    /** @var callable(array<string, mixed>): void */
    private $applyPayment;

    /** @var callable(int, string): void */
    private $addNote;

    /** @var callable(string): Client|null */
    private $clientFactory;

    /** @var callable(WebhookEvent, string): void|null */
    private $refundCallback;

    /** @var callable(): int */
    private $clock;

    /** @var callable(string, string, array<string, mixed>): void|null */
    private $logger;

    /**
     * @param callable(int): (?array<string, mixed>) $loadInvoice
     * @param callable(array<string, mixed>): void $applyPayment
     * @param callable(int, string): void $addNote
     * @param callable(string): Client|null $clientFactory
     * @param callable(WebhookEvent, string): void|null $refundCallback
     * @param callable(): int|null $clock
     * @param callable(string, string, array<string, mixed>): void|null $logger
     */
    public function __construct(
        Config $config,
        PdoEventStore $eventStore,
        SnapshotStore $snapshotStore,
        callable $loadInvoice,
        callable $applyPayment,
        callable $addNote,
        ?callable $clientFactory = null,
        ?callable $refundCallback = null,
        ?callable $clock = null,
        ?callable $logger = null
    ) {
        $this->config = $config;
        $this->eventStore = $eventStore;
        $this->snapshotStore = $snapshotStore;
        $this->loadInvoice = $loadInvoice;
        $this->applyPayment = $applyPayment;
        $this->addNote = $addNote;
        $this->clientFactory = $clientFactory;
        $this->refundCallback = $refundCallback;
        $this->clock = $clock ?? static function (): int {
            return time();
        };
        $this->logger = $logger;
    }

    /**
     * Process an incoming Paymos webhook delivery and return an exact HTTP result envelope.
     *
     * @return array<string, mixed>
     */
    public function handle(string $rawBody, string $signatureHeader, ?int $now = null): array
    {
        $rememberedEventId = null;
        try {
            $secrets = $this->config->webhookSecrets();
            if (count($secrets) === 0) {
                return [
                    'http_status' => 500,
                    'status' => 'configuration_error',
                    'message' => 'Paymos webhook secret is not configured.',
                    'duplicate' => false,
                ];
            }

            $verified = (new MultiEnvironmentWebhookVerifier($secrets, $this->eventStore))
                ->process($signatureHeader, $rawBody, $now ?? $this->now());

            $environment = $verified->environment();
            $event = $verified->event();
            $rememberedEventId = $event->id();

            $eventType = strtolower($event->type());
            $data = $event->toArray()['data'] ?? [];

            if (strpos($eventType, 'withdrawal.') === 0) {
                if (is_array($data) && (($data['type'] ?? '') === 'refund' || !empty($data['invoice_id']))) {
                    if ($this->refundCallback !== null) {
                        ($this->refundCallback)($event, $environment);
                    }
                    $this->commitEvent($rememberedEventId);
                    return [
                        'http_status' => 200,
                        'status' => 'refund_processed',
                        'message' => 'OK',
                        'duplicate' => false,
                        'environment' => $environment,
                        'event_id' => $rememberedEventId,
                        'event_type' => $event->type(),
                    ];
                }

                $this->commitEvent($rememberedEventId);
                return [
                    'http_status' => 200,
                    'status' => 'ignored',
                    'message' => 'Non-refund withdrawal event ignored.',
                    'duplicate' => false,
                    'environment' => $environment,
                    'event_id' => $rememberedEventId,
                    'event_type' => $event->type(),
                ];
            }

            if (!$event->isInvoiceEvent()) {
                $this->commitEvent($rememberedEventId);
                return [
                    'http_status' => 200,
                    'status' => 'ignored',
                    'message' => 'Non-invoice event ignored.',
                    'duplicate' => false,
                    'environment' => $environment,
                    'event_id' => $rememberedEventId,
                    'event_type' => $event->type(),
                ];
            }

            $this->assertPayloadEnvironment($event, $environment);
            $outcome = $this->applyVerifiedEvent($event, $environment, true, false);
            $this->commitEvent($rememberedEventId);

            return [
                'http_status' => 200,
                'status' => (string) ($outcome['status'] ?? 'ok'),
                'message' => 'OK',
                'duplicate' => false,
                'environment' => $environment,
                'event_id' => $rememberedEventId,
                'event_type' => $event->type(),
                'applied' => (bool) ($outcome['applied'] ?? false),
                'transaction_id' => $outcome['transaction_id'] ?? null,
            ];
        } catch (DuplicateEventException $e) {
            return [
                'http_status' => 200,
                'status' => 'duplicate',
                'message' => 'Duplicate event ignored.',
                'duplicate' => true,
            ];
        } catch (EventInProgressException $e) {
            if ($rememberedEventId !== null) {
                // Thrown downstream (e.g., refund payment lock busy) after we claimed the event slot.
                $this->releaseEvent($rememberedEventId);
            }
            return [
                'http_status' => 409,
                'status' => 'in_progress',
                'message' => 'Event is currently being processed.',
                'duplicate' => false,
            ];
        } catch (SignatureMismatchException $e) {
            return [
                'http_status' => 401,
                'status' => 'bad_signature',
                'message' => 'Invalid webhook signature.',
                'duplicate' => false,
            ];
        } catch (TimestampSkewException $e) {
            return [
                'http_status' => 401,
                'status' => 'bad_timestamp',
                'message' => 'Webhook timestamp outside allowed tolerance.',
                'duplicate' => false,
            ];
        } catch (\PDOException $e) {
            $this->releaseEvent($rememberedEventId);
            $this->log('error', 'Storage error processing webhook: ' . $e->getMessage());
            return [
                'http_status' => 500,
                'status' => 'storage_error',
                'message' => 'Storage error while processing webhook.',
                'duplicate' => false,
            ];
        } catch (\InvalidArgumentException $e) {
            $this->releaseEvent($rememberedEventId);
            $this->log('error', 'Configuration error processing webhook: ' . $e->getMessage());
            return [
                'http_status' => 500,
                'status' => 'configuration_error',
                'message' => $this->config->redact($e->getMessage()),
                'duplicate' => false,
            ];
        } catch (\RuntimeException $e) {
            $this->releaseEvent($rememberedEventId);
            $msg = $e->getMessage();
            if ($msg === 'refund_unbound_review' || $msg === 'refund_payment_busy') {
                $this->log('warning', 'Retryable refund webhook state: ' . $msg);
                return [
                    'http_status' => 409,
                    'status' => 'retry_required',
                    'message' => $msg,
                    'duplicate' => false,
                ];
            }
            if (strpos($msg, 'manual_review:') === 0) {
                $this->log('warning', $msg);
                return [
                    'http_status' => 400,
                    'status' => 'manual_review',
                    'message' => $this->config->redact($msg),
                    'duplicate' => false,
                ];
            }
            $this->log('error', 'Webhook processing failed: ' . $msg);
            return [
                'http_status' => 400,
                'status' => 'processing_failed',
                'message' => $this->config->redact($msg),
                'duplicate' => false,
            ];
        } catch (\Throwable $e) {
            $this->releaseEvent($rememberedEventId);
            $this->log('error', 'Unexpected webhook error: ' . $e->getMessage());
            return [
                'http_status' => 500,
                'status' => 'internal_error',
                'message' => 'Internal error while processing webhook.',
                'duplicate' => false,
            ];
        }
    }

    /**
     * Apply an already-fetched invoice returned by GET /v1/invoices/{id} during cron reconciliation.
     *
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $snapshot
     */
    public function applyTrustedInvoice(array $invoice, array $snapshot, ?int $now = null): bool
    {
        $invoiceId = isset($invoice['invoice_id']) && is_scalar($invoice['invoice_id'])
            ? (string) $invoice['invoice_id']
            : (string) ($snapshot['paymos_invoice_id'] ?? '');
        $status = isset($invoice['status']) && is_scalar($invoice['status'])
            ? strtolower(trim((string) $invoice['status']))
            : '';

        if (!empty($invoice['paid_late_at'])) {
            $event = new WebhookEvent([
                'event_id' => 'reconcile_paid_late_' . $invoiceId,
                'event_type' => 'invoice.paid_late',
                'occurred_at' => $now ?? $this->now(),
                'data' => $invoice,
            ]);
            $this->applyVerifiedEvent($event, (string) $snapshot['environment'], false, true);
            return false;
        }

        $event = new WebhookEvent([
            'event_id' => 'reconcile_' . $invoiceId . '_' . $status,
            'event_type' => self::eventTypeForStatus($status),
            'occurred_at' => $now ?? $this->now(),
            'data' => $invoice,
        ]);

        $outcome = $this->applyVerifiedEvent($event, (string) $snapshot['environment'], false, true);
        return (bool) ($outcome['applied'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function applyVerifiedEvent(WebhookEvent $event, string $environment, bool $reverseVerify, bool $fromCron = false): array
    {
        $snapshot = null;
        if ($event->externalOrderId() !== '') {
            $snapshot = $this->snapshotStore->findByExternalOrderId($event->externalOrderId());
        }
        if ($snapshot === null && $event->invoiceId() !== '') {
            $snapshot = $this->snapshotStore->findByPaymosInvoiceId($event->invoiceId());
        }
        if ($snapshot === null || CheckoutService::isPendingIntent($snapshot)) {
            throw new \RuntimeException('Paymos FOSSBilling invoice snapshot was not found.');
        }

        $this->assertSnapshotMatchesEvent($snapshot, $event, $environment);

        $billingInvoiceId = is_numeric($snapshot['platform_ref'] ?? null)
            ? (int) $snapshot['platform_ref']
            : (CheckoutService::parseInvoiceIdFromExternalOrderId((string) $snapshot['external_order_id']) ?? 0);
        if ($billingInvoiceId <= 0) {
            throw new \RuntimeException('Paymos snapshot is missing a valid FOSSBilling invoice ID.');
        }

        $action = StatusMapper::invoiceAction($event->type(), $event->status());

        // invoice.paid_late is note-only and arrives AFTER a terminal unpaid status by definition.
        if ($action === StatusMapper::ACTION_NOTE_PAID_LATE) {
            if ($reverseVerify) {
                $this->assertReverseVerified($event, $snapshot, $environment, $billingInvoiceId);
            }

            $newlyNoted = $this->snapshotStore->recordPaidLateNote(
                (string) $snapshot['external_order_id'],
                $event->id() !== '' ? $event->id() : '1',
                $event->paidLateAt()
            );
            if ($newlyNoted) {
                ($this->addNote)(
                    $billingInvoiceId,
                    'Paymos: the customer finished paying after the invoice closed. Review stock and fulfil manually or refund the payment. Invoice: ' . $event->invoiceId()
                );
            }

            return ['status' => 'paid_late_noted', 'applied' => false];
        }

        if (self::recordedFinalStatus($snapshot) !== '') {
            return $this->ignoredAfterFinal($snapshot, $event, $billingInvoiceId);
        }

        if ($reverseVerify && $this->requiresReverseVerify($action)) {
            $this->assertReverseVerified($event, $snapshot, $environment, $billingInvoiceId);
        }

        // The webhook, ipn.php and the cron reconciler can reach one invoice at the same time;
        // each must see what the previous one recorded before it reads the invoice as unpaid.
        $externalOrderId = (string) $snapshot['external_order_id'];
        return $this->snapshotStore->withInvoiceLock($billingInvoiceId, function () use ($event, $environment, $fromCron, $externalOrderId, $billingInvoiceId, $action): array {
            $snapshot = $this->snapshotStore->findByExternalOrderId($externalOrderId);
            if ($snapshot === null) {
                throw new \RuntimeException('Paymos FOSSBilling invoice snapshot was not found.');
            }
            if (self::recordedFinalStatus($snapshot) !== '') {
                return $this->ignoredAfterFinal($snapshot, $event, $billingInvoiceId);
            }

            return $this->settleLocked($event, $environment, $fromCron, $snapshot, $billingInvoiceId, $action);
        });
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function settleLocked(WebhookEvent $event, string $environment, bool $fromCron, array $snapshot, int $billingInvoiceId, string $action): array
    {
        $billingInvoice = ($this->loadInvoice)($billingInvoiceId);
        if (!is_array($billingInvoice)) {
            if ($fromCron) {
                $this->snapshotStore->updateStatus((string) $snapshot['paymos_invoice_id'], SnapshotStore::STATUS_INVOICE_MISSING);
                $this->log('warning', 'Reconcile: FOSSBilling invoice not found.', ['invoice_id' => $billingInvoiceId]);
                return ['status' => 'invoice_missing', 'applied' => false];
            }
            throw new \RuntimeException('FOSSBilling invoice #' . $billingInvoiceId . ' was not found.');
        }

        // Roll-back & double-payment guard: once FOSSBilling marks an invoice Paid,
        // never credit it again or roll it back.
        $isPaid = !empty($billingInvoice['paid']);
        if ($isPaid && $this->isCompleteOrDowngrade($action)) {
            return $this->applyToPaidInvoice($event, $snapshot, $billingInvoiceId, $action);
        }

        if ($action !== StatusMapper::ACTION_PAYMENT_COMPLETE) {
            $nextStatus = $this->recordObservedStatus($event, $snapshot, $action);
            ($this->addNote)($billingInvoiceId, 'Paymos invoice status updated: ' . $nextStatus . ' (' . $event->invoiceId() . ').');
            return ['status' => $nextStatus, 'applied' => false];
        }

        // Triangulated exact decimal AmountGuard across snapshot, current FOSSBilling invoice, and webhook event.
        $currentAmount = isset($billingInvoice['amount']) && is_scalar($billingInvoice['amount'])
            ? (string) $billingInvoice['amount']
            : '';
        $currentCurrency = isset($billingInvoice['currency']) && is_scalar($billingInvoice['currency'])
            ? (string) $billingInvoice['currency']
            : '';

        if (!AmountGuard::isSafeToComplete(
            (string) $snapshot['amount'],
            (string) $snapshot['currency'],
            $currentAmount,
            $currentCurrency,
            $event->orderAmount(),
            $event->orderCurrency()
        )) {
            $summary = AmountGuard::mismatchSummary(
                (string) $snapshot['amount'],
                (string) $snapshot['currency'],
                $currentAmount,
                $currentCurrency,
                $event->orderAmount(),
                $event->orderCurrency()
            );
            $this->addNoteOnce((string) $snapshot['external_order_id'], $billingInvoiceId, 'Manual review: ' . $summary);
            throw new \RuntimeException('manual_review: ' . $summary);
        }

        $externalOrderId = (string) $snapshot['external_order_id'];
        $credit = $this->snapshotStore->fundsCreditState($externalOrderId);

        $txId = self::extractConfirmedTransactionId($event);
        $finalStatus = $event->status() !== '' ? strtolower($event->status()) : 'paid';
        $paymentData = [
            'invoice_id' => $billingInvoiceId,
            'client_id' => $billingInvoice['client_id'] ?? ($snapshot['client_id'] !== null && is_numeric($snapshot['client_id']) ? (int) $snapshot['client_id'] : null),
            'amount' => (string) $snapshot['amount'],
            'currency' => strtoupper((string) $snapshot['currency']),
            'status' => $finalStatus,
            'transaction_id' => $txId,
            'paymos_invoice_id' => $event->invoiceId() !== '' ? $event->invoiceId() : (string) $snapshot['paymos_invoice_id'],
            'external_order_id' => (string) $snapshot['external_order_id'],
            'environment' => $environment,
            'billing_raw' => $billingInvoice['raw'] ?? null,
            // How far an earlier attempt got with the client balance; the settlement callback
            // never repeats addFunds for a credit that reached (or may have reached) the balance.
            'funds_credit_state' => $credit['state'],
            'funds_credited' => $credit['state'] === SnapshotStore::FUNDS_CREDITED,
            'funds_credit_tx_record' => $credit['tx_record'],
            'funds_credit_rel_id' => $credit['rel_id'],
            'before_funds_credit' => function (string $transactionRecordId = '', string $relId = '') use ($externalOrderId): void {
                $this->snapshotStore->recordFundsCredit($externalOrderId, SnapshotStore::FUNDS_CREDIT_PENDING, $transactionRecordId, $relId);
            },
            'after_funds_credit' => function (string $transactionRecordId = '', string $relId = '') use ($externalOrderId): void {
                $this->snapshotStore->recordFundsCredit($externalOrderId, SnapshotStore::FUNDS_CREDITED, $transactionRecordId, $relId);
            },
            'reset_funds_credit' => function () use ($externalOrderId): void {
                $this->snapshotStore->recordFundsCredit($externalOrderId, '');
            },
        ];

        try {
            ($this->applyPayment)($paymentData);
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), 'manual_review:') === 0) {
                $this->addNoteOnce($externalOrderId, $billingInvoiceId, 'Manual review: ' . trim(substr($e->getMessage(), strlen('manual_review:'))));
            }
            throw $e;
        }
        $this->snapshotStore->markFinalStatus($externalOrderId, $finalStatus, $txId);

        return [
            'status' => $finalStatus,
            'applied' => true,
            'transaction_id' => $txId,
        ];
    }

    /**
     * FOSSBilling already shows the invoice as paid. A complete event is recorded with its
     * transaction (the Paymos money is real and stays refundable); other events only record
     * what Paymos reported, so a later real payment is not dropped as stale.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function applyToPaidInvoice(WebhookEvent $event, array $snapshot, int $billingInvoiceId, string $action): array
    {
        $externalOrderId = (string) $snapshot['external_order_id'];
        if ($action !== StatusMapper::ACTION_PAYMENT_COMPLETE) {
            $this->recordObservedStatus($event, $snapshot, $action);
            $this->addNoteOnce($externalOrderId, $billingInvoiceId, 'Paymos: ignored ' . ($event->type() ?: $event->status()) . ' because invoice is already paid.');
            return ['status' => 'ignored_already_paid', 'applied' => false];
        }

        $txId = self::extractConfirmedTransactionId($event);
        $finalStatus = $event->status() !== '' ? strtolower($event->status()) : 'paid';
        $creditedHere = $this->snapshotStore->fundsCreditState($externalOrderId)['state'] === SnapshotStore::FUNDS_CREDITED;
        $this->snapshotStore->markFinalStatus($externalOrderId, $finalStatus, $txId);
        if ($creditedHere) {
            // This plugin credited the payment; only the final status was missing.
            return ['status' => $finalStatus, 'applied' => false, 'transaction_id' => $txId];
        }

        $this->addNoteOnce(
            $externalOrderId,
            $billingInvoiceId,
            'Manual review: Paymos payment ' . $event->invoiceId() . ' (' . $txId . ') arrived for an invoice that was already paid another way. Refund it in Paymos Refunds or add it to the client balance.'
        );

        return ['status' => 'paid_elsewhere', 'applied' => false, 'transaction_id' => $txId];
    }

    /**
     * Writes an admin note once per snapshot and text: a webhook retried 11 times and a cron pass
     * every 10 minutes must not pile up the same manual review note.
     */
    private function addNoteOnce(string $externalOrderId, int $billingInvoiceId, string $note): void
    {
        if ($this->snapshotStore->markNoteOnce($externalOrderId, substr(hash('sha256', $note), 0, 16))) {
            ($this->addNote)($billingInvoiceId, $note);
        }
    }

    /**
     * Records the status Paymos reported, final or not.
     *
     * @param array<string, mixed> $snapshot
     */
    private function recordObservedStatus(WebhookEvent $event, array $snapshot, string $action): string
    {
        $nextStatus = $event->status() !== '' ? strtolower($event->status()) : $action;
        if (StatusMapper::isFinalStatus($nextStatus)) {
            $this->snapshotStore->markFinalStatus((string) $snapshot['external_order_id'], $nextStatus);
        } else {
            $this->snapshotStore->updateStatus((string) $snapshot['paymos_invoice_id'], $nextStatus);
        }

        return $nextStatus;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private static function recordedFinalStatus(array $snapshot): string
    {
        if (isset($snapshot['final_status']) && is_string($snapshot['final_status']) && $snapshot['final_status'] !== '') {
            return $snapshot['final_status'];
        }

        return isset($snapshot['status']) && StatusMapper::isFinalStatus((string) $snapshot['status']) ? (string) $snapshot['status'] : '';
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function ignoredAfterFinal(array $snapshot, WebhookEvent $event, int $billingInvoiceId): array
    {
        $this->log('info', 'Ignored stale status after final snapshot status.', [
            'invoice_id' => $billingInvoiceId,
            'recorded_final' => self::recordedFinalStatus($snapshot),
            'event_type' => $event->type(),
        ]);

        return ['status' => 'ignored_final', 'applied' => false];
    }

    /**
     * Extract confirmed tx_hash from data.payment.transfers[] (last confirmed),
     * falling back to data.invoice_id.
     */
    public static function extractConfirmedTransactionId(WebhookEvent $event): string
    {
        $payload = $event->toArray();
        $transfers = $payload['data']['payment']['transfers'] ?? null;
        $confirmedHash = '';
        if (is_array($transfers)) {
            foreach ($transfers as $transfer) {
                if (!is_array($transfer)) {
                    continue;
                }
                $status = isset($transfer['status']) && is_scalar($transfer['status'])
                    ? strtolower(trim((string) $transfer['status']))
                    : '';
                $hash = isset($transfer['tx_hash']) && is_scalar($transfer['tx_hash'])
                    ? trim((string) $transfer['tx_hash'])
                    : '';
                if ($status === 'confirmed' && $hash !== '') {
                    $confirmedHash = $hash;
                }
            }
        }

        if ($confirmedHash !== '') {
            return $confirmedHash;
        }

        $fallback = $event->invoiceId();
        return $fallback !== '' ? $fallback : 'paymos_' . $event->id();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function extractRawBody(array $data): string
    {
        if (isset($data['http_raw_post_data']) && is_string($data['http_raw_post_data'])) {
            return $data['http_raw_post_data'];
        }
        if (isset($data['raw_body']) && is_string($data['raw_body'])) {
            return $data['raw_body'];
        }

        return '';
    }

    /**
     * Case-insensitive extraction of X-Webhook-Signature from $_SERVER or header arrays.
     *
     * @param array<string, mixed> $headers
     */
    public static function extractSignatureHeader(array $headers): string
    {
        foreach ($headers as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            $normalized = strtolower(str_replace('_', '-', trim($key)));
            if ($normalized === 'x-webhook-signature' || $normalized === 'http-x-webhook-signature') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * Build reusable FOSSBilling 2.0 (Doctrine) + 0.7 (RedBean) callbacks for loading
     * invoices, booking payments (addFunds + payInvoiceWithCredits), and recording notes.
     *
     * @param mixed $di
     * @param mixed $apiAdmin
     * @param mixed $transactionRecordId
     * @param callable(): int|null $clock
     * @param callable(string, string, array<string, mixed>): void|null $logger
     * @return array{loadInvoice: callable, applyPayment: callable, addNote: callable}
     */
    public static function createFossBillingCallbacks(
        $di,
        $apiAdmin = null,
        $transactionRecordId = null,
        ?callable $clock = null,
        ?callable $logger = null
    ): array {
        $nowFn = $clock ?? static function (): int {
            return time();
        };

        $loadInvoice = static function (int $invoiceId) use ($di, $apiAdmin): ?array {
            $em = self::readDiKey($di, 'em');
            $doctrineInvoice = (is_object($em) && method_exists($em, 'find'))
                ? self::findDoctrineEntity($em, self::DOCTRINE_INVOICE_ENTITY, $invoiceId)
                : null;

            if (is_object($apiAdmin) && (method_exists($apiAdmin, 'invoice_get') || method_exists($apiAdmin, '__call'))) {
                try {
                    $raw = $apiAdmin->invoice_get(['id' => $invoiceId]);
                    if (is_array($raw)) {
                        $status = isset($raw['status']) && is_scalar($raw['status']) ? strtolower(trim((string) $raw['status'])) : '';
                        $amount = isset($raw['total']) && is_scalar($raw['total']) ? (string) $raw['total'] : '';
                        $currency = isset($raw['currency']) && is_scalar($raw['currency']) ? (string) $raw['currency'] : '';
                        $clientId = null;
                        if (isset($raw['client']) && is_array($raw['client']) && isset($raw['client']['id']) && is_numeric($raw['client']['id'])) {
                            $clientId = (int) $raw['client']['id'];
                        } elseif (isset($raw['client_id']) && is_numeric($raw['client_id'])) {
                            $clientId = (int) $raw['client_id'];
                        }

                        return [
                            'id' => $invoiceId,
                            'amount' => $amount,
                            'currency' => $currency,
                            'paid' => $status === 'paid',
                            'status' => $status,
                            'client_id' => $clientId,
                            'raw' => is_object($doctrineInvoice) ? $doctrineInvoice : $raw,
                        ];
                    }
                } catch (\Throwable $e) {
                    // Fall through to Doctrine/RedBean lookup.
                }
            }

            if (is_object($doctrineInvoice)) {
                $amount = self::extractEntityInvoiceAmount($di, $doctrineInvoice);
                $status = method_exists($doctrineInvoice, 'getStatus')
                    ? strtolower(trim((string) $doctrineInvoice->getStatus()))
                    : (isset($doctrineInvoice->status) ? strtolower(trim((string) $doctrineInvoice->status)) : '');
                $currency = method_exists($doctrineInvoice, 'getCurrency')
                    ? (string) $doctrineInvoice->getCurrency()
                    : (isset($doctrineInvoice->currency) ? (string) $doctrineInvoice->currency : '');
                $clientId = self::extractEntityClientId($doctrineInvoice);

                return [
                    'id' => $invoiceId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'paid' => $status === 'paid',
                    'status' => $status,
                    'client_id' => $clientId,
                    'raw' => $doctrineInvoice,
                ];
            }

            $db = self::readDiKey($di, 'db');
            if (is_object($db) && method_exists($db, 'getExistingModelById')) {
                try {
                    $model = $db->getExistingModelById('Invoice', $invoiceId);
                } catch (\Throwable $e) {
                    return null;
                }
                if (is_object($model)) {
                    $amount = self::extractEntityInvoiceAmount($di, $model);
                    $status = isset($model->status) ? strtolower(trim((string) $model->status)) : '';
                    $currency = isset($model->currency) ? (string) $model->currency : '';
                    $clientId = isset($model->client_id) && is_numeric($model->client_id) ? (int) $model->client_id : null;

                    return [
                        'id' => $invoiceId,
                        'amount' => $amount,
                        'currency' => $currency,
                        'paid' => $status === 'paid',
                        'status' => $status,
                        'client_id' => $clientId,
                        'raw' => $model,
                    ];
                }
            }

            return null;
        };

        $applyPayment = static function (array $payment) use ($di, $apiAdmin, $transactionRecordId, $nowFn): void {
            $txHash = (string) $payment['transaction_id'];
            $description = 'Paymos transaction ' . $txHash . ' (invoice ' . $payment['paymos_invoice_id'] . ')';
            $now = (int) $nowFn();
            $txUpdateFields = [
                'txn_id' => $txHash,
                'txn_status' => (string) $payment['status'],
                'status' => 'processed',
                'amount' => (string) $payment['amount'],
                'currency' => (string) $payment['currency'],
                'invoice_id' => (int) $payment['invoice_id'],
            ];

            // Hooks from CallbackProcessor record that the balance is (being) credited, so a retry
            // after a failed invoice payment never calls addFunds a second time.
            $creditState = isset($payment['funds_credit_state']) && is_string($payment['funds_credit_state'])
                ? $payment['funds_credit_state']
                : (!empty($payment['funds_credited']) ? SnapshotStore::FUNDS_CREDITED : '');
            $alreadyCredited = $creditState === SnapshotStore::FUNDS_CREDITED;
            $storedTxRecord = isset($payment['funds_credit_tx_record']) && is_scalar($payment['funds_credit_tx_record']) ? (string) $payment['funds_credit_tx_record'] : '';
            $storedRelId = isset($payment['funds_credit_rel_id']) && is_scalar($payment['funds_credit_rel_id']) ? (string) $payment['funds_credit_rel_id'] : '';
            $beforeCredit = isset($payment['before_funds_credit']) && is_callable($payment['before_funds_credit']) ? $payment['before_funds_credit'] : null;
            $afterCredit = isset($payment['after_funds_credit']) && is_callable($payment['after_funds_credit']) ? $payment['after_funds_credit'] : null;
            $resetCredit = isset($payment['reset_funds_credit']) && is_callable($payment['reset_funds_credit']) ? $payment['reset_funds_credit'] : null;

            $modService = self::readDiKey($di, 'mod_service');
            if (is_callable($modService)) {
                $clientService = $modService('client');
                $invoiceService = $modService('invoice');
                $em = self::readDiKey($di, 'em');
                $db = self::readDiKey($di, 'db');

                $invoiceModel = self::resolveInvoiceModel($payment, $em, $db);
                $clientModel = self::resolveClientModel($payment, $invoiceModel, $em, $db);

                if (!is_object($clientModel) || !is_object($invoiceModel)) {
                    throw new \RuntimeException('Unable to resolve FOSSBilling Client or Invoice entity for payment settlement.');
                }

                if ($creditState === SnapshotStore::FUNDS_CREDIT_PENDING) {
                    // An earlier attempt stopped inside addFunds. The client_balance row it would
                    // have written decides: there -> finish without crediting, absent -> credit now.
                    $onBalance = self::balanceHasCredit($db, $clientModel, $storedRelId);
                    if ($onBalance === null) {
                        throw new \RuntimeException(
                            'manual_review: Paymos payment ' . (string) $payment['paymos_invoice_id']
                            . ' may already be on the client balance. Check the balance, then pay this invoice from it.'
                        );
                    }
                    if ($onBalance) {
                        if ($afterCredit !== null) {
                            $afterCredit($storedTxRecord, $storedRelId);
                        }
                        $alreadyCredited = true;
                    }
                }

                if ($alreadyCredited) {
                    $effectiveTxId = $storedTxRecord;
                } else {
                    $effectiveTxId = ($transactionRecordId !== null && $transactionRecordId !== '')
                        ? (string) $transactionRecordId
                        : ($storedTxRecord !== '' ? $storedTxRecord : self::createTransactionRecord($di, $invoiceModel, $payment, $txUpdateFields, $now));

                    $relId = self::formatClientBalanceRelId($effectiveTxId, $payment, $txHash);
                    $txData = [
                        'type' => 'transaction',
                        'rel_id' => $relId,
                        'txn_id' => $txHash,
                    ];

                    if ($beforeCredit !== null) {
                        $beforeCredit($effectiveTxId, $relId);
                    }
                    try {
                        $clientService->addFunds($clientModel, (string) $payment['amount'], $description, $txData);
                    } catch (\Throwable $e) {
                        // FOSSBilling validates before it writes (e.g. no client currency): if nothing
                        // reached the balance the mark is cleared and the next delivery credits normally.
                        $onBalance = self::balanceHasCredit($db, $clientModel, $relId);
                        if ($onBalance === false && $resetCredit !== null) {
                            $resetCredit();
                        } elseif ($onBalance === true && $afterCredit !== null) {
                            $afterCredit($effectiveTxId, $relId);
                        }
                        throw $e;
                    }
                    if ($afterCredit !== null) {
                        $afterCredit($effectiveTxId, $relId);
                    }
                }

                self::payInvoiceFromBalance($invoiceService, $invoiceModel);
                $paidStatus = self::invoiceModelStatus($invoiceModel);
                if ($paidStatus !== null && $paidStatus !== 'paid') {
                    throw new \RuntimeException(
                        'manual_review: FOSSBilling did not mark invoice #' . (int) $payment['invoice_id']
                        . ' paid after the Paymos payment was added to the client balance.'
                    );
                }

                self::updateTransactionRecord($di, $apiAdmin, $effectiveTxId !== '' ? $effectiveTxId : $transactionRecordId, $txUpdateFields, $now);
                return;
            }

            if (is_object($apiAdmin)) {
                $relId = self::formatClientBalanceRelId(
                    ($transactionRecordId !== null && $transactionRecordId !== '') ? (string) $transactionRecordId : '',
                    $payment,
                    $txHash
                );
                $txData = [
                    'type' => 'transaction',
                    'rel_id' => $relId,
                    'txn_id' => $txHash,
                ];
                if ($creditState === SnapshotStore::FUNDS_CREDIT_PENDING) {
                    // The admin API offers no balance lookup: never risk a second credit.
                    throw new \RuntimeException(
                        'manual_review: Paymos payment ' . (string) $payment['paymos_invoice_id']
                        . ' may already be on the client balance. Check the balance, then pay this invoice from it.'
                    );
                }
                $credited = $alreadyCredited;
                $canAddFunds = method_exists($apiAdmin, 'client_balance_add_funds') || method_exists($apiAdmin, '__call');
                if (!$alreadyCredited && ($canAddFunds || method_exists($apiAdmin, 'addFunds'))) {
                    if ($beforeCredit !== null) {
                        $beforeCredit(($transactionRecordId !== null && $transactionRecordId !== '') ? (string) $transactionRecordId : '', $relId);
                    }
                    if ($canAddFunds) {
                        $apiAdmin->client_balance_add_funds([
                            'id' => $payment['client_id'],
                            'amount' => (string) $payment['amount'],
                            'description' => $description,
                            'type' => 'Paymos',
                            'rel_id' => $relId,
                        ]);
                    } else {
                        $apiAdmin->addFunds($payment['client_id'], (string) $payment['amount'], $description, $txData);
                    }
                    if ($afterCredit !== null) {
                        $afterCredit(($transactionRecordId !== null && $transactionRecordId !== '') ? (string) $transactionRecordId : '');
                    }
                    $credited = true;
                }

                if (method_exists($apiAdmin, 'invoice_pay_with_credits') || method_exists($apiAdmin, '__call')) {
                    $apiAdmin->invoice_pay_with_credits(['id' => (int) $payment['invoice_id']]);
                } elseif (method_exists($apiAdmin, 'payInvoiceWithCredits')) {
                    $apiAdmin->payInvoiceWithCredits((int) $payment['invoice_id']);
                }

                if ($credited) {
                    self::updateTransactionRecord($di, $apiAdmin, $transactionRecordId, $txUpdateFields, $now);
                    return;
                }
            }

            throw new \RuntimeException('Unable to credit client funds in FOSSBilling.');
        };

        $addNote = static function (int $invoiceId, string $note) use ($di, $apiAdmin, $transactionRecordId, $logger): void {
            $safeNote = Config::redactStatic($note);
            $diLogger = self::readDiKey($di, 'logger');
            if (is_object($diLogger) && method_exists($diLogger, 'info')) {
                $diLogger->info('[Paymos][invoice #' . $invoiceId . '] ' . $safeNote);
            }
            if ($logger !== null) {
                $logger('note', $safeNote, ['invoice_id' => $invoiceId]);
            }
            self::appendInvoiceNote($di, $invoiceId, $safeNote);
            if ($transactionRecordId !== null && $transactionRecordId !== '' && is_object($apiAdmin)
                && (method_exists($apiAdmin, 'invoice_transaction_update') || method_exists($apiAdmin, '__call'))
            ) {
                $apiAdmin->invoice_transaction_update([
                    'id' => (int) $transactionRecordId,
                    'note' => $safeNote,
                ]);
            }
        };

        return [
            'loadInvoice' => $loadInvoice,
            'applyPayment' => $applyPayment,
            'addNote' => $addNote,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function assertReverseVerified(WebhookEvent $event, array $snapshot, string $environment, int $billingInvoiceId): void
    {
        $result = (new InvoiceReverseVerifier($this->client($environment)))->verify($event, [
            'invoice_id' => (string) $snapshot['paymos_invoice_id'],
            'project_id' => (string) $snapshot['project_id'],
            'external_order_id' => (string) $snapshot['external_order_id'],
            'amount' => (string) $snapshot['amount'],
            'currency' => (string) $snapshot['currency'],
            'environment' => $environment,
        ]);

        if (!$result->isVerified()) {
            $reason = $result->reason() !== '' ? $result->reason() : 'reverse_verification_failed';
            $this->addNoteOnce((string) $snapshot['external_order_id'], $billingInvoiceId, 'Manual review: Paymos reverse verification failed (' . $reason . ').');
            throw new \RuntimeException('manual_review: Paymos reverse verification failed: ' . $reason);
        }
    }

    private function requiresReverseVerify(string $action): bool
    {
        return in_array($action, [
            StatusMapper::ACTION_PAYMENT_COMPLETE,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ], true);
    }

    private function isCompleteOrDowngrade(string $action): bool
    {
        return in_array($action, [
            StatusMapper::ACTION_PAYMENT_COMPLETE,
            StatusMapper::ACTION_CONFIRMING,
            StatusMapper::ACTION_AWAITING_PAYMENT,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ], true);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function assertSnapshotMatchesEvent(array $snapshot, WebhookEvent $event, string $environment): void
    {
        if ((string) $snapshot['environment'] !== $environment) {
            throw new \RuntimeException('Paymos event environment does not match FOSSBilling snapshot.');
        }
        if ((string) $snapshot['project_id'] !== '' && $event->projectId() !== '' && (string) $snapshot['project_id'] !== $event->projectId()) {
            throw new \RuntimeException('Paymos event project does not match FOSSBilling snapshot.');
        }
        if ((string) $snapshot['external_order_id'] !== '' && $event->externalOrderId() !== '' && (string) $snapshot['external_order_id'] !== $event->externalOrderId()) {
            throw new \RuntimeException('Paymos event external order does not match FOSSBilling snapshot.');
        }
        if ((string) $snapshot['paymos_invoice_id'] !== '' && $event->invoiceId() !== '' && (string) $snapshot['paymos_invoice_id'] !== $event->invoiceId()) {
            throw new \RuntimeException('Paymos event invoice ID does not match FOSSBilling snapshot.');
        }
    }

    private function assertPayloadEnvironment(WebhookEvent $event, string $environment): void
    {
        $isTest = $event->isTest();
        if ($isTest === null) {
            return;
        }
        if ($environment === 'sandbox' && $isTest !== true) {
            throw new \RuntimeException('Sandbox webhook payload is not marked as test.');
        }
        if ($environment === 'live' && $isTest !== false) {
            throw new \RuntimeException('Live webhook payload is marked as test.');
        }
    }

    private function client(string $environment): Client
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($environment);
        }

        return new Client($this->config->clientConfig($environment));
    }

    private function commitEvent(string $eventId): void
    {
        if (!$this->eventStore->commit($eventId)) {
            throw new \PDOException('Failed to commit webhook deduplication event.');
        }
    }

    private function releaseEvent(?string $eventId): void
    {
        if ($eventId === null || $eventId === '') {
            return;
        }

        try {
            $this->eventStore->release($eventId);
        } catch (\Throwable $e) {
            // Preserve the original failure while the owner-fenced reservation expires safely.
        }
    }

    private static function eventTypeForStatus(string $status): string
    {
        switch (StatusMapper::invoiceAction('', $status)) {
            case StatusMapper::ACTION_CONFIRMING:
                return 'invoice.confirming';
            case StatusMapper::ACTION_AWAITING_PAYMENT:
                return $status === 'awaiting_payment' ? 'invoice.awaiting_payment' : 'invoice.underpaid_waiting';
            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                return $status === 'paid_over' ? 'invoice.paid_over' : 'invoice.paid';
            case StatusMapper::ACTION_FAIL_ORDER:
                return 'invoice.underpaid';
            case StatusMapper::ACTION_CANCEL_ORDER:
                return $status === 'expired' ? 'invoice.expired' : 'invoice.cancelled';
        }

        return 'invoice.updated';
    }

    /**
     * Format a client_balance.rel_id value guaranteed to fit FOSSBilling's VARCHAR(20) column.
     *
     * @param array<string, mixed> $payment
     */
    public static function formatClientBalanceRelId(string $transactionRecordId, array $payment, string $txHash): string
    {
        $candidate = trim($transactionRecordId);
        if ($candidate === '' && isset($payment['invoice_id']) && is_scalar($payment['invoice_id'])) {
            $candidate = trim((string) $payment['invoice_id']);
        }
        if ($candidate === '') {
            $candidate = trim($txHash);
        }

        return substr($candidate, 0, 20);
    }

    /**
     * Create a native FOSSBilling Transaction record when settlement arrives via the fixed
     * /api/guest/paymos/webhook endpoint (where ipn.php has not pre-created a row).
     *
     * @param mixed $di
     * @param array<string, mixed> $payment
     * @param array<string, mixed> $fields
     */
    private static function createTransactionRecord($di, object $invoiceModel, array $payment, array $fields, int $now): string
    {
        $db = self::readDiKey($di, 'db');
        if (!is_object($db) || !method_exists($db, 'dispense') || !method_exists($db, 'store')) {
            return '';
        }

        try {
            $tx = $db->dispense('Transaction');
            if (!is_object($tx)) {
                return '';
            }

            $tx->invoice_id = (int) ($fields['invoice_id'] ?? ($payment['invoice_id'] ?? 0));
            $gatewayId = null;
            if (isset($invoiceModel->gateway_id) && is_numeric($invoiceModel->gateway_id) && (int) $invoiceModel->gateway_id > 0) {
                $gatewayId = (int) $invoiceModel->gateway_id;
            } elseif (method_exists($db, 'findOne')) {
                $gw = $db->findOne('PayGateway', 'gateway = :gw', [':gw' => 'Paymos']);
                if (is_object($gw) && isset($gw->id) && is_numeric($gw->id)) {
                    $gatewayId = (int) $gw->id;
                }
            }
            if ($gatewayId !== null) {
                $tx->gateway_id = $gatewayId;
            }

            $tx->txn_id = (string) ($fields['txn_id'] ?? ($payment['transaction_id'] ?? ''));
            $tx->txn_status = (string) ($fields['txn_status'] ?? ($payment['status'] ?? 'paid'));
            $tx->status = 'received';
            $tx->type = 'payment';
            $tx->amount = (string) ($fields['amount'] ?? ($payment['amount'] ?? '0'));
            $tx->currency = (string) ($fields['currency'] ?? ($payment['currency'] ?? ''));
            $tx->validate_ipn = 1;
            $tx->ip = isset($_SERVER['REMOTE_ADDR']) && is_scalar($_SERVER['REMOTE_ADDR'])
                ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45)
                : null;
            $timestamp = date('Y-m-d H:i:s', $now);
            $tx->created_at = $timestamp;
            $tx->updated_at = $timestamp;

            $id = $db->store($tx);
            if (is_numeric($id) && (int) $id > 0) {
                return (string) (int) $id;
            }
        } catch (\Throwable $e) {
            return '';
        }

        return '';
    }

    /**
     * Persist a redacted audit note onto the FOSSBilling Invoice record (Doctrine or RedBean).
     *
     * @param mixed $di
     */
    private static function appendInvoiceNote($di, int $invoiceId, string $safeNote): void
    {
        if ($invoiceId <= 0 || trim($safeNote) === '') {
            return;
        }

        $em = self::readDiKey($di, 'em');
        if (is_object($em) && method_exists($em, 'find')) {
            $entity = self::findDoctrineEntity($em, self::DOCTRINE_INVOICE_ENTITY, $invoiceId);
            if (is_object($entity) && method_exists($entity, 'setNotes')) {
                try {
                    $existing = method_exists($entity, 'getNotes') ? trim((string) $entity->getNotes()) : '';
                    if ($existing === '' || strpos($existing, $safeNote) === false) {
                        $entity->setNotes($existing !== '' ? ($existing . "\n" . $safeNote) : $safeNote);
                        if (method_exists($em, 'flush')) {
                            $em->flush();
                        }
                    }
                    return;
                } catch (\Throwable $e) {
                    // Fall back to RedBean below.
                }
            }
        }

        $db = self::readDiKey($di, 'db');
        if (is_object($db) && method_exists($db, 'getExistingModelById') && method_exists($db, 'store')) {
            try {
                $model = $db->getExistingModelById('Invoice', $invoiceId);
                if (is_object($model)) {
                    $existing = isset($model->notes) && is_scalar($model->notes) ? trim((string) $model->notes) : '';
                    if ($existing === '' || strpos($existing, $safeNote) === false) {
                        $model->notes = $existing !== '' ? ($existing . "\n" . $safeNote) : $safeNote;
                        $db->store($model);
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal in minimal test doubles.
            }
        }
    }

    /**
     * @param mixed $di
     * @param mixed $apiAdmin
     * @param mixed $transactionRecordId
     * @param array<string, mixed> $fields
     */
    private static function updateTransactionRecord($di, $apiAdmin, $transactionRecordId, array $fields, int $now): void
    {
        if ($transactionRecordId === null || $transactionRecordId === '') {
            return;
        }

        $em = self::readDiKey($di, 'em');
        if (is_object($em) && method_exists($em, 'find')) {
            $tx = self::findDoctrineEntity($em, self::DOCTRINE_TRANSACTION_ENTITY, (int) $transactionRecordId);
            if (is_object($tx)) {
                self::applyDoctrineTransactionFields($tx, $fields, $now);
                if (method_exists($em, 'persist')) {
                    $em->persist($tx);
                }
                if (method_exists($em, 'flush')) {
                    $em->flush();
                }
                return;
            }
        }

        $db = self::readDiKey($di, 'db');
        if (is_object($db) && method_exists($db, 'getExistingModelById') && method_exists($db, 'store')) {
            try {
                $tx = $db->getExistingModelById('Transaction', (int) $transactionRecordId);
                if (is_object($tx)) {
                    foreach ($fields as $key => $value) {
                        $tx->{$key} = $value;
                    }
                    $tx->updated_at = date('Y-m-d H:i:s', $now);
                    $db->store($tx);
                    return;
                }
            } catch (\Throwable $e) {
                // Fall back to admin API update below.
            }
        }

        if (is_object($apiAdmin) && (method_exists($apiAdmin, 'invoice_transaction_update') || method_exists($apiAdmin, '__call'))) {
            $payload = $fields;
            $payload['id'] = (int) $transactionRecordId;
            $apiAdmin->invoice_transaction_update($payload);
        }
    }

    private static function findDoctrineEntity(object $em, string $entityClass, int $id): ?object
    {
        if ($id <= 0 || !method_exists($em, 'find')) {
            return null;
        }
        try {
            $entity = $em->find($entityClass, $id);
            return is_object($entity) ? $entity : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $payment
     * @param mixed $em
     * @param mixed $db
     */
    private static function resolveInvoiceModel(array $payment, $em, $db): ?object
    {
        $raw = $payment['billing_raw'] ?? null;
        if (is_object($raw)) {
            return $raw;
        }

        $invoiceId = (int) ($payment['invoice_id'] ?? 0);
        if (is_object($em) && method_exists($em, 'find')) {
            $entity = self::findDoctrineEntity($em, self::DOCTRINE_INVOICE_ENTITY, $invoiceId);
            if (is_object($entity)) {
                return $entity;
            }
        }

        if (is_object($db) && method_exists($db, 'getExistingModelById')) {
            try {
                $model = $db->getExistingModelById('Invoice', $invoiceId);
                return is_object($model) ? $model : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payment
     * @param mixed $em
     * @param mixed $db
     */
    private static function resolveClientModel(array $payment, ?object $invoiceModel, $em, $db): ?object
    {
        $clientId = isset($payment['client_id']) && is_numeric($payment['client_id'])
            ? (int) $payment['client_id']
            : null;

        if (is_object($em) && method_exists($em, 'find')) {
            if (is_object($invoiceModel) && method_exists($invoiceModel, 'getClient')) {
                $client = $invoiceModel->getClient();
                if (is_object($client)) {
                    return $client;
                }
            }
            if ($clientId === null && is_object($invoiceModel)) {
                $clientId = self::extractEntityClientId($invoiceModel);
            }
            if ($clientId !== null) {
                $client = self::findDoctrineEntity($em, self::DOCTRINE_CLIENT_ENTITY, $clientId);
                if (is_object($client)) {
                    return $client;
                }
            }
        }

        if (is_object($db) && method_exists($db, 'getExistingModelById')) {
            $resolvedId = $clientId;
            if ($resolvedId === null && is_object($invoiceModel) && isset($invoiceModel->client_id) && is_numeric($invoiceModel->client_id)) {
                $resolvedId = (int) $invoiceModel->client_id;
            }
            if ($resolvedId !== null && $resolvedId > 0) {
                try {
                    $model = $db->getExistingModelById('Client', $resolvedId);
                    return is_object($model) ? $model : null;
                } catch (\Throwable $e) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * @param mixed $di
     */
    private static function extractEntityInvoiceAmount($di, object $model): string
    {
        $modService = self::readDiKey($di, 'mod_service');
        if (is_callable($modService)) {
            $invoiceService = $modService('invoice');
            if (is_object($invoiceService) && method_exists($invoiceService, 'getTotalWithTax')) {
                return (string) $invoiceService->getTotalWithTax($model);
            }
        }

        if (method_exists($model, 'getTotal')) {
            return (string) $model->getTotal();
        }
        if (isset($model->total)) {
            return (string) $model->total;
        }

        return '';
    }

    /**
     * Close the FOSSBilling invoice from the client balance the way the bundled gateways do
     * (PayPalEmail 0.8.9): an "Add funds" invoice is marked paid without spending the balance
     * it just topped up; any other invoice is approved first (tryPayWithCredits skips
     * unapproved invoices) and then paid from the balance.
     *
     * @param mixed $invoiceService
     */
    private static function payInvoiceFromBalance($invoiceService, object $invoiceModel): void
    {
        if (!is_object($invoiceService)) {
            throw new \RuntimeException('FOSSBilling invoice service is unavailable for payment settlement.');
        }

        $isDeposit = method_exists($invoiceService, 'isInvoiceTypeDeposit')
            && $invoiceService->isInvoiceTypeDeposit($invoiceModel);
        if ($isDeposit) {
            if (method_exists($invoiceService, 'markAsPaid')) {
                $invoiceService->markAsPaid($invoiceModel);
            }
            return;
        }

        if (self::isInvoiceModelApproved($invoiceModel) === false && method_exists($invoiceService, 'approveInvoice')) {
            $invoiceService->approveInvoice($invoiceModel, ['use_credits' => false]);
        }
        $invoiceService->payInvoiceWithCredits($invoiceModel);
    }

    /**
     * Whether addFunds() wrote the client_balance row for this credit (type `transaction`,
     * the rel_id the plugin passed). null when the ledger cannot be read.
     *
     * @param mixed $db
     */
    private static function balanceHasCredit($db, object $clientModel, string $relId): ?bool
    {
        if ($relId === '' || !is_object($db) || !method_exists($db, 'findOne')) {
            return null;
        }
        $clientId = method_exists($clientModel, 'getId') ? $clientModel->getId() : ($clientModel->id ?? null);
        if (!is_numeric($clientId)) {
            return null;
        }

        try {
            $row = $db->findOne('ClientBalance', 'client_id = :client_id AND type = :type AND rel_id = :rel_id', [
                ':client_id' => (int) $clientId,
                ':type' => 'transaction',
                ':rel_id' => $relId,
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        return $row !== null && $row !== false;
    }

    /**
     * null when the model does not expose its approval flag.
     */
    private static function isInvoiceModelApproved(object $invoiceModel): ?bool
    {
        foreach (['isApproved', 'getApproved'] as $method) {
            if (method_exists($invoiceModel, $method)) {
                return (bool) $invoiceModel->{$method}();
            }
        }
        if (isset($invoiceModel->approved) && is_scalar($invoiceModel->approved)) {
            return (bool) (int) $invoiceModel->approved;
        }

        return null;
    }

    /**
     * Lower-case status of a FOSSBilling invoice model, or null when it does not expose one.
     */
    private static function invoiceModelStatus(object $invoiceModel): ?string
    {
        if (method_exists($invoiceModel, 'getStatus')) {
            $status = $invoiceModel->getStatus();
            if ($status instanceof \BackedEnum) {
                $status = $status->value;
            }
            return is_scalar($status) ? strtolower(trim((string) $status)) : null;
        }
        if (isset($invoiceModel->status) && is_scalar($invoiceModel->status)) {
            return strtolower(trim((string) $invoiceModel->status));
        }

        return null;
    }

    private static function extractEntityClientId(object $invoice): ?int
    {
        if (method_exists($invoice, 'getClient')) {
            $client = $invoice->getClient();
            if (is_object($client)) {
                if (method_exists($client, 'getId') && is_numeric($client->getId())) {
                    return (int) $client->getId();
                }
                if (isset($client->id) && is_numeric($client->id)) {
                    return (int) $client->id;
                }
            }
        }
        if (method_exists($invoice, 'getClientId') && is_numeric($invoice->getClientId())) {
            return (int) $invoice->getClientId();
        }
        if (isset($invoice->client_id) && is_numeric($invoice->client_id)) {
            return (int) $invoice->client_id;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function applyDoctrineTransactionFields(object $tx, array $fields, int $now): void
    {
        $setterMap = [
            'txn_id' => 'setTxnId',
            'txn_status' => 'setTxnStatus',
            'status' => 'setStatus',
            'amount' => 'setAmount',
            'currency' => 'setCurrency',
            'invoice_id' => 'setInvoiceId',
            'note' => 'setNote',
        ];

        foreach ($setterMap as $field => $method) {
            if (!array_key_exists($field, $fields) || !method_exists($tx, $method)) {
                continue;
            }
            $value = $fields[$field];
            if ($field === 'amount') {
                $value = self::coerceNumericSetterArg($tx, $method, $value);
            } elseif ($field === 'invoice_id') {
                $value = (int) $value;
            } else {
                $value = (string) $value;
            }
            $tx->{$method}($value);
        }

        if (method_exists($tx, 'setUpdatedAt')) {
            $tx->setUpdatedAt(self::coerceDateTimeSetterArg($tx, 'setUpdatedAt', $now));
        }
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function coerceNumericSetterArg(object $entity, string $method, $value)
    {
        try {
            $ref = new \ReflectionMethod($entity, $method);
            $params = $ref->getParameters();
            if (isset($params[0])) {
                $type = $params[0]->getType();
                if ($type instanceof \ReflectionNamedType && $type->getName() === 'float') {
                    return (float) $value;
                }
            }
        } catch (\Throwable $e) {
            // Default to string.
        }

        return (string) $value;
    }

    /**
     * @return mixed
     */
    private static function coerceDateTimeSetterArg(object $entity, string $method, int $timestamp)
    {
        try {
            $ref = new \ReflectionMethod($entity, $method);
            $params = $ref->getParameters();
            if (isset($params[0])) {
                $type = $params[0]->getType();
                $typeNames = [];
                if ($type instanceof \ReflectionNamedType) {
                    $typeNames[] = ltrim($type->getName(), '\\');
                } elseif ($type instanceof \ReflectionUnionType) {
                    foreach ($type->getTypes() as $member) {
                        if ($member instanceof \ReflectionNamedType) {
                            $typeNames[] = ltrim($member->getName(), '\\');
                        }
                    }
                }
                if (in_array('DateTimeImmutable', $typeNames, true) || in_array('DateTimeInterface', $typeNames, true)) {
                    return (new \DateTimeImmutable())->setTimestamp($timestamp);
                }
                if (in_array('DateTime', $typeNames, true)) {
                    return (new \DateTime())->setTimestamp($timestamp);
                }
            }
        } catch (\Throwable $e) {
            // Fall through to formatted string.
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param mixed $di
     * @return mixed
     */
    private static function readDiKey($di, string $key)
    {
        if (is_array($di)) {
            return $di[$key] ?? null;
        }
        if ($di instanceof \ArrayAccess) {
            try {
                return $di->offsetExists($key) ? $di[$key] : null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        if (is_object($di) && method_exists($di, 'has') && method_exists($di, 'get')) {
            try {
                return $di->has($key) ? $di->get($key) : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $this->config->redact($message), $context);
        }
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
