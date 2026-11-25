<?php

declare(strict_types=1);

namespace PaymosFossBilling;

use Paymos\Client;
use Paymos\Exception\ApiException;
use Paymos\Exception\NotFoundException;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\InvoicePresentation;
use Paymos\Plugin\InvoiceRenewal;
use Paymos\Plugin\InvoiceReplacement;
use Paymos\Plugin\InvoiceReplacementBlockedException;
use Paymos\Plugin\InvoiceReplacementResult;
use Paymos\Plugin\StatusMapper;

final class CheckoutService
{
    public const STATUS_CREATING = SnapshotStore::STATUS_CREATING;

    private Config $config;
    private SnapshotStore $snapshots;

    /** @var callable(Config, ?string): Client|null */
    private $clientFactory;

    /** @var callable(string, string, array<string, mixed>): void|null */
    private $logger;

    /** @var callable(): int */
    private $clock;

    /**
     * @param callable(Config, ?string): Client|null $clientFactory
     * @param callable(string, string, array<string, mixed>): void|null $logger
     * @param callable(): int|null $clock
     */
    public function __construct(
        Config $config,
        SnapshotStore $snapshots,
        ?callable $clientFactory = null,
        ?callable $logger = null,
        ?callable $clock = null
    ) {
        $this->config = $config;
        $this->snapshots = $snapshots;
        $this->clientFactory = $clientFactory;
        $this->logger = $logger;
        $this->clock = $clock ?? static function (): int {
            return time();
        };
    }

