<?php

declare(strict_types=1);

namespace PaymosFossBilling;

use Paymos\ClientConfig;
use Paymos\Plugin\CredentialSet;

final class Config
{
    public const DEFAULT_BASE_URL = CredentialSet::DEFAULT_BASE_URL;
    public const CALLBACK_PATH = '/index.php?_url=/api/guest/paymos/webhook';

    /** @var array<string, array<string, mixed>> */
    private array $credentials;

    private string $mode;
    private string $buttonText;

    /**
     * @param array<string, array<string, mixed>> $credentials
     */
    private function __construct(array $credentials, string $mode, string $buttonText)
    {
        $this->credentials = $credentials;
        $this->mode = $mode;
        $this->buttonText = $buttonText;
    }

    /**
     * @param array<string, mixed> $params Non-secret display/mode preferences (`mode`, `test_mode`, `button_text`).
     */
    public static function fromCredentialStore(CredentialStore $store, array $params = []): self
    {
        $storedSettings = $store->loadSettings();
        $mergedParams = array_merge($storedSettings, self::filterNonSecretParams($params));
        $credentials = $store->loadCredentials();

        return self::fromCredentials($credentials, $mergedParams);
    }

    /**
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $params
     */
    public static function fromCredentials(array $credentials, array $params = []): self
    {
        $normalized = CredentialSet::normalize($credentials);
        $mode = self::resolveMode($params);
        $buttonText = isset($params['button_text']) && is_scalar($params['button_text'])
            ? trim((string) $params['button_text'])
            : '';

        $config = new self($normalized, $mode, $buttonText);
        $config->assertEnvironmentConfigured($mode);

        $secrets = $config->webhookSecrets();
        if (count($secrets) === 0) {
            throw new \InvalidArgumentException('At least one Paymos webhook secret is required.');
        }
        if (!isset($secrets[$mode])) {
            throw new \InvalidArgumentException('Paymos ' . $mode . ' webhook secret is required for the selected mode.');
        }

        return $config;
    }

    /**
     * Build a webhook-capable Config when at least one connected environment exists,
     * even if only one environment was provisioned.
     *
     * @param array<string, mixed> $params
     */
    public static function forWebhooks(CredentialStore $store, array $params = []): self
    {
        $storedSettings = $store->loadSettings();
        $mergedParams = array_merge($storedSettings, self::filterNonSecretParams($params));
        $normalized = CredentialSet::normalize($store->loadCredentials());
        if (count($normalized) === 0) {
            throw new \InvalidArgumentException('Paymos is not connected.');
        }

        $mode = self::resolveMode($mergedParams);
        if (!isset($normalized[$mode])) {
            $keys = array_keys($normalized);
            $mode = (string) $keys[0];
        }

        $buttonText = isset($mergedParams['button_text']) && is_scalar($mergedParams['button_text'])
            ? trim((string) $mergedParams['button_text'])
            : '';

        return new self($normalized, $mode, $buttonText);
    }

    public function environment(): string
    {
        return $this->mode;
    }

    public function isSandbox(): bool
    {
        return $this->mode === 'sandbox';
    }

    public function isConnected(): bool
    {
        return count($this->credentials) > 0;
    }

    public function hasEnvironment(string $environment): bool
    {
        $env = self::normalizeEnvironment($environment);
        return isset($this->credentials[$env]);
    }

    public function apiKey(?string $environment = null): string
    {
        return $this->environmentField($environment ?? $this->mode, 'api_key');
    }

    public function apiSecret(?string $environment = null): string
    {
        return $this->environmentField($environment ?? $this->mode, 'api_secret');
    }

    public function projectId(?string $environment = null): string
    {
        return $this->environmentField($environment ?? $this->mode, 'project_id');
    }

    public function apiBaseUrl(?string $environment = null): string
    {
        $value = $this->environmentField($environment ?? $this->mode, 'base_url');
        return $value !== '' ? rtrim($value, '/') : self::DEFAULT_BASE_URL;
    }

