<?php

declare(strict_types=1);

namespace PaymosFossBilling;

use Paymos\Client;
use Paymos\Exception\ApiException;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\ExternalRefundObservation;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\NativeRefundProjectorInterface;
use Paymos\Plugin\RefundCoordinator;
use Paymos\Plugin\RefundCopy;
use Paymos\Plugin\RefundDiscovery;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;
use Paymos\Webhook\WebhookEvent;

final class RefundAdapter implements NativeRefundProjectorInterface
{
    private const DOCTRINE_INVOICE_ENTITY = 'Box\\Mod\\Invoice\\Entity\\Invoice';

    /** @var array<int, string> */
    private const UNMANAGED_FIELDS = [
        'address',
        'destination_address',
        'network',
        'parts',
        'crypto_amount',
        'recipient',
        'wallet',
        'fee',
        'partial_amount',
        'custom_amount',
    ];

    private CredentialStore $credentialStore;
    private SnapshotStore $snapshotStore;
    private RefundIntentStore $store;

    /** @var callable(string, array<string, mixed>): array<string, mixed> */
    private $native;

    /** @var callable(string): Client|null */
    private $clientFactory;

    /** @var callable(): void */
    private $authorize;

    /** @var callable(): int */
    private $clock;

    /** @var callable(string, string, array<string, mixed>): void|null */
    private $logger;

    /**
     * @param callable(string, array<string, mixed>): array<string, mixed> $native
     * @param callable(string): Client|null $clientFactory
     * @param callable(): void|null $authorize
     * @param callable(): int|null $clock
     * @param callable(string, string, array<string, mixed>): void|null $logger
     */
    public function __construct(
        CredentialStore $credentialStore,
        SnapshotStore $snapshotStore,
        RefundIntentStore $store,
        callable $native,
        ?callable $clientFactory = null,
        ?callable $authorize = null,
        ?callable $clock = null,
        ?callable $logger = null
    ) {
        $this->credentialStore = $credentialStore;
        $this->snapshotStore = $snapshotStore;
        $this->store = $store;
        $this->native = $native;
        $this->clientFactory = $clientFactory;
        $this->authorize = $authorize ?? static function (): void {
            throw new \RuntimeException('refund_admin_access_denied');
        };
        $this->clock = $clock ?? static function (): int {
            return time();
        };
        $this->logger = $logger;
    }

    /**
     * Submit an admin-authorized full or overpayment refund through SDK RefundCoordinator.
     *
     * @param array<string, mixed> $input
     */
    public function submit(array $input): RefundIntent
    {
        ($this->authorize)();

        foreach (self::UNMANAGED_FIELDS as $forbidden) {
            if (array_key_exists($forbidden, $input)) {
                throw new \RuntimeException('refund_managed_input_only');
            }
        }

        $rawPaymentKey = $input['invoice_id'] ?? ($input['invoiceid'] ?? '');
        $paymentKey = is_scalar($rawPaymentKey) ? trim((string) $rawPaymentKey) : '';
        $token = isset($input['request_token']) && is_string($input['request_token']) ? trim($input['request_token']) : '';
        $mode = isset($input['mode']) && is_string($input['mode']) ? trim($input['mode']) : '';

        if (!preg_match('/\A[1-9][0-9]*\z/', $paymentKey)) {
            throw new \RuntimeException('refund_payment_key_invalid');
        }
        if (!RefundIntentStore::isUuidV4($token)) {
            throw new \RuntimeException('refund_durable_token_required');
        }
        if (!in_array($mode, [RefundStates::FULL, RefundStates::OVERPAYMENT], true)) {
            throw new \RuntimeException('refund_mode_invalid');
        }

        return $this->store->withPaymentLock($paymentKey, function () use ($input, $paymentKey, $token, $mode): RefundIntent {
            $installation = $this->credentialStore->installationId();
            $existing = $this->store->findByRequestToken($installation, $token);

            if ($existing !== null) {
                if ($existing->paymentKey() !== $paymentKey || $existing->mode() !== $mode) {
                    throw new \RuntimeException('refund_request_conflict');
                }
                $this->assertRequestMatchesDraft($input, $existing->fullDraft());
                $intent = $existing;
            } else {
                $snapshot = $this->nativeCall('snapshot', ['invoice_id' => (int) $paymentKey]);
                $this->assertOriginalPaid($snapshot);
                $draft = $mode === RefundStates::FULL ? $this->buildFullDraft($snapshot) : null;
                $this->assertRequestMatchesDraft($input, $draft);
                $mapping = $snapshot['mapping'];

                $intent = new RefundIntent([
                    'operation_id' => RefundIntentStore::uuid(),
                    'request_token' => $token,
                    'installation_id' => $installation,
                    'platform' => 'fossbilling',
                    'native_payment_key' => $paymentKey,
                    'invoice_id' => (string) $mapping['paymos_invoice_id'],
                    'project_id' => (string) $mapping['project_id'],
                    'environment' => (string) $mapping['environment'],
                    'mode' => $mode,
                    'full_draft' => $draft,
                ]);

                $reason = $this->preflight($intent);
                if ($reason !== null) {
                    throw new \RuntimeException(
                        $reason === NativeRefundProjectorInterface::PREFLIGHT_RETRY
                            ? 'refund_preflight_unavailable'
                            : $reason
                    );
                }

                $intent = $this->store->createIntent($intent);
            }

            $this->coordinator($intent)->submit($intent->operationId());
            $this->store->assertOwned();
            $this->reconcile($intent->operationId());

            return $this->store->getIntent($intent->operationId());
        });
    }

