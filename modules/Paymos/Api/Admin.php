<?php

declare(strict_types=1);

namespace Box\Mod\Paymos\Api;

require_once dirname(__DIR__) . '/src/Autoloader.php';
\PaymosFossBilling\Autoloader::register();

use Box\Mod\Paymos\Service;
use Paymos\Connect\DeviceConnectClient;
use PaymosFossBilling\Config;
use PaymosFossBilling\ConnectHandler;
use PaymosFossBilling\CredentialStore;
use PaymosFossBilling\DbDriverInterface;
use PaymosFossBilling\RefundAdapter;
use PaymosFossBilling\RefundIntentStore;
use PaymosFossBilling\SnapshotStore;
use PaymosFossBilling\Translation;

if (!class_exists(__NAMESPACE__ . '\\AdminApiBase', false)) {
    if (class_exists('\\FOSSBilling\\Api\\AbstractApi')) {
        class_alias('\\FOSSBilling\\Api\\AbstractApi', __NAMESPACE__ . '\\AdminApiBase');
    } elseif (class_exists('\\Api_Abstract')) {
        class_alias('\\Api_Abstract', __NAMESPACE__ . '\\AdminApiBase');
    } else {
        abstract class AdminApiBase
        {
            /** @var mixed */
            protected $di = null;

            /** @var mixed */
            protected $service = null;

            /** @param mixed $di */
            public function setDi($di): void
            {
                $this->di = $di;
            }

            /** @return mixed */
            public function getDi()
            {
                return $this->di;
            }

            /** @param mixed $service */
            public function setService($service): void
            {
                $this->service = $service;
            }

            /** @return mixed */
            public function getService()
            {
                return $this->service;
            }
        }
    }
}

class Admin extends AdminApiBase
{
    private ?DbDriverInterface $dbDriver = null;
    private ?CredentialStore $credentialStore = null;
    private ?DeviceConnectClient $connectClient = null;
    private ?RefundAdapter $refundAdapter = null;

    /** @var callable(array<string, mixed>, bool, string): void|null */
    private $authorizeCallback = null;

    /** @var callable(string): \Paymos\Client|null */
    private $clientFactory = null;

    /** @var callable(): int|null */
    private $clock = null;

    public function setDbDriver(?DbDriverInterface $dbDriver): void
    {
        $this->dbDriver = $dbDriver;
    }

    public function setCredentialStore(?CredentialStore $credentialStore): void
    {
        $this->credentialStore = $credentialStore;
    }

    public function setConnectClient(?DeviceConnectClient $connectClient): void
    {
        $this->connectClient = $connectClient;
    }

    public function setRefundAdapter(?RefundAdapter $refundAdapter): void
    {
        $this->refundAdapter = $refundAdapter;
    }

    /**
     * @param callable(array<string, mixed>, bool, string): void|null $authorize
     */
    public function setAuthorizeCallback(?callable $authorize): void
    {
        $this->authorizeCallback = $authorize;
    }

    /**
     * @param callable(string): \Paymos\Client|null $clientFactory
     */
    public function setClientFactory(?callable $clientFactory): void
    {
        $this->clientFactory = $clientFactory;
    }

    /**
     * @param callable(): int|null $clock
     */
    public function setClock(?callable $clock): void
    {
        $this->clock = $clock;
    }

    /**
     * Read-only connection diagnostics & non-secret settings (never returns secrets).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function status(array $data = []): array
    {
        $this->authorize($data, false, 'manage_settings');
        $storeUrl = $this->resolveSourceUrl($data);
        $status = $this->connectHandler($data, false)->status($storeUrl);

        // Show what the checkout really uses, so the first save does not flip Live to Sandbox.
        $effective = $this->effectiveSettings();
        $status['mode'] = $effective['mode'];
        $status['button_text'] = $effective['button_text'];

        return $status;
    }

    /**
     * Module settings once saved; before that, the gateway-form values the checkout still applies.
     *
     * @return array{mode: string, button_text: string}
     */
    private function effectiveSettings(): array
    {
        $store = $this->resolveCredentialStore();
        $settings = $store->loadSettings();
        if (!$store->hasSavedSettings()) {
            $settings = array_merge($settings, Service::readGatewaySettings($this->di));
        }

        return ['mode' => (string) $settings['mode'], 'button_text' => (string) $settings['button_text']];
    }

