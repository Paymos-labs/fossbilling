<?php

declare(strict_types=1);

namespace PaymosFossBilling;

use Paymos\Connect\DeviceConnectClient;

final class ConnectHandler
{
    public const PLUGIN_SLUG = 'fossbilling';
    public const DEFAULT_CONNECT_URL = 'https://app.paymos.io';

    private CredentialStore $credentialStore;

    /** @var callable(): void */
    private $authorize;

    private DeviceConnectClient $connectClient;

    /**
     * @param callable(): void|null $authorize
     */
    public function __construct(
        CredentialStore $credentialStore,
        ?callable $authorize = null,
        ?DeviceConnectClient $connectClient = null
    ) {
        $this->credentialStore = $credentialStore;
        $this->authorize = $authorize ?? static function (): void {
            throw new \RuntimeException('paymos_connect_access_denied');
        };
        $this->connectClient = $connectClient ?? new DeviceConnectClient(self::DEFAULT_CONNECT_URL);
    }

    /**
     * @return array{verification_url: string, user_code: string, interval: int}
     */
    public function start(string $sourceUrl, string $returnUrl = ''): array
    {
        ($this->authorize)();

        $normalizedSource = self::assertHttpsSourceUrl($sourceUrl);
        $normalizedReturn = trim($returnUrl);
        if ($normalizedReturn !== '' && !self::isHttpsUrl($normalizedReturn)) {
            $normalizedReturn = '';
        }

        $state = $this->connectClient->start(self::PLUGIN_SLUG, $normalizedSource, $normalizedReturn);
        $this->credentialStore->saveState($state);

        return [
            'verification_url' => (string) $state['verification_url'],
            'user_code' => (string) $state['user_code'],
            'interval' => max(1, (int) ($state['interval'] ?? 5)),
        ];
    }

    /**
     * @return array{status: string}
     */
    public function poll(string $sourceUrl): array
    {
        ($this->authorize)();

        $normalizedSource = self::assertHttpsSourceUrl($sourceUrl);
        $state = $this->credentialStore->loadState();
        if (!isset($state['device_code']) || !is_string($state['device_code']) || trim($state['device_code']) === '') {
            throw new \RuntimeException('No active Paymos connection request.');
        }

        $result = $this->connectClient->poll($state['device_code']);
        $status = isset($result['status']) && is_string($result['status']) ? $result['status'] : '';

        if ($status === 'connected') {
            $plugin = isset($result['plugin']) && is_string($result['plugin']) ? trim($result['plugin']) : '';
            $boundSource = isset($result['source_url']) && is_string($result['source_url'])
                ? rtrim(trim($result['source_url']), '/')
                : '';

            if ($plugin !== self::PLUGIN_SLUG || $boundSource !== $normalizedSource) {
                $this->credentialStore->clearState();
                throw new \RuntimeException('Paymos connection response does not match this FOSSBilling installation.');
            }

            if (!isset($result['credentials']) || !is_array($result['credentials'])) {
                $this->credentialStore->clearState();
                throw new \RuntimeException('Paymos connection response is missing credentials.');
            }

            $this->credentialStore->saveCredentials($result['credentials']);
            $this->credentialStore->clearState();

            return ['status' => 'connected'];
        }

        if (in_array($status, ['authorization_pending', 'slow_down'], true)) {
            return ['status' => $status];
        }

        $this->credentialStore->clearState();
        throw new \RuntimeException('Paymos connection was denied or expired.');
    }

    /**
     * Read-only connection diagnostics safe to render in FOSSBilling admin UI.
     * Never exposes API keys, API secrets, webhook secrets, or device codes.
     *
     * @return array<string, mixed>
     */
    public function status(string $sourceUrl = ''): array
    {
        $connected = false;
        $sandboxReady = false;
        $liveReady = false;
        $refundsCapable = false;

        try {
            $credentials = $this->credentialStore->loadCredentials();
            $connected = count($credentials) > 0;
            $sandboxReady = isset($credentials['sandbox']);
            $liveReady = isset($credentials['live']);
            foreach (['sandbox', 'live'] as $env) {
                if (isset($credentials[$env])) {
                    $refundConfig = $this->credentialStore->refundConfig($env);
                    if (in_array('invoice_refunds_v1', $refundConfig['capabilities'], true)
                        && $refundConfig['refund_contract_version'] === 1
                    ) {
                        $refundsCapable = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            $connected = false;
        }

        $settings = $this->credentialStore->loadSettings();
        $trimmedSource = rtrim(trim($sourceUrl), '/');

        return [
            'connected' => $connected,
            'sandbox_configured' => $sandboxReady,
            'live_configured' => $liveReady,
            'refunds_capable' => $refundsCapable,
            'mode' => $settings['mode'],
            'button_text' => $settings['button_text'],
            'callback_path' => Config::CALLBACK_PATH,
            'callback_url' => $trimmedSource !== '' ? $trimmedSource . Config::CALLBACK_PATH : Config::CALLBACK_PATH,
            'https_valid' => $trimmedSource !== '' && self::isHttpsUrl($trimmedSource),
        ];
    }

    public static function assertHttpsSourceUrl(string $sourceUrl): string
    {
        $normalized = rtrim(trim($sourceUrl), '/');
        if (!self::isHttpsUrl($normalized)) {
            throw new \InvalidArgumentException('FOSSBilling installation URL must use HTTPS.');
        }

        $parts = parse_url($normalized);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('FOSSBilling installation URL must be a clean HTTPS origin/path.');
        }

        return $normalized;
    }

    public static function isHttpsUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && strtolower((string) $parts['scheme']) === 'https';
    }
}
