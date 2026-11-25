<?php

declare(strict_types=1);

namespace Box\Mod\Paymos;

require_once __DIR__ . '/src/Autoloader.php';
\PaymosFossBilling\Autoloader::register();

use PaymosFossBilling\CallbackProcessor;
use PaymosFossBilling\Config;
use PaymosFossBilling\CredentialStore;
use PaymosFossBilling\DbDriverInterface;
use PaymosFossBilling\PdoDbDriver;
use PaymosFossBilling\PdoEventStore;
use PaymosFossBilling\Reconciler;
use PaymosFossBilling\RefundAdapter;
use PaymosFossBilling\RefundIntentStore;
use PaymosFossBilling\SnapshotStore;

if (!interface_exists(__NAMESPACE__ . '\\ModuleDiInterface', false)) {
    if (interface_exists('\\FOSSBilling\\Core\\Container\\InjectionAwareInterface')) {
        class_alias('\\FOSSBilling\\Core\\Container\\InjectionAwareInterface', __NAMESPACE__ . '\\ModuleDiInterface');
    } elseif (interface_exists('\\FOSSBilling\\InjectionAwareInterface')) {
        class_alias('\\FOSSBilling\\InjectionAwareInterface', __NAMESPACE__ . '\\ModuleDiInterface');
    } else {
        interface ModuleDiInterface
        {
            /** @param mixed $di */
            public function setDi($di): void;

            /** @return mixed */
            public function getDi();
        }
    }
}

class Service implements ModuleDiInterface
{
    /** @var mixed */
    protected $di = null;

    private ?DbDriverInterface $dbDriver = null;
    private ?CredentialStore $credentialStore = null;

    /** @var callable(string): \Paymos\Client|null */
    private $clientFactory = null;

    /** @var callable(): int|null */
    private $clock = null;

    /** @var callable(string, string, array<string, mixed>): void|null */
    private $logger = null;

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

    public function setDbDriver(DbDriverInterface $dbDriver): void
    {
        $this->dbDriver = $dbDriver;
    }

    public function setCredentialStore(CredentialStore $credentialStore): void
    {
        $this->credentialStore = $credentialStore;
    }

    /**
     * @param callable(string): \Paymos\Client $clientFactory
     */
    public function setClientFactory(callable $clientFactory): void
    {
        $this->clientFactory = $clientFactory;
    }

    /**
     * @param callable(): int $clock
     */
    public function setClock(callable $clock): void
    {
        $this->clock = $clock;
    }

    /**
     * @param callable(string, string, array<string, mixed>): void $logger
     */
    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getModulePermissions(): array
    {
        return [
            'manage_settings' => [
                'type' => 'bool',
                'display_name' => 'Configure Paymos connection and settings',
                'description' => 'Allows connecting Paymos and changing Sandbox/Live mode and button text.',
            ],
            'manage_refunds' => [
                'type' => 'bool',
                'display_name' => 'Manage Paymos refunds',
                'description' => 'Allows inspecting refund status and submitting full or overpayment refunds through Paymos.',
            ],
        ];
    }

    /**
     * Symfony / FOSSBilling 2.0 event subscriber map alongside FOSSBilling 0.7 Box_Event hook.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterAdminCronRun' => 'onAfterAdminCronRun',
            'FOSSBilling\\Core\\Event\\AfterAdminCronRunEvent' => 'handleAfterAdminCronRun',
        ];
    }

    public function install(): bool
    {
        $driver = $this->resolveDbDriver();
        if (!$driver->inTransaction()) {
            $this->resolveCredentialStore($driver)->ensureSchema();
            (new PdoEventStore($driver, $this->clock))->ensureSchema();
            (new SnapshotStore($driver, $this->clock))->ensureSchema();
            (new RefundIntentStore($driver, $this->clock))->ensureSchema();
        }

        return true;
    }

    public function uninstall(): bool
    {
        $driver = $this->resolveDbDriver();
        $refundStore = new RefundIntentStore($driver, $this->clock);
        if ($driver->tableExists(RefundIntentStore::TABLE) && $refundStore->hasAnyRefundHistory()) {
            throw new \RuntimeException('Paymos cannot be uninstalled while refund history remains.');
        }

        return true;
    }

    /**
     * FOSSBilling 0.7 (`Box_Event`) & 2.0 (`AfterAdminCronRunEvent`) cron listener.
     *
     * @param mixed $event
     */
    public static function onAfterAdminCronRun($event): void
    {
        $di = null;
        if (is_object($event) && method_exists($event, 'getDi')) {
            $di = $event->getDi();
        } elseif (is_array($event) && isset($event['di'])) {
            $di = $event['di'];
        }

        if ($di === null) {
            return;
        }

        $service = new self();
        $service->setDi($di);
        $service->handleAfterAdminCronRun($event);
    }

