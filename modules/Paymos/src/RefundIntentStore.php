<?php

declare(strict_types=1);

namespace PaymosFossBilling;

use Paymos\Exception\EventInProgressException;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\EffectClaim;
use Paymos\Plugin\ExternalRefundObservation;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\RefundCandidateStoreInterface;
use Paymos\Plugin\RefundCoordinator;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundIntentPage;
use Paymos\Plugin\RefundIntentStoreInterface;
use Paymos\Plugin\RefundRecipientIdentity;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;

final class RefundIntentStore implements RefundIntentStoreInterface, RefundCandidateStoreInterface
{
    public const TABLE = 'paymos_refund_intents';
    public const EXTERNAL_TABLE = 'paymos_external_refunds';

    private DbDriverInterface $db;

    /** @var callable(): int */
    private $clock;

    private bool $schemaReady = false;
    private ?string $paymentKey = null;
    private int $depth = 0;

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

        $intentsExists = $this->db->tableExists(self::TABLE);
        $externalExists = $this->db->tableExists(self::EXTERNAL_TABLE);
        if ($intentsExists && $externalExists) {
            $this->schemaReady = true;
            return;
        }

        if ($this->db->inTransaction()) {
            throw new \RuntimeException('refund_schema_transaction_conflict');
        }

        if (!$intentsExists) {
            if ($this->db->driverName() === 'mysql') {
                $this->db->exec(
                    'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                    . 'operation_id VARCHAR(36) NOT NULL PRIMARY KEY, '
                    . 'installation_id VARCHAR(36) NOT NULL, '
                    . 'request_token VARCHAR(64) NOT NULL, '
                    . 'external_order_id VARCHAR(200) NOT NULL, '
                    . 'native_payment_key VARCHAR(32) NOT NULL, '
                    . 'withdrawal_id VARCHAR(128) NULL, '
                    . 'payload LONGTEXT NOT NULL, '
                    . 'version BIGINT UNSIGNED NOT NULL DEFAULT 1, '
                    . 'created_at BIGINT UNSIGNED NOT NULL, '
                    . 'next_check BIGINT UNSIGNED NOT NULL, '
                    . 'UNIQUE KEY uq_paymos_refund_req_token (installation_id, request_token), '
                    . 'UNIQUE KEY uq_paymos_refund_ext_order (external_order_id), '
                    . 'UNIQUE KEY uq_paymos_refund_withdrawal (withdrawal_id), '
                    . 'KEY idx_paymos_refund_payment_key (native_payment_key), '
                    . 'KEY idx_paymos_refund_due (next_check, operation_id)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
            } else {
                $this->db->exec(
                    'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                    . 'operation_id VARCHAR(36) NOT NULL PRIMARY KEY, '
                    . 'installation_id VARCHAR(36) NOT NULL, '
                    . 'request_token VARCHAR(64) NOT NULL, '
                    . 'external_order_id VARCHAR(200) NOT NULL UNIQUE, '
                    . 'native_payment_key VARCHAR(32) NOT NULL, '
                    . 'withdrawal_id VARCHAR(128) NULL UNIQUE, '
                    . 'payload TEXT NOT NULL, '
                    . 'version BIGINT NOT NULL DEFAULT 1, '
                    . 'created_at BIGINT NOT NULL, '
                    . 'next_check BIGINT NOT NULL, '
                    . 'UNIQUE (installation_id, request_token)'
                    . ')'
                );
                $this->db->exec('CREATE INDEX IF NOT EXISTS idx_paymos_refund_payment_key ON ' . self::TABLE . ' (native_payment_key)');
                $this->db->exec('CREATE INDEX IF NOT EXISTS idx_paymos_refund_due ON ' . self::TABLE . ' (next_check, operation_id)');
            }
        }

        if (!$externalExists) {
            if ($this->db->driverName() === 'mysql') {
                $this->db->exec(
                    'CREATE TABLE IF NOT EXISTS ' . self::EXTERNAL_TABLE . ' ('
                    . 'withdrawal_id VARCHAR(128) NOT NULL PRIMARY KEY, '
                    . 'native_payment_key VARCHAR(32) NOT NULL, '
                    . 'invoice_id VARCHAR(64) NOT NULL, '
                    . 'payload LONGTEXT NOT NULL, '
                    . 'updated_at BIGINT UNSIGNED NOT NULL, '
                    . 'KEY idx_paymos_ext_refund_payment (native_payment_key), '
                    . 'KEY idx_paymos_ext_refund_invoice (invoice_id)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
            } else {
                $this->db->exec(
                    'CREATE TABLE IF NOT EXISTS ' . self::EXTERNAL_TABLE . ' ('
                    . 'withdrawal_id VARCHAR(128) NOT NULL PRIMARY KEY, '
                    . 'native_payment_key VARCHAR(32) NOT NULL, '
                    . 'invoice_id VARCHAR(64) NOT NULL, '
                    . 'payload TEXT NOT NULL, '
                    . 'updated_at BIGINT NOT NULL'
                    . ')'
                );
                $this->db->exec('CREATE INDEX IF NOT EXISTS idx_paymos_ext_refund_payment ON ' . self::EXTERNAL_TABLE . ' (native_payment_key)');
                $this->db->exec('CREATE INDEX IF NOT EXISTS idx_paymos_ext_refund_invoice ON ' . self::EXTERNAL_TABLE . ' (invoice_id)');
            }
        }

        $this->schemaReady = true;
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public static function isUuidV4(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
    }

    public function withPaymentLock(string $paymentKey, callable $action)
    {
        if (!preg_match('/\A[1-9][0-9]*\z/', $paymentKey)) {
            throw new \RuntimeException('refund_payment_key_invalid');
        }
        $this->ensureSchema();

        if ($this->paymentKey !== null) {
            if ($this->paymentKey !== $paymentKey) {
                throw new \RuntimeException('refund_lock_scope_conflict');
            }
            $this->assertOwned();
            return $action();
        }

        $lockName = $this->lockName($paymentKey);
        if (!$this->db->acquireNamedLock($lockName, 10)) {
            throw new EventInProgressException('refund_payment_busy');
        }

        $this->paymentKey = $paymentKey;
        try {
            return $action();
        } finally {
            $this->paymentKey = null;
            $this->db->releaseNamedLock($lockName);
        }
    }

    public function assertOwned(): void
    {
        if ($this->paymentKey === null || !$this->db->isNamedLockOwned($this->lockName($this->paymentKey))) {
            throw new \RuntimeException('refund_payment_lock_lost');
        }
    }

    public function transaction(callable $action)
    {
        $this->assertOwned();
        if ($this->depth > 0) {
            return $action();
        }
        if ($this->db->inTransaction()) {
            throw new \RuntimeException('refund_foreign_transaction');
        }

        $this->db->beginTransaction();
        $this->depth = 1;
        try {
            $result = $action();
            $this->assertOwned();
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        } finally {
            $this->depth = 0;
        }
    }

    /**
     * Execute native FOSSBilling service calls outside our open SQL transaction so
     * Doctrine/RedBean manages its own commit boundary without nested transaction faults.
     *
     * @return mixed
     */
    public function outsideTransaction(callable $action)
    {
        $this->assertOwned();
        $resume = $this->depth > 0;
        if (!$resume && $this->db->inTransaction()) {
            throw new \RuntimeException('refund_foreign_transaction');
        }
        if ($resume) {
            $this->db->commit();
            $this->depth = 0;
        }

        try {
            $result = $action();
            $this->assertOwned();
            return $result;
        } finally {
            if ($resume) {
                $this->assertOwned();
                $this->db->beginTransaction();
                $this->depth = 1;
            }
        }
    }

    public function createIntent(RefundIntent $intent): RefundIntent
    {
        $this->assertIntentLock($intent);
        if ($this->depth !== 0) {
            throw new \RuntimeException('refund_intent_must_commit');
        }

        return $this->transaction(function () use ($intent): RefundIntent {
            $data = $intent->toArray();
            if (($data['platform'] ?? '') !== 'fossbilling') {
                throw new \RuntimeException('refund_platform_invalid');
            }

            $existing = $this->findByRequestToken((string) $data['installation_id'], (string) $data['request_token']);
            if ($existing !== null) {
                $old = $existing->toArray();
                foreach (['platform', 'native_payment_key', 'invoice_id', 'project_id', 'environment', 'mode', 'full_draft'] as $field) {
                    if (($old[$field] ?? null) !== ($data[$field] ?? null)) {
                        throw new \RuntimeException('refund_request_conflict');
                    }
                }
                return $existing;
            }

            foreach ($this->forPayment($intent->paymentKey()) as $old) {
                if ($this->blocksNewIntent($old)) {
                    throw new \RuntimeException('refund_payment_has_unresolved_intent');
                }
            }

            $now = $this->now();
            $data = array_replace($data, [
                'created_at' => $now,
                'checks' => 0,
                'version' => 1,
            ]);

            $inserted = $this->db->insertUnique(
                'INSERT INTO ' . self::TABLE
                . ' (operation_id, installation_id, request_token, external_order_id, native_payment_key, withdrawal_id, payload, version, created_at, next_check)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $intent->operationId(),
                    $data['installation_id'],
                    $data['request_token'],
                    $intent->externalOrderId(),
                    $intent->paymentKey(),
                    null,
                    $this->encode($data),
                    1,
                    $now,
                    $now + 60,
                ]
            );
            if (!$inserted) {
                throw new \RuntimeException('refund_request_conflict');
            }

            return new RefundIntent($data);
        });
    }

    public function blocksNewIntent(RefundIntent $intent): bool
    {
        return $intent->projectionState() === RefundStates::NATIVE_REVIEW
            || ($intent->mode() === RefundStates::FULL && ($intent->cashState() === RefundStates::COMPLETED || $intent->toArray()['projection'] !== null))
            || !in_array($intent->cashState(), [RefundStates::COMPLETED, RefundStates::FAILED, RefundStates::CANCELLED], true)
            || ($intent->cashState() === RefundStates::COMPLETED && $intent->projectionState() !== RefundStates::PROJECTION_APPLIED);
    }

    public function findByRequestToken(string $installationId, string $token): ?RefundIntent
    {
        $this->ensureSchema();
        return $this->one('installation_id = ? AND request_token = ?', [$installationId, $token]);
    }

    public function getIntent(string $operationId): RefundIntent
    {
        $this->ensureSchema();
        $intent = $this->one('operation_id = ?', [$operationId]);
        if ($intent === null) {
            throw new \RuntimeException('refund_intent_missing');
        }

        return $intent;
    }

    public function findByExternalId(string $key): ?RefundIntent
    {
        $this->ensureSchema();
        return $this->one('external_order_id = ?', [$key]);
    }

    public function findByWithdrawalId(string $id): ?RefundIntent
    {
        $this->ensureSchema();
        return $this->one('withdrawal_id = ?', [$id]);
    }

    /**
     * @return array<int, RefundIntent>
     */
    public function forPayment(string $paymentKey): array
    {
        $this->ensureSchema();
        return $this->intents('native_payment_key = ? ORDER BY created_at ASC, operation_id ASC', [$paymentKey]);
    }

    public function bindWithdrawal(string $operationId, string $id): string
    {
        return $this->transaction(function () use ($operationId, $id): string {
            $intent = $this->getIntent($operationId);
            $this->assertIntentLock($intent);

            $other = $this->findByWithdrawalId($id);
            if ($id === ''
                || ($other !== null && $other->operationId() !== $operationId)
                || ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $id)
            ) {
                $this->recordState($operationId, RefundStates::REVIEW, RefundStates::NATIVE_REVIEW, 'refund_binding_conflict');
                return 'conflict';
            }

            if ($intent->withdrawalId() === $id) {
                return 'same';
            }

            $this->save($intent, ['withdrawal_id' => $id]);
            return 'bound';
        });
    }

