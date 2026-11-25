<?php

declare(strict_types=1);

namespace PaymosFossBilling;

use Paymos\Plugin\AesGcmEnvelope;
use Paymos\Plugin\CredentialSet;

final class CredentialStore
{
    public const TABLE = 'paymos_module_state';

    private const CREDENTIALS_KEY = 'credentials_v1';
    private const STATE_KEY = 'connect_state_v1';
    private const SETTINGS_KEY = 'module_settings_v1';
    private const INSTALLATION_KEY = 'installation_id_v1';

    private const CREDENTIALS_AAD = 'paymos-fossbilling-credentials-v1';
    private const STATE_AAD = 'paymos-fossbilling-connect-state-v1';

    private DbDriverInterface $db;
    private string $keyMaterial;

    /** @var callable(): int */
    private $clock;

    private bool $schemaReady = false;

    /**
     * @param callable(): int|null $clock
     */
    public function __construct(DbDriverInterface $db, string $keyMaterial, ?callable $clock = null)
    {
        $this->db = $db;
        $this->keyMaterial = trim($keyMaterial);
        $this->clock = $clock ?? static function (): int {
            return time();
        };
    }

    /**
     * Construct a CredentialStore from FOSSBilling's DI container, extracting the
     * installation encryption key strictly from $di['config']['salt'].
     *
     * @param mixed $di
     * @param callable(): int|null $clock
     */
    public static function fromDi($di, ?DbDriverInterface $db = null, ?callable $clock = null): self
    {
        $driver = $db ?? PdoDbDriver::fromFossBillingDi($di);
        $salt = self::extractSaltFromDi($di);
        return new self($driver, $salt, $clock);
    }