    /**
     * NativeRefundProjectorInterface::preflight()
     */
    public function preflight(RefundIntent $intent): ?string
    {
        try {
            $snapshot = $this->nativeCall('snapshot', ['invoice_id' => (int) $intent->paymentKey()]);
            $this->assertMapping($snapshot, $intent);
            $this->assertOriginalPaid($snapshot);
            $this->store->assertNoExternalReview($intent->paymentKey());

            if ($intent->mode() === RefundStates::FULL && $this->buildFullDraft($snapshot) !== $intent->fullDraft()) {
                return 'refund_native_draft_changed';
            }

            $config = $this->credentialStore->refundConfig($intent->environment());
            if (!in_array('invoice_refunds_v1', $config['capabilities'], true)
                || $config['refund_contract_version'] !== 1
            ) {
                return 'refund_reconnect_required';
            }
            if ($config['refunds_enabled'] !== true) {
                return 'refunds_not_enabled';
            }

            try {
                $invoice = $this->client($intent->environment())->invoices()->get($intent->invoiceId());
            } catch (ApiException $error) {
                if ($error->statusCode() === 429 || $error->statusCode() >= 500) {
                    return NativeRefundProjectorInterface::PREFLIGHT_RETRY;
                }
                return $error->errorCode() !== '' ? $error->errorCode() : $error->getMessage();
            } catch (\RuntimeException $error) {
                return NativeRefundProjectorInterface::PREFLIGHT_RETRY;
            }

            $this->store->assertOwned();
            $this->assertApiInvoice($invoice, $snapshot, $intent);

            $summary = $this->invoiceSummary($invoice, $intent);
            $field = $intent->mode() === RefundStates::FULL ? 'can_refund_full' : 'can_refund_overpayment';
            $amountField = $intent->mode() === RefundStates::FULL ? 'full_refund_amount' : 'overpayment_refund_amount';

            if (($summary[$field] ?? false) !== true) {
                return 'refund_mode_unavailable';
            }
            if (array_key_exists('refund_summary', $invoice)) {
                $amountVal = $summary[$amountField] ?? null;
                if (!is_string($amountVal)
                    || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/', $amountVal) !== 1
                    || AmountGuard::normalizeDecimal($amountVal) === '0'
                ) {
                    return 'refund_mode_unavailable';
                }
            }

            return null;
        } catch (\Throwable $error) {
            return $error->getMessage();
        }
    }

    /**
     * NativeRefundProjectorInterface::inspect()
     */
    public function inspect(string $operationId): NativeProjectionResult
    {
        $intent = $this->store->getIntent($operationId);
        $snapshot = $this->nativeCall('snapshot', ['invoice_id' => (int) $intent->paymentKey()]);

        try {
            $this->assertMapping($snapshot, $intent);
        } catch (\RuntimeException $error) {
            return $this->ambiguous($intent, $error->getMessage());
        }

        if ($intent->mode() === RefundStates::OVERPAYMENT) {
            $marker = $this->nativeCall('marker', [
                'invoice_id' => (int) $intent->paymentKey(),
                'operation_id' => $intent->operationId(),
                'withdrawal_id' => $intent->withdrawalId() ?? '',
                'mode' => RefundStates::OVERPAYMENT,
            ]);
            if (empty($marker['found'])) {
                $storedProjection = $intent->toArray()['projection'];
                if ($storedProjection !== null) {
                    return $this->ambiguous($intent, 'refund_cash_note_missing');
                }
                try {
                    $this->assertOriginalPaid($snapshot);
                } catch (\RuntimeException $error) {
                    return $this->ambiguous($intent, $error->getMessage());
                }
                return new NativeProjectionResult(NativeProjectionResult::ABSENT, RefundStates::OVERPAYMENT);
            }

            $obs = $intent->observation();
            if ($obs === null
                || ($marker['withdrawal_id'] ?? '') !== $intent->withdrawalId()
                || !AmountGuard::amountsEqual((string) ($marker['amount'] ?? ''), $obs->principalDecimal())
                || (string) ($marker['asset'] ?? '') !== $obs->currency() . '/' . $obs->network()
            ) {
                return $this->ambiguous($intent, 'refund_cash_note_conflict');
            }

            return new NativeProjectionResult(
                NativeProjectionResult::EXACT_MATCH,
                RefundStates::OVERPAYMENT,
                (string) ($marker['native_id'] ?? ('paymos_overpayment_' . $intent->operationId())),
                null,
                null,
                $obs->principalDecimal(),
                $obs->currency() . '/' . $obs->network()
            );
        }

        // Mode === 'full'
        $marker = $this->nativeCall('marker', [
            'invoice_id' => (int) $intent->paymentKey(),
            'operation_id' => $intent->operationId(),
            'withdrawal_id' => $intent->withdrawalId() ?? '',
            'mode' => RefundStates::FULL,
        ]);

        if (empty($marker['found'])) {
            try {
                $this->assertOriginalPaid($snapshot);
                if ($this->buildFullDraft($snapshot) !== $intent->fullDraft()) {
                    throw new \RuntimeException('refund_native_draft_changed');
                }
            } catch (\RuntimeException $error) {
                return $this->ambiguous($intent, $error->getMessage());
            }
            return new NativeProjectionResult(NativeProjectionResult::ABSENT, RefundStates::FULL);
        }

        $draft = $intent->fullDraft();
        if (!is_array($draft)
            || (string) ($marker['invoice_id'] ?? '') !== $intent->paymentKey()
            || (string) ($marker['withdrawal_id'] ?? '') !== (string) $intent->withdrawalId()
            || (string) ($marker['amount_minor'] ?? '') !== $draft['amount_minor']
            || strtoupper((string) ($marker['currency'] ?? '')) !== $draft['currency']
        ) {
            return $this->ambiguous($intent, 'refund_native_marker_conflict');
        }

        $nativeId = isset($marker['native_id']) && is_scalar($marker['native_id']) && trim((string) $marker['native_id']) !== ''
            ? trim((string) $marker['native_id'])
            : 'paymos_refund_' . (string) $intent->withdrawalId();

        return new NativeProjectionResult(
            NativeProjectionResult::EXACT_MATCH,
            RefundStates::FULL,
            $nativeId,
            $draft['amount_minor'],
            $draft['currency']
        );
    }

