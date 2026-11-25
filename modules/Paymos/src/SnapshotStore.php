<?php

declare(strict_types=1);

namespace PaymosFossBilling;

use Paymos\Plugin\StatusMapper;

final class SnapshotStore
{
    public const TABLE = 'paymos_invoice_snapshots';
    public const STATUS_CREATING = 'creating';
    public const STATUS_INVOICE_MISSING = 'fossbilling_invoice_missing';

    private DbDriverInterface $db;

    /** @var callable(): int */
    private $clock;

    private bool $schemaReady = false;

    /**
     * @param callable(): int|null $clock
     */
    public function __construct(DbDriverInterface $db, ?callable $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock ?? static function (): int {
            return time();
        };
    }

    public function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }

        if ($this->db->tableExists(self::TABLE)) {
            $this->schemaReady = true;
            return;
        }

        if ($this->db->inTransaction()) {
            throw new \RuntimeException(
                'Paymos invoice snapshot table "' . self::TABLE . '" does not exist and cannot be created inside an active database transaction.'
            );
        }

        if ($this->db->driverName() === 'mysql') {
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . 'external_order_id VARCHAR(128) NOT NULL PRIMARY KEY, '
                . 'paymos_invoice_id VARCHAR(128) NOT NULL, '
                . 'platform_ref VARCHAR(128) NOT NULL, '
                . 'client_id VARCHAR(64) NULL, '
                . 'payload_meta TEXT NULL, '
                . 'amount VARCHAR(64) NOT NULL, '
                . 'invoice_total VARCHAR(64) NULL, '
                . 'currency VARCHAR(16) NOT NULL, '
                . 'environment VARCHAR(16) NOT NULL, '
                . 'project_id VARCHAR(64) NOT NULL, '
                . 'payment_url TEXT NOT NULL, '
                . 'transaction_id VARCHAR(128) NULL, '
                . 'status VARCHAR(32) NOT NULL DEFAULT \'created\', '
                . 'expires_at BIGINT UNSIGNED NULL, '
                . 'renewal_attempt INT UNSIGNED NOT NULL DEFAULT 0, '
                . 'final_status VARCHAR(32) NULL, '
                . 'paid_late_noted VARCHAR(128) NULL, '
                . 'created_at BIGINT UNSIGNED NOT NULL, '
                . 'updated_at BIGINT UNSIGNED NOT NULL, '
                . 'KEY idx_paymos_snapshots_invoice_id (paymos_invoice_id), '
                . 'KEY idx_paymos_snapshots_platform_ref (platform_ref), '
                . 'KEY idx_paymos_snapshots_tx_id (transaction_id), '
                . 'KEY idx_paymos_snapshots_reconcile (status, updated_at)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } else {
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . 'external_order_id VARCHAR(128) NOT NULL PRIMARY KEY, '
                . 'paymos_invoice_id VARCHAR(128) NOT NULL, '
                . 'platform_ref VARCHAR(128) NOT NULL, '
                . 'client_id VARCHAR(64) NULL, '
                . 'payload_meta TEXT NULL, '
                . 'amount VARCHAR(64) NOT NULL, '
                . 'invoice_total VARCHAR(64) NULL, '
                . 'currency VARCHAR(16) NOT NULL, '
                . 'environment VARCHAR(16) NOT NULL, '
                . 'project_id VARCHAR(64) NOT NULL, '
                . 'payment_url TEXT NOT NULL, '
                . 'transaction_id VARCHAR(128) NULL, '
                . 'status VARCHAR(32) NOT NULL DEFAULT \'created\', '
                . 'expires_at BIGINT NULL, '
                . 'renewal_attempt INT NOT NULL DEFAULT 0, '
                . 'final_status VARCHAR(32) NULL, '
                . 'paid_late_noted VARCHAR(128) NULL, '
                . 'created_at BIGINT NOT NULL, '
                . 'updated_at BIGINT NOT NULL'
                . ')'
            );
            $this->db->exec('CREATE INDEX IF NOT EXISTS idx_paymos_snapshots_invoice_id ON ' . self::TABLE . ' (paymos_invoice_id)');
            $this->db->exec('CREATE INDEX IF NOT EXISTS idx_paymos_snapshots_platform_ref ON ' . self::TABLE . ' (platform_ref)');
            $this->db->exec('CREATE INDEX IF NOT EXISTS idx_paymos_snapshots_tx_id ON ' . self::TABLE . ' (transaction_id)');
            $this->db->exec('CREATE INDEX IF NOT EXISTS idx_paymos_snapshots_reconcile ON ' . self::TABLE . ' (status, updated_at)');
        }

        $this->schemaReady = true;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function save(array $snapshot): void
    {
        $this->ensureSchema();

        $now = isset($snapshot['updated_at']) && is_numeric($snapshot['updated_at'])
            ? (int) $snapshot['updated_at']
            : $this->now();
        $createdAt = isset($snapshot['created_at']) && is_numeric($snapshot['created_at'])
            ? (int) $snapshot['created_at']
            : $now;

        $externalOrderId = (string) ($snapshot['external_order_id'] ?? '');
        if ($externalOrderId === '') {
            throw new \InvalidArgumentException('Snapshot external_order_id is required.');
        }

        $paymosInvoiceId = (string) ($snapshot['paymos_invoice_id'] ?? '');
        $platformRef = (string) ($snapshot['platform_ref'] ?? '');
        $clientId = isset($snapshot['client_id']) && $snapshot['client_id'] !== '' ? (string) $snapshot['client_id'] : null;
        $payloadMeta = isset($snapshot['payload_meta']) && is_string($snapshot['payload_meta']) ? $snapshot['payload_meta'] : null;
        $amount = (string) ($snapshot['amount'] ?? '');
        $invoiceTotal = isset($snapshot['invoice_total']) && $snapshot['invoice_total'] !== '' ? (string) $snapshot['invoice_total'] : $amount;
        $currency = strtoupper((string) ($snapshot['currency'] ?? ''));
        $environment = (string) ($snapshot['environment'] ?? '');
        $projectId = (string) ($snapshot['project_id'] ?? '');
        $paymentUrl = (string) ($snapshot['payment_url'] ?? '');
        $transactionId = isset($snapshot['transaction_id']) && $snapshot['transaction_id'] !== '' ? (string) $snapshot['transaction_id'] : null;
        $status = isset($snapshot['status']) && is_string($snapshot['status']) && trim($snapshot['status']) !== ''
            ? trim($snapshot['status'])
            : 'created';
        $expiresAt = isset($snapshot['expires_at']) && is_numeric($snapshot['expires_at']) ? (int) $snapshot['expires_at'] : null;
        $renewalAttempt = isset($snapshot['renewal_attempt']) && is_numeric($snapshot['renewal_attempt'])
            ? max(0, (int) $snapshot['renewal_attempt'])
            : (isset($snapshot['renew_count']) && is_numeric($snapshot['renew_count']) ? max(0, (int) $snapshot['renew_count']) : 0);
        $finalStatus = isset($snapshot['final_status']) && $snapshot['final_status'] !== '' ? (string) $snapshot['final_status'] : null;
        if ($finalStatus === null && StatusMapper::isFinalStatus($status)) {
            $finalStatus = $status;
        }
        $paidLateNoted = isset($snapshot['paid_late_noted']) && $snapshot['paid_late_noted'] !== '' && $snapshot['paid_late_noted'] !== false
            ? (string) $snapshot['paid_late_noted']
            : null;

        $inserted = $this->db->insertUnique(
            'INSERT INTO ' . self::TABLE
            . ' (external_order_id, paymos_invoice_id, platform_ref, client_id, payload_meta, amount, invoice_total, currency, environment, project_id, payment_url, transaction_id, status, expires_at, renewal_attempt, final_status, paid_late_noted, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $externalOrderId,
                $paymosInvoiceId,
                $platformRef,
                $clientId,
                $payloadMeta,
                $amount,
                $invoiceTotal,
                $currency,
                $environment,
                $projectId,
                $paymentUrl,
                $transactionId,
                $status,
                $expiresAt,
                $renewalAttempt,
                $finalStatus,
                $paidLateNoted,
                $createdAt,
                $now,
            ],
            'external_order_id'
        );

        if (!$inserted) {
            $this->db->execute(
                'UPDATE ' . self::TABLE
                . ' SET paymos_invoice_id = ?, platform_ref = ?, client_id = ?, payload_meta = ?, amount = ?, invoice_total = ?, currency = ?, environment = ?, project_id = ?, payment_url = ?, transaction_id = ?, status = ?, expires_at = ?, renewal_attempt = ?, final_status = ?, paid_late_noted = ?, updated_at = ?'
                . ' WHERE external_order_id = ?',
                [
                    $paymosInvoiceId,
                    $platformRef,
                    $clientId,
                    $payloadMeta,
                    $amount,
                    $invoiceTotal,
                    $currency,
                    $environment,
                    $projectId,
                    $paymentUrl,
                    $transactionId,
                    $status,
                    $expiresAt,
                    $renewalAttempt,
                    $finalStatus,
                    $paidLateNoted,
                    $now,
                    $externalOrderId,
                ]
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByExternalOrderId(string $externalOrderId): ?array
    {
        $this->ensureSchema();
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . self::TABLE . ' WHERE external_order_id = ?',
            [$externalOrderId]
        );

        return $row === null ? null : $this->normalizeRow($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPaymosInvoiceId(string $paymosInvoiceId): ?array
    {
        $this->ensureSchema();
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . self::TABLE . ' WHERE paymos_invoice_id = ? ORDER BY updated_at DESC LIMIT 1',
            [$paymosInvoiceId]
        );

        return $row === null ? null : $this->normalizeRow($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByTransactionId(string $transactionId): ?array
    {
        $this->ensureSchema();
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . self::TABLE . ' WHERE transaction_id = ? ORDER BY updated_at DESC LIMIT 1',
            [$transactionId]
        );

        return $row === null ? null : $this->normalizeRow($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestByPlatformRef(string $platformRef): ?array
    {
        $this->ensureSchema();
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . self::TABLE . ' WHERE platform_ref = ? ORDER BY renewal_attempt DESC, updated_at DESC LIMIT 1',
            [$platformRef]
        );

        return $row === null ? null : $this->normalizeRow($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPaidByPlatformRef(string $platformRef): ?array
    {
        $this->ensureSchema();
        $row = $this->db->fetchOne(
            'SELECT * FROM ' . self::TABLE
            . " WHERE platform_ref = ? AND (status IN ('paid', 'paid_over') OR final_status IN ('paid', 'paid_over'))"
            . ' ORDER BY renewal_attempt DESC, updated_at DESC LIMIT 1',
            [$platformRef]
        );

        return $row === null ? null : $this->normalizeRow($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findUnpaidRecent(int $limit = 50, ?int $since = null): array
    {
        $this->ensureSchema();
        $limit = max(1, min(100, $limit));
        $cutoff = $since ?? ($this->now() - 86400);

        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . self::TABLE
            . ' WHERE updated_at >= ? AND status NOT IN (?, ?, ?, ?, ?, ?, ?)'
            . ' AND (final_status IS NULL OR final_status = \'\')'
            . ' ORDER BY updated_at ASC LIMIT ' . $limit,
            [
                $cutoff,
                self::STATUS_CREATING,
                'paid',
                'paid_over',
                'underpaid',
                'expired',
                'cancelled',
                self::STATUS_INVOICE_MISSING,
            ]
        );

        $normalized = [];
        foreach ($rows as $row) {
            $item = $this->normalizeRow($row);
            if ($item['status'] === self::STATUS_CREATING || strpos($item['paymos_invoice_id'], 'pending:') === 0) {
                continue;
            }
            $normalized[] = $item;
        }

        return $normalized;
    }

    public function updateStatus(string $paymosInvoiceId, string $status, ?string $transactionId = null): void
    {
        $this->ensureSchema();
        $finalStatus = StatusMapper::isFinalStatus($status) ? $status : null;
        $this->db->execute(
            'UPDATE ' . self::TABLE
            . ' SET status = ?, final_status = COALESCE(?, final_status), transaction_id = COALESCE(?, transaction_id), updated_at = ?'
            . ' WHERE paymos_invoice_id = ?',
            [$status, $finalStatus, $transactionId, $this->now(), $paymosInvoiceId]
        );
    }

    public function markFinalStatus(string $externalOrderId, string $finalStatus, ?string $transactionId = null): void
    {
        $this->ensureSchema();
        $this->db->execute(
            'UPDATE ' . self::TABLE
            . ' SET status = ?, final_status = ?, transaction_id = COALESCE(?, transaction_id), updated_at = ?'
            . ' WHERE external_order_id = ?',
            [$finalStatus, $finalStatus, $transactionId, $this->now(), $externalOrderId]
        );
    }

    /**
     * Idempotently records a paid_late note without changing final_status.
     * Returns true when newly noted, false if already noted on this snapshot.
     */
    public function recordPaidLateNote(string $externalOrderId, string $eventId, ?int $paidLateAt = null): bool
    {
        $this->ensureSchema();
        $existing = $this->findByExternalOrderId($externalOrderId);
        if ($existing === null) {
            return true;
        }

        $meta = [];
        if (isset($existing['payload_meta']) && is_string($existing['payload_meta']) && $existing['payload_meta'] !== '') {
            $decoded = json_decode($existing['payload_meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        if (!empty($existing['paid_late_noted']) || !empty($meta['paid_late_noted'])) {
            return false;
        }

        $marker = trim($eventId) !== '' ? trim($eventId) : ($paidLateAt !== null ? (string) $paidLateAt : '1');
        $meta['paid_late_noted'] = true;
        $meta['paid_late_event_id'] = $marker;
        if ($paidLateAt !== null) {
            $meta['paid_late_at'] = $paidLateAt;
        }

        $encoded = json_encode($meta, JSON_UNESCAPED_SLASHES);
        $this->db->execute(
            'UPDATE ' . self::TABLE . ' SET paid_late_noted = ?, payload_meta = ?, updated_at = ? WHERE external_order_id = ?',
            [$marker, is_string($encoded) ? $encoded : '{}', $this->now(), $externalOrderId]
        );

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $meta = [];
        if (isset($row['payload_meta']) && is_string($row['payload_meta']) && $row['payload_meta'] !== '') {
            $decoded = json_decode($row['payload_meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $paidLate = isset($row['paid_late_noted']) && $row['paid_late_noted'] !== null && $row['paid_late_noted'] !== ''
            ? (string) $row['paid_late_noted']
            : (!empty($meta['paid_late_noted']) ? (string) ($meta['paid_late_event_id'] ?? '1') : null);

        $status = isset($row['status']) && is_string($row['status']) && $row['status'] !== ''
            ? $row['status']
            : (isset($row['final_status']) && is_string($row['final_status']) && $row['final_status'] !== '' ? $row['final_status'] : 'created');

        return [
            'external_order_id' => (string) ($row['external_order_id'] ?? ''),
            'paymos_invoice_id' => (string) ($row['paymos_invoice_id'] ?? ''),
            'platform_ref' => (string) ($row['platform_ref'] ?? ''),
            'client_id' => isset($row['client_id']) && $row['client_id'] !== null && $row['client_id'] !== '' ? (string) $row['client_id'] : null,
            'payload_meta' => isset($row['payload_meta']) && is_string($row['payload_meta']) ? $row['payload_meta'] : null,
            'amount' => (string) ($row['amount'] ?? ''),
            'invoice_total' => isset($row['invoice_total']) && $row['invoice_total'] !== null && $row['invoice_total'] !== '' ? (string) $row['invoice_total'] : (string) ($row['amount'] ?? ''),
            'currency' => strtoupper((string) ($row['currency'] ?? '')),
            'environment' => (string) ($row['environment'] ?? ''),
            'project_id' => (string) ($row['project_id'] ?? ''),
            'payment_url' => (string) ($row['payment_url'] ?? ''),
            'transaction_id' => isset($row['transaction_id']) && $row['transaction_id'] !== null && $row['transaction_id'] !== '' ? (string) $row['transaction_id'] : null,
            'status' => $status,
            'expires_at' => isset($row['expires_at']) && is_numeric($row['expires_at']) ? (int) $row['expires_at'] : null,
            'renewal_attempt' => isset($row['renewal_attempt']) && is_numeric($row['renewal_attempt']) ? (int) $row['renewal_attempt'] : 0,
            'final_status' => isset($row['final_status']) && $row['final_status'] !== null && $row['final_status'] !== '' ? (string) $row['final_status'] : null,
            'paid_late_noted' => $paidLate,
            'created_at' => isset($row['created_at']) && is_numeric($row['created_at']) ? (int) $row['created_at'] : (int) ($row['updated_at'] ?? 0),
            'updated_at' => isset($row['updated_at']) && is_numeric($row['updated_at']) ? (int) $row['updated_at'] : 0,
        ];
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
