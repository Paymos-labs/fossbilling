<?php

declare(strict_types=1);

namespace Box\Mod\Paymos\Api;

require_once dirname(__DIR__) . '/src/Autoloader.php';
\PaymosFossBilling\Autoloader::register();

use Box\Mod\Paymos\Service;
use PaymosFossBilling\CallbackProcessor;
use PaymosFossBilling\Config;
use PaymosFossBilling\CredentialStore;
use PaymosFossBilling\DbDriverInterface;
use PaymosFossBilling\PdoEventStore;
use PaymosFossBilling\RefundAdapter;
use PaymosFossBilling\RefundIntentStore;
use PaymosFossBilling\SnapshotStore;
use Paymos\Webhook\WebhookEvent;

if (!class_exists(__NAMESPACE__ . '\\GuestApiBase', false)) {
    if (class_exists('\\Api_Abstract')) {
        class_alias('\\Api_Abstract', __NAMESPACE__ . '\\GuestApiBase');
    } else {
        abstract class GuestApiBase
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

/**
 * Fixed Paymos webhook endpoint: /index.php?_url=/api/guest/paymos/webhook
 *
 * Reads the exact raw request body from php://input, verifies signatures across
 * both Sandbox and Live environments via MultiEnvironmentWebhookVerifier, and
 * emits exact HTTP status codes (200/401/409/400/500) directly so FOSSBilling's
 * Api_Handler never rewrites webhook errors into HTTP 200.
 */
class Guest extends GuestApiBase
{
    /** @var callable(): string|null */
    private $rawBodyProvider = null;

    /** @var callable(): array<string, mixed>|null */
    private $serverProvider = null;

    /** @var callable(int, array<string, mixed>): void|null */
    private $responseEmitter = null;

    private ?CallbackProcessor $processor = null;
    private ?DbDriverInterface $dbDriver = null;
    private ?CredentialStore $credentialStore = null;

    /** @var callable(string): \Paymos\Client|null */
    private $clientFactory = null;

    /** @var callable(): int|null */
    private $clock = null;

    private bool $terminateOnDefaultEmit = true;

    /**
     * @param callable(): string|null $provider
     */
    public function setRawBodyProvider(?callable $provider): void
    {
        $this->rawBodyProvider = $provider;
    }

    /**
     * @param callable(): array<string, mixed>|null $provider
     */
    public function setServerProvider(?callable $provider): void
    {
        $this->serverProvider = $provider;
    }

    /**
     * @param callable(int, array<string, mixed>): void|null $emitter
     */
    public function setResponseEmitter(?callable $emitter): void
    {
        $this->responseEmitter = $emitter;
    }

    public function setProcessor(?CallbackProcessor $processor): void
    {
        $this->processor = $processor;
    }

    public function setDbDriver(?DbDriverInterface $dbDriver): void
    {
        $this->dbDriver = $dbDriver;
    }

    public function setCredentialStore(?CredentialStore $credentialStore): void
    {
        $this->credentialStore = $credentialStore;
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

    public function setTerminateOnDefaultEmit(bool $terminate): void
    {
        $this->terminateOnDefaultEmit = $terminate;
    }

    /**
     * Fixed Paymos webhook receiver mounted at /index.php?_url=/api/guest/paymos/webhook.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function webhook(array $data = []): array
    {
        $rawBody = $this->readRawBody($data);
        $signatureHeader = $this->readSignatureHeader($data);
        $now = $this->clock !== null ? (int) ($this->clock)() : null;

        try {
            $processor = $this->resolveProcessor();
            $result = $processor->handle($rawBody, $signatureHeader, $now);
        } catch (\Throwable $e) {
            $result = [
                'http_status' => 500,
                'status' => 'configuration_error',
                'message' => Config::redactStatic($e->getMessage()),
                'duplicate' => false,
            ];
        }

        $statusCode = isset($result['http_status']) && is_int($result['http_status'])
            ? $result['http_status']
            : 500;

        $body = [
            'ok' => $statusCode >= 200 && $statusCode < 300,
            'status' => (string) ($result['status'] ?? 'error'),
            'message' => (string) ($result['message'] ?? ''),
            'duplicate' => (bool) ($result['duplicate'] ?? false),
        ];

        $this->emitResponse($statusCode, $body);

        return array_merge($body, ['http_status' => $statusCode]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function readRawBody(array $data): string
    {
        if ($this->rawBodyProvider !== null) {
            return (string) ($this->rawBodyProvider)();
        }

        $fromData = CallbackProcessor::extractRawBody($data);
        if ($fromData !== '') {
            return $fromData;
        }

        $input = @file_get_contents('php://input');
        return is_string($input) ? $input : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function readSignatureHeader(array $data): string
    {
        if ($this->serverProvider !== null) {
            $headers = ($this->serverProvider)();
            if (is_array($headers)) {
                $sig = CallbackProcessor::extractSignatureHeader($headers);
                if ($sig !== '') {
                    return $sig;
                }
            }
        }

        if (isset($data['server']) && is_array($data['server'])) {
            $sig = CallbackProcessor::extractSignatureHeader($data['server']);
            if ($sig !== '') {
                return $sig;
            }
        }

        if (function_exists('getallheaders')) {
            $all = @getallheaders();
            if (is_array($all)) {
                $sig = CallbackProcessor::extractSignatureHeader($all);
                if ($sig !== '') {
                    return $sig;
                }
            }
        }

        if (isset($_SERVER) && is_array($_SERVER)) {
            return CallbackProcessor::extractSignatureHeader($_SERVER);
        }

        return '';
    }

    private function resolveProcessor(): CallbackProcessor
    {
        if ($this->processor !== null) {
            return $this->processor;
        }

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

        $driver = $service->resolveDbDriver();
        $credentialStore = $service->resolveCredentialStore($driver);
        $eventStore = new PdoEventStore($driver, $this->clock);
        $snapshotStore = new SnapshotStore($driver, $this->clock);
        $refundStore = new RefundIntentStore($driver, $this->clock);

        if (!$driver->inTransaction()) {
            $credentialStore->ensureSchema();
            $eventStore->ensureSchema();
            $snapshotStore->ensureSchema();
            $refundStore->ensureSchema();
        }

        $config = Config::forWebhooks($credentialStore);
        $callbacks = CallbackProcessor::createFossBillingCallbacks($this->di, null, null, $this->clock);
        $refundAdapter = $service->buildRefundAdapter($credentialStore, $snapshotStore, $refundStore);

        return new CallbackProcessor(
            $config,
            $eventStore,
            $snapshotStore,
            $callbacks['loadInvoice'],
            $callbacks['applyPayment'],
            $callbacks['addNote'],
            $this->clientFactory,
            static function (WebhookEvent $event, string $environment) use ($refundAdapter): void {
                $refundAdapter->callback($event, $environment);
            },
            $this->clock
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function emitResponse(int $statusCode, array $body): void
    {
        if ($this->responseEmitter !== null) {
            ($this->responseEmitter)($statusCode, $body);
            return;
        }

        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        echo json_encode($body, JSON_UNESCAPED_SLASHES);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        if ($this->terminateOnDefaultEmit && PHP_SAPI !== 'cli') {
            exit;
        }
    }
}