    /**
     * Create or safely reuse a Paymos hosted checkout invoice for a FOSSBilling invoice.
     *
     * @param array<string, mixed> $invoice FOSSBilling invoice array (from invoice_get)
     * @return array<string, mixed>
     */
    public function createCheckout(array $invoice): array
    {
        $invoiceId = isset($invoice['id']) && is_numeric($invoice['id']) ? (int) $invoice['id'] : 0;
        if ($invoiceId <= 0) {
            throw new \InvalidArgumentException('Invalid FOSSBilling invoice ID.');
        }

        $rawAmount = $invoice['total'] ?? ($invoice['amount'] ?? null);
        $amount = self::normalizePositiveAmount($rawAmount);
        if ($amount === null) {
            throw new \InvalidArgumentException('Invoice amount must be a positive decimal.');
        }

        $currency = isset($invoice['currency']) && is_scalar($invoice['currency'])
            ? strtoupper(trim((string) $invoice['currency']))
            : '';
        if (!preg_match('/\A[A-Z0-9]{2,10}\z/', $currency)) {
            throw new \InvalidArgumentException('Invoice currency is missing or invalid.');
        }

        $clientId = self::extractNativeClientId($invoice);
        $lines = isset($invoice['lines']) && is_array($invoice['lines']) ? $invoice['lines'] : [];

        $existing = $this->snapshots->findLatestByPlatformRef((string) $invoiceId);

        if (is_array($existing) && self::isPendingIntent($existing)) {
            $existing = $this->recoverPendingIntent($existing, $clientId, $lines);
        }

        if (is_array($existing) && self::isPaidLateNoted($existing)) {
            $this->blockPaidLate($existing, 'Paymos invoice was paid late and requires manual review.');
        }

        if (is_array($existing)
            && $this->snapshotMatches($existing, $amount, $currency)
            && $this->keepsExistingInvoice($existing)
        ) {
            return $existing;
        }

        if (is_array($existing)) {
            $this->closeBeforeReplacing($existing);
        }

        $renewCount = is_array($existing) ? ((int) ($existing['renewal_attempt'] ?? 0) + 1) : 0;
        $externalOrderId = self::buildExternalOrderId($invoiceId, $renewCount);
        $payload = $this->buildCreatePayload(
            $this->config->projectId(),
            $amount,
            $currency,
            $externalOrderId,
            $clientId,
            $lines
        );

        // Persist crash-safe pending intent BEFORE POST /v1/invoices.
        $now = $this->now();
        $intent = [
            'external_order_id' => $externalOrderId,
            'paymos_invoice_id' => self::pendingInvoiceId($externalOrderId),
            'platform_ref' => (string) $invoiceId,
            'client_id' => $clientId,
            'amount' => $amount,
            'invoice_total' => $amount,
            'currency' => $currency,
            'environment' => $this->config->environment(),
            'project_id' => $this->config->projectId(),
            'payment_url' => '',
            'status' => self::STATUS_CREATING,
            'renewal_attempt' => $renewCount,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->snapshots->save($intent);

        $response = $this->client($this->config->environment())->invoices()->create($payload);
        return $this->bindCreatedInvoice($intent, $response);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function isPendingIntent(array $row): bool
    {
        return isset($row['status']) && (string) $row['status'] === self::STATUS_CREATING;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function isPaidLateNoted(array $row): bool
    {
        if (!empty($row['paid_late_noted'])) {
            return true;
        }
        if (isset($row['payload_meta']) && is_string($row['payload_meta']) && $row['payload_meta'] !== '') {
            $meta = json_decode($row['payload_meta'], true);
            if (is_array($meta) && !empty($meta['paid_late_noted'])) {
                return true;
            }
        }

        return false;
    }

    public static function buildExternalOrderId(int $invoiceId, int $renewCount = 0): string
    {
        return 'fossbilling_' . $invoiceId . '_' . max(0, $renewCount);
    }

    public static function parseInvoiceIdFromExternalOrderId(string $externalOrderId): ?int
    {
        $trimmed = trim($externalOrderId);
        if (preg_match('/\Afossbilling_([1-9][0-9]*)(?:_[0-9]+)?\z/', $trimmed, $m) === 1) {
            return (int) $m[1];
        }
        if (preg_match('/\Afb-([1-9][0-9]*)-/', $trimmed, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Extract 1..50 validated line items for Paymos `products[]`, or return null
     * (all-or-nothing amount-only fallback) if empty, >50, or any line is invalid.
     *
     * @param array<int, mixed> $lines
     * @return array<int, array<string, mixed>>|null
     */
    public static function extractProducts(array $lines): ?array
    {
        if (count($lines) === 0) {
            return null;
        }

        $candidates = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                return null;
            }
            if (self::isExcludedLine($line)) {
                continue;
            }

            $title = null;
            $description = null;
            if (isset($line['title']) && is_string($line['title']) && trim($line['title']) !== '') {
                $title = $line['title'];
                if (isset($line['description']) && is_string($line['description']) && trim($line['description']) !== '' && trim($line['description']) !== trim($title)) {
                    $description = $line['description'];
                }
            } elseif (isset($line['description']) && is_string($line['description']) && trim($line['description']) !== '') {
                $parts = explode("\n", str_replace(["\r\n", "\r"], "\n", $line['description']));
                $title = array_shift($parts);
                $rest = trim(implode("\n", $parts));
                $description = $rest !== '' ? $rest : null;
            }

            $quantity = array_key_exists('quantity', $line)
                ? $line['quantity']
                : (array_key_exists('qty', $line) ? $line['qty'] : 1);

            $rawUnit = array_key_exists('unit_amount', $line)
                ? $line['unit_amount']
                : (array_key_exists('price', $line)
                    ? $line['price']
                    : (array_key_exists('amount', $line) ? $line['amount'] : null));

            $unitAmount = self::formatPositiveUnitAmount($rawUnit);
            if ($unitAmount === null) {
                return null;
            }

            $candidates[] = [
                'title' => $title,
                'description' => $description,
                'quantity' => $quantity,
                'unit_amount' => $unitAmount,
            ];
        }

        return InvoicePresentation::normalizeProducts($candidates);
    }

    public static function renderPaymentHtml(string $paymentUrl, string $buttonText = '', string $locale = 'en'): string
    {
        $trimmedUrl = trim($paymentUrl);
        if (!ConnectHandler::isHttpsUrl($trimmedUrl)) {
            throw new \RuntimeException('Paymos payment URL must use HTTPS.');
        }

        $label = trim($buttonText);
        if ($label === '' || $label === 'Pay with Paymos') {
            $label = Translation::text('pay_button', $locale);
        }

        $escapedUrl = htmlspecialchars($trimmedUrl, ENT_QUOTES, 'UTF-8');
        $escapedLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

        return '<form method="get" action="' . $escapedUrl . '" class="paymos-checkout-form">'
            . '<button type="submit" class="btn btn-primary">' . $escapedLabel . '</button>'
            . '</form>';
    }

    public static function failureNotice(\Throwable $e, string $locale = 'en', ?Config $config = null): string
    {
        if ($e instanceof InvoiceReplacementBlockedException) {
            return self::alert(Translation::text('replacement_blocked', $locale));
        }

        if ($e instanceof ApiException) {
            $detail = $e->detail();
            if ($detail === null || trim($detail) === '' || !Translation::isEnglish($locale)) {
                $detail = Translation::text('payment_cannot_be_used', $locale);
            }
            $field = $e->field();
            if ($field !== null && trim($field) !== '') {
                $detail .= ' (' . $field . ')';
            }
            $safe = $config !== null ? $config->redact($detail) : Config::redactStatic($detail);
            return self::alert($safe);
        }

        return self::alert(Translation::text('payment_unavailable', $locale));
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<int, mixed> $lines
     * @return array<string, mixed>
     */
    private function recoverPendingIntent(array $intent, ?string $clientId, array $lines): array
    {
        $payload = $this->buildCreatePayload(
            (string) $intent['project_id'],
            (string) $intent['amount'],
            (string) $intent['currency'],
            (string) $intent['external_order_id'],
            $intent['client_id'] ?? $clientId,
            $lines
        );

        $response = $this->client((string) $intent['environment'])->invoices()->create($payload);
        return $this->bindCreatedInvoice($intent, $response);
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function bindCreatedInvoice(array $intent, array $response): array
    {
        $paymosInvoiceId = isset($response['invoice_id']) && is_scalar($response['invoice_id'])
            ? trim((string) $response['invoice_id'])
            : '';
        $paymentUrl = isset($response['payment_url']) && is_scalar($response['payment_url'])
            ? trim((string) $response['payment_url'])
            : '';

        if ($paymosInvoiceId === '' || $paymentUrl === '' || !ConnectHandler::isHttpsUrl($paymentUrl)) {
            throw new \RuntimeException('Paymos invoice create response is missing invoice_id or HTTPS payment_url.');
        }

        $status = isset($response['status']) && is_scalar($response['status'])
            ? strtolower(trim((string) $response['status']))
            : '';
        $expiresAt = isset($response['expires_at']) && is_numeric($response['expires_at'])
            ? (int) $response['expires_at']
            : null;

        $row = $intent;
        $row['paymos_invoice_id'] = $paymosInvoiceId;
        $row['payment_url'] = $paymentUrl;
        $row['expires_at'] = $expiresAt;
        // A recovered idempotent create may already be terminal on Paymos before FOSSBilling booked it.
        $row['status'] = StatusMapper::isFinalStatus($status) ? 'created' : ($status !== '' ? $status : 'created');
        $row['updated_at'] = $this->now();
        $this->snapshots->save($row);

        return $row;
    }

    /**
     * @param array<int, mixed> $lines
     * @return array<string, mixed>
     */
    private function buildCreatePayload(
        string $projectId,
        string $amount,
        string $currency,
        string $externalOrderId,
        ?string $clientId,
        array $lines
    ): array {
        $payload = [
            'project_id' => $projectId,
            'amount' => $amount,
            'currency' => $currency,
            'external_order_id' => $externalOrderId,
        ];

        if ($clientId !== null && $clientId !== '') {
            $payload['client_id'] = $clientId;
        }

        $products = self::extractProducts($lines);
        if ($products !== null) {
            $payload['products'] = $products;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function snapshotMatches(array $row, string $amount, string $currency): bool
    {
        return AmountGuard::amountsEqual((string) ($row['amount'] ?? ''), $amount)
            && strtoupper((string) ($row['currency'] ?? '')) === strtoupper($currency)
            && (string) ($row['project_id'] ?? '') === $this->config->projectId()
            && (string) ($row['environment'] ?? '') === $this->config->environment()
            && trim((string) ($row['payment_url'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function keepsExistingInvoice(array $row): bool
    {
        $now = $this->now();
        if (InvoiceRenewal::isRequired($row, $now)) {
            return false;
        }
        $localStatus = (string) ($row['final_status'] ?? $row['status'] ?? '');
        if (StatusMapper::isFinalStatus($localStatus)) {
            return true;
        }

        try {
            $live = $this->client((string) $row['environment'])->invoices()->get((string) $row['paymos_invoice_id']);
        } catch (NotFoundException $e) {
            return false;
        } catch (\Throwable $e) {
            return true;
        }

        if (is_array($live) && !empty($live['paid_late_at'])) {
            $paidLateAt = is_numeric($live['paid_late_at']) ? (int) $live['paid_late_at'] : null;
            $this->snapshots->recordPaidLateNote((string) $row['external_order_id'], (string) ($paidLateAt ?? '1'), $paidLateAt);
            $this->blockPaidLate($row, 'Paymos invoice was paid late and requires manual review.');
        }

        if (!is_array($live) || !InvoiceRenewal::isRequired($live, $now)) {
            return true;
        }

        $liveStatus = isset($live['status']) && is_scalar($live['status']) ? strtolower(trim((string) $live['status'])) : '';
        if ($liveStatus !== '') {
            $this->snapshots->updateStatus((string) $row['paymos_invoice_id'], $liveStatus);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function closeBeforeReplacing(array $row): void
    {
        if (self::isPaidLateNoted($row)) {
            $this->blockPaidLate($row, 'Paymos invoice was paid late and requires manual review.');
        }

        $environment = (string) $row['environment'];
        $recorded = (string) ($row['final_status'] ?? $row['status'] ?? '');
        $observed = new \stdClass();
        $observed->invoice = null;

        $replacement = new InvoiceReplacement(function () use ($environment, $observed) {
            $client = $this->client($environment);
            return new class($client, $observed) {
                private Client $client;
                private \stdClass $observed;

                public function __construct(Client $client, \stdClass $observed)
                {
                    $this->client = $client;
                    $this->observed = $observed;
                }

                public function invoices(): object
                {
                    $invoices = $this->client->invoices();
                    $observed = $this->observed;
                    return new class($invoices, $observed) {
                        private object $invoices;
                        private \stdClass $observed;

                        public function __construct(object $invoices, \stdClass $observed)
                        {
                            $this->invoices = $invoices;
                            $this->observed = $observed;
                        }

                        /** @return array<string, mixed> */
                        public function cancel(string $invoiceId, string $reason): array
                        {
                            $res = $this->invoices->cancel($invoiceId, $reason);
                            if (is_array($res) && (!empty($res['paid_late_at']) || !is_array($this->observed->invoice))) {
                                $this->observed->invoice = $res;
                            }
                            return $res;
                        }

                        /** @return array<string, mixed> */
                        public function get(string $invoiceId): array
                        {
                            $res = $this->invoices->get($invoiceId);
                            if (is_array($res) && (!empty($res['paid_late_at']) || !is_array($this->observed->invoice))) {
                                $this->observed->invoice = $res;
                            }
                            return $res;
                        }
                    };
                }
            };
        });

        $result = $replacement->close((string) $row['paymos_invoice_id'], $recorded);

        if (is_array($observed->invoice) && !empty($observed->invoice['paid_late_at'])) {
            $paidLateAt = is_numeric($observed->invoice['paid_late_at']) ? (int) $observed->invoice['paid_late_at'] : null;
            $this->snapshots->recordPaidLateNote((string) $row['external_order_id'], (string) ($paidLateAt ?? '1'), $paidLateAt);
            $this->blockPaidLate($row, 'Paymos invoice was paid late and requires manual review.');
        }

        if ($result->isClosed()) {
            if ($result->status() !== '' && $result->status() !== $recorded) {
                $this->snapshots->updateStatus((string) $row['paymos_invoice_id'], $result->status());
            }
            return;
        }

        $this->log('warning', 'Manual review: ' . $result->summary(), [
            'invoice_id' => (string) ($row['platform_ref'] ?? ''),
            'paymos_invoice_id' => $result->invoiceId(),
            'status' => $result->status(),
            'reason' => $result->reason(),
        ]);

        throw new InvoiceReplacementBlockedException($result);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function blockPaidLate(array $row, string $detail): void
    {
        $result = InvoiceReplacementResult::blocked(
            (string) ($row['paymos_invoice_id'] ?? ''),
            (string) ($row['status'] ?? ''),
            InvoiceReplacementResult::REASON_PAID,
            $detail
        );

        $this->log('warning', 'Manual review: ' . $result->summary(), [
            'invoice_id' => (string) ($row['platform_ref'] ?? ''),
            'paymos_invoice_id' => $result->invoiceId(),
            'reason' => $result->reason(),
        ]);

        throw new InvoiceReplacementBlockedException($result);
    }

    private function client(string $environment): Client
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($this->config, $environment);
        }

        return new Client($this->config->clientConfig($environment));
    }

    private static function pendingInvoiceId(string $externalOrderId): string
    {
        return 'pending:' . $externalOrderId;
    }

    /**
     * @param mixed $rawAmount
     */
    private static function normalizePositiveAmount($rawAmount): ?string
    {
        $normalized = AmountGuard::normalizeDecimal($rawAmount);
        if ($normalized === null || $normalized === '0' || $normalized[0] === '-') {
            return null;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private static function extractNativeClientId(array $invoice): ?string
    {
        $candidate = null;
        if (isset($invoice['client']) && is_array($invoice['client']) && isset($invoice['client']['id']) && is_scalar($invoice['client']['id'])) {
            $candidate = trim((string) $invoice['client']['id']);
        } elseif (isset($invoice['client_id']) && is_scalar($invoice['client_id'])) {
            $candidate = trim((string) $invoice['client_id']);
        }

        if ($candidate === null || $candidate === '' || $candidate === '0' || strpos($candidate, '@') !== false) {
            return null;
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function isExcludedLine(array $line): bool
    {
        $type = isset($line['type']) && is_scalar($line['type']) ? strtolower(trim((string) $line['type'])) : '';
        if ($type === '') {
            return false;
        }

        $excluded = [
            'discount',
            'promo',
            'tax',
            'shipping',
            'fee',
            'credit',
            'latefee',
            'paymentfee',
            'gatewayfee',
        ];
        if (in_array($type, $excluded, true)) {
            return true;
        }

        return strncmp($type, 'promo', 5) === 0 || substr($type, -8) === 'discount';
    }

    /**
     * @param mixed $raw
     */
    private static function formatPositiveUnitAmount($raw): ?string
    {
        if (is_int($raw)) {
            return $raw > 0 ? (string) $raw : null;
        }
        if (is_float($raw)) {
            if (!is_finite($raw) || $raw <= 0.0) {
                return null;
            }
            $formatted = json_encode($raw);
            if (!is_string($formatted) || $formatted === '' || strpos($formatted, ',') !== false || stripos($formatted, 'e') !== false) {
                $formatted = rtrim(rtrim(sprintf('%.18F', $raw), '0'), '.');
            }
            $normalized = AmountGuard::normalizeDecimal($formatted);
            return ($normalized !== null && $normalized !== '0' && $normalized[0] !== '-') ? $normalized : null;
        }
        if (!is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);
        $normalized = AmountGuard::normalizeDecimal($trimmed);
        if ($normalized === null || $normalized === '0' || $normalized[0] === '-') {
            return null;
        }

        return $normalized;
    }

    private static function alert(string $text): string
    {
        return '<div class="alert alert-danger">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
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