    /**
     * @param mixed $di
     */
    public static function extractSaltFromDi($di): string
    {
        $config = self::readDiKey($di, 'config');
        if (is_array($config) && isset($config['salt']) && is_scalar($config['salt'])) {
            return trim((string) $config['salt']);
        }
        if ($config instanceof \ArrayAccess) {
            try {
                if ($config->offsetExists('salt') && is_scalar($config['salt'])) {
                    return trim((string) $config['salt']);
                }
            } catch (\Throwable $e) {
                return '';
            }
        }

        return '';
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
                'Paymos module state table "' . self::TABLE . '" does not exist and cannot be created inside an active database transaction.'
            );
        }

        if ($this->db->driverName() === 'mysql') {
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . 'state_key VARCHAR(128) NOT NULL PRIMARY KEY, '
                . 'state_value LONGTEXT NOT NULL, '
                . 'updated_at BIGINT UNSIGNED NOT NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } else {
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . 'state_key VARCHAR(128) NOT NULL PRIMARY KEY, '
                . 'state_value TEXT NOT NULL, '
                . 'updated_at BIGINT NOT NULL'
                . ')'
            );
        }

        $this->schemaReady = true;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function loadCredentials(): array
    {
        $payload = $this->loadEncrypted(self::CREDENTIALS_KEY, self::CREDENTIALS_AAD);
        if (count($payload) === 0) {
            return [];
        }

        if (!isset($payload['schema'], $payload['environments'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['environments'])
        ) {
            throw new \RuntimeException('Stored Paymos credentials have an invalid schema.');
        }

        return CredentialSet::normalize($payload['environments']);
    }

    /**
     * @param array<string, mixed> $environments
     */
    public function saveCredentials(array $environments): void
    {
        $normalized = CredentialSet::normalize($environments);
        if (count($normalized) === 0) {
            throw new \InvalidArgumentException('At least one Paymos environment is required.');
        }

        $this->saveEncrypted(self::CREDENTIALS_KEY, self::CREDENTIALS_AAD, [
            'schema' => 1,
            'environments' => $normalized,
        ]);
    }

    public function isConnected(): bool
    {
        try {
            return count($this->loadCredentials()) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array{project_id: string, capabilities: array<int, string>, refund_contract_version: ?int, refunds_enabled: bool}
     */
    public function refundConfig(string $environment): array
    {
        if (!in_array($environment, ['sandbox', 'live'], true)) {
            throw new \InvalidArgumentException('refund_environment_invalid');
        }

        $credentials = $this->loadCredentials();
        $values = isset($credentials[$environment]) && is_array($credentials[$environment])
            ? $credentials[$environment]
            : [];

        return [
            'project_id' => isset($values['project_id']) && is_string($values['project_id']) ? $values['project_id'] : '',
            'capabilities' => CredentialSet::hasCapability($values, 'invoice_refunds_v1')
                ? ['invoice_refunds_v1']
                : [],
            'refund_contract_version' => isset($values['refund_contract_version']) && is_int($values['refund_contract_version'])
                ? $values['refund_contract_version']
                : null,
            'refunds_enabled' => CredentialSet::refundsEnabled($values),
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function saveState(array $state): void
    {
        $expiresIn = isset($state['expires_in']) && is_numeric($state['expires_in'])
            ? max(1, (int) $state['expires_in'])
            : 600;

        $this->saveEncrypted(self::STATE_KEY, self::STATE_AAD, [
            'schema' => 1,
            'expires_at' => $this->now() + $expiresIn,
            'state' => $state,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function loadState(): array
    {
        $payload = $this->loadEncrypted(self::STATE_KEY, self::STATE_AAD);
        if (count($payload) === 0) {
            return [];
        }

        if (!isset($payload['schema'], $payload['expires_at'], $payload['state'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['state'])
            || $this->now() >= (int) $payload['expires_at']
        ) {
            $this->clearState();
            return [];
        }

        return $payload['state'];
    }

    public function clearState(): void
    {
        $this->deleteKey(self::STATE_KEY);
    }

    /**
     * Non-secret module preferences (`mode`, `button_text`).
     *
     * @return array{mode: string, button_text: string}
     */
    public function loadSettings(): array
    {
        $raw = $this->getMetadata(self::SETTINGS_KEY);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [
                'mode' => 'sandbox',
                'button_text' => '',
            ];
        }

        $mode = isset($decoded['mode']) && is_string($decoded['mode']) ? strtolower(trim($decoded['mode'])) : 'sandbox';
        if (!in_array($mode, ['sandbox', 'live'], true)) {
            $mode = 'sandbox';
        }
        $buttonText = isset($decoded['button_text']) && is_scalar($decoded['button_text'])
            ? trim((string) $decoded['button_text'])
            : '';

        return [
            'mode' => $mode,
            'button_text' => $buttonText,
        ];
    }

    /**
     * Persist non-secret display/mode preferences only; rejects manual credential fields.
     *
     * @param array<string, mixed> $settings
     */
    public function saveSettings(array $settings): void
    {
        foreach (['api_key', 'api_secret', 'project_id', 'webhook_secret', 'base_url'] as $forbidden) {
            if (array_key_exists($forbidden, $settings)) {
                throw new \InvalidArgumentException('Manual Paymos credential fields are not supported. Use Connect Paymos.');
            }
        }

        $current = $this->loadSettings();
        $mode = array_key_exists('mode', $settings)
            ? strtolower(trim((string) $settings['mode']))
            : $current['mode'];
        if (!in_array($mode, ['sandbox', 'live'], true)) {
            throw new \InvalidArgumentException('Paymos mode must be sandbox or live.');
        }

        $buttonText = array_key_exists('button_text', $settings)
            ? trim((string) $settings['button_text'])
            : $current['button_text'];
        if (strlen($buttonText) > 120) {
            throw new \InvalidArgumentException('Button text must be at most 120 characters.');
        }

        $encoded = json_encode([
            'mode' => $mode,
            'button_text' => $buttonText,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Failed to encode Paymos module settings.');
        }

        $this->setMetadata(self::SETTINGS_KEY, $encoded);
    }

    public function installationId(): string
    {
        $existing = $this->getMetadata(self::INSTALLATION_KEY);
        if (is_string($existing) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $existing) === 1) {
            return $existing;
        }

        $generated = RefundIntentStore::uuid();
        $this->ensureSchema();
        $this->db->insertUnique(
            'INSERT INTO ' . self::TABLE . ' (state_key, state_value, updated_at) VALUES (?, ?, ?)',
            [self::INSTALLATION_KEY, $generated, $this->now()],
            'state_key'
        );

        $stored = $this->getMetadata(self::INSTALLATION_KEY);
        if (!is_string($stored) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $stored) !== 1) {
            throw new \RuntimeException('refund_installation_missing');
        }

        return $stored;
    }

    public function getMetadata(string $key): ?string
    {
        $this->ensureSchema();
        $row = $this->db->fetchOne(
            'SELECT state_value FROM ' . self::TABLE . ' WHERE state_key = ?',
            [$key]
        );
        if ($row === null || !isset($row['state_value']) || !is_string($row['state_value'])) {
            return null;
        }

        return $row['state_value'];
    }

    public function setMetadata(string $key, ?string $value): void
    {
        if ($value === null) {
            $this->deleteKey($key);
            return;
        }

        $this->ensureSchema();
        $now = $this->now();
        $inserted = $this->db->insertUnique(
            'INSERT INTO ' . self::TABLE . ' (state_key, state_value, updated_at) VALUES (?, ?, ?)',
            [$key, $value, $now],
            'state_key'
        );

        if (!$inserted) {
            $this->db->execute(
                'UPDATE ' . self::TABLE . ' SET state_value = ?, updated_at = ? WHERE state_key = ?',
                [$value, $now, $key]
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadEncrypted(string $key, string $aad): array
    {
        $encoded = $this->getMetadata($key);
        if ($encoded === null || $encoded === '') {
            return [];
        }

        return AesGcmEnvelope::open($encoded, $this->requireKeyMaterial(), $aad);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function saveEncrypted(string $key, string $aad, array $payload): void
    {
        $encoded = AesGcmEnvelope::seal($payload, $this->requireKeyMaterial(), $aad);
        $this->setMetadata($key, $encoded);
    }

    private function deleteKey(string $key): void
    {
        $this->ensureSchema();
        $this->db->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE state_key = ?',
            [$key]
        );
    }

    private function requireKeyMaterial(): string
    {
        if ($this->keyMaterial === '') {
            throw new \RuntimeException('FOSSBilling installation salt is not configured.');
        }

        return $this->keyMaterial;
    }

    private function now(): int
    {
        return (int) ($this->clock)();
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
}
