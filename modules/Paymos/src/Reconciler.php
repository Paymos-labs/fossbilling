<?php

declare(strict_types=1);

namespace PaymosFossBilling;

use Paymos\Client;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\InvoiceReconciler;
use Paymos\Plugin\StatusMapper;

final class Reconciler
{
    public const THROTTLE_SECONDS = 600;
    public const LOOKBACK_SECONDS = 86400;
    public const BATCH_LIMIT = 50;
    private const LAST_RUN_KEY = 'reconcile_last_run';

    private Config $config;
    private CredentialStore $credentialStore;
    private SnapshotStore $snapshotStore;
    private CallbackProcessor $callbackProcessor;

    /** @var callable(string): Client|null */
    private $clientFactory;

    /** @var callable(): int */
    private $clock;

    /** @var callable(string, string, array<string, mixed>): void|null */
    private $logger;

    /**
     * @param callable(string): Client|null $clientFactory
     * @param callable(): int|null $clock
     * @param callable(string, string, array<string, mixed>): void|null $logger
     */
    public function __construct(
        Config $config,
        CredentialStore $credentialStore,
        SnapshotStore $snapshotStore,
        CallbackProcessor $callbackProcessor,
        ?callable $clientFactory = null,
        ?callable $clock = null,
        ?callable $logger = null
    ) {
        $this->config = $config;
        $this->credentialStore = $credentialStore;
        $this->snapshotStore = $snapshotStore;
        $this->callbackProcessor = $callbackProcessor;
        $this->clientFactory = $clientFactory;
        $this->clock = $clock ?? static function (): int {
            return time();
        };
        $this->logger = $logger;
    }

    public function isDue(?int $now = null): bool
    {
        $timestamp = $now ?? $this->now();
        $rawLast = $this->credentialStore->getMetadata(self::LAST_RUN_KEY);
        $lastRun = is_string($rawLast) && is_numeric($rawLast) ? (int) $rawLast : 0;

        if ($lastRun > 0 && ($timestamp - $lastRun) < self::THROTTLE_SECONDS) {
            return false;
        }

        $this->credentialStore->setMetadata(self::LAST_RUN_KEY, (string) $timestamp);
        return true;
    }

    public function run(?int $now = null, bool $force = false): int
    {
        $timestamp = $now ?? $this->now();
        if (!$force && !$this->isDue($timestamp)) {
            return 0;
        }
        if ($force) {
            $this->credentialStore->setMetadata(self::LAST_RUN_KEY, (string) $timestamp);
        }

        $rows = $this->snapshotStore->findUnpaidRecent(self::BATCH_LIMIT, $timestamp - self::LOOKBACK_SECONDS);
        $appliedCount = 0;

        foreach ($rows as $row) {
            if (CheckoutService::isPendingIntent($row)) {
                continue;
            }

            $environment = (string) ($row['environment'] ?? '');
            $paymosInvoiceId = (string) ($row['paymos_invoice_id'] ?? '');
            if ($paymosInvoiceId === '' || !$this->config->hasEnvironment($environment)) {
                continue;
            }

            try {
                $reconciler = new InvoiceReconciler($this->client($environment));
                $results = $reconciler->reconcile([$paymosInvoiceId]);
                if (count($results) === 0) {
                    continue;
                }

                $result = $results[0];
                $invoice = $result->invoice();

                if (!$this->snapshotMatches($row, $invoice)) {
                    $this->log('warning', 'Snapshot mismatch during reconciliation.', [
                        'paymos_invoice_id' => $paymosInvoiceId,
                        'external_order_id' => $row['external_order_id'] ?? '',
                    ]);
                    continue;
                }

                if (!empty($invoice['paid_late_at'])) {
                    $this->callbackProcessor->applyTrustedInvoice($invoice, $row, $timestamp);
                    continue;
                }

                if ($result->action() === StatusMapper::ACTION_IGNORE) {
                    continue;
                }

                if ($this->callbackProcessor->applyTrustedInvoice($invoice, $row, $timestamp)) {
                    $appliedCount++;
                }
            } catch (\Throwable $e) {
                $this->log('error', 'Reconcile error: ' . $e->getMessage(), [
                    'paymos_invoice_id' => $paymosInvoiceId,
                ]);
            }
        }

        return $appliedCount;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $invoice
     */
    private function snapshotMatches(array $row, array $invoice): bool
    {
        if (!array_key_exists('is_test', $invoice) || !is_bool($invoice['is_test'])) {
            return false;
        }
        $expectedIsTest = strtolower(trim((string) ($row['environment'] ?? ''))) === 'sandbox';
        if ($invoice['is_test'] !== $expectedIsTest) {
            return false;
        }

        return self::matchesString((string) ($row['project_id'] ?? ''), self::field($invoice, ['project_id']))
            && self::matchesString((string) ($row['external_order_id'] ?? ''), self::field($invoice, ['order', 'external_id']))
            && self::matchesAmount((string) ($row['amount'] ?? ''), self::field($invoice, ['order', 'amount']))
            && self::matchesString(strtoupper((string) ($row['currency'] ?? '')), strtoupper(self::field($invoice, ['order', 'currency'])));
    }

    private static function matchesString(string $expected, string $actual): bool
    {
        $expected = trim($expected);
        $actual = trim($actual);
        return $expected !== '' && $actual !== '' && $expected === $actual;
    }

    private static function matchesAmount(string $expected, string $actual): bool
    {
        $expected = trim($expected);
        $actual = trim($actual);
        return $expected !== '' && $actual !== '' && AmountGuard::amountsEqual($expected, $actual);
    }

    private function client(string $environment): Client
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($environment);
        }

        return new Client($this->config->clientConfig($environment));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $path
     */
    private static function field(array $payload, array $path): string
    {
        $current = $payload;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }
            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : '';
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