    public function recordObservation(string $operationId, VerifiedRefund $snapshot): void
    {
        $this->transaction(function () use ($operationId, $snapshot): void {
            $intent = $this->getIntent($operationId);
            $pin = $intent->observation();

            if ($intent->withdrawalId() !== $snapshot->withdrawalId()
                || $intent->invoiceId() !== $snapshot->invoiceId()
                || $intent->projectId() !== $snapshot->projectId()
                || $intent->environment() !== $snapshot->environment()
                || $intent->mode() !== $snapshot->mode()
                || $intent->externalOrderId() !== $snapshot->externalOrderId()
                || ($pin !== null && (
                    !AmountGuard::amountsEqual($pin->principalDecimal(), $snapshot->principalDecimal())
                    || $pin->currency() !== $snapshot->currency()
                    || $pin->network() !== $snapshot->network()
                    || !RefundRecipientIdentity::same($pin, $snapshot)
                ))
            ) {
                throw new \RuntimeException('refund_observation_conflict');
            }

            $this->save($intent, ['observation' => $snapshot->toArray()]);
        });
    }

    public function recordProjection(string $operationId, NativeProjectionResult $result): void
    {
        $this->transaction(function () use ($operationId, $result): void {
            $intent = $this->getIntent($operationId);
            $draft = $intent->fullDraft();
            $snapshot = $intent->observation();
            $old = $intent->toArray()['projection'];

            if ($result->kind() !== NativeProjectionResult::EXACT_MATCH
                || $result->mode() !== $intent->mode()
                || $result->nativeId() === null || $result->nativeId() === ''
                || $snapshot === null || $snapshot->status() !== 'completed'
                || ($old !== null && $old !== $result->toArray())
                || ($draft !== null && ($result->nativeAmountMinor() !== $draft['amount_minor'] || $result->nativeCurrency() !== $draft['currency']))
                || ($draft !== null && ($result->cryptoObservationAmount() !== null || $result->cryptoAsset() !== null))
                || ($draft === null && (
                    $result->nativeAmountMinor() !== null
                    || $result->nativeCurrency() !== null
                    || !AmountGuard::amountsEqual($result->cryptoObservationAmount(), $snapshot->principalDecimal())
                    || $result->cryptoAsset() !== $snapshot->currency() . '/' . $snapshot->network()
                ))
            ) {
                throw new \RuntimeException('refund_projection_conflict');
            }

            $this->save($intent, ['projection' => $result->toArray()]);
        });
    }

