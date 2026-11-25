<?php

declare(strict_types=1);

namespace PaymosFossBilling;

final class PdoDbDriver implements DbDriverInterface
{
    private \PDO $pdo;

    /** @var array<string, bool> */
    private array $localLocks = [];

    public function __construct(\PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo = $pdo;
    }

    /**
     * Resolve a native \PDO connection from FOSSBilling's DI container
     * across both 0.7 ($di['pdo'] / $di['db']) and 0.8+/2.0 (Doctrine $di['em']).
     *
     * @param mixed $di
     */
    public static function fromFossBillingDi($di): self
    {
        if ($di instanceof \PDO) {
            return new self($di);
        }

        $pdo = self::extractPdoFromContainer($di);
        if ($pdo instanceof \PDO) {
            return new self($pdo);
        }

        throw new \RuntimeException('Unable to resolve PDO connection from FOSSBilling DI container.');
    }

    public function driverName(): string
    {
        try {
            $name = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            return is_string($name) ? strtolower($name) : 'pdo';
        } catch (\Throwable $e) {
            return 'pdo';
        }
    }

    public function inTransaction(): bool
    {
        try {
            return $this->pdo->inTransaction();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->beginTransaction()) {
            throw new \RuntimeException('Unable to begin database transaction.');
        }
    }