    /**
     * @return array<string, string>
     */
    public function webhookSecrets(): array
    {
        $secrets = [];
        foreach (['sandbox', 'live'] as $environment) {
            if (isset($this->credentials[$environment]['webhook_secret'])
                && is_string($this->credentials[$environment]['webhook_secret'])
                && $this->credentials[$environment]['webhook_secret'] !== ''
            ) {
                $secrets[$environment] = $this->credentials[$environment]['webhook_secret'];
            }
        }

        return $secrets;
    }

    public function clientConfig(?string $environment = null): ClientConfig
    {
        $env = self::normalizeEnvironment($environment ?? $this->mode);
        $this->assertEnvironmentConfigured($env);

        return new ClientConfig(
            $this->apiKey($env),
            $this->apiSecret($env),
            $this->apiBaseUrl($env),
            30
        );
    }

    public function buttonText(): string
    {
        return $this->buttonText;
    }

    public function redact(string $message): string
    {
        $secrets = [];
        foreach ($this->credentials as $values) {
            foreach (['api_key', 'api_secret', 'webhook_secret'] as $field) {
                if (isset($values[$field]) && is_string($values[$field]) && $values[$field] !== '') {
                    $secrets[] = $values[$field];
                }
            }
        }

        return self::redactStatic($message, $secrets);
    }

    /**
     * @param array<int, mixed> $secrets
     */
    public static function redactStatic(string $message, array $secrets = []): string
    {
        $redacted = $message;
        foreach ($secrets as $secret) {
            if (is_scalar($secret)) {
                $value = trim((string) $secret);
                if ($value !== '') {
                    $redacted = str_replace($value, '[REDACTED]', $redacted);
                }
            }
        }

        $patterns = [
            '/\b(?:pk|sk|rk)_(?:live|test)_[A-Za-z0-9_-]{4,}\b/',
            '/\bwhsec_[A-Za-z0-9_-]{4,}\b/',
            '/\bdc_[A-Za-z0-9_-]{6,}\b/',
        ];

        $cleaned = preg_replace($patterns, '[REDACTED]', $redacted);
        return is_string($cleaned) ? $cleaned : $redacted;
    }

    private function assertEnvironmentConfigured(string $environment): void
    {
        $env = self::normalizeEnvironment($environment);
        if (!isset($this->credentials[$env])) {
            throw new \InvalidArgumentException('Paymos is not connected for the ' . $env . ' environment.');
        }

        // Re-verify through CredentialSet invariants.
        CredentialSet::normalizeEnvironment($env, $this->credentials[$env]);
    }

    private function environmentField(string $environment, string $field): string
    {
        $env = self::normalizeEnvironment($environment);
        if (!isset($this->credentials[$env][$field]) || !is_scalar($this->credentials[$env][$field])) {
            return '';
        }

        return trim((string) $this->credentials[$env][$field]);
    }

    private static function normalizeEnvironment(string $environment): string
    {
        $normalized = strtolower(trim($environment));
        if (!in_array($normalized, ['sandbox', 'live'], true)) {
            throw new \InvalidArgumentException('Paymos environment must be sandbox or live.');
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function resolveMode(array $params): string
    {
        if (isset($params['mode']) && is_scalar($params['mode'])) {
            $mode = strtolower(trim((string) $params['mode']));
            if (in_array($mode, ['sandbox', 'live'], true)) {
                return $mode;
            }
        }

        if (array_key_exists('test_mode', $params)) {
            $raw = $params['test_mode'];
            $isTest = is_bool($raw)
                ? $raw
                : in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
            return $isTest ? 'sandbox' : 'live';
        }

        return 'sandbox';
    }

    /**
     * Strip any legacy/unmanaged secret keys from adapter params so credentials can
     * NEVER be injected via manual gateway configuration.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function filterNonSecretParams(array $params): array
    {
        $allowed = [];
        foreach (['mode', 'test_mode', 'button_text'] as $key) {
            if (array_key_exists($key, $params)) {
                $allowed[$key] = $params[$key];
            }
        }

        return $allowed;
    }
}