    /**
     * NativeRefundProjectorInterface::applyCompleted()
     * Called STRICTLY after verified completed status; NEVER makes Paymos HTTP calls.
     */
    public function applyCompleted(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult
    {
        $this->store->assertOwned();

        if ($refund->status() !== 'completed'
            || $refund->withdrawalId() !== $intent->withdrawalId()
            || $refund->invoiceId() !== $intent->invoiceId()
            || $refund->mode() !== $intent->mode()
        ) {
            return $this->ambiguous($intent, 'refund_completion_required');
        }

        $existing = $this->inspect($intent->operationId());
        if ($existing->kind() !== NativeProjectionResult::ABSENT) {
            return $existing;
        }

        if ($intent->mode() === RefundStates::OVERPAYMENT) {
            $asset = $refund->currency() . '/' . $refund->network();
            $note = RefundCopy::text('cash_note', 'en')
                . ' [pmrf:' . $intent->operationId() . '] '
                . $refund->principalDecimal() . ' ' . $asset
                . ' (' . $refund->withdrawalId() . ')';

            $this->store->outsideTransaction(function () use ($intent, $refund, $asset, $note): void {
                $this->nativeCall('record_overpayment_note', [
                    'invoice_id' => (int) $intent->paymentKey(),
                    'operation_id' => $intent->operationId(),
                    'withdrawal_id' => $refund->withdrawalId(),
                    'amount' => $refund->principalDecimal(),
                    'asset' => $asset,
                    'note' => $note,
                ]);
            });

            return $this->inspect($intent->operationId());
        }

        $draft = $intent->fullDraft();
        if (!is_array($draft)) {
            return $this->ambiguous($intent, 'refund_full_draft_missing');
        }

        $note = 'Full refund completed through Paymos (withdrawal ' . $refund->withdrawalId()
            . ', operation ' . $intent->operationId() . ').';

        $this->store->outsideTransaction(function () use ($intent, $refund, $draft, $note): void {
            $this->nativeCall('refund_invoice_full', [
                'invoice_id' => (int) $intent->paymentKey(),
                'operation_id' => $intent->operationId(),
                'withdrawal_id' => $refund->withdrawalId(),
                'amount_decimal' => $draft['amount_decimal'],
                'amount_minor' => $draft['amount_minor'],
                'currency' => $draft['currency'],
                'client_id' => $draft['client_id'],
                'note' => $note,
            ]);
        });

        return $this->inspect($intent->operationId());
    }

    public function reconcile(string $operationId): string
    {
        $intent = $this->store->getIntent($operationId);
        return $this->store->withPaymentLock($intent->paymentKey(), function () use ($operationId): string {
            $intent = $this->store->getIntent($operationId);
            $action = $this->coordinator($intent)->reconcile($operationId);
            $this->store->assertOwned();

            $fresh = $this->store->getIntent($operationId);
            if ($fresh->mode() === RefundStates::FULL
                && $fresh->cashState() === RefundStates::COMPLETED
                && $fresh->projectionState() === RefundStates::PROJECTION_APPLIED
            ) {
                if ($this->store->claimEffect($operationId, 'confirmation', 1)->kind() === 'claimed') {
                    $this->store->recordEffectOutcome($operationId, 'confirmation', 1, RefundStates::EFFECT_SKIPPED);
                }
            }

            return $action;
        });
    }

    public function callback(WebhookEvent $event, string $environment): void
    {
        $wire = $event->toArray()['data'] ?? [];
        if (!is_array($wire)
            || !isset($wire['withdrawal_id'], $wire['invoice_id'], $wire['project_id'], $wire['is_test'])
            || !is_string($wire['withdrawal_id']) || $wire['withdrawal_id'] === ''
            || !is_string($wire['invoice_id']) || $wire['invoice_id'] === ''
            || !is_string($wire['project_id']) || $wire['project_id'] === ''
            || $wire['is_test'] !== ($environment === 'sandbox')
        ) {
            throw new \RuntimeException('refund_callback_scope_missing');
        }

        $intent = $this->store->findByWithdrawalId($wire['withdrawal_id']);
        if ($intent === null && isset($wire['external_order_id']) && is_string($wire['external_order_id']) && $wire['external_order_id'] !== '') {
            $intent = $this->store->findByExternalId($wire['external_order_id']);
        }

        if ($intent === null) {
            // Refund initiated directly in Paymos Dashboard/API: verify via GET and project cleanly.
            $this->observeExternal($wire, $environment);
            return;
        }

        if ($intent->environment() !== $environment
            || $wire['invoice_id'] !== $intent->invoiceId()
            || $wire['project_id'] !== $intent->projectId()
            || (isset($wire['mode']) && $wire['mode'] !== $intent->mode())
            || (isset($wire['refund_mode']) && $wire['refund_mode'] !== $intent->mode())
            || ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $wire['withdrawal_id'])
        ) {
            throw new \RuntimeException('refund_callback_scope_conflict');
        }

        $this->reconcile($intent->operationId());
    }

    /**
     * Verify and project an externally initiated (Paymos Dashboard / API) refund via
     * fresh GET /v1/refunds/{id} + GET /v1/invoices/{id} without ever calling POST /v1/refunds.
     *
     * @param array<string, mixed> $signal
     */
    public function observeExternal(array $signal, string $environment): void
    {
        if (!isset($signal['withdrawal_id'], $signal['invoice_id'])
            || !is_string($signal['withdrawal_id']) || $signal['withdrawal_id'] === ''
            || !is_string($signal['invoice_id']) || $signal['invoice_id'] === ''
            || ($signal['is_test'] ?? null) !== ($environment === 'sandbox')
        ) {
            throw new \RuntimeException('refund_callback_scope_missing');
        }

        $client = $this->client($environment);
        $wire = $client->refunds()->get($signal['withdrawal_id']);
        $invoice = $client->invoices()->get($signal['invoice_id']);
        $refund = VerifiedRefund::fromApi($wire, $invoice, new \DateTimeImmutable('@' . $this->now()));

        if ($refund->withdrawalId() !== $signal['withdrawal_id']
            || $refund->invoiceId() !== $signal['invoice_id']
            || $refund->environment() !== $environment
            || (isset($signal['project_id']) && is_string($signal['project_id']) && $signal['project_id'] !== $refund->projectId())
            || (isset($signal['mode']) && is_string($signal['mode']) && $signal['mode'] !== $refund->mode())
            || (isset($signal['external_order_id']) && is_string($signal['external_order_id']) && $signal['external_order_id'] !== $refund->externalOrderId())
        ) {
            throw new \RuntimeException('refund_callback_scope_conflict');
        }

        $localSnapshot = $this->snapshotStore->findByPaymosInvoiceId($refund->invoiceId());
        if ($localSnapshot === null) {
            $ext = isset($invoice['order']['external_id']) && is_string($invoice['order']['external_id'])
                ? $invoice['order']['external_id']
                : '';
            if ($ext !== '') {
                $localSnapshot = $this->snapshotStore->findByExternalOrderId($ext);
            }
        }
        if ($localSnapshot === null || !is_numeric($localSnapshot['platform_ref'] ?? null)) {
            throw new \RuntimeException('refund_unbound_review');
        }

        $paymentKey = (string) ((int) $localSnapshot['platform_ref']);
        $refundConfig = $this->credentialStore->refundConfig($environment);
        if (($localSnapshot['project_id'] ?? '') !== $refund->projectId()
            || ($localSnapshot['environment'] ?? '') !== $environment
            || ($refundConfig['project_id'] ?? '') !== $refund->projectId()
        ) {
            throw new \RuntimeException('refund_external_scope_conflict');
        }

        $this->store->withPaymentLock($paymentKey, function () use ($refund, $paymentKey): void {
            $old = $this->store->findExternalByWithdrawalId($refund->withdrawalId());
            $row = ExternalRefundObservation::fold($old, $refund, $paymentKey);

            if ($refund->status() === 'completed' && $row['note_id'] === null) {
                if ($refund->mode() === RefundStates::OVERPAYMENT) {
                    $asset = $refund->currency() . '/' . $refund->network();
                    $note = RefundCopy::text('cash_note', 'en')
                        . ' [pmrf:external:' . $refund->withdrawalId() . '] '
                        . $refund->principalDecimal() . ' ' . $asset;

                    $res = $this->nativeCall('record_overpayment_note', [
                        'invoice_id' => (int) $paymentKey,
                        'operation_id' => 'external_' . $refund->withdrawalId(),
                        'withdrawal_id' => $refund->withdrawalId(),
                        'amount' => $refund->principalDecimal(),
                        'asset' => $asset,
                        'note' => $note,
                    ]);
                    $row['note_id'] = (string) ($res['native_id'] ?? ('paymos_external_' . $refund->withdrawalId()));
                    $row['review_reason'] = null;
                } else {
                    // Completed full refund initiated in Paymos Dashboard: project natively via FOSSBilling 2.0 external_reversal / 0.7 fallback.
                    $snapshot = $this->nativeCall('snapshot', ['invoice_id' => (int) $paymentKey]);
                    $existingMarker = $this->nativeCall('marker', [
                        'invoice_id' => (int) $paymentKey,
                        'operation_id' => 'external_' . $refund->withdrawalId(),
                        'withdrawal_id' => $refund->withdrawalId(),
                        'mode' => RefundStates::FULL,
                    ]);

                    if (!empty($existingMarker['found'])) {
                        $row['note_id'] = (string) ($existingMarker['native_id'] ?? ('paymos_refund_' . $refund->withdrawalId()));
                        $row['review_reason'] = null;
                    } else {
                        try {
                            $this->assertOriginalPaid($snapshot);
                            $draft = $this->buildFullDraft($snapshot);
                            $note = 'Full refund from Paymos Dashboard completed (withdrawal ' . $refund->withdrawalId() . ').';
                            $res = $this->nativeCall('refund_invoice_full', [
                                'invoice_id' => (int) $paymentKey,
                                'operation_id' => 'external_' . $refund->withdrawalId(),
                                'withdrawal_id' => $refund->withdrawalId(),
                                'amount_decimal' => $draft['amount_decimal'],
                                'amount_minor' => $draft['amount_minor'],
                                'currency' => $draft['currency'],
                                'client_id' => $draft['client_id'],
                                'note' => $note,
                            ]);
                            $row['note_id'] = (string) ($res['native_id'] ?? ('paymos_refund_' . $refund->withdrawalId()));
                            $row['review_reason'] = null;
                        } catch (\Throwable $e) {
                            $row['review_reason'] = 'refund_unbound_full_projection_review';
                        }
                    }
                }
            }

            $this->store->saveExternalObservation($refund->withdrawalId(), $paymentKey, $refund->invoiceId(), $row);
        });
    }

