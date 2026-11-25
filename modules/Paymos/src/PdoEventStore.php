<?php

declare(strict_types=1);

namespace PaymosFossBilling;

use Paymos\Webhook\CommitAwareEventStoreInterface;

final class PdoEventStore implements CommitAwareEventStoreInterface
{
    public const RESERVATION_SECONDS = 300;
    public const CLEANUP_BATCH_SIZE = 100;
    public const TABLE = 'paymos_webhook_events';

    private DbDriverInterface $db;

    /** @var callable(): int */
    private $clock;

    private bool $schemaReady = false;

    /** @var array<string, array{token: string, ttl: int}> */
    private array $ownerTokens = [];

    private ?string $lastRememberedId = null;

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
                'Paymos webhook deduplication table "' . self::TABLE . '" does not exist and cannot be created inside an active database transaction.'
            );
        }

        if ($this->db->driverName() === 'mysql') {
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . 'event_id VARCHAR(128) NOT NULL PRIMARY KEY, '
                . 'owner_token VARCHAR(64) NOT NULL, '
                . 'state VARCHAR(16) NOT NULL, '
                . 'expires_at BIGINT UNSIGNED NOT NULL, '
                . 'created_at BIGINT UNSIGNED NOT NULL, '
                . 'KEY idx_paymos_webhook_events_expires (expires_at)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } else {
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . 'event_id VARCHAR(128) NOT NULL PRIMARY KEY, '
                . 'owner_token VARCHAR(64) NOT NULL, '
                . 'state VARCHAR(16) NOT NULL, '
                . 'expires_at BIGINT NOT NULL, '
                . 'created_at BIGINT NOT NULL'
                . ')'
            );
            $this->db->exec(
                'CREATE INDEX IF NOT EXISTS idx_paymos_webhook_events_expires ON ' . self::TABLE . ' (expires_at)'
            );
        }

        $this->schemaReady = true;
    }

    /**
     * @param string $eventId
     * @param int    $ttlSeconds
     * @return bool
     */
    public function remember($eventId, $ttlSeconds)
    {
        $this->ensureSchema();

        $eventId = trim((string) $eventId);
        $ttlSeconds = (int) $ttlSeconds;
        if ($eventId === '') {
            throw new \InvalidArgumentException('Webhook event_id must be a non-empty string.');
        }
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Webhook deduplication TTL must be a positive integer.');
        }

        $now = $this->now();
        $this->cleanupExpired($now);

        $ownerToken = bin2hex(random_bytes(16));
        $reservationExpiresAt = $now + self::RESERVATION_SECONDS;

        $inserted = $this->db->insertUnique(
            'INSERT INTO ' . self::TABLE . ' (event_id, owner_token, state, expires_at, created_at) VALUES (?, ?, ?, ?, ?)',
            [$eventId, $ownerToken, 'processing', $reservationExpiresAt, $now],
            'event_id'
        );

        if ($inserted) {
            $this->ownerTokens[$eventId] = [
                'token' => $ownerToken,
                'ttl' => $ttlSeconds,
            ];
            $this->lastRememberedId = $eventId;
            return true;
        }

        $affected = $this->db->execute(
            'UPDATE ' . self::TABLE
            . ' SET owner_token = ?, state = ?, expires_at = ?, created_at = ?'
            . ' WHERE event_id = ? AND state = ? AND expires_at <= ?',
            [$ownerToken, 'processing', $reservationExpiresAt, $now, $eventId, 'processing', $now]
        );
        if ($affected === 1) {
            $this->ownerTokens[$eventId] = [
                'token' => $ownerToken,
                'ttl' => $ttlSeconds,
            ];
            $this->lastRememberedId = $eventId;
            return true;
        }

        $expiredCommitted = $this->db->execute(
            'UPDATE ' . self::TABLE
            . ' SET owner_token = ?, state = ?, expires_at = ?, created_at = ?'
            . ' WHERE event_id = ? AND state = ? AND expires_at <= ?',
            [$ownerToken, 'processing', $reservationExpiresAt, $now, $eventId, 'committed', $now]
        );
        if ($expiredCommitted === 1) {
            $this->ownerTokens[$eventId] = [
                'token' => $ownerToken,
                'ttl' => $ttlSeconds,
            ];
            $this->lastRememberedId = $eventId;
            return true;
        }

        return false;
    }

    /**
     * @param string $eventId
     * @return bool
     */
    public function isCommitted($eventId)
    {
        $this->ensureSchema();

        $row = $this->db->fetchOne(
            'SELECT state, expires_at FROM ' . self::TABLE . ' WHERE event_id = ?',
            [trim((string) $eventId)]
        );
        if ($row === null) {
            return false;
        }

        $state = isset($row['state']) ? (string) $row['state'] : '';
        $expiresAt = isset($row['expires_at']) ? (int) $row['expires_at'] : 0;

        return $state === 'committed' && $expiresAt > $this->now();
    }

    /**
     * Owner-fenced commit: extends the row to the full deduplication TTL only
     * when this store instance still owns the reservation token.
     */
    public function commit(?string $eventId = null): bool
    {
        $this->ensureSchema();

        $resolved = trim($eventId ?? ($this->lastRememberedId ?? ''));
        if ($resolved === '' || !isset($this->ownerTokens[$resolved])) {
            return false;
        }

        $entry = $this->ownerTokens[$resolved];
        unset($this->ownerTokens[$resolved]);
        if ($this->lastRememberedId === $resolved) {
            $this->lastRememberedId = null;
        }

        $affected = $this->db->execute(
            'UPDATE ' . self::TABLE . ' SET state = ?, expires_at = ? WHERE event_id = ? AND owner_token = ?',
            ['committed', $this->now() + $entry['ttl'], $resolved, $entry['token']]
        );

        return $affected === 1;
    }

    /**
     * Owner-fenced release: drops only an active processing reservation owned
     * by this store instance. Never deletes committed rows or another worker's lock.
     */
    public function release(?string $eventId = null): void
    {
        $this->ensureSchema();

        $resolved = trim($eventId ?? ($this->lastRememberedId ?? ''));
        if ($resolved === '' || !isset($this->ownerTokens[$resolved])) {
            return;
        }

        $entry = $this->ownerTokens[$resolved];
        unset($this->ownerTokens[$resolved]);
        if ($this->lastRememberedId === $resolved) {
            $this->lastRememberedId = null;
        }

        $this->db->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE event_id = ? AND state = ? AND owner_token = ?',
            [$resolved, 'processing', $entry['token']]
        );
    }

    private function cleanupExpired(int $now): void
    {
        $expiredRows = $this->db->fetchAll(
            'SELECT event_id FROM ' . self::TABLE . ' WHERE expires_at < ? LIMIT ' . self::CLEANUP_BATCH_SIZE,
            [$now]
        );
        if (count($expiredRows) === 0) {
            return;
        }

        $ids = [];
        foreach ($expiredRows as $row) {
            if (isset($row['event_id']) && is_string($row['event_id']) && $row['event_id'] !== '') {
                $ids[] = $row['event_id'];
            }
        }
        if (count($ids) === 0) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE expires_at < ? AND event_id IN (' . $placeholders . ')',
            array_merge([$now], $ids)
        );
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
