<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/modules/Paymos/src/Autoloader.php';
\PaymosFossBilling\Autoloader::register();

use Paymos\Client;
use Paymos\Exception\ApiException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;
use Paymos\Http\TransportInterface;
use Paymos\Plugin\InvoiceReplacementBlockedException;
use Paymos\Webhook\EventStoreInterface;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;
use Paymos\Webhook\WebhookEvent;
use PaymosFossBilling\CallbackProcessor;
use PaymosFossBilling\CheckoutService;
use PaymosFossBilling\Config;
use PaymosFossBilling\CredentialStore;
use PaymosFossBilling\DbDriverInterface;
use PaymosFossBilling\PdoDbDriver;
use PaymosFossBilling\PdoEventStore;
use PaymosFossBilling\RefundAdapter;
use PaymosFossBilling\RefundIntentStore;
use PaymosFossBilling\SnapshotStore;
use PaymosFossBilling\Translation;

if (!interface_exists('\\PaymosFossBillingDiInterface', false)) {
    if (interface_exists('\\FOSSBilling\\Core\\Container\\InjectionAwareInterface')) {
        class_alias('\\FOSSBilling\\Core\\Container\\InjectionAwareInterface', 'PaymosFossBillingDiInterface');
    } elseif (interface_exists('\\FOSSBilling\\InjectionAwareInterface')) {
        class_alias('\\FOSSBilling\\InjectionAwareInterface', 'PaymosFossBillingDiInterface');
    } else {
        interface PaymosFossBillingDiInterface
        {
            /**
             * @param mixed $di
             */
            public function setDi($di): void;

            public function getDi(): ?\Pimple\Container;
        }
    }
}

if (!interface_exists('\\PaymosFossBillingJsonWebhookAuthInterface', false)) {
    if (interface_exists('\\FOSSBilling\\Core\\Payment\\JsonWebhookAuthenticatorInterface')) {
        class_alias(
            '\\FOSSBilling\\Core\\Payment\\JsonWebhookAuthenticatorInterface',
            'PaymosFossBillingJsonWebhookAuthInterface'
        );
    } else {
        interface PaymosFossBillingJsonWebhookAuthInterface
        {
            /**
             * @param array<string, mixed> $data
             */
            public function authenticateJsonWebhook(array $data): void;
        }
    }
}