    public function commit(): void
    {
        if (!$this->pdo->commit()) {
            throw new \RuntimeException('Unable to commit database transaction.');
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function tableExists(string $tableName): bool
    {
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $tableName) !== 1) {
            throw new \InvalidArgumentException('Invalid table name.');
        }

        $driver = $this->driverName();
        if ($driver === 'mysql') {
            $row = $this->fetchOne(
                'SELECT 1 AS found FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
                [$tableName]
            );
            return $row !== null;
        }

        if ($driver === 'pgsql') {
            $row = $this->fetchOne(
                'SELECT 1 AS found FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ? LIMIT 1',
                [$tableName]
            );
            return $row !== null;
        }

        if ($driver === 'sqlite') {
            $row = $this->fetchOne(
                "SELECT 1 AS found FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
                [$tableName]
            );
            return $row !== null;
        }

        $row = $this->fetchOne(
            'SELECT 1 AS found FROM information_schema.tables WHERE table_name = ? LIMIT 1',
            [$tableName]
        );
        return $row !== null;
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($params));
        return $stmt->rowCount();
    }

    /**
     * Rewrite INSERT INTO ... to a dialect-native conflict-safe INSERT so unique
     * collisions never abort PostgreSQL transactions (25P02).
     */
    public static function buildConflictSafeInsertSql(string $sql, string $driver, string $conflictTarget = ''): string
    {
        $trimmed = trim($sql);
        $normalizedDriver = strtolower(trim($driver));

        if ($normalizedDriver === 'mysql') {
            if (preg_match('/\AINSERT\s+IGNORE\s+INTO\s+/i', $trimmed) === 1) {
                return $trimmed;
            }
            $rewritten = preg_replace('/\AINSERT\s+INTO\s+/i', 'INSERT IGNORE INTO ', $trimmed, 1);
            return is_string($rewritten) ? $rewritten : $trimmed;
        }

        if ($normalizedDriver === 'pgsql' || $normalizedDriver === 'sqlite') {
            if (preg_match('/\bON\s+CONFLICT\b/i', $trimmed) === 1) {
                return $trimmed;
            }
            $target = trim($conflictTarget);
            if ($target !== '') {
                $columns = array_map('trim', explode(',', $target));
                foreach ($columns as $col) {
                    if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $col) !== 1) {
                        throw new \InvalidArgumentException('Invalid ON CONFLICT target column.');
                    }
                }
                return $trimmed . ' ON CONFLICT (' . implode(', ', $columns) . ') DO NOTHING';
            }

            return $trimmed . ' ON CONFLICT DO NOTHING';
        }

        return $trimmed;
    }

    public function insertUnique(string $sql, array $params = [], string $conflictTarget = ''): bool
    {
        $driver = $this->driverName();

        if ($driver === 'mysql' || $driver === 'pgsql' || $driver === 'sqlite') {
            $safeSql = self::buildConflictSafeInsertSql($sql, $driver, $conflictTarget);
            $stmt = $this->pdo->prepare($safeSql);
            $stmt->execute(array_values($params));
            return $stmt->rowCount() === 1;
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_values($params));
            return true;
        } catch (\PDOException $e) {
            if (self::isUniqueConstraintViolation($e)) {
                return false;
            }
            throw $e;
        }
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($params));
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($params));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public function acquireNamedLock(string $name, int $timeoutSeconds = 10): bool
    {
        $driver = $this->driverName();
        if ($driver === 'mysql') {
            $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
            $stmt->execute([$name, max(0, $timeoutSeconds)]);
            return (int) $stmt->fetchColumn() === 1;
        }

        if ($driver === 'pgsql') {
            [$k1, $k2] = self::pgAdvisoryKeys($name);
            $stmt = $this->pdo->prepare('SELECT pg_try_advisory_lock(?, ?)');
            $stmt->execute([$k1, $k2]);
            return (bool) $stmt->fetchColumn();
        }

        if (isset($this->localLocks[$name])) {
            return false;
        }
        $this->localLocks[$name] = true;
        return true;
    }

    public function isNamedLockOwned(string $name): bool
    {
        $driver = $this->driverName();
        if ($driver === 'mysql') {
            $stmt = $this->pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
            $stmt->execute([$name]);
            return (int) $stmt->fetchColumn() === 1;
        }

        if ($driver === 'pgsql') {
            [$k1, $k2] = self::pgAdvisoryKeys($name);
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND classid = ? AND objid = ? AND objsubid = 2 LIMIT 1"
            );
            $stmt->execute([$k1, $k2]);
            return $stmt->fetchColumn() !== false;
        }

        return isset($this->localLocks[$name]);
    }

    public function releaseNamedLock(string $name): void
    {
        $driver = $this->driverName();
        if ($driver === 'mysql') {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$name]);
            return;
        }

        if ($driver === 'pgsql') {
            [$k1, $k2] = self::pgAdvisoryKeys($name);
            $stmt = $this->pdo->prepare('SELECT pg_advisory_unlock(?, ?)');
            $stmt->execute([$k1, $k2]);
            return;
        }

        unset($this->localLocks[$name]);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function pgAdvisoryKeys(string $name): array
    {
        $hash = hash('sha256', $name, true);
        $parts = unpack('N2', substr($hash, 0, 8));
        $k1 = self::toSignedInt32((int) ($parts[1] ?? 0));
        $k2 = self::toSignedInt32((int) ($parts[2] ?? 0));
        return [$k1, $k2];
    }

    private static function toSignedInt32(int $unsigned): int
    {
        return $unsigned >= 0x80000000 ? $unsigned - 0x100000000 : $unsigned;
    }

    /**
     * @param mixed $di
     */
    private static function extractPdoFromContainer($di): ?\PDO
    {
        $pdo = self::readContainerKey($di, 'pdo');
        if ($pdo instanceof \PDO) {
            return $pdo;
        }

        $em = self::readContainerKey($di, 'em');
        if (is_object($em) && method_exists($em, 'getConnection')) {
            $connection = $em->getConnection();
            if ($connection instanceof \PDO) {
                return $connection;
            }
            if (is_object($connection) && method_exists($connection, 'getNativeConnection')) {
                $native = $connection->getNativeConnection();
                if ($native instanceof \PDO) {
                    return $native;
                }
            }
            if (is_object($connection) && method_exists($connection, 'getWrappedConnection')) {
                $wrapped = $connection->getWrappedConnection();
                if ($wrapped instanceof \PDO) {
                    return $wrapped;
                }
            }
        }

        $db = self::readContainerKey($di, 'db');
        if ($db instanceof \PDO) {
            return $db;
        }
        if (is_object($db) && method_exists($db, 'getPdo')) {
            $candidate = $db->getPdo();
            if ($candidate instanceof \PDO) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param mixed $di
     * @return mixed
     */
    private static function readContainerKey($di, string $key)
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

    private static function isUniqueConstraintViolation(\PDOException $e): bool
    {
        $sqlState = (string) $e->getCode();
        $errorInfo = is_array($e->errorInfo) ? $e->errorInfo : [];
        $infoState = isset($errorInfo[0]) ? (string) $errorInfo[0] : '';
        $driverCode = isset($errorInfo[1]) && is_numeric($errorInfo[1]) ? (int) $errorInfo[1] : 0;

        if ($sqlState === '23505' || $infoState === '23505') {
            return true;
        }

        if ($driverCode === 1062) {
            return true;
        }

        if (in_array($driverCode, [19, 1555, 2067], true)) {
            $msg = strtolower($e->getMessage());
            if (strpos($msg, 'unique') !== false || strpos($msg, 'primary key') !== false) {
                return true;
            }
        }

        if ($sqlState === '23000' || $infoState === '23000') {
            $msg = strtolower($e->getMessage());
            return strpos($msg, 'duplicate entry') !== false
                || strpos($msg, 'unique constraint') !== false
                || strpos($msg, 'duplicate key') !== false;
        }

        return false;
    }
}