    /**
     * Sweep Paymos RefundDiscovery pages to detect refunds created outside FOSSBilling.
     */
    public function discoverExternal(?string $onlyEnvironment = null): int
    {
        $environments = $onlyEnvironment !== null ? [$onlyEnvironment] : ['sandbox', 'live'];
        $checked = 0;

        foreach ($environments as $environment) {
            try {
                $refundConfig = $this->credentialStore->refundConfig($environment);
                if ($refundConfig['project_id'] === '') {
                    continue;
                }
                $cursorKey = 'external_refund_cursor_' . $environment . '_' . substr(hash('sha256', $refundConfig['project_id']), 0, 16);
                $cursor = $this->credentialStore->getMetadata($cursorKey);

                $result = RefundDiscovery::page(
                    $this->client($environment),
                    $environment,
                    is_string($cursor) && $cursor !== '' ? $cursor : null,
                    function (array $signal, string $env): bool {
                        $intent = $this->store->findByWithdrawalId((string) $signal['withdrawal_id'])
                            ?? $this->store->findByExternalId((string) $signal['external_order_id']);
                        if ($intent !== null) {
                            return false;
                        }
                        $this->observeExternal($signal, $env);
                        return true;
                    }
                );

                $this->credentialStore->setMetadata(
                    $cursorKey,
                    isset($result['next_cursor']) && is_string($result['next_cursor']) ? $result['next_cursor'] : null
                );
                $checked += (int) ($result['checked'] ?? 0);
            } catch (\Throwable $error) {
                $this->log('error', 'Paymos external refund discovery failed: ' . $error->getMessage());
            }
        }

        return $checked;
    }