class Payment_Adapter_Paymos extends Payment_AdapterAbstract implements
    \PaymosFossBillingDiInterface,
    \PaymosFossBillingJsonWebhookAuthInterface
{
    /** @var mixed */
    protected $di = null;

    /** @var array<string, mixed> */
    private array $rawConfig = [];

    private ?DbDriverInterface $dbDriver = null;
    private ?CredentialStore $credentialStore = null;
    private ?TransportInterface $transport = null;

    /** @var callable(Config, ?string): Client|null */
    private $checkoutClientFactory = null;

    /** @var callable(string): Client|null */
    private $envClientFactory = null;

    /** @var callable(): int|null */
    private $clock = null;

    /** @var callable(string, string, array<string, mixed>): void|null */
    private $logger = null;

    /** @var callable(): void|null */
    private $refundAuthorize = null;

    private ?PdoEventStore $eventStore = null;
    private ?SnapshotStore $snapshotStore = null;
    private ?RefundIntentStore $refundIntentStore = null;
    private ?RefundAdapter $refundAdapter = null;

    /** @var array<string, mixed>|null */
    private ?array $lastWebhookResult = null;

    /**
     * @param mixed $config
     */
    public function __construct($config)
    {
        parent::__construct($config);
        $this->rawConfig = is_array($config) ? $config : [];
    }

    /**
     * @param mixed $di
     */
    public function setDi($di): void
    {
        $this->di = $di;
        if ($this->dbDriver === null) {
            $this->resetRuntimeCache();
        }
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di instanceof \Pimple\Container ? $this->di : null;
    }

    public function setDbDriver(DbDriverInterface $dbDriver): void
    {
        $this->dbDriver = $dbDriver;
        $this->resetRuntimeCache();
    }

    public function setCredentialStore(CredentialStore $credentialStore): void
    {
        $this->credentialStore = $credentialStore;
        $this->resetRuntimeCache();
    }

    public function setTransport(TransportInterface $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * @param callable(Config, ?string): Client $factory
     */
    public function setCheckoutClientFactory(callable $factory): void
    {
        $this->checkoutClientFactory = $factory;
    }

    /**
     * @param callable(string): Client $factory
     */
    public function setEnvClientFactory(callable $factory): void
    {
        $this->envClientFactory = $factory;
    }

    /**
     * @param callable(): int $clock
     */
    public function setClock(callable $clock): void
    {
        $this->clock = $clock;
        $this->resetRuntimeCache();
    }

    /**
     * @param callable(string, string, array<string, mixed>): void $logger
     */
    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param callable(): void $authorize
     */
    public function setRefundAuthorize(callable $authorize): void
    {
        $this->refundAuthorize = $authorize;
        $this->refundAdapter = null;
    }

    public function setRefundAdapter(RefundAdapter $adapter): void
    {
        $this->refundAdapter = $adapter;
    }

    /**
     * Gateway settings exposed in FOSSBilling Payment Gateways configuration.
     * Strictly zero manual API key, API secret, Project ID, or Webhook Secret inputs:
     * credentials are provisioned atomically via One-Click Connect Paymos in Modules -> Paymos.
     *
     * @return array<string, mixed>
     */
    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Accept USDT, USDC, USD1, DAI stablecoins and XAUT payments through Paymos. Connect your installation with one click under Extensions -> Paymos (Connect Paymos) to provision Sandbox and Live credentials into encrypted storage and register the fixed webhook endpoint (/index.php?_url=/api/guest/paymos/webhook). No manual API keys or webhook secrets are entered here.',
            'logo' => [
                'logo' => '/Paymos/Paymos.png',
                'height' => '50px',
                'width' => '50px',
            ],
            'form' => [
                'mode' => [
                    'radio',
                    [
                        'multiOptions' => [
                            'sandbox' => 'Sandbox',
                            'live' => 'Live',
                        ],
                        'label' => 'Mode',
                        'description' => 'Use Sandbox for test payments, then switch to Live for real orders.',
                        'required' => false,
                    ],
                ],
                'button_text' => [
                    'text',
                    [
                        'label' => 'Button Text',
                        'description' => 'Optional custom text for the Paymos invoice payment button.',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * No secrets are stored in FOSSBilling's payment gateway JSON configuration;
     * all credentials live inside the AES-256-GCM CredentialStore keyed from salt.
     *
     * @return array<int, string>
     */
    public static function getSecretFields(): array
    {
        return [];
    }

    /**
     * FOSSBilling 0.8+/2.0 JSON webhook authentication fallback for ipn.php.
     * Verifies HMAC-SHA256 across Sandbox and Live via MultiEnvironmentWebhookVerifier
     * WITHOUT consuming the deduplication slot so processTransaction() deduplicates atomically.
     *
     * @param array<string, mixed> $data
     * @throws Payment_Exception
     */
    public function authenticateJsonWebhook(array $data): void
    {
        $config = $this->resolveWebhookConfig();
        $rawBody = CallbackProcessor::extractRawBody($data);
        $signatureHeader = $this->extractSignature($data);

        try {
            $noopStore = new class implements EventStoreInterface {
                public function remember($eventId, $ttlSeconds)
                {
                    return true;
                }
            };
            (new MultiEnvironmentWebhookVerifier($config->webhookSecrets(), $noopStore))
                ->process($signatureHeader, $rawBody, $this->now());
        } catch (SignatureMismatchException | TimestampSkewException $e) {
            http_response_code(401);
            throw new Payment_Exception('Invalid or expired Paymos webhook signature.');
        } catch (\Throwable $e) {
            http_response_code(401);
            throw new Payment_Exception('Unable to authenticate Paymos webhook.');
        }

        $this->ensureTablesOutsideTransaction();
    }

    /**
     * @param mixed $api_admin
     * @param mixed $invoice_id
     * @param mixed $subscription
     * @throws Payment_Exception
     */
    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        $locale = Translation::resolveLocale(null, $this->di);
        if ((bool) $subscription) {
            throw new Payment_Exception(Translation::text('subscriptions_unsupported', $locale));
        }

        $config = $this->resolveConfig();
        $invoiceIdInt = (int) $invoice_id;
        if ($invoiceIdInt <= 0) {
            throw new Payment_Exception('Invalid FOSSBilling invoice ID.');
        }

        $invoice = $this->fetchAdminInvoice($api_admin, $invoiceIdInt);
        if (!is_array($invoice)) {
            throw new Payment_Exception('Unable to load FOSSBilling invoice.');
        }
        if (!isset($invoice['id'])) {
            $invoice['id'] = $invoiceIdInt;
        }

        $this->ensureTablesOutsideTransaction();

        $checkout = new CheckoutService(
            $config,
            $this->getSnapshotStore(),
            $this->makeCheckoutClientFactory(),
            $this->loggerCallback(),
            $this->clockCallback()
        );

        try {
            $result = $checkout->createCheckout($invoice);
            return CheckoutService::renderPaymentHtml(
                (string) $result['payment_url'],
                $config->buttonText(),
                $locale
            );
        } catch (InvoiceReplacementBlockedException | ApiException $e) {
            return CheckoutService::failureNotice($e, $locale, $config);
        } catch (\Throwable $e) {
            throw new Payment_Exception($config->redact($e->getMessage()));
        }
    }

    /**
     * Resolve integer FOSSBilling invoice ID from a verified JSON webhook payload
     * external_id (`fossbilling_{id}_{renew}` or legacy `fb-{id}-...`), SnapshotStore,
     * or query parameters.
     *
     * @param mixed $data
     */
    public function getInvoiceId($data): ?int
    {
        if (!is_array($data)) {
            return null;
        }

        $rawBody = CallbackProcessor::extractRawBody($data);
        if ($rawBody !== '') {
            try {
                $config = $this->resolveWebhookConfig();
                $signatureHeader = $this->extractSignature($data);
                $noopStore = new class implements EventStoreInterface {
                    public function remember($eventId, $ttlSeconds)
                    {
                        return true;
                    }
                };
                $verified = (new MultiEnvironmentWebhookVerifier($config->webhookSecrets(), $noopStore))
                    ->process($signatureHeader, $rawBody, $this->now());
                $event = $verified->event();
            } catch (\Throwable $e) {
                return null;
            }

            $externalId = $event->externalOrderId();
            if ($externalId !== '') {
                $parsed = CheckoutService::parseInvoiceIdFromExternalOrderId($externalId);
                if ($parsed !== null) {
                    return $parsed;
                }
            }

            $paymosInvoiceId = $event->invoiceId();
            if ($paymosInvoiceId !== '') {
                try {
                    $snapshot = $this->getSnapshotStore()->findByPaymosInvoiceId($paymosInvoiceId);
                    if (is_array($snapshot) && is_numeric($snapshot['platform_ref'] ?? null)) {
                        $refId = (int) $snapshot['platform_ref'];
                        if ($refId > 0) {
                            return $refId;
                        }
                    }
                } catch (\Throwable $e) {
                    // Continue to query parameter fallback below.
                }
            }
        }

        if (isset($data['get']) && is_array($data['get'])) {
            foreach (['invoice_id', 'bb_invoice_id'] as $param) {
                if (isset($data['get'][$param]) && is_numeric($data['get'][$param])) {
                    $id = (int) $data['get'][$param];
                    if ($id > 0) {
                        return $id;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Legacy ipn.php fallback processing path (primary fixed endpoint is
     * Box\Mod\Paymos\Api\Guest::webhook at /index.php?_url=/api/guest/paymos/webhook).
     *
     * @param mixed $api_admin
     * @param mixed $id
     * @param mixed $data
     * @param mixed $gateway_id
     * @throws Payment_Exception
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $config = $this->resolveWebhookConfig();
        $payloadData = is_array($data) ? $data : [];
        $rawBody = CallbackProcessor::extractRawBody($payloadData);
        $signatureHeader = $this->extractSignature($payloadData);

        $this->ensureTablesOutsideTransaction();

        $callbacks = CallbackProcessor::createFossBillingCallbacks(
            $this->di,
            $api_admin,
            $id,
            $this->clockCallback(),
            $this->loggerCallback()
        );
        $refundAdapter = $this->getRefundAdapter();

        $processor = new CallbackProcessor(
            $config,
            $this->getEventStore(),
            $this->getSnapshotStore(),
            $callbacks['loadInvoice'],
            $callbacks['applyPayment'],
            $callbacks['addNote'],
            $this->makeEnvClientFactory($config),
            static function (WebhookEvent $event, string $environment) use ($refundAdapter): void {
                $refundAdapter->callback($event, $environment);
            },
            $this->clockCallback(),
            $this->loggerCallback()
        );

        $result = $processor->handle($rawBody, $signatureHeader, $this->now());
        $this->lastWebhookResult = $result;
        http_response_code((int) ($result['http_status'] ?? 500));

        if ((int) ($result['http_status'] ?? 500) !== 200) {
            throw new Payment_Exception((string) ($result['message'] ?? 'Webhook processing failed.'));
        }
    }

    /**
     * FOSSBilling 2.0 native gateway refund hook.
     * Submits a full refund through RefundCoordinator and NEVER allows premature accounting
     * debit while the refund is pending/processing/unknown.
     *
     * @param mixed $invoice
     * @param mixed $transaction
     * @param mixed $amount
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws Payment_Exception
     */
    public function refundPayment($invoice, $transaction = null, $amount = null, ?string $note = null, array $options = []): array
    {
        $locale = Translation::resolveLocale(null, $this->di);
        $invoiceId = $this->extractInvoiceIdArg($invoice);
        if ($invoiceId <= 0) {
            throw new Payment_Exception('Invalid FOSSBilling invoice ID for refund.');
        }

        $input = array_merge($options, ['invoice_id' => $invoiceId]);
        if ($amount !== null && $amount !== '') {
            $input['amount'] = (string) $amount;
        }

        $this->ensureTablesOutsideTransaction();

        try {
            return $this->getRefundAdapter()->refundPaymentForAdapter($input, $locale);
        } catch (\Throwable $e) {
            throw new Payment_Exception(Config::redactStatic($e->getMessage()));
        }
    }

    /**
     * FOSSBilling 2.0 native refund inspection hook for a gateway transaction.
     *
     * @param mixed $transaction
     * @return array<int, array<string, mixed>>
     */
    public function listRefundsForTransaction($transaction): array
    {
        $txnId = '';
        if (is_string($transaction)) {
            $txnId = $transaction;
        } elseif (is_array($transaction) && isset($transaction['txn_id']) && is_scalar($transaction['txn_id'])) {
            $txnId = (string) $transaction['txn_id'];
        } elseif (is_object($transaction)) {
            if (method_exists($transaction, 'getTxnId')) {
                $txnId = (string) $transaction->getTxnId();
            } elseif (isset($transaction->txn_id) && is_scalar($transaction->txn_id)) {
                $txnId = (string) $transaction->txn_id;
            }
        }

        if ($txnId === '') {
            return [];
        }

        return $this->getRefundAdapter()->listRefundsForTransaction($txnId);
    }

    /**
     * @throws Payment_Exception
     */
    public function getResolvedConfig(): Config
    {
        return $this->resolveConfig();
    }

    /**
     * @throws Payment_Exception
     */
    public function getEventStore(): PdoEventStore
    {
        if ($this->eventStore === null) {
            $this->eventStore = new PdoEventStore($this->resolveDbDriver(), $this->clockCallback());
        }

        return $this->eventStore;
    }

    /**
     * @throws Payment_Exception
     */
    public function getSnapshotStore(): SnapshotStore
    {
        if ($this->snapshotStore === null) {
            $this->snapshotStore = new SnapshotStore($this->resolveDbDriver(), $this->clockCallback());
        }

        return $this->snapshotStore;
    }

    /**
     * @throws Payment_Exception
     */
    public function getRefundIntentStore(): RefundIntentStore
    {
        if ($this->refundIntentStore === null) {
            $this->refundIntentStore = new RefundIntentStore($this->resolveDbDriver(), $this->clockCallback());
        }

        return $this->refundIntentStore;
    }

    /**
     * @throws Payment_Exception
     */
    public function getRefundAdapter(): RefundAdapter
    {
        if ($this->refundAdapter !== null) {
            return $this->refundAdapter;
        }

        $credentialStore = $this->resolveCredentialStore();
        $snapshotStore = $this->getSnapshotStore();
        $refundStore = $this->getRefundIntentStore();
        $native = RefundAdapter::createFossBillingNativeHandler(
            $this->di,
            $credentialStore,
            $snapshotStore,
            $this->clockCallback(),
            $this->loggerCallback()
        );

        $authorize = $this->refundAuthorize ?? function (): void {
            $auth = $this->readDiKey('auth');
            if (is_object($auth) && method_exists($auth, 'isAdminLoggedIn')) {
                if (!$auth->isAdminLoggedIn()) {
                    throw new \RuntimeException('refund_admin_access_denied');
                }
                return;
            }
            $flag = $this->readDiKey('is_admin_logged');
            if ($flag === true) {
                return;
            }
            throw new \RuntimeException('refund_admin_access_denied');
        };

        $this->refundAdapter = new RefundAdapter(
            $credentialStore,
            $snapshotStore,
            $refundStore,
            $native,
            $this->envClientFactory ?? ($this->transport !== null ? function (string $env) use ($credentialStore): Client {
                $cfg = Config::fromCredentialStore($credentialStore, ['mode' => $env]);
                return new Client($cfg->clientConfig($env), $this->transport);
            } : null),
            $authorize,
            $this->clockCallback(),
            $this->loggerCallback()
        );

        return $this->refundAdapter;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLastWebhookResult(): ?array
    {
        return $this->lastWebhookResult;
    }

    /**
     * @throws Payment_Exception
     */
    private function resolveConfig(): Config
    {
        try {
            return Config::fromCredentialStore($this->resolveCredentialStore(), $this->rawConfig);
        } catch (\Throwable $e) {
            throw new Payment_Exception(Config::redactStatic($e->getMessage()));
        }
    }

    /**
     * @throws Payment_Exception
     */
    private function resolveWebhookConfig(): Config
    {
        try {
            return Config::forWebhooks($this->resolveCredentialStore(), $this->rawConfig);
        } catch (\Throwable $e) {
            throw new Payment_Exception(Config::redactStatic($e->getMessage()));
        }
    }

    /**
     * @throws Payment_Exception
     */
    private function resolveCredentialStore(): CredentialStore
    {
        if ($this->credentialStore !== null) {
            return $this->credentialStore;
        }

        try {
            $this->credentialStore = CredentialStore::fromDi($this->di, $this->resolveDbDriver(), $this->clockCallback());
            return $this->credentialStore;
        } catch (\Throwable $e) {
            throw new Payment_Exception(Config::redactStatic($e->getMessage()));
        }
    }

    /**
     * @throws Payment_Exception
     */
    private function resolveDbDriver(): DbDriverInterface
    {
        if ($this->dbDriver !== null) {
            return $this->dbDriver;
        }

        try {
            $this->dbDriver = PdoDbDriver::fromFossBillingDi($this->di);
            return $this->dbDriver;
        } catch (\Throwable $e) {
            throw new Payment_Exception('Paymos gateway requires an active database connection.');
        }
    }

    /**
     * @return callable(Config, ?string): Client|null
     */
    private function makeCheckoutClientFactory(): ?callable
    {
        if ($this->checkoutClientFactory !== null) {
            return $this->checkoutClientFactory;
        }
        if ($this->envClientFactory !== null) {
            $factory = $this->envClientFactory;
            return static function (Config $config, ?string $env) use ($factory): Client {
                return $factory($env ?? $config->environment());
            };
        }
        if ($this->transport !== null) {
            $transport = $this->transport;
            return static function (Config $config, ?string $env) use ($transport): Client {
                return new Client($config->clientConfig($env), $transport);
            };
        }

        return null;
    }

    /**
     * @return callable(string): Client|null
     */
    private function makeEnvClientFactory(Config $config): ?callable
    {
        if ($this->envClientFactory !== null) {
            return $this->envClientFactory;
        }
        if ($this->transport !== null) {
            $transport = $this->transport;
            return static function (string $env) use ($config, $transport): Client {
                return new Client($config->clientConfig($env), $transport);
            };
        }

        return null;
    }

    private function resetRuntimeCache(): void
    {
        $this->eventStore = null;
        $this->snapshotStore = null;
        $this->refundIntentStore = null;
        $this->refundAdapter = null;
    }

    private function ensureTablesOutsideTransaction(): void
    {
        try {
            $driver = $this->resolveDbDriver();
            if (!$driver->inTransaction()) {
                $this->resolveCredentialStore()->ensureSchema();
                $this->getEventStore()->ensureSchema();
                $this->getSnapshotStore()->ensureSchema();
                $this->getRefundIntentStore()->ensureSchema();
            }
        } catch (\Throwable $e) {
            // Non-fatal pre-check.
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractSignature(array $data): string
    {
        if (isset($data['server']) && is_array($data['server'])) {
            $sig = CallbackProcessor::extractSignatureHeader($data['server']);
            if ($sig !== '') {
                return $sig;
            }
        }

        if (isset($_SERVER) && is_array($_SERVER)) {
            return CallbackProcessor::extractSignatureHeader($_SERVER);
        }

        return '';
    }

    /**
     * @param mixed $api_admin
     * @return array<string, mixed>|null
     */
    private function fetchAdminInvoice($api_admin, int $invoiceId): ?array
    {
        if (is_object($api_admin) && (method_exists($api_admin, 'invoice_get') || method_exists($api_admin, '__call'))) {
            $invoice = $api_admin->invoice_get(['id' => $invoiceId]);
            if (is_array($invoice)) {
                return $invoice;
            }
        }

        return null;
    }

    /**
     * @param mixed $invoice
     */
    private function extractInvoiceIdArg($invoice): int
    {
        if (is_numeric($invoice)) {
            return (int) $invoice;
        }
        if (is_array($invoice) && isset($invoice['id']) && is_numeric($invoice['id'])) {
            return (int) $invoice['id'];
        }
        if (is_object($invoice)) {
            if (method_exists($invoice, 'getId') && is_numeric($invoice->getId())) {
                return (int) $invoice->getId();
            }
            if (isset($invoice->id) && is_numeric($invoice->id)) {
                return (int) $invoice->id;
            }
        }

        return 0;
    }

    /**
     * @return mixed
     */
    private function readDiKey(string $key)
    {
        if (is_array($this->di)) {
            return $this->di[$key] ?? null;
        }
        if ($this->di instanceof \ArrayAccess) {
            try {
                return $this->di->offsetExists($key) ? $this->di[$key] : null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        if (is_object($this->di) && method_exists($this->di, 'has') && method_exists($this->di, 'get')) {
            try {
                return $this->di->has($key) ? $this->di->get($key) : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return callable(): int
     */
    private function clockCallback(): callable
    {
        if ($this->clock !== null) {
            return $this->clock;
        }

        return static function (): int {
            return time();
        };
    }

    /**
     * @return callable(string, string, array<string, mixed>): void|null
     */
    private function loggerCallback(): ?callable
    {
        if ($this->logger !== null) {
            return $this->logger;
        }

        $diLogger = $this->readDiKey('logger');
        if (is_object($diLogger) && method_exists($diLogger, 'info')) {
            return static function (string $level, string $message, array $context = []) use ($diLogger): void {
                $line = '[Paymos][' . strtoupper($level) . '] ' . Config::redactStatic($message);
                if (count($context) > 0) {
                    $encoded = json_encode($context, JSON_UNESCAPED_SLASHES);
                    if (is_string($encoded)) {
                        $line .= ' ' . Config::redactStatic($encoded);
                    }
                }
                $diLogger->info($line);
            };
        }

        return null;
    }

    private function now(): int
    {
        return (int) ($this->clockCallback())();
    }
}