    public function recordReadCandidate(string $operationId, ?string $candidateWithdrawalId, int $retryCount): void
    {
        if ($candidateWithdrawalId !== null && trim($candidateWithdrawalId) === '') {
            throw new \InvalidArgumentException('refund_candidate_invalid');
        }
        if ($retryCount < 0) {
            throw new \InvalidArgumentException('refund_retry_count_invalid');
        }

        $this->transaction(function () use ($operationId, $candidateWithdrawalId, $retryCount): void {
            $this->save($this->getIntent($operationId), [
                'candidate_withdrawal_id' => $candidateWithdrawalId,
                'read_retry_count' => $retryCount,
            ]);
        });
    }

    public function recordState(string $operationId, string $cashState, string $projectionState, ?string $reason): void
    {
        if (!in_array($cashState, ['not_submitted', 'unknown', 'created', 'processing', 'completed', 'failed', 'cancelled', 'review'], true)
            || !in_array($projectionState, ['none', 'pending', 'applied', 'native_review'], true)
        ) {
            throw new \InvalidArgumentException('refund_state_invalid');
        }

        $this->transaction(function () use ($operationId, $cashState, $projectionState, $reason): void {
            $this->save($this->getIntent($operationId), [
                'cash_state' => $cashState,
                'projection_state' => $projectionState,
                'review_reason' => $reason,
            ]);
        });
    }