    /**
     * Instance entry point for cron reconciliation of unpaid invoices (24h / 600s throttle)
     * and pending/external refunds.
     *
     * @param mixed $event
     * @return array{invoices_reconciled: int, refunds_ran: bool}
     */
    public function handleAfterAdminCronRun($event = null, ?int $now = null, bool $forceInvoiceReconcile = false): array
    {
        if ($this->di === null) {
            if (is_object($event) && method_exists($event, 'getDi')) {
                $this->di = $event->getDi();
            } elseif (is_array($event) && isset($event['di'])) {
                $this->di = $event['di'];
            }
        }

        $invoicesReconciled = 0;
        $refundsRan = false;

        try {
            $driver = $this->resolveDbDriver();
            $credentialStore = $this->resolveCredentialStore($driver);
            if (!$credentialStore->isConnected()) {
                return ['invoices_reconciled' => 0, 'refunds_ran' => false];
            }

            $eventStore = new PdoEventStore($driver, $this->clock);
            $snapshotStore = new SnapshotStore($driver, $this->clock);
            $refundStore = new RefundIntentStore($driver, $this->clock);
            $config = Config::forWebhooks($credentialStore);
            $callbacks = CallbackProcessor::createFossBillingCallbacks($this->di, null, null, $this->clock, $this->logger);

            $refundAdapter = $this->buildRefundAdapter($credentialStore, $snapshotStore, $refundStore);
            $processor = new CallbackProcessor(
                $config,
                $eventStore,
                $snapshotStore,
                $callbacks['loadInvoice'],
                $callbacks['applyPayment'],
                $callbacks['addNote'],
                $this->clientFactory,
                static function (\Paymos\Webhook\WebhookEvent $evt, string $env) use ($refundAdapter): void {
                    $refundAdapter->callback($evt, $env);
                },
                $this->clock,
                $this->logger
            );

            $reconciler = new Reconciler(
                $config,
                $credentialStore,
                $snapshotStore,
                $processor,
                $this->clientFactory,
                $this->clock,
                $this->logger
            );
            $invoicesReconciled = $reconciler->run($now, $forceInvoiceReconcile);
        } catch (\Throwable $e) {
            $this->logError('Paymos invoice cron reconciliation failed: ' . $e->getMessage());
        }

        try {
            $driver = $this->resolveDbDriver();
            $credentialStore = $this->resolveCredentialStore($driver);
            if ($credentialStore->isConnected()) {
                $snapshotStore = new SnapshotStore($driver, $this->clock);
                $refundStore = new RefundIntentStore($driver, $this->clock);
                $refundAdapter = $this->buildRefundAdapter($credentialStore, $snapshotStore, $refundStore);
                $refundAdapter->run($now);
                $refundsRan = true;
            }
        } catch (\Throwable $e) {
            $this->logError('Paymos refund cron reconciliation failed: ' . $e->getMessage());
        }

        return [
            'invoices_reconciled' => $invoicesReconciled,
            'refunds_ran' => $refundsRan,
        ];
    }

    /**
     * Resolve canonical FOSSBilling public installation URL from $di['config']['url'].
     *
     * @param mixed $di
     */
    public static function resolveStoreUrl($di): string
    {
        $config = self::readDiKey($di, 'config');
        if (is_array($config) && isset($config['url']) && is_scalar($config['url'])) {
            $url = rtrim(trim((string) $config['url']), '/');
            if ($url !== '') {
                return $url;
            }
        }
        if ($config instanceof \ArrayAccess) {
            try {
                if ($config->offsetExists('url') && is_scalar($config['url'])) {
                    $url = rtrim(trim((string) $config['url']), '/');
                    if ($url !== '') {
                        return $url;
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to FOSSBilling 0.8+ Config::getProperty() / SYSTEM_URL.
            }
        }

        if (class_exists('\\FOSSBilling\\Config') && method_exists('\\FOSSBilling\\Config', 'getProperty')) {
            try {
                $configured = \FOSSBilling\Config::getProperty('url', '');
                if (is_scalar($configured) && trim((string) $configured) !== '') {
                    return rtrim(trim((string) $configured), '/');
                }
            } catch (\Throwable $e) {
                // Fall through to SYSTEM_URL.
            }
        }

        if (defined('SYSTEM_URL') && is_scalar(SYSTEM_URL) && trim((string) SYSTEM_URL) !== '') {
            return rtrim(trim((string) SYSTEM_URL), '/');
        }

        return '';
    }

    public function buildRefundAdapter(
        CredentialStore $credentialStore,
        SnapshotStore $snapshotStore,
        RefundIntentStore $refundStore,
        ?callable $authorize = null
    ): RefundAdapter {
        $native = RefundAdapter::createFossBillingNativeHandler(
            $this->di,
            $credentialStore,
            $snapshotStore,
            $this->clock,
            $this->logger
        );

        return new RefundAdapter(
            $credentialStore,
            $snapshotStore,
            $refundStore,
            $native,
            $this->clientFactory,
            $authorize,
            $this->clock,
            $this->logger
        );
    }

    public function resolveDbDriver(): DbDriverInterface
    {
        if ($this->dbDriver !== null) {
            return $this->dbDriver;
        }

        $this->dbDriver = PdoDbDriver::fromFossBillingDi($this->di);
        return $this->dbDriver;
    }

    public function resolveCredentialStore(?DbDriverInterface $driver = null): CredentialStore
    {
        if ($this->credentialStore !== null) {
            return $this->credentialStore;
        }

        $this->credentialStore = CredentialStore::fromDi($this->di, $driver ?? $this->resolveDbDriver(), $this->clock);
        return $this->credentialStore;
    }

    private function logError(string $message): void
    {
        $safe = Config::redactStatic($message);
        if ($this->logger !== null) {
            ($this->logger)('error', $safe, []);
            return;
        }
        $diLogger = self::readDiKey($this->di, 'logger');
        if (is_object($diLogger) && method_exists($diLogger, 'err')) {
            $diLogger->err('[Paymos] ' . $safe);
        } elseif (is_object($diLogger) && method_exists($diLogger, 'info')) {
            $diLogger->info('[Paymos][ERROR] ' . $safe);
        }
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