    /**
     * Save ONLY non-secret display/mode preferences (`mode`, `button_text`).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function settings_save(array $data = []): array
    {
        $this->authorize($data, true, 'manage_settings');

        $store = $this->resolveCredentialStore();
        $effective = $this->effectiveSettings();
        $store->saveSettings([
            'mode' => $data['mode'] ?? $effective['mode'],
            'button_text' => $data['button_text'] ?? $effective['button_text'],
        ] + array_intersect_key($data, array_flip(['api_key', 'api_secret', 'project_id', 'webhook_secret', 'base_url'])));

        return [
            'saved' => true,
            'settings' => $store->loadSettings(),
        ];
    }

    /**
     * Start RFC 8628 One-Click Connect Paymos device flow.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function connect_start(array $data = []): array
    {
        $sourceUrl = $this->resolveSourceUrl($data);
        $returnUrl = isset($data['return_url']) && is_scalar($data['return_url'])
            ? trim((string) $data['return_url'])
            : '';

        try {
            return $this->connectHandler($data, true)->start($sourceUrl, $returnUrl);
        } catch (\Throwable $e) {
            throw new \RuntimeException(Config::redactStatic($e->getMessage()), (int) $e->getCode(), $e);
        }
    }

    /**
     * Poll RFC 8628 Connect Paymos device flow and atomically seal Sandbox + Live credentials.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function connect_poll(array $data = []): array
    {
        $sourceUrl = $this->resolveSourceUrl($data);

        try {
            return $this->connectHandler($data, true)->poll($sourceUrl);
        } catch (\Throwable $e) {
            throw new \RuntimeException(Config::redactStatic($e->getMessage()), (int) $e->getCode(), $e);
        }
    }

    /**
     * Load Paymos Refunds panel data for a FOSSBilling invoice (runs RefundDiscovery + reconcile).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function refund_panel(array $data = []): array
    {
        $this->authorize($data, false, 'manage_refunds');

        $invoiceId = isset($data['invoice_id']) && is_numeric($data['invoice_id']) ? (int) $data['invoice_id'] : 0;
        if ($invoiceId <= 0) {
            throw new \InvalidArgumentException('Valid FOSSBilling invoice_id is required.');
        }

        $locale = Translation::resolveLocale(
            isset($data['locale']) && is_string($data['locale']) ? $data['locale'] : null,
            $this->di
        );

        return $this->resolveRefundAdapter($data, false)->panel($invoiceId, $locale);
    }

    /**
     * Submit an admin-authorized full or overpayment refund via RefundCoordinator.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function refund_submit(array $data = []): array
    {
        $this->authorize($data, true, 'manage_refunds');

        $adapter = $this->resolveRefundAdapter($data, true);
        $intent = $adapter->submit($data);
        $obs = $intent->observation();

        return [
            'operation_id' => $intent->operationId(),
            'withdrawal_id' => $intent->withdrawalId(),
            'mode' => $intent->mode(),
            'cash_state' => $intent->cashState(),
            'projection_state' => $intent->projectionState(),
            'review_reason' => $intent->toArray()['review_reason'],
            'amount' => $obs !== null ? $obs->principalDecimal() : null,
            'asset' => $obs !== null ? $obs->currency() . '/' . $obs->network() : null,
        ];
    }

    /**
     * Manual trigger for cron reconciliation (invoices + refunds).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function reconcile_run(array $data = []): array
    {
        $this->authorize($data, true, 'manage_settings');
        $service = $this->resolveService();
        return $service->handleAfterAdminCronRun(null, null, true);
    }

    /**
     * Enforce FOSSBilling administrator authentication, permission, and CSRF token verification.
     *
     * @param array<string, mixed> $data
     */
    public function authorize(array $data, bool $requireCsrf, string $permission): void
    {
        if ($this->authorizeCallback !== null) {
            ($this->authorizeCallback)($data, $requireCsrf, $permission);
            return;
        }

        $isAdmin = false;
        $auth = self::readDiKey($this->di, 'auth');
        if (is_object($auth) && method_exists($auth, 'isAdminLoggedIn')) {
            $isAdmin = (bool) $auth->isAdminLoggedIn();
        } else {
            $flag = self::readDiKey($this->di, 'is_admin_logged');
            if (is_bool($flag)) {
                $isAdmin = $flag;
            }
        }

        if (!$isAdmin) {
            throw new \RuntimeException('paymos_admin_access_denied', 403);
        }

        $modService = self::readDiKey($this->di, 'mod_service');
        $loggedAdmin = self::readDiKey($this->di, 'loggedin_admin');
        if (is_callable($modService) && is_object($loggedAdmin)) {
            try {
                $staffService = $modService('staff');
                if (is_object($staffService) && method_exists($staffService, 'hasPermission')) {
                    if (!$staffService->hasPermission($loggedAdmin, 'paymos', $permission)) {
                        throw new \RuntimeException('paymos_admin_permission_denied', 403);
                    }
                }
            } catch (\RuntimeException $e) {
                if ($e->getCode() === 403) {
                    throw $e;
                }
            } catch (\Throwable $e) {
                // Permission hook unavailable in minimal DI stubs.
            }
        }

        if ($requireCsrf) {
            $expectedToken = $this->readSessionCsrfToken();
            $providedToken = $this->readProvidedCsrfToken($data);
            if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
                throw new \RuntimeException('paymos_csrf_invalid', 403);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function connectHandler(array $data, bool $requireCsrf): ConnectHandler
    {
        return new ConnectHandler(
            $this->resolveCredentialStore(),
            function () use ($data, $requireCsrf): void {
                $this->authorize($data, $requireCsrf, 'manage_settings');
            },
            $this->connectClient
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveRefundAdapter(array $data, bool $requireCsrf): RefundAdapter
    {
        if ($this->refundAdapter !== null) {
            return $this->refundAdapter;
        }

        $service = $this->resolveService();
        $driver = $service->resolveDbDriver();
        $credentialStore = $service->resolveCredentialStore($driver);
        $snapshotStore = new SnapshotStore($driver, $this->clock);
        $refundStore = new RefundIntentStore($driver, $this->clock);

        return $service->buildRefundAdapter(
            $credentialStore,
            $snapshotStore,
            $refundStore,
            function () use ($data, $requireCsrf): void {
                $this->authorize($data, $requireCsrf, 'manage_refunds');
            }
        );
    }

    private function resolveService(): Service
    {
        $service = $this->service instanceof Service ? $this->service : new Service();
        if ($this->di !== null) {
            $service->setDi($this->di);
        }
        if ($this->dbDriver !== null) {
            $service->setDbDriver($this->dbDriver);
        }
        if ($this->credentialStore !== null) {
            $service->setCredentialStore($this->credentialStore);
        }
        if ($this->clientFactory !== null) {
            $service->setClientFactory($this->clientFactory);
        }
        if ($this->clock !== null) {
            $service->setClock($this->clock);
        }

        return $service;
    }

    private function resolveCredentialStore(): CredentialStore
    {
        return $this->resolveService()->resolveCredentialStore();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveSourceUrl(array $data): string
    {
        $configured = Service::resolveStoreUrl($this->di);
        if ($configured !== '') {
            return $configured;
        }
        if (isset($data['source_url']) && is_scalar($data['source_url'])) {
            return rtrim(trim((string) $data['source_url']), '/');
        }

        return '';
    }

    /**
     * FOSSBilling core keeps the admin CSRF token in the session under `csrf_token`
     * (TwigFactory::getCsrfToken, Api controller _checkCSRFToken).
     */
    private function readSessionCsrfToken(): string
    {
        $session = self::readDiKey($this->di, 'session');
        foreach (['csrf_token', 'CSRFToken'] as $key) {
            if (is_object($session) && method_exists($session, 'get')) {
                $token = $session->get($key);
                if (is_scalar($token) && trim((string) $token) !== '') {
                    return trim((string) $token);
                }
            }
            if (isset($_SESSION[$key]) && is_scalar($_SESSION[$key]) && trim((string) $_SESSION[$key]) !== '') {
                return trim((string) $_SESSION[$key]);
            }
        }

        return '';
    }

    /**
     * The core removes `CSRFToken` from the params before calling the module, so the
     * X-CSRF-Token header the admin pages send is the token that reaches this check.
     *
     * @param array<string, mixed> $data
     */
    private function readProvidedCsrfToken(array $data): string
    {
        foreach (['CSRFToken', 'csrf_token'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_scalar($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            return trim((string) $_SERVER['HTTP_X_CSRF_TOKEN']);
        }

        return '';
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