    public function claimEffect(string $operationId, string $kind, int $generation): EffectClaim
    {
        if ($kind === '' || $generation < 1) {
            throw new \InvalidArgumentException('refund_effect_invalid');
        }
        if ($this->depth !== 0) {
            throw new \RuntimeException('refund_effect_must_commit');
        }

        return $this->transaction(function () use ($operationId, $kind, $generation): EffectClaim {
            $intent = $this->getIntent($operationId);
            $effects = $intent->toArray()['effects'];
            $key = $kind . ':' . $generation;
            if (isset($effects[$key]) && $effects[$key] !== RefundStates::EFFECT_PENDING) {
                return new EffectClaim(EffectClaim::ALREADY_STARTED);
            }
            $effects[$key] = RefundStates::EFFECT_STARTED;
            $this->save($intent, ['effects' => $effects]);
            return new EffectClaim(EffectClaim::CLAIMED);
        });
    }

    public function recordEffectOutcome(string $operationId, string $kind, int $generation, string $state): void
    {
        if ($kind === '' || $generation < 1
            || !in_array($state, ['delivered', 'failed_no_effect', 'unknown', 'skipped'], true)
        ) {
            throw new \InvalidArgumentException('refund_effect_invalid');
        }

        $this->transaction(function () use ($operationId, $kind, $generation, $state): void {
            $intent = $this->getIntent($operationId);
            $effects = $intent->toArray()['effects'];
            $key = $kind . ':' . $generation;
            if (!isset($effects[$key]) || $effects[$key] !== RefundStates::EFFECT_STARTED) {
                throw new \RuntimeException('refund_effect_not_started');
            }
            $effects[$key] = $state;
            $this->save($intent, ['effects' => $effects]);
        });
    }

