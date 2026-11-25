<?php

declare(strict_types=1);

namespace PaymosFossBilling;

interface DbDriverInterface
{
    public function driverName(): string;

    /**
     * Whether the underlying database connection currently has an active transaction.
     */
    public function inTransaction(): bool;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    /**
     * Read-only metadata check for table existence (information_schema.tables / sqlite_master).
     * MUST NOT issue any DDL statement.
     */
    public function tableExists(string $tableName): bool;

    /**
     * Execute a DDL statement.
     */
    public function exec(string $sql): void;

    /**
     * Execute a parameterized DML statement (UPDATE / DELETE) and return affected row count.
     *
     * @param array<int, mixed> $params
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Attempt an atomic, transaction-safe INSERT into a unique-keyed table.
     * Uses dialect-native conflict suppression (INSERT IGNORE on MySQL,
     * ON CONFLICT (...) DO NOTHING on PostgreSQL/SQLite) so active PostgreSQL
     * transactions never enter aborted state (25P02).
     * Returns true if inserted, false ONLY on unique constraint conflict.
     * Any other database failure MUST throw.
     *
     * @param array<int, mixed> $params
     */
    public function insertUnique(string $sql, array $params = [], string $conflictTarget = ''): bool;

    /**
     * Fetch a single associative row, or null when no row matches.
     *
     * @param array<int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array;

    /**
     * Fetch all matching associative rows.
     *
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array;

    /**
     * Acquire a connection-scoped named mutex lock (GET_LOCK / pg_try_advisory_lock).
     */
    public function acquireNamedLock(string $name, int $timeoutSeconds = 10): bool;

    /**
     * Verify this connection still holds the named mutex lock.
     */
    public function isNamedLockOwned(string $name): bool;

    /**
     * Release the named mutex lock held by this connection.
     */
    public function releaseNamedLock(string $name): void;
}