    /**
     * Build structured admin panel state for a FOSSBilling invoice, reconciling active
     * intents and running RefundDiscovery.
     *
     * @return array<string, mixed>
     */
    public function panel(int $invoiceId, string $locale = 'en'): array
    {
        ($this->authorize)();

        $paymentKey = (string) $invoiceId;
        if ($invoiceId <= 0) {
            throw new \InvalidArgumentException('Invalid FOSSBilling invoice ID.');
        }

        $snapshot = null;
        try {
            $snapshot = $this->nativeCall('snapshot', ['invoice_id' => $invoiceId]);
        } catch (\Throwable $e) {
            $snapshot = null;
        }

        $environment = is_array($snapshot) && isset($snapshot['mapping']['environment'])
            ? (string) $snapshot['mapping']['environment']
            : '';

        if ($environment !== '') {
            $this->discoverExternal($environment);
        }

        return $this->store->withPaymentLock($paymentKey, function () use ($invoiceId, $paymentKey, $locale, $snapshot): array {
            foreach ($this->store->forPayment($paymentKey) as $existing) {
                if (!in_array($existing->cashState(), [RefundStates::COMPLETED, RefundStates::FAILED, RefundStates::CANCELLED], true)
                    || ($existing->cashState() === RefundStates::COMPLETED && $existing->projectionState() !== RefundStates::PROJECTION_APPLIED)
                ) {
                    try {
                        $this->reconcile($existing->operationId());
                    } catch (\Throwable $e) {
                        // Surface current persisted state even if Paymos is temporarily unreachable.
                    }
                }
            }

            // Reload snapshot after any reconciliation/discovery projection.
            try {
                $snapshot = $this->nativeCall('snapshot', ['invoice_id' => $invoiceId]);
            } catch (\Throwable $e) {
                // Keep prior snapshot.
            }

            $tokens = [
                RefundStates::FULL => RefundIntentStore::uuid(),
                RefundStates::OVERPAYMENT => RefundIntentStore::uuid(),
            ];
            $hasCompletedFull = false;
            $hasCompletedOverpayment = false;
            $hasBlocking = false;
            $intentViews = [];

            foreach ($this->store->forPayment($paymentKey) as $intent) {
                if ($this->store->blocksNewIntent($intent)) {
                    $hasBlocking = true;
                    $tokens[$intent->mode()] = (string) $intent->toArray()['request_token'];
                }
                if ($intent->mode() === RefundStates::FULL && $intent->cashState() === RefundStates::COMPLETED) {
                    $hasCompletedFull = true;
                }
                if ($intent->mode() === RefundStates::OVERPAYMENT && $intent->cashState() === RefundStates::COMPLETED) {
                    $hasCompletedOverpayment = true;
                }

                $obs = $intent->observation();
                $reason = $intent->toArray()['review_reason'];
                $intentViews[] = [
                    'operation_id' => $intent->operationId(),
                    'withdrawal_id' => $intent->withdrawalId(),
                    'mode' => $intent->mode(),
                    'cash_state' => $intent->cashState(),
                    'projection_state' => $intent->projectionState(),
                    'review_reason' => $reason,
                    'status_text' => RefundCopy::state($intent->cashState(), $intent->mode(), $intent->projectionState(), $reason, $locale),
                    'amount' => $obs !== null ? $obs->principalDecimal() : null,
                    'asset' => $obs !== null ? $obs->currency() . '/' . $obs->network() : null,
                ];
            }

            $externalViews = [];
            foreach ($this->store->externalObservationsForPayment($paymentKey) as $row) {
                $obs = $row['observation'];
                if (($obs['mode'] ?? '') === RefundStates::FULL && ($obs['status'] ?? '') === 'completed') {
                    $hasCompletedFull = true;
                }
                if (($obs['mode'] ?? '') === RefundStates::OVERPAYMENT && ($obs['status'] ?? '') === 'completed') {
                    $hasCompletedOverpayment = true;
                }
                if (($row['review_reason'] ?? null) !== null) {
                    $hasBlocking = true;
                }
                $externalViews[] = [
                    'withdrawal_id' => $obs['withdrawal_id'],
                    'mode' => $obs['mode'],
                    'status' => $obs['status'],
                    'amount' => $obs['amount'],
                    'asset' => $obs['currency'] . '/' . $obs['network'],
                    'review_reason' => $row['review_reason'],
                    'note_id' => $row['note_id'],
                ];
            }

            $mapping = is_array($snapshot) && isset($snapshot['mapping']) && is_array($snapshot['mapping']) ? $snapshot['mapping'] : [];
            $invoice = is_array($snapshot) && isset($snapshot['invoice']) && is_array($snapshot['invoice']) ? $snapshot['invoice'] : [];
            $env = (string) ($mapping['environment'] ?? '');
            $refundsCapable = false;
            if (in_array($env, ['sandbox', 'live'], true)) {
                try {
                    $cfg = $this->credentialStore->refundConfig($env);
                    $refundsCapable = in_array('invoice_refunds_v1', $cfg['capabilities'], true)
                        && $cfg['refund_contract_version'] === 1
                        && $cfg['refunds_enabled'] === true
                        && $cfg['project_id'] === ($mapping['project_id'] ?? '');
                } catch (\Throwable $e) {
                    $refundsCapable = false;
                }
            }

            $invoiceStatus = strtolower(trim((string) ($invoice['status'] ?? '')));
            $mappingStatus = strtolower(trim((string) ($mapping['final_status'] ?? $mapping['status'] ?? '')));
            $isPaid = $invoiceStatus === 'paid' && in_array($mappingStatus, ['paid', 'paid_over'], true);

            $canFull = $isPaid && $refundsCapable && !$hasBlocking && !$hasCompletedFull;
            $canOverpayment = $canFull && $mappingStatus === 'paid_over' && !$hasCompletedOverpayment;

            return [
                'invoice_id' => $invoiceId,
                'environment' => $env,
                'paymos_invoice_id' => (string) ($mapping['paymos_invoice_id'] ?? ''),
                'invoice_status' => $invoiceStatus,
                'mapping_status' => $mappingStatus,
                'amount' => (string) ($invoice['total'] ?? ($mapping['amount'] ?? '')),
                'currency' => strtoupper((string) ($invoice['currency'] ?? ($mapping['currency'] ?? ''))),
                'refunds_capable' => $refundsCapable,
                'can_refund_full' => $canFull,
                'can_refund_overpayment' => $canOverpayment,
                'tokens' => $tokens,
                'intents' => $intentViews,
                'external_refunds' => $externalViews,
                'copy' => RefundCopy::labels($locale),
            ];
        });
    }

    /**
     * Cron pass: reconcile due refund intents + run RefundDiscovery.
     */
    public function run(?int $now = null): void
    {
        $timestamp = $now ?? $this->now();
        $cursor = $this->credentialStore->getMetadata('refund_cursor');
        $page = $this->store->listDue(
            new \DateTimeImmutable('@' . $timestamp),
            100,
            is_string($cursor) && $cursor !== '' ? $cursor : null
        );

        foreach ($page->items() as $intent) {
            try {
                if ($intent->cashState() === RefundStates::NOT_SUBMITTED
                    && $intent->projectionState() !== RefundStates::NATIVE_REVIEW
                ) {
                    $this->coordinator($intent)->submit($intent->operationId());
                }
                $this->reconcile($intent->operationId());
            } catch (\Throwable $error) {
                $this->log('error', 'Refund reconciliation failed: ' . $error->getMessage(), [
                    'operation_id' => $intent->operationId(),
                ]);
            } finally {
                try {
                    $this->store->withPaymentLock($intent->paymentKey(), function () use ($intent, $timestamp): void {
                        $this->store->defer($intent->operationId(), $timestamp);
                    });
                } catch (\Throwable $error) {
                    $this->log('error', 'Refund defer failed: ' . $error->getMessage(), [
                        'operation_id' => $intent->operationId(),
                    ]);
                }
            }
        }

        $this->credentialStore->setMetadata('refund_cursor', $page->nextCursor());
        $this->discoverExternal();
    }