    public function listDue(\DateTimeImmutable $now, int $limit, ?string $cursor): RefundIntentPage
    {
        $this->ensureSchema();
        $limit = max(1, min(100, $limit));
        $items = $this->intents(
            'next_check <= ? AND operation_id > ? ORDER BY operation_id ASC LIMIT ' . $limit,
            [$now->getTimestamp(), $cursor ?? '']
        );
        $last = count($items) === $limit ? end($items) : false;

        return new RefundIntentPage($items, $last instanceof RefundIntent ? $last->operationId() : null);
    }

    public function defer(string $operationId, ?int $now = null): void
    {
        $timestamp = $now ?? $this->now();
        $this->transaction(function () use ($operationId, $timestamp): void {
            $intent = $this->getIntent($operationId);
            $data = $intent->toArray();
            $checks = (int) ($data['checks'] ?? 0) + 1;

            $recoverableReadUnavailable = $intent->cashState() === RefundStates::REVIEW
                && ($data['review_reason'] ?? null) === 'refund_read_unavailable'
                && $intent->candidateWithdrawalId() !== null
                && $intent->readRetryCount() < RefundCoordinator::MAX_READ_RETRIES;

            $historical = !$recoverableReadUnavailable
                && in_array($intent->cashState(), [RefundStates::COMPLETED, RefundStates::FAILED, RefundStates::CANCELLED, RefundStates::REVIEW], true);

            $changes = ['checks' => $checks];
            if (!$historical && ($timestamp - (int) ($data['created_at'] ?? $timestamp)) > 86400) {
                $changes['projection_state'] = RefundStates::NATIVE_REVIEW;
                $changes['review_reason'] = 'refund_unresolved_over_24h';
                $historical = true;
            }

            $delays = [60, 120, 300, 900, 3600];
            $nextCheck = $timestamp + ($historical ? 86400 : $delays[min($checks - 1, count($delays) - 1)]);
            if ($intent->cashState() === RefundStates::COMPLETED && $intent->projectionState() === RefundStates::PROJECTION_PENDING) {
                $nextCheck = $timestamp + 60;
            }

            $this->save($intent, $changes, $nextCheck);
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function externalObservationsForPayment(string $paymentKey): array
    {
        $this->ensureSchema();
        $rows = $this->db->fetchAll(
            'SELECT withdrawal_id, payload FROM ' . self::EXTERNAL_TABLE . ' WHERE native_payment_key = ? ORDER BY updated_at ASC',
            [$paymentKey]
        );

        $result = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row['payload'] ?? ''), true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('refund_external_history_corrupt');
            }
            $cash = ExternalRefundObservation::stored($decoded);
            $id = (string) ($row['withdrawal_id'] ?? '');
            if ($cash->withdrawalId() !== $id || ($decoded['payment_key'] ?? '') !== $paymentKey) {
                throw new \RuntimeException('refund_external_history_corrupt');
            }
            $result[$id] = $decoded;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findExternalByWithdrawalId(string $withdrawalId): ?array
    {
        $this->ensureSchema();
        $row = $this->db->fetchOne(
            'SELECT payload FROM ' . self::EXTERNAL_TABLE . ' WHERE withdrawal_id = ?',
            [$withdrawalId]
        );
        if ($row === null) {
            return null;
        }
        $decoded = json_decode((string) ($row['payload'] ?? ''), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('refund_external_history_corrupt');
        }
        ExternalRefundObservation::stored($decoded);
        return $decoded;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function saveExternalObservation(string $withdrawalId, string $paymentKey, string $invoiceId, array $row): void
    {
        $this->ensureSchema();
        $this->assertOwned();
        if ($this->paymentKey !== $paymentKey) {
            throw new \RuntimeException('refund_lock_scope_conflict');
        }

        $encoded = $this->encode($row);
        $now = $this->now();
        $inserted = $this->db->insertUnique(
            'INSERT INTO ' . self::EXTERNAL_TABLE . ' (withdrawal_id, native_payment_key, invoice_id, payload, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$withdrawalId, $paymentKey, $invoiceId, $encoded, $now],
            'withdrawal_id'
        );

        if (!$inserted) {
            $this->db->execute(
                'UPDATE ' . self::EXTERNAL_TABLE . ' SET payload = ?, updated_at = ? WHERE withdrawal_id = ? AND native_payment_key = ?',
                [$encoded, $now, $withdrawalId, $paymentKey]
            );
        }
    }

    public function assertNoExternalReview(string $paymentKey): void
    {
        foreach ($this->externalObservationsForPayment($paymentKey) as $row) {
            if (($row['review_reason'] ?? null) !== null) {
                throw new \RuntimeException('refund_unbound_requires_review');
            }
        }
    }

    public function hasAnyRefundHistory(): bool
    {
        $this->ensureSchema();
        $intentRow = $this->db->fetchOne('SELECT operation_id FROM ' . self::TABLE . ' LIMIT 1');
        if ($intentRow !== null) {
            return true;
        }
        $extRow = $this->db->fetchOne('SELECT withdrawal_id FROM ' . self::EXTERNAL_TABLE . ' LIMIT 1');
        return $extRow !== null;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function one(string $where, array $values): ?RefundIntent
    {
        $rows = $this->intents($where, $values);
        if (count($rows) > 1) {
            throw new \RuntimeException('refund_identity_ambiguous');
        }
        return count($rows) === 1 ? $rows[0] : null;
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, RefundIntent>
     */
    private function intents(string $where, array $values): array
    {
        $rows = $this->db->fetchAll('SELECT payload FROM ' . self::TABLE . ' WHERE ' . $where, $values);
        $result = [];
        foreach ($rows as $row) {
            $data = json_decode((string) ($row['payload'] ?? ''), true);
            if (!is_array($data)) {
                throw new \RuntimeException('refund_store_corrupt');
            }
            $result[] = new RefundIntent($data);
        }

        return $result;
    }

    private function assertIntentLock(RefundIntent $intent): void
    {
        $this->assertOwned();
        if ($this->paymentKey !== $intent->paymentKey()) {
            throw new \RuntimeException('refund_lock_scope_conflict');
        }
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function save(RefundIntent $intent, array $changes, ?int $nextCheck = null): void
    {
        $this->assertIntentLock($intent);
        $old = $intent->toArray();
        $oldVersion = (int) ($old['version'] ?? 1);
        $data = array_replace($old, $changes, ['version' => $oldVersion + 1]);

        $sql = 'UPDATE ' . self::TABLE . ' SET payload = ?, withdrawal_id = ?, version = ?';
        $values = [$this->encode($data), $data['withdrawal_id'], $data['version']];
        if ($nextCheck !== null) {
            $sql .= ', next_check = ?';
            $values[] = $nextCheck;
        }
        $sql .= ' WHERE operation_id = ? AND version = ?';
        $values[] = $intent->operationId();
        $values[] = $oldVersion;

        if ($this->db->execute($sql, $values) !== 1) {
            throw new \RuntimeException('refund_store_write_conflict');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('refund_store_encoding_failed');
        }
        return $json;
    }

    private function lockName(string $paymentKey): string
    {
        return 'paymos-fossbilling-invoice:' . $paymentKey;
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