    /**
     * Adapter entry point for FOSSBilling 2.0 Payment_Adapter_Paymos::refundPayment().
     * NEVER allows premature debit while refund is pending/processing/unknown.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function refundPaymentForAdapter(array $input, string $locale = 'en'): array
    {
        $input['mode'] = RefundStates::FULL;
        if (!isset($input['request_token']) || !is_string($input['request_token']) || trim($input['request_token']) === '') {
            $reusedToken = null;
            $rawInvoiceId = $input['invoice_id'] ?? $input['payment_key'] ?? 0;
            if (is_numeric($rawInvoiceId) && (int) $rawInvoiceId > 0) {
                $paymentKey = (string) (int) $rawInvoiceId;
                foreach ($this->store->forPayment($paymentKey) as $existing) {
                    if ($existing->mode() === RefundStates::FULL && $this->store->blocksNewIntent($existing)) {
                        $tokenCandidate = (string) ($existing->toArray()['request_token'] ?? '');
                        if ($tokenCandidate !== '') {
                            $reusedToken = $tokenCandidate;
                            break;
                        }
                    }
                }
            }
            $input['request_token'] = $reusedToken ?? RefundIntentStore::uuid();
        }

        $intent = $this->submit($input);

        if ($intent->cashState() === RefundStates::COMPLETED
            && $intent->projectionState() === RefundStates::PROJECTION_APPLIED
        ) {
            return [
                'status' => 'completed',
                'refund_id' => $intent->withdrawalId(),
                'withdrawal_id' => $intent->withdrawalId(),
                'operation_id' => $intent->operationId(),
                'cash_state' => $intent->cashState(),
                'projection_state' => $intent->projectionState(),
            ];
        }

        if (in_array($intent->cashState(), [RefundStates::FAILED, RefundStates::CANCELLED, RefundStates::REVIEW], true)) {
            $reason = $intent->toArray()['review_reason'] ?? $intent->cashState();
            throw new \RuntimeException(Translation::text('refund_native_failed', $locale) . ' (' . $reason . ')');
        }

        // Async refund still pending/processing/unknown: abort native immediate debit!
        throw new \RuntimeException(
            Translation::text('refund_native_in_progress', $locale) . ' [' . $intent->operationId() . ']'
        );
    }

    /**
     * Adapter entry point for FOSSBilling 2.0 Payment_Adapter_Paymos::listRefundsForTransaction().
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRefundsForTransaction(string $transactionId): array
    {
        $trimmed = trim($transactionId);
        if ($trimmed === '') {
            return [];
        }

        $snapshot = $this->snapshotStore->findByTransactionId($trimmed)
            ?? $this->snapshotStore->findByPaymosInvoiceId($trimmed);
        if ($snapshot === null || !is_numeric($snapshot['platform_ref'] ?? null)) {
            return [];
        }

        $paymentKey = (string) ((int) $snapshot['platform_ref']);
        $items = [];

        foreach ($this->store->forPayment($paymentKey) as $intent) {
            $obs = $intent->observation();
            $items[] = [
                'source' => 'plugin',
                'operation_id' => $intent->operationId(),
                'withdrawal_id' => $intent->withdrawalId(),
                'mode' => $intent->mode(),
                'cash_state' => $intent->cashState(),
                'projection_state' => $intent->projectionState(),
                'amount' => $obs !== null ? $obs->principalDecimal() : null,
                'currency' => $obs !== null ? $obs->currency() : null,
                'network' => $obs !== null ? $obs->network() : null,
                'review_reason' => $intent->toArray()['review_reason'],
            ];
        }

        foreach ($this->store->externalObservationsForPayment($paymentKey) as $row) {
            $obs = $row['observation'];
            $items[] = [
                'source' => 'external',
                'operation_id' => null,
                'withdrawal_id' => $obs['withdrawal_id'],
                'mode' => $obs['mode'],
                'cash_state' => $obs['status'],
                'projection_state' => $row['note_id'] !== null ? RefundStates::PROJECTION_APPLIED : RefundStates::PROJECTION_PENDING,
                'amount' => $obs['amount'],
                'currency' => $obs['currency'],
                'network' => $obs['network'],
                'review_reason' => $row['review_reason'],
            ];
        }

        return $items;
    }

    /**
     * Create the production FOSSBilling native handler bridging RefundAdapter to
     * FOSSBilling 2.0 Invoice\Service::refundInvoice(..., external_reversal) and 0.7 fallback.
     *
     * @param mixed $di
     * @param callable(): int|null $clock
     * @param callable(string, string, array<string, mixed>): void|null $logger
     * @return callable(string, array<string, mixed>): array<string, mixed>
     */
    public static function createFossBillingNativeHandler(
        $di,
        CredentialStore $credentialStore,
        SnapshotStore $snapshotStore,
        ?callable $clock = null,
        ?callable $logger = null
    ): callable {
        $callbacks = CallbackProcessor::createFossBillingCallbacks($di, null, null, $clock, $logger);

        return static function (string $command, array $input) use ($di, $credentialStore, $snapshotStore, $callbacks): array {
            if ($command === 'snapshot') {
                $invoiceId = (int) ($input['invoice_id'] ?? 0);
                $invoice = ($callbacks['loadInvoice'])($invoiceId);
                $mapping = $snapshotStore->findPaidByPlatformRef((string) $invoiceId)
                    ?? $snapshotStore->findLatestByPlatformRef((string) $invoiceId);
                if (!is_array($invoice) || !is_array($mapping)) {
                    throw new \RuntimeException('refund_payment_mapping_missing');
                }
                return [
                    'invoice' => [
                        'id' => $invoiceId,
                        'status' => (string) ($invoice['status'] ?? ($invoice['paid'] ? 'paid' : 'unpaid')),
                        'total' => (string) ($invoice['amount'] ?? ''),
                        'currency' => strtoupper((string) ($invoice['currency'] ?? '')),
                        'client_id' => (string) ($invoice['client_id'] ?? ''),
                        'raw' => $invoice['raw'] ?? null,
                    ],
                    'mapping' => $mapping,
                ];
            }

            if ($command === 'marker') {
                $invoiceId = (int) ($input['invoice_id'] ?? 0);
                $mode = (string) ($input['mode'] ?? RefundStates::FULL);
                $withdrawalId = (string) ($input['withdrawal_id'] ?? '');
                $operationId = (string) ($input['operation_id'] ?? '');
                $key = 'refund_marker_' . $invoiceId . '_' . $mode . '_' . ($withdrawalId !== '' ? $withdrawalId : $operationId);
                $raw = $credentialStore->getMetadata($key);
                if (!is_string($raw) || $raw === '') {
                    return ['found' => false];
                }
                $decoded = json_decode($raw, true);
                return is_array($decoded) ? array_merge(['found' => true], $decoded) : ['found' => false];
            }

            if ($command === 'refund_invoice_full') {
                $invoiceId = (int) ($input['invoice_id'] ?? 0);
                $withdrawalId = (string) ($input['withdrawal_id'] ?? '');
                $operationId = (string) ($input['operation_id'] ?? '');
                $note = (string) ($input['note'] ?? '');
                $amountDecimal = (string) ($input['amount_decimal'] ?? '');
                $amountMinor = (string) ($input['amount_minor'] ?? '');
                $currency = strtoupper((string) ($input['currency'] ?? ''));

                $nativeId = 'paymos_refund_' . $withdrawalId;
                $modService = self::readDiKey($di, 'mod_service');
                $em = self::readDiKey($di, 'em');
                $db = self::readDiKey($di, 'db');
                $invoiceModel = null;
                $loadedViaDoctrine = false;

                if (is_object($em) && method_exists($em, 'find')) {
                    try {
                        $invoiceModel = $em->find(self::DOCTRINE_INVOICE_ENTITY, $invoiceId);
                        if (is_object($invoiceModel)) {
                            $loadedViaDoctrine = true;
                        }
                    } catch (\Throwable $e) {
                        $invoiceModel = null;
                    }
                }
                if (!is_object($invoiceModel) && is_object($db) && method_exists($db, 'getExistingModelById')) {
                    try {
                        $invoiceModel = $db->getExistingModelById('Invoice', $invoiceId);
                    } catch (\Throwable $e) {
                        $invoiceModel = null;
                    }
                }

                $projectedVia20 = false;
                $nativeRefundRecorded = false;
                if (is_callable($modService) && is_object($invoiceModel)) {
                    $invoiceService = $modService('invoice');
                    if (is_object($invoiceService) && method_exists($invoiceService, 'refundInvoice')) {
                        $ref = new \ReflectionMethod($invoiceService, 'refundInvoice');
                        if ($ref->getNumberOfParameters() < 4 && self::acceptsFirstArgument($ref, $invoiceModel)) {
                            // FOSSBilling 0.8.x refundInvoice($invoice, $note): writes the credit note or
                            // negative invoice per invoice_refund_logic and reverses income. It never
                            // calls a gateway, so the cash already returned by Paymos is not sent twice.
                            $invoiceService->refundInvoice($invoiceModel, $note);
                            $nativeRefundRecorded = true;
                        }
                        if ($ref->getNumberOfParameters() >= 4) {
                            // FOSSBilling 2.0 native external_reversal projection (no second gateway call).
                            $invoiceService->refundInvoice($invoiceModel, $note, null, [
                                'gateway_returned' => true,
                                'gateway_refund' => false,
                                'external_reversal' => [
                                    'gateway' => 'Paymos',
                                    'reference' => $nativeId,
                                    'withdrawal_id' => $withdrawalId,
                                    'operation_id' => $operationId,
                                    'amount' => $amountDecimal,
                                    'currency' => $currency,
                                ],
                            ]);
                            $projectedVia20 = true;
                        }
                    }
                }

                if (!$projectedVia20) {
                    // FOSSBilling 0.7/0.8 fallback: mark invoice refunded/note safely without crediting wallet twice.
                    if (is_object($invoiceModel)) {
                        if (method_exists($invoiceModel, 'setStatus')) {
                            $invoiceModel->setStatus('refunded');
                        } elseif (property_exists($invoiceModel, 'status') || isset($invoiceModel->status)) {
                            $invoiceModel->status = 'refunded';
                        }
                        if ($loadedViaDoctrine && is_object($em) && method_exists($em, 'flush')) {
                            $em->flush();
                        } elseif (is_object($db) && method_exists($db, 'store')) {
                            $db->store($invoiceModel);
                        } elseif (is_object($em) && method_exists($em, 'flush')) {
                            $em->flush();
                        }
                    }
                    ($callbacks['addNote'])($invoiceId, $note);
                }

                $markerPayload = [
                    'native_id' => $nativeId,
                    'invoice_id' => (string) $invoiceId,
                    'withdrawal_id' => $withdrawalId,
                    'operation_id' => $operationId,
                    'amount_decimal' => $amountDecimal,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'mode' => RefundStates::FULL,
                    'via' => $projectedVia20
                        ? 'fossbilling_2_external_reversal'
                        : ($nativeRefundRecorded ? 'fossbilling_08_refund_invoice' : 'fossbilling_07_fallback'),
                ];
                $encoded = json_encode($markerPayload, JSON_UNESCAPED_SLASHES);
                if (is_string($encoded)) {
                    $credentialStore->setMetadata('refund_marker_' . $invoiceId . '_full_' . $withdrawalId, $encoded);
                }

                return $markerPayload;
            }

            if ($command === 'record_overpayment_note') {
                $invoiceId = (int) ($input['invoice_id'] ?? 0);
                $withdrawalId = (string) ($input['withdrawal_id'] ?? '');
                $operationId = (string) ($input['operation_id'] ?? '');
                $amount = (string) ($input['amount'] ?? '');
                $asset = (string) ($input['asset'] ?? '');
                $note = (string) ($input['note'] ?? '');

                ($callbacks['addNote'])($invoiceId, $note);

                $nativeId = 'paymos_overpayment_' . $withdrawalId;
                $markerPayload = [
                    'native_id' => $nativeId,
                    'invoice_id' => (string) $invoiceId,
                    'withdrawal_id' => $withdrawalId,
                    'operation_id' => $operationId,
                    'amount' => $amount,
                    'asset' => $asset,
                    'mode' => RefundStates::OVERPAYMENT,
                ];
                $encoded = json_encode($markerPayload, JSON_UNESCAPED_SLASHES);
                if (is_string($encoded)) {
                    $credentialStore->setMetadata('refund_marker_' . $invoiceId . '_overpayment_' . $withdrawalId, $encoded);
                }

                return $markerPayload;
            }

            throw new \RuntimeException('Unsupported native refund command: ' . $command);
        };
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $draft
     */
    private function assertRequestMatchesDraft(array $input, ?array $draft): void
    {
        if ($draft === null) {
            if (array_key_exists('amount', $input) || array_key_exists('currency', $input)) {
                throw new \RuntimeException('refund_cash_only_input');
            }
            return;
        }

        if (array_key_exists('amount', $input)) {
            $raw = $input['amount'];
            if (!is_scalar($raw) || !AmountGuard::amountsEqual((string) $raw, (string) $draft['amount_decimal'])) {
                throw new \RuntimeException('refund_partial_or_changed_payment');
            }
        }
        if (array_key_exists('currency', $input)) {
            $raw = $input['currency'];
            if (!is_string($raw) || strtoupper(trim($raw)) !== $draft['currency']) {
                throw new \RuntimeException('refund_partial_or_changed_payment');
            }
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function assertMapping(array $snapshot, RefundIntent $intent): void
    {
        $mapping = $snapshot['mapping'] ?? [];
        $invoice = $snapshot['invoice'] ?? [];
        $config = $this->credentialStore->refundConfig($intent->environment());

        if ((string) ($invoice['id'] ?? '') !== $intent->paymentKey()
            || ($mapping['paymos_invoice_id'] ?? '') !== $intent->invoiceId()
            || ($mapping['project_id'] ?? '') !== $intent->projectId()
            || ($config['project_id'] ?? '') !== $intent->projectId()
            || ($mapping['environment'] ?? '') !== $intent->environment()
            || empty($mapping['external_order_id'])
        ) {
            throw new \RuntimeException('refund_payment_mapping_conflict');
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function assertOriginalPaid(array $snapshot): void
    {
        $invoice = $snapshot['invoice'] ?? [];
        $mapping = $snapshot['mapping'] ?? [];
        $invoiceStatus = strtolower(trim((string) ($invoice['status'] ?? '')));
        $mappingStatus = strtolower(trim((string) ($mapping['final_status'] ?? $mapping['status'] ?? '')));

        if ($invoiceStatus !== 'paid'
            || !in_array($mappingStatus, ['paid', 'paid_over'], true)
            || !isset($invoice['total'], $invoice['currency'], $mapping['amount'], $mapping['currency'])
            || !AmountGuard::amountsEqual((string) $invoice['total'], (string) $mapping['amount'])
            || strtoupper(trim((string) $invoice['currency'])) !== strtoupper(trim((string) $mapping['currency']))
        ) {
            throw new \RuntimeException('refund_original_full_paid_required');
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function buildFullDraft(array $snapshot): array
    {
        $invoice = $snapshot['invoice'] ?? [];
        $mapping = $snapshot['mapping'] ?? [];
        $total = AmountGuard::normalizeDecimal((string) ($invoice['total'] ?? ''));
        $currency = strtoupper(trim((string) ($invoice['currency'] ?? '')));

        if ($total === null || $total === '0' || preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', $total) !== 1 || $currency === '') {
            throw new \RuntimeException('refund_native_precision_invalid');
        }

        $parts = explode('.', $total, 2);
        $minor = ltrim($parts[0] . str_pad($parts[1] ?? '', 2, '0'), '0');
        if ($minor === '' || preg_match('/\A[1-9][0-9]*\z/', $minor) !== 1) {
            throw new \RuntimeException('refund_native_precision_invalid');
        }

        return [
            'amount_decimal' => $total,
            'amount_minor' => $minor,
            'currency' => $currency,
            'client_id' => (string) ($invoice['client_id'] ?? ($mapping['client_id'] ?? '')),
            'original_transaction_id' => (string) ($mapping['transaction_id'] ?? ($mapping['paymos_invoice_id'] ?? '')),
            'send_email' => false,
        ];
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $snapshot
     */
    private function assertApiInvoice(array $invoice, array $snapshot, RefundIntent $intent): void
    {
        $mapping = $snapshot['mapping'] ?? [];
        if (($invoice['invoice_id'] ?? '') !== $intent->invoiceId()
            || ($invoice['project_id'] ?? '') !== $intent->projectId()
            || ($invoice['is_test'] ?? null) !== ($intent->environment() === 'sandbox')
            || ($invoice['order']['external_id'] ?? '') !== ($mapping['external_order_id'] ?? '')
            || !AmountGuard::amountsEqual($invoice['order']['amount'] ?? '', $mapping['amount'] ?? '')
            || strtoupper(trim((string) ($invoice['order']['currency'] ?? ''))) !== strtoupper(trim((string) ($mapping['currency'] ?? '')))
        ) {
            throw new \RuntimeException('refund_invoice_scope_conflict');
        }
    }

    /**
     * @param array<string, mixed> $invoice
     * @return array<string, mixed>
     */
    private function invoiceSummary(array $invoice, RefundIntent $intent): array
    {
        if (array_key_exists('refund_summary', $invoice)) {
            return is_array($invoice['refund_summary']) ? $invoice['refund_summary'] : [];
        }

        $status = isset($invoice['status']) && is_string($invoice['status']) ? $invoice['status'] : '';
        $payment = isset($invoice['payment']) && is_array($invoice['payment']) ? $invoice['payment'] : [];
        $completedOverpayment = false;

        foreach ($this->store->forPayment($intent->paymentKey()) as $prior) {
            if ($prior->invoiceId() === $intent->invoiceId()
                && $prior->mode() === RefundStates::OVERPAYMENT
                && $prior->cashState() === RefundStates::COMPLETED
            ) {
                $completedOverpayment = true;
                break;
            }
        }
        if (!$completedOverpayment) {
            foreach ($this->store->externalObservationsForPayment($intent->paymentKey()) as $ext) {
                $obs = $ext['observation'];
                if ($obs['invoice_id'] === $intent->invoiceId()
                    && $obs['mode'] === RefundStates::OVERPAYMENT
                    && $obs['status'] === 'completed'
                ) {
                    $completedOverpayment = true;
                    break;
                }
            }
        }

        return [
            'can_refund_full' => in_array($status, ['paid', 'paid_over'], true),
            'can_refund_overpayment' => $status === 'paid_over' && !$completedOverpayment,
            'full_refund_amount' => null,
            'overpayment_refund_amount' => null,
            'refund_asset' => isset($payment['currency'], $payment['network'])
                ? ['currency' => $payment['currency'], 'network' => $payment['network']]
                : null,
        ];
    }

    private function coordinator(RefundIntent $intent): RefundCoordinator
    {
        $clockFn = function (): \DateTimeImmutable {
            return new \DateTimeImmutable('@' . $this->now());
        };
        return new RefundCoordinator($this->client($intent->environment()), $this->store, $this, $clockFn);
    }

    private function client(string $environment): Client
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($environment);
        }

        $config = Config::fromCredentialStore($this->credentialStore, ['mode' => $environment]);
        return new Client($config->clientConfig($environment));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function nativeCall(string $command, array $input): array
    {
        $result = ($this->native)($command, $input);
        return is_array($result) ? $result : [];
    }

    private function ambiguous(RefundIntent $intent, string $reason): NativeProjectionResult
    {
        return new NativeProjectionResult(NativeProjectionResult::AMBIGUOUS, $intent->mode(), null, null, null, null, null, $reason);
    }

    /**
     * Whether $argument fits the first parameter's declared type (a Doctrine entity is not a
     * RedBean \Model_Invoice).
     */
    private static function acceptsFirstArgument(\ReflectionMethod $method, object $argument): bool
    {
        $params = $method->getParameters();
        if (!isset($params[0])) {
            return false;
        }
        $type = $params[0]->getType();
        if ($type === null) {
            return true;
        }
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }
        if ($type->isBuiltin()) {
            return in_array($type->getName(), ['object', 'mixed'], true);
        }

        return is_a($argument, $type->getName());
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
            ($this->logger)($level, Config::redactStatic($message), $context);
        }
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
