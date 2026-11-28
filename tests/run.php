<?php

declare(strict_types=1);

/**
 * Comprehensive standalone PHP 8.1+ test suite for plugins/fossbilling-paymos/.
 *
 * Covers:
 * - PHP syntax lint across all modules/ and library/ PHP files
 * - manifest.json schema v1 & Paymos-labs/fossbilling zero-leak checks
 * - Legacy root MVP file deletion verification
 * - Contract 1: CredentialStore (AesGcmEnvelope on salt, CredentialSet invariants, refundConfig) & Config
 * - Contract 2: ConnectHandler (RFC 8628, HTTPS, plugin/source_url binding, admin auth + CSRF)
 * - Contract 3: CheckoutService (STATUS_CREATING, crash recovery, paid_late_noted, InvoiceReplacement, InvoiceRenewal, products[])
 * - Contract 4: Api/Guest::webhook() (exact 200/401/409/400/500 HTTP codes), CallbackProcessor (MultiEnvironmentWebhookVerifier,
 *               dual-v1 rotation, reverse-verify, AmountGuard, rollback guard, paid_late note-only, Doctrine 2.0 + RedBean 0.7),
 *               ipn.php fallback, Reconciler (24h sweep + 600s throttle), Service::onAfterAdminCronRun
 * - Contract 5: RefundIntentStore (full RefundIntentStoreInterface + RefundCandidateStoreInterface), RefundAdapter
 *               (full + overpayment, async no-premature-debit, FOSSBilling 2.0 external_reversal + 0.7 fallback,
 *               RefundDiscovery + ExternalRefundObservation, panel, rejects, adapter refundPayment/listRefundsForTransaction)
 * - Six native locales (en, ru, de, es, tr, zh-Hans) full key parity & translation verification
 */

if (!class_exists('Payment_Exception', false)) {
    class Payment_Exception extends \Exception
    {
        /** @param array<string, mixed>|null $variables */
        public function __construct(string $message = '', ?array $variables = null, int $code = 0)
        {
            parent::__construct($message, $code);
        }
    }
}

if (!class_exists('Payment_AdapterAbstract', false)) {
    abstract class Payment_AdapterAbstract
    {
        /** @var array<string, mixed> */
        protected $config = [];

        /** @param mixed $config */
        public function __construct($config)
        {
            $this->config = is_array($config) ? $config : [];
        }

        /** @return mixed */
        public function getParam(string $param)
        {
            return $this->config[$param] ?? null;
        }
    }
}

$pluginRoot = dirname(__DIR__);
require_once $pluginRoot . '/library/Payment/Adapter/Paymos/Paymos.php';

use Box\Mod\Paymos\Api\Admin as AdminApi;
use Box\Mod\Paymos\Api\Guest as GuestApi;
use Box\Mod\Paymos\Controller\Admin as AdminController;
use Box\Mod\Paymos\Service as ModuleService;
use Paymos\Client;
use Paymos\Connect\DeviceConnectClient;
use Paymos\Http\HttpResponse;
use Paymos\Http\TransportInterface;
use Paymos\Plugin\InvoiceReplacementBlockedException;
use Paymos\Plugin\RefundStates;
use Paymos\Webhook\WebhookVerifier;
use PaymosFossBilling\CallbackProcessor;
use PaymosFossBilling\CheckoutService;
use PaymosFossBilling\Config;
use PaymosFossBilling\ConnectHandler;
use PaymosFossBilling\CredentialStore;
use PaymosFossBilling\DbDriverInterface;
use PaymosFossBilling\PdoDbDriver;
use PaymosFossBilling\PdoEventStore;
use PaymosFossBilling\Reconciler;
use PaymosFossBilling\RefundAdapter;
use PaymosFossBilling\RefundIntentStore;
use PaymosFossBilling\SnapshotStore;
use PaymosFossBilling\Translation;

final class QueueTransport implements TransportInterface
{
    /** @var array<int, HttpResponse> */
    private array $queue = [];

    /** @var array<int, array<string, mixed>> */
    private array $requests = [];

    /** @param array<string, mixed>|string $body @param array<string, string> $headers */
    public function enqueue(int $statusCode, $body = [], array $headers = []): void
    {
        $raw = is_string($body) ? $body : (string) json_encode($body);
        $this->queue[] = new HttpResponse($statusCode, $raw, $headers);
    }

    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeoutSeconds,
        ];
        if (empty($this->queue)) {
            throw new \RuntimeException("QueueTransport exhausted on {$method} {$url}");
        }
        return array_shift($this->queue);
    }

    /** @return array<int, array<string, mixed>> */
    public function requests(): array
    {
        return $this->requests;
    }
}

final class InMemoryDbDriver implements DbDriverInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $state = [];

    /** @var array<string, array<string, mixed>> */
    public array $events = [];

    /** @var array<string, array<string, mixed>> */
    public array $snapshots = [];

    /** @var array<string, array<string, mixed>> */
    public array $refundIntents = [];

    /** @var array<string, array<string, mixed>> */
    public array $externalRefunds = [];

    /** @var array<string, bool> */
    public array $existingTables = [];

    /** @var array<string, bool> */
    public array $locks = [];

    /** @var array<string, bool> */
    public array $forceLockBusy = [];

    public bool $activeTransaction = false;
    public int $ddlExecCount = 0;

    public function driverName(): string
    {
        return 'memory';
    }

    public function inTransaction(): bool
    {
        return $this->activeTransaction;
    }

    public function beginTransaction(): void
    {
        $this->activeTransaction = true;
    }

    public function commit(): void
    {
        $this->activeTransaction = false;
    }

    public function rollBack(): void
    {
        $this->activeTransaction = false;
    }

    public function tableExists(string $tableName): bool
    {
        return !empty($this->existingTables[$tableName]);
    }

    public function exec(string $sql): void
    {
        $this->ddlExecCount++;
        if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-zA-Z_][a-zA-Z0-9_]*)/i', $sql, $m) === 1) {
            $this->existingTables[$m[1]] = true;
        }
    }

    public function acquireNamedLock(string $name, int $timeoutSeconds = 10): bool
    {
        if (!empty($this->forceLockBusy[$name]) || !empty($this->locks[$name])) {
            return false;
        }
        $this->locks[$name] = true;
        return true;
    }

    public function isNamedLockOwned(string $name): bool
    {
        return !empty($this->locks[$name]);
    }

    public function releaseNamedLock(string $name): void
    {
        unset($this->locks[$name]);
    }

    public function insertUnique(string $sql, array $params = [], string $conflictTarget = ''): bool
    {
        $normalized = preg_replace('/\s+/', ' ', trim($sql));

        if (str_starts_with($normalized, 'INSERT INTO paymos_module_state ')) {
            $key = (string) $params[0];
            if (isset($this->state[$key])) {
                return false;
            }
            $this->state[$key] = [
                'state_key' => $key,
                'state_value' => (string) $params[1],
                'updated_at' => (int) $params[2],
            ];
            return true;
        }

        if (str_starts_with($normalized, 'INSERT INTO paymos_webhook_events ')) {
            $eventId = (string) $params[0];
            if (isset($this->events[$eventId])) {
                return false;
            }
            $this->events[$eventId] = [
                'event_id' => $eventId,
                'owner_token' => (string) $params[1],
                'state' => (string) $params[2],
                'expires_at' => (int) $params[3],
                'created_at' => (int) $params[4],
            ];
            return true;
        }

        if (str_starts_with($normalized, 'INSERT INTO paymos_invoice_snapshots ')) {
            $extId = (string) $params[0];
            if (isset($this->snapshots[$extId])) {
                return false;
            }
            $this->snapshots[$extId] = [
                'external_order_id' => $extId,
                'paymos_invoice_id' => (string) $params[1],
                'platform_ref' => (string) $params[2],
                'client_id' => $params[3],
                'payload_meta' => $params[4],
                'amount' => (string) $params[5],
                'invoice_total' => (string) $params[6],
                'currency' => (string) $params[7],
                'environment' => (string) $params[8],
                'project_id' => (string) $params[9],
                'payment_url' => (string) $params[10],
                'transaction_id' => $params[11],
                'status' => (string) $params[12],
                'expires_at' => $params[13] === null ? null : (int) $params[13],
                'renewal_attempt' => (int) $params[14],
                'final_status' => $params[15],
                'paid_late_noted' => $params[16],
                'created_at' => (int) $params[17],
                'updated_at' => (int) $params[18],
            ];
            return true;
        }

        if (str_starts_with($normalized, 'INSERT INTO paymos_refund_intents ')) {
            $opId = (string) $params[0];
            $installId = (string) $params[1];
            $reqToken = (string) $params[2];
            $extOrderId = (string) $params[3];
            $withdrawalId = $params[5];
            foreach ($this->refundIntents as $row) {
                if ($row['operation_id'] === $opId
                    || ($row['installation_id'] === $installId && $row['request_token'] === $reqToken)
                    || $row['external_order_id'] === $extOrderId
                    || ($withdrawalId !== null && $row['withdrawal_id'] === $withdrawalId)
                ) {
                    return false;
                }
            }
            $this->refundIntents[$opId] = [
                'operation_id' => $opId,
                'installation_id' => $installId,
                'request_token' => $reqToken,
                'external_order_id' => $extOrderId,
                'native_payment_key' => (string) $params[4],
                'withdrawal_id' => $withdrawalId,
                'payload' => (string) $params[6],
                'version' => (int) $params[7],
                'created_at' => (int) $params[8],
                'next_check' => (int) $params[9],
            ];
            return true;
        }

        if (str_starts_with($normalized, 'INSERT INTO paymos_external_refunds ')) {
            $wId = (string) $params[0];
            if (isset($this->externalRefunds[$wId])) {
                return false;
            }
            $this->externalRefunds[$wId] = [
                'withdrawal_id' => $wId,
                'native_payment_key' => (string) $params[1],
                'invoice_id' => (string) $params[2],
                'payload' => (string) $params[3],
                'updated_at' => (int) $params[4],
            ];
            return true;
        }

        throw new \RuntimeException('Unhandled insertUnique SQL: ' . $normalized);
    }

    public function execute(string $sql, array $params = []): int
    {
        $normalized = preg_replace('/\s+/', ' ', trim($sql));

        if ($normalized === 'UPDATE paymos_module_state SET state_value = ?, updated_at = ? WHERE state_key = ?') {
            $key = (string) $params[2];
            if (!isset($this->state[$key])) {
                return 0;
            }
            $this->state[$key]['state_value'] = (string) $params[0];
            $this->state[$key]['updated_at'] = (int) $params[1];
            return 1;
        }

        if ($normalized === 'DELETE FROM paymos_module_state WHERE state_key = ?') {
            $key = (string) $params[0];
            if (!isset($this->state[$key])) {
                return 0;
            }
            unset($this->state[$key]);
            return 1;
        }

        if (str_starts_with($normalized, 'DELETE FROM paymos_webhook_events WHERE expires_at < ? AND event_id IN (')) {
            $now = (int) $params[0];
            $deleted = 0;
            foreach (array_slice($params, 1) as $id) {
                $k = (string) $id;
                if (isset($this->events[$k]) && (int) $this->events[$k]['expires_at'] < $now) {
                    unset($this->events[$k]);
                    $deleted++;
                }
            }
            return $deleted;
        }

        if ($normalized === 'UPDATE paymos_webhook_events SET owner_token = ?, state = ?, expires_at = ?, created_at = ? WHERE event_id = ? AND state = ? AND expires_at <= ?') {
            $eventId = (string) $params[4];
            if (!isset($this->events[$eventId])) {
                return 0;
            }
            $row = $this->events[$eventId];
            if ($row['state'] !== (string) $params[5] || (int) $row['expires_at'] > (int) $params[6]) {
                return 0;
            }
            $this->events[$eventId]['owner_token'] = (string) $params[0];
            $this->events[$eventId]['state'] = (string) $params[1];
            $this->events[$eventId]['expires_at'] = (int) $params[2];
            $this->events[$eventId]['created_at'] = (int) $params[3];
            return 1;
        }

        if ($normalized === 'UPDATE paymos_webhook_events SET state = ?, expires_at = ? WHERE event_id = ? AND owner_token = ?') {
            $eventId = (string) $params[2];
            if (!isset($this->events[$eventId]) || $this->events[$eventId]['owner_token'] !== (string) $params[3]) {
                return 0;
            }
            $this->events[$eventId]['state'] = (string) $params[0];
            $this->events[$eventId]['expires_at'] = (int) $params[1];
            return 1;
        }

        if ($normalized === 'DELETE FROM paymos_webhook_events WHERE event_id = ? AND state = ? AND owner_token = ?') {
            $eventId = (string) $params[0];
            if (!isset($this->events[$eventId])
                || $this->events[$eventId]['state'] !== (string) $params[1]
                || $this->events[$eventId]['owner_token'] !== (string) $params[2]
            ) {
                return 0;
            }
            unset($this->events[$eventId]);
            return 1;
        }

        if (str_starts_with($normalized, 'UPDATE paymos_invoice_snapshots SET paymos_invoice_id = ?,')) {
            $extId = (string) $params[17];
            if (!isset($this->snapshots[$extId])) {
                return 0;
            }
            $createdAt = $this->snapshots[$extId]['created_at'];
            $this->snapshots[$extId] = [
                'external_order_id' => $extId,
                'paymos_invoice_id' => (string) $params[0],
                'platform_ref' => (string) $params[1],
                'client_id' => $params[2],
                'payload_meta' => $params[3],
                'amount' => (string) $params[4],
                'invoice_total' => (string) $params[5],
                'currency' => (string) $params[6],
                'environment' => (string) $params[7],
                'project_id' => (string) $params[8],
                'payment_url' => (string) $params[9],
                'transaction_id' => $params[10],
                'status' => (string) $params[11],
                'expires_at' => $params[12] === null ? null : (int) $params[12],
                'renewal_attempt' => (int) $params[13],
                'final_status' => $params[14],
                'paid_late_noted' => $params[15],
                'created_at' => $createdAt,
                'updated_at' => (int) $params[16],
            ];
            return 1;
        }

        if ($normalized === 'UPDATE paymos_invoice_snapshots SET status = ?, final_status = COALESCE(?, final_status), transaction_id = COALESCE(?, transaction_id), updated_at = ? WHERE paymos_invoice_id = ?') {
            $count = 0;
            foreach ($this->snapshots as &$row) {
                if ($row['paymos_invoice_id'] === (string) $params[4]) {
                    $row['status'] = (string) $params[0];
                    if ($params[1] !== null) {
                        $row['final_status'] = (string) $params[1];
                    }
                    if ($params[2] !== null) {
                        $row['transaction_id'] = (string) $params[2];
                    }
                    $row['updated_at'] = (int) $params[3];
                    $count++;
                }
            }
            unset($row);
            return $count;
        }

        if ($normalized === 'UPDATE paymos_invoice_snapshots SET status = ?, final_status = ?, transaction_id = COALESCE(?, transaction_id), updated_at = ? WHERE external_order_id = ?') {
            $extId = (string) $params[4];
            if (!isset($this->snapshots[$extId])) {
                return 0;
            }
            $this->snapshots[$extId]['status'] = (string) $params[0];
            $this->snapshots[$extId]['final_status'] = (string) $params[1];
            if ($params[2] !== null) {
                $this->snapshots[$extId]['transaction_id'] = (string) $params[2];
            }
            $this->snapshots[$extId]['updated_at'] = (int) $params[3];
            return 1;
        }

        if ($normalized === 'UPDATE paymos_invoice_snapshots SET payload_meta = ? WHERE external_order_id = ?') {
            $extId = (string) $params[1];
            if (!isset($this->snapshots[$extId])) {
                return 0;
            }
            $this->snapshots[$extId]['payload_meta'] = (string) $params[0];
            return 1;
        }

        if ($normalized === 'UPDATE paymos_invoice_snapshots SET paid_late_noted = ?, payload_meta = ?, updated_at = ? WHERE external_order_id = ?') {
            $extId = (string) $params[3];
            if (!isset($this->snapshots[$extId])) {
                return 0;
            }
            $this->snapshots[$extId]['paid_late_noted'] = (string) $params[0];
            $this->snapshots[$extId]['payload_meta'] = (string) $params[1];
            $this->snapshots[$extId]['updated_at'] = (int) $params[2];
            return 1;
        }

        if (str_starts_with($normalized, 'UPDATE paymos_refund_intents SET payload = ?, withdrawal_id = ?, version = ?')) {
            $hasNext = str_contains($normalized, ', next_check = ?');
            $opId = (string) ($hasNext ? $params[4] : $params[3]);
            $oldVersion = (int) ($hasNext ? $params[5] : $params[4]);
            if (!isset($this->refundIntents[$opId]) || (int) $this->refundIntents[$opId]['version'] !== $oldVersion) {
                return 0;
            }
            $this->refundIntents[$opId]['payload'] = (string) $params[0];
            $this->refundIntents[$opId]['withdrawal_id'] = $params[1];
            $this->refundIntents[$opId]['version'] = (int) $params[2];
            if ($hasNext) {
                $this->refundIntents[$opId]['next_check'] = (int) $params[3];
            }
            return 1;
        }

        if ($normalized === 'UPDATE paymos_external_refunds SET payload = ?, updated_at = ? WHERE withdrawal_id = ? AND native_payment_key = ?') {
            $wId = (string) $params[2];
            if (!isset($this->externalRefunds[$wId]) || $this->externalRefunds[$wId]['native_payment_key'] !== (string) $params[3]) {
                return 0;
            }
            $this->externalRefunds[$wId]['payload'] = (string) $params[0];
            $this->externalRefunds[$wId]['updated_at'] = (int) $params[1];
            return 1;
        }

        throw new \RuntimeException('Unhandled execute SQL: ' . $normalized);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $all = $this->fetchAll($sql, $params);
        return $all[0] ?? null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $normalized = preg_replace('/\s+/', ' ', trim($sql));

        if ($normalized === 'SELECT state_value FROM paymos_module_state WHERE state_key = ?') {
            $key = (string) $params[0];
            return isset($this->state[$key]) ? [['state_value' => $this->state[$key]['state_value']]] : [];
        }

        if ($normalized === 'SELECT state, expires_at FROM paymos_webhook_events WHERE event_id = ?') {
            $id = (string) $params[0];
            if (!isset($this->events[$id])) {
                return [];
            }
            return [[
                'state' => $this->events[$id]['state'],
                'expires_at' => $this->events[$id]['expires_at'],
            ]];
        }

        if (preg_match('/\ASELECT event_id FROM paymos_webhook_events WHERE expires_at < \? LIMIT ([0-9]+)\z/', $normalized, $m) === 1) {
            $now = (int) $params[0];
            $limit = (int) $m[1];
            $out = [];
            foreach ($this->events as $row) {
                if ((int) $row['expires_at'] < $now) {
                    $out[] = ['event_id' => $row['event_id']];
                    if (count($out) >= $limit) {
                        break;
                    }
                }
            }
            return $out;
        }

        if ($normalized === 'SELECT * FROM paymos_invoice_snapshots WHERE external_order_id = ?') {
            $ext = (string) $params[0];
            return isset($this->snapshots[$ext]) ? [$this->snapshots[$ext]] : [];
        }

        if ($normalized === 'SELECT * FROM paymos_invoice_snapshots WHERE paymos_invoice_id = ? ORDER BY updated_at DESC LIMIT 1') {
            $matches = array_values(array_filter($this->snapshots, static fn (array $r): bool => $r['paymos_invoice_id'] === (string) $params[0]));
            usort($matches, static fn (array $a, array $b): int => $b['updated_at'] <=> $a['updated_at']);
            return array_slice($matches, 0, 1);
        }

        if ($normalized === 'SELECT * FROM paymos_invoice_snapshots WHERE transaction_id = ? ORDER BY updated_at DESC LIMIT 1') {
            $matches = array_values(array_filter($this->snapshots, static fn (array $r): bool => $r['transaction_id'] === (string) $params[0]));
            usort($matches, static fn (array $a, array $b): int => $b['updated_at'] <=> $a['updated_at']);
            return array_slice($matches, 0, 1);
        }

        if ($normalized === 'SELECT * FROM paymos_invoice_snapshots WHERE platform_ref = ? ORDER BY renewal_attempt DESC, updated_at DESC LIMIT 1') {
            $matches = array_values(array_filter($this->snapshots, static fn (array $r): bool => $r['platform_ref'] === (string) $params[0]));
            usort($matches, static fn (array $a, array $b): int => ($b['renewal_attempt'] <=> $a['renewal_attempt']) ?: ($b['updated_at'] <=> $a['updated_at']));
            return array_slice($matches, 0, 1);
        }

        if ($normalized === "SELECT * FROM paymos_invoice_snapshots WHERE platform_ref = ? AND (status IN ('paid', 'paid_over') OR final_status IN ('paid', 'paid_over')) ORDER BY renewal_attempt DESC, updated_at DESC LIMIT 1") {
            $matches = array_values(array_filter($this->snapshots, static fn (array $r): bool => $r['platform_ref'] === (string) $params[0]
                && (in_array($r['status'], ['paid', 'paid_over'], true) || in_array($r['final_status'], ['paid', 'paid_over'], true))));
            usort($matches, static fn (array $a, array $b): int => ($b['renewal_attempt'] <=> $a['renewal_attempt']) ?: ($b['updated_at'] <=> $a['updated_at']));
            return array_slice($matches, 0, 1);
        }

        if (preg_match('/\ASELECT \* FROM paymos_invoice_snapshots WHERE updated_at >= \? AND status NOT IN/', $normalized) === 1) {
            $cutoff = (int) $params[0];
            $hasCursor = str_contains($normalized, 'AND (updated_at > ? OR (updated_at = ? AND external_order_id > ?))');
            $cursor = $hasCursor ? array_slice($params, -3) : null;
            $excluded = $hasCursor ? array_slice($params, 1, -3) : array_slice($params, 1);
            preg_match('/LIMIT ([0-9]+)\z/', $normalized, $lm);
            $limit = isset($lm[1]) ? (int) $lm[1] : 50;
            $matches = array_values(array_filter($this->snapshots, static function (array $r) use ($cutoff, $excluded, $cursor): bool {
                if ($cursor !== null
                    && !((int) $r['updated_at'] > (int) $cursor[0]
                        || ((int) $r['updated_at'] === (int) $cursor[1] && strcmp((string) $r['external_order_id'], (string) $cursor[2]) > 0))
                ) {
                    return false;
                }
                return (int) $r['updated_at'] >= $cutoff
                    && !in_array($r['status'], $excluded, true)
                    && ($r['final_status'] === null || $r['final_status'] === '');
            }));
            usort($matches, static fn (array $a, array $b): int => ($a['updated_at'] <=> $b['updated_at']) ?: strcmp((string) $a['external_order_id'], (string) $b['external_order_id']));
            return array_slice($matches, 0, $limit);
        }

        if ($normalized === 'SELECT payload FROM paymos_refund_intents WHERE operation_id = ?') {
            $id = (string) $params[0];
            return isset($this->refundIntents[$id]) ? [['payload' => $this->refundIntents[$id]['payload']]] : [];
        }

        if ($normalized === 'SELECT payload FROM paymos_refund_intents WHERE installation_id = ? AND request_token = ?') {
            $matches = [];
            foreach ($this->refundIntents as $row) {
                if ($row['installation_id'] === (string) $params[0] && $row['request_token'] === (string) $params[1]) {
                    $matches[] = ['payload' => $row['payload']];
                }
            }
            return $matches;
        }

        if ($normalized === 'SELECT payload FROM paymos_refund_intents WHERE external_order_id = ?') {
            $matches = [];
            foreach ($this->refundIntents as $row) {
                if ($row['external_order_id'] === (string) $params[0]) {
                    $matches[] = ['payload' => $row['payload']];
                }
            }
            return $matches;
        }

        if ($normalized === 'SELECT payload FROM paymos_refund_intents WHERE withdrawal_id = ?') {
            $matches = [];
            foreach ($this->refundIntents as $row) {
                if ($row['withdrawal_id'] === (string) $params[0]) {
                    $matches[] = ['payload' => $row['payload']];
                }
            }
            return $matches;
        }

        if ($normalized === 'SELECT payload FROM paymos_refund_intents WHERE native_payment_key = ? ORDER BY created_at ASC, operation_id ASC') {
            $rows = array_values(array_filter($this->refundIntents, static fn (array $r): bool => $r['native_payment_key'] === (string) $params[0]));
            usort($rows, static fn (array $a, array $b): int => ($a['created_at'] <=> $b['created_at']) ?: strcmp($a['operation_id'], $b['operation_id']));
            return array_map(static fn (array $r): array => ['payload' => $r['payload']], $rows);
        }

        if (preg_match('/\ASELECT payload FROM paymos_refund_intents WHERE next_check <= \? AND operation_id > \? ORDER BY operation_id ASC LIMIT ([0-9]+)\z/', $normalized, $m) === 1) {
            $now = (int) $params[0];
            $cursor = (string) $params[1];
            $limit = (int) $m[1];
            $rows = array_values(array_filter($this->refundIntents, static fn (array $r): bool => (int) $r['next_check'] <= $now && strcmp($r['operation_id'], $cursor) > 0));
            usort($rows, static fn (array $a, array $b): int => strcmp($a['operation_id'], $b['operation_id']));
            $rows = array_slice($rows, 0, $limit);
            return array_map(static fn (array $r): array => ['payload' => $r['payload']], $rows);
        }

        if ($normalized === 'SELECT operation_id FROM paymos_refund_intents LIMIT 1') {
            $first = reset($this->refundIntents);
            return is_array($first) ? [['operation_id' => $first['operation_id']]] : [];
        }

        if ($normalized === 'SELECT withdrawal_id FROM paymos_external_refunds LIMIT 1') {
            $first = reset($this->externalRefunds);
            return is_array($first) ? [['withdrawal_id' => $first['withdrawal_id']]] : [];
        }

        if ($normalized === 'SELECT withdrawal_id, payload FROM paymos_external_refunds WHERE native_payment_key = ? ORDER BY updated_at ASC') {
            $rows = array_values(array_filter($this->externalRefunds, static fn (array $r): bool => $r['native_payment_key'] === (string) $params[0]));
            usort($rows, static fn (array $a, array $b): int => $a['updated_at'] <=> $b['updated_at']);
            return array_map(static fn (array $r): array => ['withdrawal_id' => $r['withdrawal_id'], 'payload' => $r['payload']], $rows);
        }

        if ($normalized === 'SELECT payload FROM paymos_external_refunds WHERE withdrawal_id = ?') {
            $wId = (string) $params[0];
            return isset($this->externalRefunds[$wId]) ? [['payload' => $this->externalRefunds[$wId]['payload']]] : [];
        }

        throw new \RuntimeException('Unhandled fetchAll SQL: ' . $normalized);
    }
}

final class TestRunner
{
    public int $passed = 0;
    public int $failed = 0;

    public function test(string $name, callable $fn): void
    {
        try {
            $fn();
            $this->passed++;
            echo "[PASS] {$name}\n";
        } catch (\Throwable $e) {
            $this->failed++;
            echo "[FAIL] {$name}: {$e->getMessage()} ({$e->getFile()}:{$e->getLine()})\n";
        }
    }

    /** @param mixed $expected @param mixed $actual */
    public static function assertEquals($expected, $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(
                ($msg !== '' ? $msg . ' — ' : '')
                . 'Expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true)
            );
        }
    }

    public static function assertTrue(bool $cond, string $msg = 'Assertion failed'): void
    {
        if (!$cond) {
            throw new \RuntimeException($msg);
        }
    }

    public static function assertContains(string $needle, string $haystack, string $msg = ''): void
    {
        if (strpos($haystack, $needle) === false) {
            throw new \RuntimeException(($msg !== '' ? $msg . ' — ' : '') . "Expected string to contain '{$needle}'.");
        }
    }

    public static function assertNotContains(string $needle, string $haystack, string $msg = ''): void
    {
        if (strpos($haystack, $needle) !== false) {
            throw new \RuntimeException(($msg !== '' ? $msg . ' — ' : '') . "Expected string NOT to contain '{$needle}'.");
        }
    }

    public static function assertThrows(string $class, callable $fn, ?string $messageSubstring = null): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!($e instanceof $class)) {
                throw new \RuntimeException("Expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
            }
            if ($messageSubstring !== null && strpos($e->getMessage(), $messageSubstring) === false) {
                throw new \RuntimeException("Expected exception message containing '{$messageSubstring}', got '{$e->getMessage()}'.");
            }
            return $e;
        }
        throw new \RuntimeException("Expected exception {$class} was not thrown.");
    }
}

function sampleCredentials(bool $refundsEnabled = true, bool $withCapability = true): array
{
    $caps = $withCapability ? ['invoice_refunds_v1'] : [];
    $ver = $withCapability ? 1 : null;
    return [
        'sandbox' => [
            'base_url' => 'https://api.paymos.io',
            'api_key' => 'pk_test_fb_sandbox_key_123456',
            'api_secret' => 'sk_test_fb_sandbox_secret_123456',
            'project_id' => 'prj_sandbox_fossbilling_001',
            'webhook_secret' => 'whsec_sandbox_fossbilling_secret_001',
            'capabilities' => $caps,
            'refund_contract_version' => $ver,
            'refunds_enabled' => $refundsEnabled,
        ],
        'live' => [
            'base_url' => 'https://api.paymos.io',
            'api_key' => 'pk_live_fb_live_key_987654',
            'api_secret' => 'sk_live_fb_live_secret_987654',
            'project_id' => 'prj_live_fossbilling_002',
            'webhook_secret' => 'whsec_live_fossbilling_secret_002',
            'capabilities' => $caps,
            'refund_contract_version' => $ver,
            'refunds_enabled' => $refundsEnabled,
        ],
    ];
}

function signWebhook(string $secret, string $rawBody, int $timestamp, ?string $secondarySecret = null): string
{
    $sig1 = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    $header = 't=' . $timestamp . ',v1=' . $sig1;
    if ($secondarySecret !== null) {
        $sig2 = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secondarySecret);
        $header .= ',v1=' . $sig2;
    }
    return $header;
}

$runner = new TestRunner();

// ---------------------------------------------------------------------------
// 1. PHP syntax lint, manifest.json schema v1, canonical layout & zero-leak
// ---------------------------------------------------------------------------

$runner->test('php -l syntax check passes for all PHP files in modules/ and library/', function () use ($pluginRoot): void {
    $dirs = [$pluginRoot . '/modules', $pluginRoot . '/library'];
    $count = 0;
    foreach ($dirs as $dir) {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $count++;
                $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1';
                $out = [];
                $exit = 0;
                exec($cmd, $out, $exit);
                TestRunner::assertEquals(0, $exit, 'Syntax error in ' . $file->getPathname() . ': ' . implode("\n", $out));
            }
        }
    }
    TestRunner::assertTrue($count >= 25, 'Expected at least 25 PHP source/locale files, found ' . $count);
});

$runner->test('manifest.json conforms to schemas.fossbilling.net/manifest/v1.json and Paymos-labs/fossbilling', function () use ($pluginRoot): void {
    $path = $pluginRoot . '/modules/Paymos/manifest.json';
    TestRunner::assertTrue(is_file($path), 'modules/Paymos/manifest.json must exist');
    $json = json_decode((string) file_get_contents($path), true);
    TestRunner::assertTrue(is_array($json), 'manifest.json must decode to array');

    TestRunner::assertEquals('https://schemas.fossbilling.net/manifest/v1.json', $json['$schema'] ?? null);
    TestRunner::assertEquals('mod', $json['type'] ?? null);
    TestRunner::assertEquals('paymos', $json['id'] ?? null);
    TestRunner::assertTrue(
        preg_match('/^\d+\.\d+\.\d+$/', (string) ($json['version'] ?? '')) === 1,
        'manifest.json version must be valid SemVer'
    );
    TestRunner::assertEquals('Paymos Labs', $json['author'] ?? null);
    TestRunner::assertEquals('https://paymos.io', $json['author_url'] ?? null);
    TestRunner::assertEquals('https://github.com/Paymos-labs/fossbilling', $json['homepage_url'] ?? null);
    TestRunner::assertEquals('8.1.0', $json['minimum_php_version'] ?? null);
    TestRunner::assertEquals('>=8.1', $json['requires']['php'] ?? null);
    TestRunner::assertEquals('MIT', $json['license'] ?? null);
});

$runner->test('Legacy root MVP files are deleted, Paymos.png is in adapter directory, and docs/Twig have zero leaks', function () use ($pluginRoot): void {
    $deleted = [
        'Paymos.php', 'Paymos.png', 'manifest.json', 'scripts/package.py', 'dist/Paymos.zip',
        'src/Autoloader.php', 'src/Config.php', 'src/DbDriverInterface.php', 'src/GatewayService.php',
        'src/PdoDbDriver.php', 'src/PdoEventStore.php', 'src/SnapshotStore.php',
    ];
    foreach ($deleted as $rel) {
        TestRunner::assertTrue(!file_exists($pluginRoot . '/' . $rel), "Legacy file must be deleted: {$rel}");
    }

    $pngPath = $pluginRoot . '/library/Payment/Adapter/Paymos/Paymos.png';
    TestRunner::assertTrue(is_file($pngPath), 'Adapter icon Paymos.png must exist');
    $pngHeader = substr((string) file_get_contents($pngPath), 0, 8);
    TestRunner::assertEquals("\x89PNG\r\n\x1a\n", $pngHeader, 'Paymos.png must be a valid PNG');

    // Adapter getConfig() has zero manual secret fields
    $cfg = Payment_Adapter_Paymos::getConfig();
    TestRunner::assertEquals([], Payment_Adapter_Paymos::getSecretFields());
    foreach (['api_key', 'api_secret', 'project_id', 'webhook_secret', 'base_url'] as $forbidden) {
        TestRunner::assertTrue(!array_key_exists($forbidden, $cfg['form']), "Adapter getConfig() must NOT expose {$forbidden}");
    }
    // Mode and button text are set on the Extensions -> Paymos page only (CMSP-20).
    TestRunner::assertTrue(!array_key_exists('mode', $cfg['form']), 'Adapter getConfig() must not expose a second mode');
    TestRunner::assertTrue(!array_key_exists('button_text', $cfg['form']), 'Adapter getConfig() must not expose a second button_text');

    // Twig templates contain zero manual credential or unmanaged refund inputs
    $settingsTwig = (string) file_get_contents($pluginRoot . '/modules/Paymos/html_admin/mod_paymos_settings.html.twig');
    foreach (['api_key', 'api_secret', 'webhook_secret', 'project_id', 'type="password"'] as $forbidden) {
        TestRunner::assertNotContains($forbidden, $settingsTwig, 'mod_paymos_settings.html.twig must not contain ' . $forbidden);
    }
    TestRunner::assertContains('paymos-connect-button', $settingsTwig);
    TestRunner::assertContains('CSRFToken', $settingsTwig);

    $refundsTwig = (string) file_get_contents($pluginRoot . '/modules/Paymos/html_admin/mod_paymos_refunds.html.twig');
    foreach (['destination_address', 'crypto_amount', 'partial_amount', 'network'] as $forbidden) {
        TestRunner::assertNotContains($forbidden, $refundsTwig, 'mod_paymos_refunds.html.twig must not contain ' . $forbidden);
    }
    TestRunner::assertContains('data-mode="full"', $refundsTwig);
    TestRunner::assertContains('data-mode="overpayment"', $refundsTwig);

    // README, CHANGELOG, LICENSE hygiene
    $readme = (string) file_get_contents($pluginRoot . '/README.md');
    TestRunner::assertContains('- PHP 8.1+', $readme);
    TestRunner::assertContains('https://github.com/Paymos-labs/fossbilling', $readme);
    TestRunner::assertContains('/index.php?_url=/api/guest/paymos/webhook', $readme);
    TestRunner::assertTrue(preg_match('/github\.com\/(?!Paymos-labs\/)/i', $readme) !== 1, 'README.md must reference only Paymos-labs GitHub URLs');
    TestRunner::assertTrue(stripos($readme, 'instant settlement') === false, 'README.md must not claim instant settlement');

    $changelog = (string) file_get_contents($pluginRoot . '/CHANGELOG.md');
    TestRunner::assertContains('## [Unreleased]', $changelog);

    $license = (string) file_get_contents($pluginRoot . '/LICENSE');
    TestRunner::assertContains('MIT License', $license);
    TestRunner::assertContains('Copyright (c) 2026 Paymos Labs', $license);
});

// ---------------------------------------------------------------------------
// 2. Contract 1: CredentialStore & Config
// ---------------------------------------------------------------------------

$runner->test('Contract 1: CredentialStore seals AES-256-GCM over salt, rejects missing/tampered salt and bad fields', function (): void {
    $db = new InMemoryDbDriver();
    $now = 1760000000;
    $clock = static function () use (&$now): int {
        return $now;
    };

    $store = CredentialStore::fromDi(['config' => ['salt' => 'fossbilling-install-salt-0123456789abcdef']], $db, $clock);
    TestRunner::assertEquals([], $store->loadCredentials());

    $creds = sampleCredentials();
    $store->saveCredentials($creds);
    TestRunner::assertTrue($store->isConnected());

    // Stored ciphertext must NEVER contain plaintext secrets
    $rawStored = $db->state['credentials_v1']['state_value'] ?? '';
    TestRunner::assertNotContains('sk_test_fb_sandbox_secret_123456', $rawStored);
    TestRunner::assertNotContains('whsec_live_fossbilling_secret_002', $rawStored);

    // Round-trip matches normalized credentials
    $loaded = $store->loadCredentials();
    TestRunner::assertEquals('prj_sandbox_fossbilling_001', $loaded['sandbox']['project_id']);
    TestRunner::assertEquals('prj_live_fossbilling_002', $loaded['live']['project_id']);

    // refundConfig verifies capabilities & contract version
    $refCfg = $store->refundConfig('live');
    TestRunner::assertEquals('prj_live_fossbilling_002', $refCfg['project_id']);
    TestRunner::assertEquals(['invoice_refunds_v1'], $refCfg['capabilities']);
    TestRunner::assertEquals(1, $refCfg['refund_contract_version']);
    TestRunner::assertTrue($refCfg['refunds_enabled']);

    // Wrong salt fails closed
    $wrongSaltStore = new CredentialStore($db, 'different-salt-value', $clock);
    TestRunner::assertThrows(\RuntimeException::class, static fn () => $wrongSaltStore->loadCredentials());

    // Empty salt fails closed
    $emptySaltStore = CredentialStore::fromDi(['config' => []], $db, $clock);
    TestRunner::assertThrows(\RuntimeException::class, static fn () => $emptySaltStore->saveCredentials($creds), 'salt');

    // Invalid project_id / untrusted base_url / payout key rk_ fail closed
    $badPrj = $creds;
    $badPrj['sandbox']['project_id'] = 'not_a_prj_id';
    TestRunner::assertThrows(\InvalidArgumentException::class, static fn () => $store->saveCredentials($badPrj));

    $badUrl = $creds;
    $badUrl['live']['base_url'] = 'https://evil.example';
    TestRunner::assertThrows(\InvalidArgumentException::class, static fn () => $store->saveCredentials($badUrl));

    $payoutKey = $creds;
    $payoutKey['live']['api_key'] = 'rk_live_payout_key_123456';
    TestRunner::assertThrows(\InvalidArgumentException::class, static fn () => $store->saveCredentials($payoutKey));

    // Device state auto-expires cleanly
    $store->saveState(['device_code' => 'dc_secret_123456', 'expires_in' => 60]);
    TestRunner::assertEquals('dc_secret_123456', $store->loadState()['device_code']);
    $now += 61;
    TestRunner::assertEquals([], $store->loadState());

    // saveSettings rejects manual credential fields
    TestRunner::assertThrows(\InvalidArgumentException::class, static fn () => $store->saveSettings(['mode' => 'live', 'api_secret' => 'sk_live_x']));
    $store->saveSettings(['mode' => 'live', 'button_text' => 'Pay Crypto']);
    TestRunner::assertEquals(['mode' => 'live', 'button_text' => 'Pay Crypto'], $store->loadSettings());

    // Secret redaction
    $cfg = Config::fromCredentialStore($store);
    $redacted = $cfg->redact('Error with sk_live_fb_live_secret_987654 and whsec_sandbox_fossbilling_secret_001 and dc_abc123456');
    TestRunner::assertNotContains('sk_live_fb_live_secret_987654', $redacted);
    TestRunner::assertNotContains('whsec_sandbox_fossbilling_secret_001', $redacted);
    TestRunner::assertNotContains('dc_abc123456', $redacted);
});

// ---------------------------------------------------------------------------
// 3. Contract 2: ConnectHandler & Admin API auth/CSRF
// ---------------------------------------------------------------------------

$runner->test('Contract 2: ConnectHandler enforces HTTPS, admin auth + CSRF, plugin & source_url binding, and atomic Connect', function (): void {
    $db = new InMemoryDbDriver();
    $salt = 'fossbilling-salt-connect-test';
    $store = new CredentialStore($db, $salt);

    // Non-HTTPS rejected before any network request
    $transport = new QueueTransport();
    $handler = new ConnectHandler($store, static function (): void {}, new DeviceConnectClient('https://app.paymos.io', $transport));
    TestRunner::assertThrows(\InvalidArgumentException::class, static fn () => $handler->start('http://billing.example'), 'HTTPS');
    TestRunner::assertEquals(0, count($transport->requests()));

    // Admin API auth & CSRF guards
    $diUnauth = [
        'config' => ['salt' => $salt, 'url' => 'https://billing.example'],
        'auth' => new class { public function isAdminLoggedIn(): bool { return false; } },
        'session' => new class { public function get(string $k): string { return 'csrf-valid-123'; } },
    ];
    $adminApiUnauth = new AdminApi();
    $adminApiUnauth->setDi($diUnauth);
    $adminApiUnauth->setDbDriver($db);
    TestRunner::assertThrows(\RuntimeException::class, static fn () => $adminApiUnauth->connect_start(['CSRFToken' => 'csrf-valid-123']), 'access_denied');

    $diAuth = [
        'config' => ['salt' => $salt, 'url' => 'https://billing.example'],
        'auth' => new class { public function isAdminLoggedIn(): bool { return true; } },
        'session' => new class { public function get(string $k): string { return 'csrf-valid-123'; } },
    ];
    $adminApi = new AdminApi();
    $adminApi->setDi($diAuth);
    $adminApi->setDbDriver($db);
    $adminApi->setCredentialStore($store);
    $adminApi->setConnectClient(new DeviceConnectClient('https://app.paymos.io', $transport));

    // Wrong CSRF token rejected
    TestRunner::assertThrows(\RuntimeException::class, static fn () => $adminApi->connect_start(['CSRFToken' => 'wrong']), 'csrf_invalid');

    // Happy Connect flow: start -> pending -> connected
    $transport->enqueue(200, [
        'device_code' => 'dc_device_secret_999',
        'user_code' => 'ABCD-EFGH',
        'verification_uri_complete' => 'https://app.paymos.io/connect/approve?code=ABCD-EFGH',
        'expires_in' => 600,
        'interval' => 5,
    ]);
    $startRes = $adminApi->connect_start(['CSRFToken' => 'csrf-valid-123', 'return_url' => 'https://billing.example/admin/paymos']);
    TestRunner::assertEquals('ABCD-EFGH', $startRes['user_code']);
    TestRunner::assertTrue(!isset($startRes['device_code']), 'device_code must never be returned to browser');
    TestRunner::assertContains('cms=fossbilling', $startRes['verification_url']);
    TestRunner::assertContains('source_url=' . rawurlencode('https://billing.example'), $startRes['verification_url']);

    // Pending poll
    $transport->enqueue(400, ['error' => 'authorization_pending']);
    $pending = $adminApi->connect_poll(['CSRFToken' => 'csrf-valid-123']);
    TestRunner::assertEquals(['status' => 'authorization_pending'], $pending);

    // Mismatched plugin rejects and clears state without saving credentials
    $transport->enqueue(200, [
        'paymos_config' => [
            'plugin' => 'whmcs',
            'source_url' => 'https://billing.example',
            'environments' => sampleCredentials(),
        ],
    ]);
    TestRunner::assertThrows(\RuntimeException::class, static fn () => $adminApi->connect_poll(['CSRFToken' => 'csrf-valid-123']), 'does not match');
    TestRunner::assertTrue(!$store->isConnected(), 'Credentials must not be saved on plugin mismatch');
    TestRunner::assertEquals([], $store->loadState(), 'State must be cleared on mismatch');

    // Start again + mismatched source_url rejects
    $transport->enqueue(200, [
        'device_code' => 'dc_device_secret_888',
        'user_code' => 'WXYZ-1234',
        'verification_uri_complete' => 'https://app.paymos.io/connect/approve?code=WXYZ-1234',
        'expires_in' => 600,
        'interval' => 5,
    ]);
    $adminApi->connect_start(['CSRFToken' => 'csrf-valid-123']);
    $transport->enqueue(200, [
        'paymos_config' => [
            'plugin' => 'fossbilling',
            'source_url' => 'https://other-site.example',
            'environments' => sampleCredentials(),
        ],
    ]);
    TestRunner::assertThrows(\RuntimeException::class, static fn () => $adminApi->connect_poll(['CSRFToken' => 'csrf-valid-123']), 'does not match');
    TestRunner::assertTrue(!$store->isConnected(), 'Credentials must not be saved on source_url mismatch');

    // Start again + valid approval -> saves Sandbox + Live atomically
    $transport->enqueue(200, [
        'device_code' => 'dc_device_secret_777',
        'user_code' => 'GOOD-9999',
        'verification_uri_complete' => 'https://app.paymos.io/connect/approve?code=GOOD-9999',
        'expires_in' => 600,
        'interval' => 5,
    ]);
    $adminApi->connect_start(['CSRFToken' => 'csrf-valid-123']);
    $transport->enqueue(200, [
        'paymos_config' => [
            'plugin' => 'fossbilling',
            'source_url' => 'https://billing.example/',
            'environments' => sampleCredentials(),
        ],
    ]);
    $done = $adminApi->connect_poll(['CSRFToken' => 'csrf-valid-123']);
    TestRunner::assertEquals(['status' => 'connected'], $done);
    TestRunner::assertTrue($store->isConnected());
    TestRunner::assertEquals([], $store->loadState());

    $status = $adminApi->status([]);
    TestRunner::assertTrue($status['connected']);
    TestRunner::assertTrue($status['sandbox_configured']);
    TestRunner::assertTrue($status['live_configured']);
    TestRunner::assertTrue($status['refunds_capable']);
    TestRunner::assertEquals('https://billing.example/index.php?_url=/api/guest/paymos/webhook', $status['callback_url']);
    $statusJson = (string) json_encode($status);
    TestRunner::assertNotContains('sk_test_', $statusJson);
    TestRunner::assertNotContains('whsec_', $statusJson);
    TestRunner::assertNotContains('pk_live_', $statusJson);
});

// ---------------------------------------------------------------------------
// 4. Contract 3: CheckoutService (STATUS_CREATING, recovery, products[], replacement, paid_late_noted)
// ---------------------------------------------------------------------------

$runner->test('Contract 3: CheckoutService persists STATUS_CREATING before POST, recovers lost response, sends products[], and enforces fail-closed replacement', function (): void {
    $db = new InMemoryDbDriver();
    $now = 1760000000;
    $clock = static function () use (&$now): int {
        return $now;
    };
    $credStore = new CredentialStore($db, 'salt-checkout', $clock);
    $credStore->saveCredentials(sampleCredentials());
    $config = Config::fromCredentialStore($credStore, ['mode' => 'sandbox']);
    $snapshots = new SnapshotStore($db, $clock);

    // 1. Clean checkout with 2 valid lines + 1 excluded discount line
    $transport = new QueueTransport();
    $checkout = new CheckoutService(
        $config,
        $snapshots,
        static fn (Config $c, ?string $env): Client => new Client($c->clientConfig($env), $transport),
        null,
        $clock
    );

    $transport->enqueue(201, [
        'invoice_id' => 'inv_fb_100_0',
        'payment_url' => 'https://checkout.paymos.io/inv_fb_100_0',
        'status' => 'awaiting_client',
        'expires_at' => $now + 1800,
    ]);

    $invoice = [
        'id' => 100,
        'total' => '49.90',
        'currency' => 'USD',
        'client' => ['id' => 42, 'email' => 'buyer@example.com'],
        'lines' => [
            ['title' => 'VPS Pro Plan', 'description' => '4 vCPU / 8GB RAM', 'quantity' => 1, 'price' => '40.00', 'type' => 'order'],
            ['title' => 'Dedicated IPv4', 'quantity' => 1, 'price' => '9.90', 'type' => 'custom'],
            ['title' => 'Promo Discount', 'quantity' => 1, 'price' => '-5.00', 'type' => 'discount'],
        ],
    ];

    $res = $checkout->createCheckout($invoice);
    TestRunner::assertEquals('inv_fb_100_0', $res['paymos_invoice_id']);
    TestRunner::assertEquals('https://checkout.paymos.io/inv_fb_100_0', $res['payment_url']);

    $sentReq = $transport->requests()[0];
    $sentBody = json_decode($sentReq['body'], true);
    TestRunner::assertEquals('prj_sandbox_fossbilling_001', $sentBody['project_id']);
    TestRunner::assertEquals('49.9', $sentBody['amount']);
    TestRunner::assertEquals('USD', $sentBody['currency']);
    TestRunner::assertEquals('fossbilling_100_0', $sentBody['external_order_id']);
    TestRunner::assertEquals('42', $sentBody['client_id']);
    TestRunner::assertTrue(!isset($sentBody['merchant_id']), 'Must NEVER send merchant_id');
    TestRunner::assertTrue(!isset($sentBody['lifetime']) && !isset($sentBody['ttl']) && !isset($sentBody['expires_in']), 'Must NEVER send TTL/lifetime');
    TestRunner::assertEquals(2, count($sentBody['products']));
    TestRunner::assertEquals('VPS Pro Plan', $sentBody['products'][0]['title']);
    TestRunner::assertEquals('40', $sentBody['products'][0]['unit_amount']);
    TestRunner::assertEquals('9.9', $sentBody['products'][1]['unit_amount']);

    // 2. Crash recovery: simulate STATUS_CREATING left behind when POST response was lost
    $snapshots->save([
        'external_order_id' => 'fossbilling_200_0',
        'paymos_invoice_id' => 'pending:fossbilling_200_0',
        'platform_ref' => '200',
        'client_id' => '77',
        'amount' => '19.5',
        'invoice_total' => '19.5',
        'currency' => 'EUR',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'payment_url' => '',
        'status' => CheckoutService::STATUS_CREATING,
        'renewal_attempt' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $transport->enqueue(200, [
        'invoice_id' => 'inv_fb_200_recovered',
        'payment_url' => 'https://checkout.paymos.io/inv_fb_200_recovered',
        'status' => 'awaiting_client',
        'expires_at' => $now + 1800,
    ]);
    // Live GET check for reuse after binding
    $transport->enqueue(200, [
        'invoice_id' => 'inv_fb_200_recovered',
        'status' => 'awaiting_client',
        'expires_at' => $now + 1800,
    ]);

    $recovered = $checkout->createCheckout([
        'id' => 200,
        'total' => '19.50',
        'currency' => 'EUR',
        'client_id' => 77,
        'lines' => [
            ['title' => 'Domain Renewal', 'quantity' => 1, 'price' => '19.50'],
        ],
    ]);
    TestRunner::assertEquals('inv_fb_200_recovered', $recovered['paymos_invoice_id']);
    $replayBody = json_decode($transport->requests()[1]['body'], true);
    TestRunner::assertEquals('fossbilling_200_0', $replayBody['external_order_id'], 'Recovery must replay exact external_order_id');
    TestRunner::assertEquals('19.5', $replayBody['amount']);

    // 3. Amount changed -> InvoiceReplacement::close() cancels old invoice cleanly -> new invoice with renew_count=1
    $transport->enqueue(200, [
        'invoice_id' => 'inv_fb_100_0',
        'status' => 'cancelled',
    ]);
    $transport->enqueue(201, [
        'invoice_id' => 'inv_fb_100_1',
        'payment_url' => 'https://checkout.paymos.io/inv_fb_100_1',
        'status' => 'awaiting_client',
        'expires_at' => $now + 1800,
    ]);
    $invoice['total'] = '55.00';
    $invoice['lines'] = []; // amount-only fallback when lines empty
    $replaced = $checkout->createCheckout($invoice);
    TestRunner::assertEquals('inv_fb_100_1', $replaced['paymos_invoice_id']);
    TestRunner::assertEquals('fossbilling_100_1', $replaced['external_order_id']);
    $lastBody = json_decode($transport->requests()[count($transport->requests()) - 1]['body'], true);
    TestRunner::assertTrue(!isset($lastBody['products']), 'Empty lines must fall back to amount-only');

    // 4. Amount changed while old invoice is still awaiting_payment (409 on cancel + GET awaiting_payment) -> fail-closed!
    $transport->enqueue(409, ['code' => 'invoice_cannot_be_cancelled', 'detail' => 'Already confirmed']);
    $transport->enqueue(200, [
        'invoice_id' => 'inv_fb_100_1',
        'status' => 'awaiting_payment',
    ]);
    $invoice['total'] = '60.00';
    TestRunner::assertThrows(InvoiceReplacementBlockedException::class, static fn () => $checkout->createCheckout($invoice));

    // 5. paid_late_noted blocks any replacement immediately without calling Paymos
    $snapshots->recordPaidLateNote('fossbilling_100_1', 'evt_late_1', $now);
    $beforeReqCount = count($transport->requests());
    TestRunner::assertThrows(InvoiceReplacementBlockedException::class, static fn () => $checkout->createCheckout($invoice));
    TestRunner::assertEquals($beforeReqCount, count($transport->requests()), 'paid_late_noted must block locally without extra HTTP');

    // 6. Invalid product line (unit_amount=0 or >50 items) falls back to amount-only
    TestRunner::assertEquals(null, CheckoutService::extractProducts([['title' => 'Zero', 'quantity' => 1, 'price' => '0']]));
    $tooMany = array_fill(0, 51, ['title' => 'Item', 'quantity' => 1, 'price' => '1.00']);
    TestRunner::assertEquals(null, CheckoutService::extractProducts($tooMany));
});

// ---------------------------------------------------------------------------
// 5. Contract 4: Api/Guest::webhook(), CallbackProcessor (Doctrine 2.0 + RedBean 0.7),
//    MultiEnvironmentWebhookVerifier, dual-v1, reverse-verify, AmountGuard, rollback, paid_late, Reconciler & Cron
// ---------------------------------------------------------------------------

$runner->test('Contract 4: Guest::webhook() emits exact HTTP 401/200/409/400, verifies Sandbox+Live dual-v1, settles Doctrine 2.0 & RedBean 0.7', function (): void {
    $db = new InMemoryDbDriver();
    $now = 1760000500;
    $clock = static function () use (&$now): int {
        return $now;
    };
    $salt = 'salt-webhook-test';
    $credStore = new CredentialStore($db, $salt, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);

    // Seed Sandbox snapshot for Doctrine 2.0 invoice #501
    $snapshots->save([
        'external_order_id' => 'fossbilling_501_0',
        'paymos_invoice_id' => 'inv_sbx_501',
        'platform_ref' => '501',
        'client_id' => '11',
        'amount' => '100',
        'invoice_total' => '100',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'payment_url' => 'https://checkout.paymos.io/inv_sbx_501',
        'status' => 'awaiting_payment',
        'renewal_attempt' => 0,
        'created_at' => $now - 60,
        'updated_at' => $now - 60,
    ]);

    // Seed Live snapshot for RedBean 0.7 invoice #502
    $snapshots->save([
        'external_order_id' => 'fossbilling_502_0',
        'paymos_invoice_id' => 'inv_live_502',
        'platform_ref' => '502',
        'client_id' => '22',
        'amount' => '250.5',
        'invoice_total' => '250.5',
        'currency' => 'EUR',
        'environment' => 'live',
        'project_id' => 'prj_live_fossbilling_002',
        'payment_url' => 'https://checkout.paymos.io/inv_live_502',
        'status' => 'awaiting_payment',
        'renewal_attempt' => 0,
        'created_at' => $now - 60,
        'updated_at' => $now - 60,
    ]);

    // Mock Doctrine 2.0 DI for invoice #501
    $doctrineClient = new class {
        public function getId(): int { return 11; }
    };
    $doctrineInvoice = new class($doctrineClient) {
        public string $status = 'unpaid';
        private object $client;
        public function __construct(object $c) { $this->client = $c; }
        public function getStatus(): string { return $this->status; }
        public function getTotal(): string { return '100.00'; }
        public function getCurrency(): string { return 'USD'; }
        public function getClient(): object { return $this->client; }
    };
    $doctrineState = new \stdClass();
    $doctrineState->fundsAdded = [];
    $doctrineState->paidInvoices = [];

    $doctrineDi = [
        'config' => ['salt' => $salt, 'url' => 'https://billing.example'],
        'em' => new class($doctrineInvoice, $doctrineClient) {
            private object $inv;
            private object $cli;
            public function __construct(object $i, object $c) { $this->inv = $i; $this->cli = $c; }
            public function find(string $cls, int $id): ?object {
                if ($cls === 'Box\\Mod\\Invoice\\Entity\\Invoice' && $id === 501) { return $this->inv; }
                if ($cls === 'Box\\Mod\\Client\\Entity\\Client' && $id === 11) { return $this->cli; }
                return null;
            }
        },
        'mod_service' => static function (string $name) use ($doctrineState, $doctrineInvoice): object {
            if ($name === 'client') {
                return new class($doctrineState) {
                    private \stdClass $st;
                    public function __construct(\stdClass $s) { $this->st = $s; }
                    public function addFunds(object $client, string $amount, string $desc, array $data): void {
                        $this->st->fundsAdded[] = ['amount' => $amount, 'desc' => $desc, 'data' => $data];
                    }
                };
            }
            return new class($doctrineState, $doctrineInvoice) {
                private \stdClass $st;
                private object $inv;
                public function __construct(\stdClass $s, object $i) { $this->st = $s; $this->inv = $i; }
                public function getTotalWithTax(object $m): string { return '100.00'; }
                public function isInvoiceTypeDeposit(object $m): bool { return false; }
                public function payInvoiceWithCredits(object $m): void {
                    $this->inv->status = 'paid';
                    $this->st->paidInvoices[] = 501;
                }
            };
        },
    ];

    $transport = new QueueTransport();
    $emitted = [];
    $guest = new GuestApi();
    $guest->setDi($doctrineDi);
    $guest->setDbDriver($db);
    $guest->setCredentialStore($credStore);
    $guest->setClock($clock);
    $guest->setClientFactory(static fn (string $env): Client => new Client(Config::forWebhooks($credStore)->clientConfig($env), $transport));
    $guest->setResponseEmitter(static function (int $code, array $body) use (&$emitted): void {
        $emitted[] = ['code' => $code, 'body' => $body];
    });

    $paidPayload = (string) json_encode([
        'event_id' => 'evt_sbx_paid_501',
        'event_type' => 'invoice.paid',
        'version' => 1,
        'occurred_at' => $now,
        'data' => [
            'invoice_id' => 'inv_sbx_501',
            'project_id' => 'prj_sandbox_fossbilling_001',
            'status' => 'paid',
            'is_test' => true,
            'order' => ['external_id' => 'fossbilling_501_0', 'amount' => '100', 'currency' => 'USD'],
            'payment' => [
                'transfers' => [
                    ['status' => 'confirmed', 'tx_hash' => '0xconfirmed_hash_501'],
                ],
            ],
        ],
    ]);

    // 1. Forged signature -> HTTP 401
    $guest->setRawBodyProvider(static fn (): string => $paidPayload);
    $guest->setServerProvider(static fn (): array => ['HTTP_X_WEBHOOK_SIGNATURE' => 't=' . $now . ',v1=deadbeef']);
    $guest->webhook();
    TestRunner::assertEquals(401, $emitted[0]['code']);
    TestRunner::assertEquals('unpaid', $doctrineInvoice->status);

    // 2. Stale timestamp (>300s) -> HTTP 401
    $staleSig = signWebhook('whsec_sandbox_fossbilling_secret_001', $paidPayload, $now - 305);
    $guest->setServerProvider(static fn (): array => ['HTTP_X_WEBHOOK_SIGNATURE' => $staleSig]);
    $guest->webhook();
    TestRunner::assertEquals(401, $emitted[1]['code']);
    TestRunner::assertEquals('unpaid', $doctrineInvoice->status);

    // 3. Valid dual-v1 Sandbox signature -> reverse-verify -> Doctrine 2.0 paid -> HTTP 200
    $dualSig = signWebhook('whsec_old_rotated_secret', $paidPayload, $now, 'whsec_sandbox_fossbilling_secret_001');
    $guest->setServerProvider(static fn (): array => ['x-webhook-signature' => $dualSig]);
    $transport->enqueue(200, [
        'invoice_id' => 'inv_sbx_501',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'status' => 'paid',
        'is_test' => true,
        'order' => ['external_id' => 'fossbilling_501_0', 'amount' => '100.00', 'currency' => 'USD'],
    ]);
    $guest->webhook();
    TestRunner::assertEquals(200, $emitted[2]['code']);
    TestRunner::assertEquals('paid', $doctrineInvoice->status);
    TestRunner::assertEquals(1, count($doctrineState->fundsAdded));
    TestRunner::assertEquals('0xconfirmed_hash_501', $snapshots->findByExternalOrderId('fossbilling_501_0')['transaction_id']);

    // 4. Committed duplicate -> HTTP 200 without double credit
    $guest->webhook();
    TestRunner::assertEquals(200, $emitted[3]['code']);
    TestRunner::assertTrue($emitted[3]['body']['duplicate']);
    TestRunner::assertEquals(1, count($doctrineState->fundsAdded), 'Duplicate must not double-credit');

    // 5. Roll-back guard: late cancelled event after paid -> ignored, invoice remains paid
    $cancelPayload = (string) json_encode([
        'event_id' => 'evt_sbx_cancel_late_501',
        'event_type' => 'invoice.cancelled',
        'version' => 1,
        'occurred_at' => $now,
        'data' => [
            'invoice_id' => 'inv_sbx_501',
            'project_id' => 'prj_sandbox_fossbilling_001',
            'status' => 'cancelled',
            'is_test' => true,
            'order' => ['external_id' => 'fossbilling_501_0', 'amount' => '100', 'currency' => 'USD'],
        ],
    ]);
    $guest->setRawBodyProvider(static fn (): string => $cancelPayload);
    $guest->setServerProvider(static fn (): array => ['HTTP_X_WEBHOOK_SIGNATURE' => signWebhook('whsec_sandbox_fossbilling_secret_001', $cancelPayload, $now)]);
    $guest->webhook();
    TestRunner::assertEquals(200, $emitted[4]['code']);
    TestRunner::assertEquals('paid', $doctrineInvoice->status);
    TestRunner::assertEquals('paid', $snapshots->findByExternalOrderId('fossbilling_501_0')['final_status']);

    // 6. Concurrent in-flight delivery -> HTTP 409 Conflict
    $eventStore = new PdoEventStore($db, $clock);
    TestRunner::assertTrue($eventStore->remember('evt_inflight_502', 604800));
    $inflightPayload = (string) json_encode([
        'event_id' => 'evt_inflight_502',
        'event_type' => 'invoice.paid',
        'version' => 1,
        'occurred_at' => $now,
        'data' => [
            'invoice_id' => 'inv_live_502',
            'project_id' => 'prj_live_fossbilling_002',
            'status' => 'paid',
            'is_test' => false,
            'order' => ['external_id' => 'fossbilling_502_0', 'amount' => '250.5', 'currency' => 'EUR'],
        ],
    ]);
    $guest->setRawBodyProvider(static fn (): string => $inflightPayload);
    $guest->setServerProvider(static fn (): array => ['HTTP_X_WEBHOOK_SIGNATURE' => signWebhook('whsec_live_fossbilling_secret_002', $inflightPayload, $now)]);
    $guest->webhook();
    TestRunner::assertEquals(409, $emitted[5]['code']);
    TestRunner::assertTrue(isset($db->events['evt_inflight_502']), '409 must not release another worker reservation');
    $eventStore->release('evt_inflight_502');

    // 7. Live RedBean 0.7 settlement + Amount mismatch hold -> HTTP 400 manual_review -> then fixed amount succeeds
    $redBeanInvoice = (object) ['id' => 502, 'client_id' => 22, 'status' => 'unpaid', 'total' => '999.00', 'currency' => 'EUR'];
    $redBeanClient = (object) ['id' => 22];
    $rbCredits = [];
    $redBeanDi = [
        'config' => ['salt' => $salt, 'url' => 'https://billing.example'],
        'db' => new class($redBeanInvoice, $redBeanClient) {
            public object $inv;
            public object $cli;
            public function __construct(object $i, object $c) { $this->inv = $i; $this->cli = $c; }
            public function getExistingModelById(string $model, int $id): object {
                if ($model === 'Invoice' && $id === 502) { return $this->inv; }
                if ($model === 'Client' && $id === 22) { return $this->cli; }
                throw new \RuntimeException('Not found');
            }
        },
        'mod_service' => static function (string $name) use (&$rbCredits, $redBeanInvoice): object {
            if ($name === 'client') {
                return new class($rbCredits) {
                    public array $log;
                    public function __construct(array &$l) { $this->log = &$l; }
                    public function addFunds(object $client, string $amount, string $desc, array $data): void {
                        $this->log[] = ['amount' => $amount, 'txn_id' => $data['txn_id']];
                    }
                };
            }
            return new class($redBeanInvoice) {
                private object $inv;
                public function __construct(object $i) { $this->inv = $i; }
                public function getTotalWithTax(object $m): string { return (string) $m->total; }
                public function isInvoiceTypeDeposit(object $m): bool { return false; }
                public function payInvoiceWithCredits(object $m): void { $this->inv->status = 'paid'; }
            };
        },
    ];

    $guestRb = new GuestApi();
    $guestRb->setDi($redBeanDi);
    $guestRb->setDbDriver($db);
    $guestRb->setCredentialStore($credStore);
    $guestRb->setClock($clock);
    $guestRb->setClientFactory(static fn (string $env): Client => new Client(Config::forWebhooks($credStore)->clientConfig($env), $transport));
    $rbEmitted = [];
    $guestRb->setResponseEmitter(static function (int $code, array $body) use (&$rbEmitted): void {
        $rbEmitted[] = ['code' => $code, 'body' => $body];
    });

    $livePayload = (string) json_encode([
        'event_id' => 'evt_live_paid_over_502',
        'event_type' => 'invoice.paid_over',
        'version' => 1,
        'occurred_at' => $now,
        'data' => [
            'invoice_id' => 'inv_live_502',
            'project_id' => 'prj_live_fossbilling_002',
            'status' => 'paid_over',
            'is_test' => false,
            'order' => ['external_id' => 'fossbilling_502_0', 'amount' => '250.5', 'currency' => 'EUR'],
        ],
    ]);
    $liveSig = signWebhook('whsec_live_fossbilling_secret_002', $livePayload, $now);
    $guestRb->setRawBodyProvider(static fn (): string => $livePayload);
    $guestRb->setServerProvider(static fn (): array => ['HTTP_X_WEBHOOK_SIGNATURE' => $liveSig]);

    // Amount mismatch (999.00 vs 250.5) -> HTTP 400 manual_review, lock released, invoice still unpaid
    $transport->enqueue(200, [
        'invoice_id' => 'inv_live_502',
        'project_id' => 'prj_live_fossbilling_002',
        'status' => 'paid_over',
        'is_test' => false,
        'order' => ['external_id' => 'fossbilling_502_0', 'amount' => '250.50', 'currency' => 'EUR'],
    ]);
    $guestRb->webhook();
    TestRunner::assertEquals(400, $rbEmitted[0]['code']);
    TestRunner::assertEquals('manual_review', $rbEmitted[0]['body']['status']);
    TestRunner::assertEquals('unpaid', $redBeanInvoice->status);
    TestRunner::assertTrue(!isset($db->events['evt_live_paid_over_502']), 'Lock must be released on manual_review');

    // Fix invoice total -> retry same event succeeds with fallback invoice_id as txn_id
    $redBeanInvoice->total = '250.50';
    $transport->enqueue(200, [
        'invoice_id' => 'inv_live_502',
        'project_id' => 'prj_live_fossbilling_002',
        'status' => 'paid_over',
        'is_test' => false,
        'order' => ['external_id' => 'fossbilling_502_0', 'amount' => '250.50', 'currency' => 'EUR'],
    ]);
    $guestRb->webhook();
    TestRunner::assertEquals(200, $rbEmitted[1]['code']);
    TestRunner::assertEquals('paid', $redBeanInvoice->status);
    TestRunner::assertEquals('inv_live_502', $rbCredits[0]['txn_id']);
    TestRunner::assertEquals('paid_over', $snapshots->findByExternalOrderId('fossbilling_502_0')['final_status']);
});

$runner->test('Contract 4: invoice.paid_late is strictly note-only; Reconciler sweeps missed webhooks with 600s throttle via Service::onAfterAdminCronRun', function (): void {
    $db = new InMemoryDbDriver();
    $now = 1760010000;
    $clock = static function () use (&$now): int {
        return $now;
    };
    $salt = 'salt-reconcile';
    $credStore = new CredentialStore($db, $salt, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    $events = new PdoEventStore($db, $clock);

    // 1. Expired snapshot receiving invoice.paid_late -> note only, never marks paid
    $snapshots->save([
        'external_order_id' => 'fossbilling_601_0',
        'paymos_invoice_id' => 'inv_late_601',
        'platform_ref' => '601',
        'client_id' => '31',
        'amount' => '75',
        'invoice_total' => '75',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'payment_url' => 'https://checkout.paymos.io/inv_late_601',
        'status' => 'expired',
        'final_status' => 'expired',
        'renewal_attempt' => 0,
        'created_at' => $now - 3600,
        'updated_at' => $now - 3600,
    ]);

    $paidCalled = 0;
    $notes = [];
    $transport = new QueueTransport();
    $config = Config::forWebhooks($credStore);
    $processor = new CallbackProcessor(
        $config,
        $events,
        $snapshots,
        static fn (int $id): array => ['id' => $id, 'amount' => '75.00', 'currency' => 'USD', 'paid' => false, 'client_id' => 31],
        static function () use (&$paidCalled): void { $paidCalled++; },
        static function (int $id, string $note) use (&$notes): void { $notes[] = [$id, $note]; },
        static fn (string $env): Client => new Client($config->clientConfig($env), $transport),
        null,
        $clock
    );

    $lateBody = (string) json_encode([
        'event_id' => 'evt_late_601',
        'event_type' => 'invoice.paid_late',
        'version' => 1,
        'occurred_at' => $now,
        'data' => [
            'invoice_id' => 'inv_late_601',
            'project_id' => 'prj_sandbox_fossbilling_001',
            'status' => 'expired',
            'paid_late_at' => $now - 10,
            'is_test' => true,
            'order' => ['external_id' => 'fossbilling_601_0', 'amount' => '75', 'currency' => 'USD'],
        ],
    ]);
    $transport->enqueue(200, [
        'invoice_id' => 'inv_late_601',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'status' => 'expired',
        'paid_late_at' => $now - 10,
        'is_test' => true,
        'order' => ['external_id' => 'fossbilling_601_0', 'amount' => '75', 'currency' => 'USD'],
    ]);
    $lateRes = $processor->handle($lateBody, signWebhook('whsec_sandbox_fossbilling_secret_001', $lateBody, $now), $now);
    TestRunner::assertEquals(200, $lateRes['http_status']);
    TestRunner::assertEquals('paid_late_noted', $lateRes['status']);
    TestRunner::assertEquals(0, $paidCalled, 'invoice.paid_late MUST NOT pay the invoice');
    TestRunner::assertEquals(1, count($notes));
    TestRunner::assertTrue(CheckoutService::isPaidLateNoted($snapshots->findByExternalOrderId('fossbilling_601_0')));

    // 2. Unpaid snapshot within 24h reconciled via Reconciler + 600s throttle
    $snapshots->save([
        'external_order_id' => 'fossbilling_602_0',
        'paymos_invoice_id' => 'inv_missed_602',
        'platform_ref' => '602',
        'client_id' => '32',
        'amount' => '120',
        'invoice_total' => '120',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'payment_url' => 'https://checkout.paymos.io/inv_missed_602',
        'status' => 'confirming',
        'renewal_attempt' => 0,
        'created_at' => $now - 600,
        'updated_at' => $now - 600,
    ]);
    $reconcilePaid = [];
    $proc2 = new CallbackProcessor(
        $config,
        $events,
        $snapshots,
        static fn (int $id): array => ['id' => $id, 'amount' => '120.00', 'currency' => 'USD', 'paid' => false, 'client_id' => 32],
        static function (array $p) use (&$reconcilePaid): void { $reconcilePaid[] = $p; },
        static function (): void {},
        static fn (string $env): Client => new Client($config->clientConfig($env), $transport),
        null,
        $clock
    );
    $reconciler = new Reconciler(
        $config,
        $credStore,
        $snapshots,
        $proc2,
        static fn (string $env): Client => new Client($config->clientConfig($env), $transport),
        $clock
    );

    $transport->enqueue(200, [
        'invoice_id' => 'inv_missed_602',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'status' => 'paid',
        'is_test' => true,
        'order' => ['external_id' => 'fossbilling_602_0', 'amount' => '120', 'currency' => 'USD'],
    ]);
    TestRunner::assertEquals(1, $reconciler->run($now));
    TestRunner::assertEquals(1, count($reconcilePaid));

    // Within 600s -> throttled (returns 0, makes 0 HTTP calls)
    $before = count($transport->requests());
    TestRunner::assertEquals(0, $reconciler->run($now + 300));
    TestRunner::assertEquals($before, count($transport->requests()));

    // R8: Reconciler MUST reject empty API fields and mismatched is_test
    $now += 650;
    $snapshots->save([
        'external_order_id' => 'fossbilling_604_0',
        'paymos_invoice_id' => 'inv_bad_env_604',
        'platform_ref' => '604',
        'client_id' => '34',
        'amount' => '30',
        'invoice_total' => '30',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'payment_url' => 'https://checkout.paymos.io/inv_bad_env_604',
        'status' => 'confirming',
        'renewal_attempt' => 0,
        'created_at' => $now - 60,
        'updated_at' => $now - 60,
    ]);
    $snapshots->save([
        'external_order_id' => 'fossbilling_605_0',
        'paymos_invoice_id' => 'inv_empty_605',
        'platform_ref' => '605',
        'client_id' => '35',
        'amount' => '35',
        'invoice_total' => '35',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'payment_url' => 'https://checkout.paymos.io/inv_empty_605',
        'status' => 'confirming',
        'renewal_attempt' => 0,
        'created_at' => $now - 50,
        'updated_at' => $now - 50,
    ]);
    $transport->enqueue(200, [
        'invoice_id' => 'inv_bad_env_604',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'status' => 'paid',
        'is_test' => false, // mismatch vs sandbox!
        'order' => ['external_id' => 'fossbilling_604_0', 'amount' => '30', 'currency' => 'USD'],
    ]);
    $transport->enqueue(200, [
        'invoice_id' => 'inv_empty_605',
        'project_id' => '',
        'status' => 'paid',
        'is_test' => true,
        'order' => ['external_id' => 'fossbilling_605_0', 'amount' => '', 'currency' => 'USD'],
    ]);
    $paidBeforeR8 = count($reconcilePaid);
    TestRunner::assertEquals(0, $reconciler->run($now), 'Reconciler MUST reject mismatched is_test and empty API fields');
    TestRunner::assertEquals($paidBeforeR8, count($reconcilePaid));
    unset($db->snapshots['fossbilling_604_0'], $db->snapshots['fossbilling_605_0']);

    // Module Service subscribes to both FOSSBilling 0.7 & 2.0 cron events and executes reconciler + refund cron
    $subscribed = ModuleService::getSubscribedEvents();
    TestRunner::assertTrue(isset($subscribed['onAfterAdminCronRun']));
    TestRunner::assertTrue(isset($subscribed['FOSSBilling\\Core\\Event\\AfterAdminCronRunEvent']));

    $now += 650; // past throttle window
    $snapshots->save([
        'external_order_id' => 'fossbilling_603_0',
        'paymos_invoice_id' => 'inv_cron_603',
        'platform_ref' => '603',
        'client_id' => '33',
        'amount' => '45',
        'invoice_total' => '45',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'payment_url' => 'https://checkout.paymos.io/inv_cron_603',
        'status' => 'confirming',
        'renewal_attempt' => 0,
        'created_at' => $now - 120,
        'updated_at' => $now - 120,
    ]);
    $cronInvoice = (object) ['id' => 603, 'client_id' => 33, 'status' => 'unpaid', 'total' => '45.00', 'currency' => 'USD'];
    $cronClient = (object) ['id' => 33];
    $cronDi = [
        'config' => ['salt' => $salt, 'url' => 'https://billing.example'],
        'db' => new class($cronInvoice, $cronClient) {
            public object $inv;
            public object $cli;
            public function __construct(object $i, object $c) { $this->inv = $i; $this->cli = $c; }
            public function getExistingModelById(string $model, int $id): object {
                if ($model === 'Invoice' && $id === 603) { return $this->inv; }
                if ($model === 'Client' && $id === 33) { return $this->cli; }
                throw new \RuntimeException('Missing');
            }
        },
        'mod_service' => static function (string $name) use ($cronInvoice): object {
            if ($name === 'client') {
                return new class {
                    public function addFunds(object $client, string $amount, string $desc, array $data): void {}
                };
            }
            return new class($cronInvoice) {
                private object $inv;
                public function __construct(object $i) { $this->inv = $i; }
                public function getTotalWithTax(object $m): string { return (string) $m->total; }
                public function isInvoiceTypeDeposit(object $m): bool { return false; }
                public function payInvoiceWithCredits(object $m): void { $this->inv->status = 'paid'; }
            };
        },
    ];
    $cronTransport = new class implements TransportInterface {
        public function request($method, $url, array $headers, $body, $timeoutSeconds) {
            if ($method === 'GET' && str_contains($url, '/v1/invoices/inv_cron_603')) {
                return new HttpResponse(200, (string) json_encode([
                    'invoice_id' => 'inv_cron_603',
                    'project_id' => 'prj_sandbox_fossbilling_001',
                    'status' => 'paid',
                    'is_test' => true,
                    'order' => ['external_id' => 'fossbilling_603_0', 'amount' => '45', 'currency' => 'USD'],
                ]), []);
            }
            if ($method === 'GET' && str_contains($url, '/v1/refunds')) {
                return new HttpResponse(200, (string) json_encode(['items' => [], 'next_cursor' => null]), []);
            }
            throw new \RuntimeException("Unexpected cron request: {$method} {$url}");
        }
    };
    $svc = new ModuleService();
    $svc->setDi($cronDi);
    $svc->setDbDriver($db);
    $svc->setCredentialStore($credStore);
    $svc->setClock($clock);
    $svc->setClientFactory(static fn (string $env): Client => new Client(Config::forWebhooks($credStore)->clientConfig($env), $cronTransport));
    $cronRes = $svc->handleAfterAdminCronRun(null, $now);
    TestRunner::assertEquals(1, $cronRes['invoices_reconciled']);
    TestRunner::assertTrue($cronRes['refunds_ran']);
    TestRunner::assertEquals('paid', $cronInvoice->status, 'Service::handleAfterAdminCronRun must reconcile unpaid invoice');
});

$runner->test('Contract 4: Payment_Adapter_Paymos ipn.php fallback (authenticateJsonWebhook, getInvoiceId, processTransaction)', function (): void {
    $db = new InMemoryDbDriver();
    $now = 1760020000;
    $clock = static fn (): int => $now;
    $salt = 'salt-ipn-fallback';
    $credStore = new CredentialStore($db, $salt, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    $snapshots->save([
        'external_order_id' => 'fossbilling_701_0',
        'paymos_invoice_id' => 'inv_ipn_701',
        'platform_ref' => '701',
        'client_id' => '71',
        'amount' => '30',
        'invoice_total' => '30',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'payment_url' => 'https://checkout.paymos.io/inv_ipn_701',
        'status' => 'awaiting_payment',
        'renewal_attempt' => 0,
        'created_at' => $now - 60,
        'updated_at' => $now - 60,
    ]);

    $transport = new QueueTransport();
    $adapter = new Payment_Adapter_Paymos(['mode' => 'sandbox']);
    $adapter->setDbDriver($db);
    $adapter->setCredentialStore($credStore);
    $adapter->setClock($clock);
    $adapter->setTransport($transport);

    $body = (string) json_encode([
        'event_id' => 'evt_ipn_701',
        'event_type' => 'invoice.paid',
        'version' => 1,
        'occurred_at' => $now,
        'data' => [
            'invoice_id' => 'inv_ipn_701',
            'project_id' => 'prj_sandbox_fossbilling_001',
            'status' => 'paid',
            'is_test' => true,
            'order' => ['external_id' => 'fossbilling_701_0', 'amount' => '30', 'currency' => 'USD'],
        ],
    ]);
    $sig = signWebhook('whsec_sandbox_fossbilling_secret_001', $body, $now);
    $ipnData = ['http_raw_post_data' => $body, 'server' => ['HTTP_X_WEBHOOK_SIGNATURE' => $sig]];

    // authenticateJsonWebhook validates HMAC without consuming dedup slot
    $adapter->authenticateJsonWebhook($ipnData);
    TestRunner::assertEquals(701, $adapter->getInvoiceId($ipnData));

    $apiAdmin = new class {
        public bool $credited = false;
        public bool $paid = false;
        public function invoice_get(array $p): array {
            return ['id' => 701, 'status' => 'unpaid', 'total' => '30.00', 'currency' => 'USD', 'client_id' => 71];
        }
        public function client_balance_add_funds(array $p): void { $this->credited = true; }
        public function invoice_pay_with_credits(array $p): void { $this->paid = true; }
        public function invoice_transaction_update(array $p): void {}
    };
    $transport->enqueue(200, [
        'invoice_id' => 'inv_ipn_701',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'status' => 'paid',
        'is_test' => true,
        'order' => ['external_id' => 'fossbilling_701_0', 'amount' => '30', 'currency' => 'USD'],
    ]);
    $adapter->processTransaction($apiAdmin, 9001, $ipnData, 1);
    TestRunner::assertTrue($apiAdmin->credited && $apiAdmin->paid);
    TestRunner::assertEquals(200, $adapter->getLastWebhookResult()['http_status']);
});

// ---------------------------------------------------------------------------
// 6. Contract 5: Full Refunds + Overpayment + Async + FOSSBilling 2.0 external_reversal
//    + 0.7 fallback + RefundDiscovery + RefundCandidateStoreInterface + Rejects
// ---------------------------------------------------------------------------

$runner->test('Contract 5: Full async refund -> no premature debit while processing -> withdrawal.completed projects via FOSSBilling 2.0 external_reversal', function (): void {
    $db = new InMemoryDbDriver();
    $now = 1760030000;
    $clock = static fn (): int => $now;
    $salt = 'salt-refunds-20';
    $credStore = new CredentialStore($db, $salt, $clock);
    $credStore->saveCredentials(sampleCredentials(true, true));
    $snapshots = new SnapshotStore($db, $clock);
    $refundStore = new RefundIntentStore($db, $clock);

    $snapshots->save([
        'external_order_id' => 'fossbilling_801_0',
        'paymos_invoice_id' => 'inv_ref_801',
        'platform_ref' => '801',
        'client_id' => '81',
        'amount' => '150',
        'invoice_total' => '150',
        'currency' => 'USD',
        'environment' => 'live',
        'project_id' => 'prj_live_fossbilling_002',
        'payment_url' => 'https://checkout.paymos.io/inv_ref_801',
        'transaction_id' => '0xtx_801',
        'status' => 'paid',
        'final_status' => 'paid',
        'renewal_attempt' => 0,
        'created_at' => $now - 600,
        'updated_at' => $now - 600,
    ]);

    // Mock FOSSBilling 2.0 Invoice\Service with 4-param refundInvoice($invoice, $note, $returnUrl, array $options)
    $fb20State = new \stdClass();
    $fb20State->refundCalls = [];
    $fb20Invoice = new class {
        public string $status = 'paid';
        public function getStatus(): string { return $this->status; }
        public function setStatus(string $s): void { $this->status = $s; }
        public function getTotal(): string { return '150.00'; }
        public function getCurrency(): string { return 'USD'; }
        public function getClientId(): int { return 81; }
    };
    $di20 = [
        'config' => ['salt' => $salt, 'url' => 'https://billing.example'],
        'em' => new class($fb20Invoice) {
            private object $inv;
            public function __construct(object $i) { $this->inv = $i; }
            public function find(string $cls, int $id): ?object {
                return ($cls === 'Box\\Mod\\Invoice\\Entity\\Invoice' && $id === 801) ? $this->inv : null;
            }
        },
        'mod_service' => static function (string $name) use ($fb20State, $fb20Invoice): object {
            return new class($fb20State, $fb20Invoice) {
                private \stdClass $st;
                private object $inv;
                public function __construct(\stdClass $s, object $i) { $this->st = $s; $this->inv = $i; }
                public function getTotalWithTax(object $m): string { return '150.00'; }
                public function refundInvoice(object $invoice, ?string $note = null, ?string $returnUrl = null, array $options = []): void {
                    $this->st->refundCalls[] = ['note' => $note, 'options' => $options];
                    $this->inv->setStatus('refunded');
                }
            };
        },
    ];

    $native = RefundAdapter::createFossBillingNativeHandler($di20, $credStore, $snapshots, $clock);

    $apiInvoicePaid = [
        'invoice_id' => 'inv_ref_801',
        'project_id' => 'prj_live_fossbilling_002',
        'status' => 'paid',
        'is_test' => false,
        'order' => ['external_id' => 'fossbilling_801_0', 'amount' => '150', 'currency' => 'USD'],
        'refund_summary' => [
            'can_refund_full' => true,
            'can_refund_overpayment' => false,
            'full_refund_amount' => '150.0',
            'overpayment_refund_amount' => null,
        ],
    ];

    $wdrId = 'wdr_full_801';
    $reqToken = RefundIntentStore::uuid();

    $dynamicTransport = new class($apiInvoicePaid, $wdrId) implements TransportInterface {
        public array $calls = [];
        public string $currentStatus = 'signed';
        public string $lastExternalOrderId = '';
        private array $invoice;
        private string $wdrId;
        public function __construct(array $inv, string $w) { $this->invoice = $inv; $this->wdrId = $w; }
        public function request($method, $url, array $headers, $body, $timeoutSeconds) {
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
            if ($method === 'POST' && str_ends_with($url, '/v1/refunds')) {
                $decoded = json_decode((string) $body, true);
                TestRunner::assertEquals(['invoice_id', 'mode', 'external_order_id'], array_keys($decoded), 'POST /v1/refunds must send ONLY managed keys');
                $this->lastExternalOrderId = (string) $decoded['external_order_id'];
                return new HttpResponse(201, (string) json_encode(['withdrawal_id' => $this->wdrId, 'status' => $this->currentStatus]), []);
            }
            if ($method === 'GET' && str_contains($url, '/v1/refunds/' . $this->wdrId)) {
                return new HttpResponse(200, (string) json_encode([
                    'withdrawal_id' => $this->wdrId,
                    'invoice_id' => 'inv_ref_801',
                    'project_id' => 'prj_live_fossbilling_002',
                    'mode' => 'full',
                    'external_order_id' => $this->lastExternalOrderId,
                    'status' => $this->currentStatus,
                    'amount' => '150',
                    'currency' => 'USDT',
                    'network' => 'TRON',
                    'first_sender' => 'TFirstSenderAddress801',
                    'destination_address' => 'TFirstSenderAddress801',
                    'is_test' => false,
                ]), []);
            }
            if ($method === 'GET' && str_contains($url, '/v1/invoices/inv_ref_801')) {
                return new HttpResponse(200, (string) json_encode($this->invoice), []);
            }
            throw new \RuntimeException('Unexpected HTTP call: ' . $method . ' ' . $url);
        }
    };

    $adapter = new RefundAdapter(
        $credStore,
        $snapshots,
        $refundStore,
        $native,
        static fn (string $env): Client => new Client(Config::forWebhooks($credStore)->clientConfig($env), $dynamicTransport),
        static function (): void {},
        $clock
    );

    // Submit async refund (returns processing)
    $intent = $adapter->submit([
        'invoice_id' => 801,
        'mode' => 'full',
        'request_token' => $reqToken,
    ]);
    TestRunner::assertEquals(RefundStates::PROCESSING, $intent->cashState());
    TestRunner::assertEquals(RefundStates::PROJECTION_PENDING, $intent->projectionState());
    TestRunner::assertEquals(0, count($fb20State->refundCalls), 'MUST NOT prematurely debit FOSSBilling while processing');
    TestRunner::assertEquals('paid', $fb20Invoice->status, 'Invoice stays paid while refund is processing');

    // Replay same request_token -> no duplicate POST /v1/refunds!
    $postCountBefore = count(array_filter($dynamicTransport->calls, static fn (array $c): bool => $c['method'] === 'POST'));
    $replayed = $adapter->submit([
        'invoice_id' => 801,
        'mode' => 'full',
        'request_token' => $reqToken,
    ]);
    $postCountAfter = count(array_filter($dynamicTransport->calls, static fn (array $c): bool => $c['method'] === 'POST'));
    TestRunner::assertEquals($intent->operationId(), $replayed->operationId());
    TestRunner::assertEquals($postCountBefore, $postCountAfter, 'Replaying same request_token must not re-POST /v1/refunds');

    // Now Paymos completes the withdrawal and delivers signed withdrawal.completed webhook via Guest::webhook()!
    $dynamicTransport->currentStatus = 'completed';
    $eventStore = new PdoEventStore($db, $clock);
    $processor = new CallbackProcessor(
        Config::forWebhooks($credStore),
        $eventStore,
        $snapshots,
        static fn (int $id): ?array => null,
        static function (): void {},
        static function (): void {},
        static fn (string $env): Client => new Client(Config::forWebhooks($credStore)->clientConfig($env), $dynamicTransport),
        static function (\Paymos\Webhook\WebhookEvent $evt, string $env) use ($adapter): void {
            $adapter->callback($evt, $env);
        },
        $clock
    );
    $wdrWebhookBody = (string) json_encode([
        'event_id' => 'evt_wdr_done_801',
        'event_type' => 'withdrawal.completed',
        'version' => 1,
        'occurred_at' => $now,
        'data' => [
            'type' => 'refund',
            'withdrawal_id' => $wdrId,
            'invoice_id' => 'inv_ref_801',
            'project_id' => 'prj_live_fossbilling_002',
            'mode' => 'full',
            'external_order_id' => $dynamicTransport->lastExternalOrderId,
            'is_test' => false,
        ],
    ]);
    $whRes = $processor->handle($wdrWebhookBody, signWebhook('whsec_live_fossbilling_secret_002', $wdrWebhookBody, $now), $now);
    TestRunner::assertEquals(200, $whRes['http_status']);
    TestRunner::assertEquals('refund_processed', $whRes['status']);

    // Verify FOSSBilling 2.0 external_reversal projection ran ONCE!
    TestRunner::assertEquals(1, count($fb20State->refundCalls));
    $opts = $fb20State->refundCalls[0]['options'];
    TestRunner::assertEquals(true, $opts['gateway_returned'] ?? null);
    TestRunner::assertEquals(false, $opts['gateway_refund'] ?? null);
    TestRunner::assertEquals('paymos_refund_' . $wdrId, $opts['external_reversal']['reference'] ?? null);
    TestRunner::assertEquals('refunded', $fb20Invoice->status);

    $finalIntent = $refundStore->getIntent($intent->operationId());
    TestRunner::assertEquals(RefundStates::COMPLETED, $finalIntent->cashState());
    TestRunner::assertEquals(RefundStates::PROJECTION_APPLIED, $finalIntent->projectionState());

    // Adapter listRefundsForTransaction surfaces the completed refund
    $paymosAdapter = new Payment_Adapter_Paymos(['mode' => 'live']);
    $paymosAdapter->setDbDriver($db);
    $paymosAdapter->setCredentialStore($credStore);
    $paymosAdapter->setRefundAdapter($adapter);
    $listed = $paymosAdapter->listRefundsForTransaction('0xtx_801');
    TestRunner::assertEquals(1, count($listed));
    TestRunner::assertEquals($wdrId, $listed[0]['withdrawal_id']);
});

$runner->test('Contract 5: Overpayment refund leaves invoice paid, RefundDiscovery projects Dashboard refund without POST, and rejects unmanaged/partial inputs', function (): void {
    $db = new InMemoryDbDriver();
    $now = 1760040000;
    $clock = static fn (): int => $now;
    $salt = 'salt-refunds-over-disc';
    $credStore = new CredentialStore($db, $salt, $clock);
    $credStore->saveCredentials(sampleCredentials(true, true));
    $snapshots = new SnapshotStore($db, $clock);
    $refundStore = new RefundIntentStore($db, $clock);

    // Paid-over invoice #802
    $snapshots->save([
        'external_order_id' => 'fossbilling_802_0',
        'paymos_invoice_id' => 'inv_over_802',
        'platform_ref' => '802',
        'client_id' => '82',
        'amount' => '100',
        'invoice_total' => '100',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'payment_url' => 'https://checkout.paymos.io/inv_over_802',
        'transaction_id' => '0xtx_802',
        'status' => 'paid_over',
        'final_status' => 'paid_over',
        'renewal_attempt' => 0,
        'created_at' => $now - 600,
        'updated_at' => $now - 600,
    ]);

    // Invoice #803 for RefundDiscovery (refund initiated in Paymos Dashboard directly!)
    $snapshots->save([
        'external_order_id' => 'fossbilling_803_0',
        'paymos_invoice_id' => 'inv_dash_803',
        'platform_ref' => '803',
        'client_id' => '83',
        'amount' => '80',
        'invoice_total' => '80',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'payment_url' => 'https://checkout.paymos.io/inv_dash_803',
        'transaction_id' => '0xtx_803',
        'status' => 'paid',
        'final_status' => 'paid',
        'renewal_attempt' => 0,
        'created_at' => $now - 600,
        'updated_at' => $now - 600,
    ]);

    $rbInvoices = [
        802 => (object) ['id' => 802, 'client_id' => 82, 'status' => 'paid', 'total' => '100.00', 'currency' => 'USD'],
        803 => (object) ['id' => 803, 'client_id' => 83, 'status' => 'paid', 'total' => '80.00', 'currency' => 'USD'],
    ];
    $notesLogged = [];
    // RedBean 0.7 fallback DI (no 4-arg refundInvoice)
    $di07 = [
        'config' => ['salt' => $salt, 'url' => 'https://billing.example'],
        'db' => new class($rbInvoices) {
            public array $invoices;
            public function __construct(array &$invs) { $this->invoices = &$invs; }
            public function getExistingModelById(string $model, int $id): object {
                if ($model === 'Invoice' && isset($this->invoices[$id])) { return $this->invoices[$id]; }
                throw new \RuntimeException('Missing');
            }
            public function store(object $m): void {}
        },
        'logger' => new class($notesLogged) {
            public array $notes;
            public function __construct(array &$n) { $this->notes = &$n; }
            public function info(string $m): void { $this->notes[] = $m; }
        },
    ];

    $transport = new class implements TransportInterface {
        public array $posts = [];
        public string $overExternalId = '';
        public function request($method, $url, array $headers, $body, $timeoutSeconds) {
            if ($method === 'POST' && str_ends_with($url, '/v1/refunds')) {
                $decoded = json_decode((string) $body, true);
                $this->posts[] = $decoded;
                $this->overExternalId = (string) $decoded['external_order_id'];
                return new HttpResponse(201, (string) json_encode(['withdrawal_id' => 'wdr_over_802', 'status' => 'completed']), []);
            }
            if ($method === 'GET' && str_contains($url, '/v1/refunds/wdr_over_802')) {
                return new HttpResponse(200, (string) json_encode([
                    'withdrawal_id' => 'wdr_over_802',
                    'invoice_id' => 'inv_over_802',
                    'project_id' => 'prj_sandbox_fossbilling_001',
                    'mode' => 'overpayment',
                    'external_order_id' => $this->overExternalId,
                    'status' => 'completed',
                    'amount' => '12.5',
                    'currency' => 'USDC',
                    'network' => 'BASE',
                    'first_sender' => '0xSenderOver802',
                    'destination_address' => '0xSenderOver802',
                    'is_test' => true,
                ]), []);
            }
            if ($method === 'GET' && str_contains($url, '/v1/refunds/wdr_dash_803')) {
                return new HttpResponse(200, (string) json_encode([
                    'withdrawal_id' => 'wdr_dash_803',
                    'invoice_id' => 'inv_dash_803',
                    'project_id' => 'prj_sandbox_fossbilling_001',
                    'mode' => 'full',
                    'external_order_id' => 'dashboard_refund_803',
                    'status' => 'completed',
                    'amount' => '80',
                    'currency' => 'USDT',
                    'network' => 'ARBITRUM',
                    'first_sender' => '0xSenderDash803',
                    'destination_address' => '0xSenderDash803',
                    'is_test' => true,
                ]), []);
            }
            if ($method === 'GET' && str_contains($url, '/v1/refunds?')) {
                return new HttpResponse(200, (string) json_encode([
                    'items' => [
                        [
                            'withdrawal_id' => 'wdr_dash_803',
                            'invoice_id' => 'inv_dash_803',
                            'project_id' => 'prj_sandbox_fossbilling_001',
                            'mode' => 'full',
                            'external_order_id' => 'dashboard_refund_803',
                            'is_test' => true,
                        ],
                    ],
                    'next_cursor' => null,
                ]), []);
            }
            if ($method === 'GET' && str_contains($url, '/v1/invoices/inv_over_802')) {
                return new HttpResponse(200, (string) json_encode([
                    'invoice_id' => 'inv_over_802',
                    'project_id' => 'prj_sandbox_fossbilling_001',
                    'status' => 'paid_over',
                    'is_test' => true,
                    'order' => ['external_id' => 'fossbilling_802_0', 'amount' => '100', 'currency' => 'USD'],
                    'refund_summary' => [
                        'can_refund_full' => true,
                        'can_refund_overpayment' => true,
                        'full_refund_amount' => '112.5',
                        'overpayment_refund_amount' => '12.5',
                    ],
                ]), []);
            }
            if ($method === 'GET' && str_contains($url, '/v1/invoices/inv_dash_803')) {
                return new HttpResponse(200, (string) json_encode([
                    'invoice_id' => 'inv_dash_803',
                    'project_id' => 'prj_sandbox_fossbilling_001',
                    'status' => 'paid',
                    'is_test' => true,
                    'order' => ['external_id' => 'fossbilling_803_0', 'amount' => '80', 'currency' => 'USD'],
                ]), []);
            }
            throw new \RuntimeException('Unexpected request: ' . $method . ' ' . $url);
        }
    };

    $native = RefundAdapter::createFossBillingNativeHandler($di07, $credStore, $snapshots, $clock);
    $adapter = new RefundAdapter(
        $credStore,
        $snapshots,
        $refundStore,
        $native,
        static fn (string $env): Client => new Client(Config::forWebhooks($credStore)->clientConfig($env), $transport),
        static function (): void {},
        $clock
    );

    // 1. Reject partial amount, unmanaged fields, bad request_token BEFORE any HTTP call
    TestRunner::assertThrows(\RuntimeException::class, static fn () => $adapter->submit([
        'invoice_id' => 802, 'mode' => 'full', 'request_token' => RefundIntentStore::uuid(), 'address' => '0xHacker',
    ]), 'refund_managed_input_only');
    TestRunner::assertThrows(\RuntimeException::class, static fn () => $adapter->submit([
        'invoice_id' => 802, 'mode' => 'full', 'request_token' => RefundIntentStore::uuid(), 'destination_address' => '0xHacker',
    ]), 'refund_managed_input_only');
    TestRunner::assertThrows(\RuntimeException::class, static fn () => $adapter->submit([
        'invoice_id' => 802, 'mode' => 'full', 'request_token' => RefundIntentStore::uuid(), 'network' => 'TRON',
    ]), 'refund_managed_input_only');
    TestRunner::assertThrows(\RuntimeException::class, static fn () => $adapter->submit([
        'invoice_id' => 802, 'mode' => 'full', 'request_token' => RefundIntentStore::uuid(), 'crypto_amount' => '10',
    ]), 'refund_managed_input_only');
    TestRunner::assertThrows(\RuntimeException::class, static fn () => $adapter->submit([
        'invoice_id' => 802, 'mode' => 'full', 'request_token' => 'not-a-uuid-v4',
    ]), 'refund_durable_token_required');
    TestRunner::assertThrows(\RuntimeException::class, static fn () => $adapter->submit([
        'invoice_id' => 802, 'mode' => 'full', 'request_token' => RefundIntentStore::uuid(), 'amount' => '50.00',
    ]), 'refund_partial_or_changed_payment');
    TestRunner::assertEquals(0, count($transport->posts), 'Rejected inputs must NEVER send POST /v1/refunds');

    // 2. Overpayment refund succeeds & keeps FOSSBilling invoice #802 paid
    $overIntent = $adapter->submit([
        'invoice_id' => 802,
        'mode' => 'overpayment',
        'request_token' => RefundIntentStore::uuid(),
    ]);
    TestRunner::assertEquals(RefundStates::COMPLETED, $overIntent->cashState());
    TestRunner::assertEquals(RefundStates::PROJECTION_APPLIED, $overIntent->projectionState());
    TestRunner::assertEquals('paid', $rbInvoices[802]->status, 'Overpayment refund MUST leave invoice paid');
    TestRunner::assertEquals(1, count($transport->posts));

    // 3. RefundDiscovery discovers & projects Dashboard-initiated full refund for #803 (0.7 fallback) without calling POST /v1/refunds!
    $discovered = $adapter->discoverExternal('sandbox');
    TestRunner::assertEquals(1, $discovered);
    TestRunner::assertEquals(1, count($transport->posts), 'Discovery must NEVER call POST /v1/refunds');
    TestRunner::assertEquals('refunded', $rbInvoices[803]->status, '0.7 fallback marks discovered full refund as refunded');

    // Panel hides completed full action for #803 and hides completed overpayment for #802 (while full remains visible for #802)
    $panel802 = $adapter->panel(802, 'ru');
    TestRunner::assertTrue($panel802['can_refund_full']);
    TestRunner::assertTrue(!$panel802['can_refund_overpayment']);

    $panel803 = $adapter->panel(803, 'en');
    TestRunner::assertTrue(!$panel803['can_refund_full']);
    TestRunner::assertEquals(1, count($panel803['external_refunds']));

    // 4. RefundCandidateStoreInterface (BUG-1347) & write-once bindWithdrawal / claimEffect
    $refundStore->withPaymentLock('802', static function () use ($refundStore, $overIntent): void {
        $refundStore->recordReadCandidate($overIntent->operationId(), 'wdr_candidate_1', 2);
        $reloaded = $refundStore->getIntent($overIntent->operationId());
        TestRunner::assertEquals('wdr_candidate_1', $reloaded->candidateWithdrawalId());
        TestRunner::assertEquals(2, $reloaded->readRetryCount());
        $refundStore->recordReadCandidate($overIntent->operationId(), null, 0);

        // bindWithdrawal: same returns 'same', different withdrawal on already-bound intent returns 'conflict'
        TestRunner::assertEquals('same', $refundStore->bindWithdrawal($overIntent->operationId(), 'wdr_over_802'));
        TestRunner::assertEquals('conflict', $refundStore->bindWithdrawal($overIntent->operationId(), 'wdr_other_999'));
    });

    // 5. Payment_Adapter_Paymos::refundPayment() refuses premature debit while processing & returns completed on verified completion
    $snapshots->save([
        'external_order_id' => 'fossbilling_804_0',
        'paymos_invoice_id' => 'inv_adapt_804',
        'platform_ref' => '804',
        'client_id' => '84',
        'amount' => '60',
        'invoice_total' => '60',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'payment_url' => 'https://checkout.paymos.io/inv_adapt_804',
        'transaction_id' => '0xtx_adapt_804',
        'status' => 'paid',
        'final_status' => 'paid',
        'renewal_attempt' => 0,
        'created_at' => $now - 300,
        'updated_at' => $now - 300,
    ]);
    $rbInvoices[804] = (object) ['id' => 804, 'client_id' => 84, 'status' => 'paid', 'total' => '60.00', 'currency' => 'USD'];
    $adaptTransport = new class implements TransportInterface {
        public string $status = 'signed';
        public string $extId = '';
        public function request($method, $url, array $headers, $body, $timeoutSeconds) {
            if ($method === 'POST' && str_ends_with($url, '/v1/refunds')) {
                $decoded = json_decode((string) $body, true);
                $this->extId = (string) $decoded['external_order_id'];
                return new HttpResponse(201, (string) json_encode(['withdrawal_id' => 'wdr_adapt_804', 'status' => $this->status]), []);
            }
            if ($method === 'GET' && str_contains($url, '/v1/refunds/wdr_adapt_804')) {
                return new HttpResponse(200, (string) json_encode([
                    'withdrawal_id' => 'wdr_adapt_804',
                    'invoice_id' => 'inv_adapt_804',
                    'project_id' => 'prj_sandbox_fossbilling_001',
                    'mode' => 'full',
                    'external_order_id' => $this->extId,
                    'status' => $this->status,
                    'amount' => '60',
                    'currency' => 'USDT',
                    'network' => 'TRON',
                    'first_sender' => 'TSender804',
                    'destination_address' => 'TSender804',
                    'is_test' => true,
                ]), []);
            }
            if ($method === 'GET' && str_contains($url, '/v1/invoices/inv_adapt_804')) {
                return new HttpResponse(200, (string) json_encode([
                    'invoice_id' => 'inv_adapt_804',
                    'project_id' => 'prj_sandbox_fossbilling_001',
                    'status' => 'paid',
                    'is_test' => true,
                    'order' => ['external_id' => 'fossbilling_804_0', 'amount' => '60', 'currency' => 'USD'],
                    'refund_summary' => [
                        'can_refund_full' => true,
                        'can_refund_overpayment' => false,
                        'full_refund_amount' => '60',
                    ],
                ]), []);
            }
            throw new \RuntimeException("Unexpected adapter refund call: {$method} {$url}");
        }
    };
    $adaptRefund = new RefundAdapter(
        $credStore,
        $snapshots,
        $refundStore,
        $native,
        static fn (string $env): Client => new Client(Config::forWebhooks($credStore)->clientConfig($env), $adaptTransport),
        static function (): void {},
        $clock
    );
    $paymosAdapt = new Payment_Adapter_Paymos(['mode' => 'sandbox']);
    $paymosAdapt->setDbDriver($db);
    $paymosAdapt->setCredentialStore($credStore);
    $paymosAdapt->setRefundAdapter($adaptRefund);
    $adaptToken = RefundIntentStore::uuid();

    // Processing -> throws controlled Payment_Exception so FOSSBilling 2.0 does NOT prematurely mark refunded
    TestRunner::assertThrows(\Payment_Exception::class, static fn () => $paymosAdapt->refundPayment(804, '0xtx_adapt_804', '60.00', null, ['mode' => 'full', 'request_token' => $adaptToken]));
    TestRunner::assertEquals('paid', $rbInvoices[804]->status, 'Invoice 804 must stay paid while processing');

    // Completed -> same token completes and returns completed status
    $adaptTransport->status = 'completed';
    $adaptDone = $paymosAdapt->refundPayment(804, '0xtx_adapt_804', '60.00', null, ['mode' => 'full', 'request_token' => $adaptToken]);
    TestRunner::assertEquals('completed', $adaptDone['status']);
    TestRunner::assertEquals('wdr_adapt_804', $adaptDone['withdrawal_id']);
    TestRunner::assertEquals('refunded', $rbInvoices[804]->status);

    // R7: Calling refundPayment() repeatedly WITHOUT request_token while processing reuses active full intent token idempotently
    $snapshots->save([
        'external_order_id' => 'fossbilling_805_0',
        'paymos_invoice_id' => 'inv_adapt_805',
        'platform_ref' => '805',
        'client_id' => '85',
        'amount' => '45',
        'invoice_total' => '45',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'payment_url' => 'https://checkout.paymos.io/inv_adapt_805',
        'transaction_id' => '0xtx_adapt_805',
        'status' => 'paid',
        'final_status' => 'paid',
        'renewal_attempt' => 0,
        'created_at' => $now - 200,
        'updated_at' => $now - 200,
    ]);
    $rbInvoices[805] = (object) ['id' => 805, 'client_id' => 85, 'status' => 'paid', 'total' => '45.00', 'currency' => 'USD'];
    $r7Transport = new class implements TransportInterface {
        public string $status = 'signed';
        public string $extId = '';
        public int $postCount = 0;
        public function request($method, $url, array $headers, $body, $timeoutSeconds) {
            if ($method === 'POST' && str_ends_with($url, '/v1/refunds')) {
                $this->postCount++;
                $decoded = json_decode((string) $body, true);
                $this->extId = (string) $decoded['external_order_id'];
                return new HttpResponse(201, (string) json_encode(['withdrawal_id' => 'wdr_adapt_805', 'status' => $this->status]), []);
            }
            if ($method === 'GET' && str_contains($url, '/v1/refunds/wdr_adapt_805')) {
                return new HttpResponse(200, (string) json_encode([
                    'withdrawal_id' => 'wdr_adapt_805',
                    'invoice_id' => 'inv_adapt_805',
                    'project_id' => 'prj_sandbox_fossbilling_001',
                    'mode' => 'full',
                    'external_order_id' => $this->extId,
                    'status' => $this->status,
                    'amount' => '45',
                    'currency' => 'USDT',
                    'network' => 'TRON',
                    'first_sender' => 'TSender805',
                    'destination_address' => 'TSender805',
                    'is_test' => true,
                ]), []);
            }
            if ($method === 'GET' && str_contains($url, '/v1/invoices/inv_adapt_805')) {
                return new HttpResponse(200, (string) json_encode([
                    'invoice_id' => 'inv_adapt_805',
                    'project_id' => 'prj_sandbox_fossbilling_001',
                    'status' => 'paid',
                    'is_test' => true,
                    'order' => ['external_id' => 'fossbilling_805_0', 'amount' => '45', 'currency' => 'USD'],
                    'refund_summary' => ['can_refund_full' => true, 'can_refund_overpayment' => false, 'full_refund_amount' => '45'],
                ]), []);
            }
            throw new \RuntimeException("Unexpected R7 call: {$method} {$url}");
        }
    };
    $r7Refund = new RefundAdapter($credStore, $snapshots, $refundStore, $native, static fn (string $env): Client => new Client(Config::forWebhooks($credStore)->clientConfig($env), $r7Transport), static function (): void {}, $clock);
    $r7Adapt = new Payment_Adapter_Paymos(['mode' => 'sandbox']);
    $r7Adapt->setDbDriver($db);
    $r7Adapt->setCredentialStore($credStore);
    $r7Adapt->setRefundAdapter($r7Refund);

    // 1st call (no request_token) -> creates 1 intent and throws controlled pending Payment_Exception
    TestRunner::assertThrows(\Payment_Exception::class, static fn () => $r7Adapt->refundPayment(805, '0xtx_adapt_805', '45.00'));
    TestRunner::assertEquals(1, $r7Transport->postCount);
    // 2nd call (still no request_token, still pending) -> reuses active token (MUST NOT fail with refund_payment_has_unresolved_intent and MUST NOT re-POST!)
    try {
        $r7Adapt->refundPayment(805, '0xtx_adapt_805', '45.00');
        throw new \RuntimeException('Expected pending Payment_Exception');
    } catch (\Payment_Exception $pe) {
        TestRunner::assertTrue(!str_contains($pe->getMessage(), 'refund_payment_has_unresolved_intent'), 'Retry must reuse active intent token instead of failing with unresolved_intent');
    }
    TestRunner::assertEquals(1, $r7Transport->postCount, 'Retry without request_token must NEVER double-POST /v1/refunds');
    // 3rd call once completed (still no request_token) -> reuses active token and completes!
    $r7Transport->status = 'completed';
    $r7Done = $r7Adapt->refundPayment(805, '0xtx_adapt_805', '45.00');
    TestRunner::assertEquals('completed', $r7Done['status']);
    TestRunner::assertEquals('refunded', $rbInvoices[805]->status);
    TestRunner::assertEquals(1, $r7Transport->postCount);

    // 6. Missing capability -> refund_reconnect_required before POST
    $credStore->saveCredentials(sampleCredentials(true, false));
    $beforePosts = count($transport->posts);
    TestRunner::assertThrows(\RuntimeException::class, static fn () => $adapter->submit([
        'invoice_id' => 802, 'mode' => 'full', 'request_token' => RefundIntentStore::uuid(),
    ]), 'refund_reconnect_required');
    TestRunner::assertEquals($beforePosts, count($transport->posts));
});

// ---------------------------------------------------------------------------
// 7. Six Native Locales (en, ru, de, es, tr, zh-Hans)
// ---------------------------------------------------------------------------

$runner->test('Packaging & Localization: all 6 locales have 100% key parity and real native translations', function () use ($pluginRoot): void {
    $locales = Translation::supportedLocales();
    TestRunner::assertEquals(['en', 'ru', 'de', 'es', 'tr', 'zh-Hans'], $locales);

    $en = require $pluginRoot . '/modules/Paymos/lang/en.php';
    TestRunner::assertTrue(is_array($en) && count($en) >= 35, 'en.php must have >=35 keys');
    $enKeys = array_keys($en);
    sort($enKeys);

    // Brand/technical terms legitimately identical in some languages
    $allowedSameAsEn = ['Paymos', 'Sandbox', 'Live', 'Code:'];

    foreach ($locales as $locale) {
        $path = $pluginRoot . '/modules/Paymos/lang/' . $locale . '.php';
        TestRunner::assertTrue(is_file($path), "Missing locale file {$locale}.php");
        $catalog = require $path;
        TestRunner::assertTrue(is_array($catalog), "{$locale}.php must return array");

        $keys = array_keys($catalog);
        sort($keys);
        TestRunner::assertEquals($enKeys, $keys, "Key parity mismatch in {$locale}.php");

        foreach ($catalog as $k => $v) {
            TestRunner::assertTrue(is_string($v) && trim($v) !== '', "Empty translation for {$locale}.{$k}");
            if ($locale !== 'en' && !in_array($v, $allowedSameAsEn, true)) {
                TestRunner::assertTrue($v !== $en[$k], "Untranslated English string in {$locale}.{$k}: '{$v}'");
            }
        }
    }

    // Locale resolution aliases
    TestRunner::assertEquals('zh-Hans', Translation::normalizeLocale('zh_CN'));
    TestRunner::assertEquals('zh-Hans', Translation::normalizeLocale('zh-Hans'));
    TestRunner::assertEquals('ru', Translation::normalizeLocale('ru_RU'));
    TestRunner::assertEquals('de', Translation::normalizeLocale('deutsch'));
    TestRunner::assertEquals('en', Translation::normalizeLocale('fr_FR'));

    // Russian copy sanity (no канцелярит)
    $ru = require $pluginRoot . '/modules/Paymos/lang/ru.php';
    foreach ($ru as $k => $v) {
        foreach (['является', 'осуществляется', 'данный', 'производится'] as $bad) {
            TestRunner::assertTrue(preg_match('/' . $bad . '/iu', $v) === 0, "Russian канцелярит in ru.{$k}: {$v}");
        }
    }
});

$runner->test('AdminController mounts routes and navigation cleanly without secrets', function (): void {
    $ctrl = new AdminController();
    $nav = $ctrl->fetchNavigation();
    TestRunner::assertEquals(2, count($nav['subpages']));
    TestRunner::assertEquals('/admin/paymos', $nav['subpages'][0]['uri']);
    TestRunner::assertEquals('/admin/paymos/refunds', $nav['subpages'][1]['uri']);

    $routes = [];
    $app = new class($routes) {
        public array $routes;
        public function __construct(array &$r) { $this->routes = &$r; }
        public function get(string $path, string $method, array $conditions, string $class): void {
            $this->routes[$path] = $method;
        }
    };
    $ctrl->register($app);
    TestRunner::assertEquals(['/paymos' => 'get_index', '/paymos/refunds' => 'get_refunds', '/paymos/refunds/:id' => 'get_invoice_refunds'], $routes);

    // R6: requireAdminSession() MUST fail closed (403) when DI lacks admin auth
    $renderStub = new class {
        public string $template = '';
        public array $vars = [];
        public function render(string $tpl, array $vars): array {
            $this->template = $tpl;
            $this->vars = $vars;
            return $vars;
        }
    };
    $ctrl->setDi([]);
    TestRunner::assertThrows(\RuntimeException::class, static fn () => $ctrl->get_index($renderStub), 'paymos_admin_access_denied');
    TestRunner::assertThrows(\RuntimeException::class, static fn () => $ctrl->get_refunds($renderStub), 'paymos_admin_access_denied');

    // R6: API URLs respect subdirectory base path & $di['url']->link()
    $ctrl->setDi([
        'is_admin_logged' => true,
        'config' => ['url' => 'https://example.com/billing/'],
    ]);
    $varsSubdir = $ctrl->get_refunds($renderStub);
    TestRunner::assertEquals('/billing/index.php?_url=/api/admin/paymos/refund_submit', $varsSubdir['api_refund_submit']);

    $ctrl->setDi([
        'is_admin_logged' => true,
        'url' => new class {
            public function link(string $p): string { return 'https://example.com/custom-billing/' . ltrim($p, '/'); }
        },
    ]);
    $varsUrlObj = $ctrl->get_refunds($renderStub);
    TestRunner::assertEquals('https://example.com/custom-billing/api/admin/paymos/refund_submit', $varsUrlObj['api_refund_submit']);
});

$runner->test('FOSSBilling 0.8.9 compatibility: templates/admin, relative icon.svg, Config::getProperty, AbstractApi, rel_id<=20, hybrid ORM refund', function () use ($pluginRoot): void {
    // #FB-1: templates/admin/ mirrors html_admin/ for FOSSBilling 0.8.x TwigLoader & Module::hasSettingsPage()
    foreach (['mod_paymos_settings.html.twig', 'mod_paymos_refunds.html.twig'] as $tpl) {
        $legacy = $pluginRoot . '/modules/Paymos/html_admin/' . $tpl;
        $modern = $pluginRoot . '/modules/Paymos/templates/admin/' . $tpl;
        TestRunner::assertTrue(is_file($modern), 'Missing 0.8.x admin template: ' . $tpl);
        TestRunner::assertEquals(file_get_contents($legacy), file_get_contents($modern), 'Template drift in ' . $tpl);
    }

    // #FB-2: manifest.json uses bundled relative icon.svg (never absolute URL prefixed by SYSTEM_URL)
    $manifest = json_decode((string) file_get_contents($pluginRoot . '/modules/Paymos/manifest.json'), true);
    TestRunner::assertEquals('icon.svg', $manifest['icon_url'] ?? null);
    $iconPath = $pluginRoot . '/modules/Paymos/icon.svg';
    TestRunner::assertTrue(is_file($iconPath), 'Bundled modules/Paymos/icon.svg must exist');
    TestRunner::assertContains('<svg', (string) file_get_contents($iconPath));

    // #FB-3: Nested $di['config']['info']['salt'] works directly
    TestRunner::assertEquals('nested-salt-089', CredentialStore::extractSaltFromDi([
        'config' => ['info' => ['salt' => 'nested-salt-089']],
    ]));

    // #FB-3 & #FB-4: Static \FOSSBilling\Config::getProperty() and \FOSSBilling\Api\AbstractApi in isolated subprocess
    $probeCode = <<<'PHP'
namespace FOSSBilling {
    final class Config {
        public static function getProperty(string $key, $default = null) {
            $map = [
                'info.salt' => 'fb089-static-salt-xyz',
                'url' => 'https://billing.meridiancloud.io/',
                'i18n.locale' => 'de_DE',
            ];
            return $map[$key] ?? $default;
        }
    }
}
namespace FOSSBilling\Api {
    abstract class AbstractApi {
        protected $di = null;
        protected $service = null;
        public function setDi($di): void { $this->di = $di; }
        public function getDi() { return $this->di; }
        public function setService($service): void { $this->service = $service; }
        public function getService() { return $this->service; }
    }
}
namespace {
    $root = $argv[1];
    require_once $root . '/modules/Paymos/Api/Guest.php';
    require_once $root . '/modules/Paymos/Api/Admin.php';
    require_once $root . '/modules/Paymos/Service.php';
    $guest = new \Box\Mod\Paymos\Api\Guest();
    $admin = new \Box\Mod\Paymos\Api\Admin();
    $ok = ($guest instanceof \FOSSBilling\Api\AbstractApi)
        && ($admin instanceof \FOSSBilling\Api\AbstractApi)
        && \PaymosFossBilling\CredentialStore::extractSaltFromDi([]) === 'fb089-static-salt-xyz'
        && \Box\Mod\Paymos\Service::resolveStoreUrl([]) === 'https://billing.meridiancloud.io'
        && \PaymosFossBilling\Translation::resolveLocale(null, []) === 'de';
    echo $ok ? 'OK' : 'FAIL';
    exit($ok ? 0 : 1);
}
PHP;
    $tmpProbe = tempnam(sys_get_temp_dir(), 'fb089_probe_');
    file_put_contents($tmpProbe, "<?php\n" . $probeCode);
    $probeOut = [];
    $probeExit = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmpProbe) . ' ' . escapeshellarg($pluginRoot) . ' 2>&1', $probeOut, $probeExit);
    @unlink($tmpProbe);
    TestRunner::assertEquals(0, $probeExit, 'FOSSBilling 0.8.9 subprocess probe failed: ' . implode("\n", $probeOut));
    TestRunner::assertEquals('OK', trim(implode('', $probeOut)));

    // #FB-5: client_balance.rel_id is clamped <= 20 chars, Transaction row created on fixed webhook, notes persisted
    $storedModels = [];
    $txCounter = 41;
    $invoiceBean = (object) ['id' => 901, 'client_id' => 77, 'gateway_id' => 5, 'total' => '25.00', 'currency' => 'USD', 'status' => 'unpaid', 'notes' => ''];
    $clientBean = (object) ['id' => 77];
    $addedFundsCalls = [];
    $paidInvoices = [];
    $rbDb = new class($storedModels, $txCounter, $invoiceBean, $clientBean) {
        public array $stored;
        public int $nextTxId;
        public object $invoice;
        public object $client;
        public function __construct(array &$stored, int &$nextTxId, object $invoice, object $client) {
            $this->stored = &$stored;
            $this->nextTxId = &$nextTxId;
            $this->invoice = $invoice;
            $this->client = $client;
        }
        public function getExistingModelById(string $type, int $id): object {
            if ($type === 'Invoice') return $this->invoice;
            if ($type === 'Client') return $this->client;
            if ($type === 'Transaction') return $this->stored['Transaction'][$id] ?? (object) ['id' => $id];
            throw new \RuntimeException('Unknown ' . $type);
        }
        public function dispense(string $type): object {
            return (object) ['_type' => $type];
        }
        public function store(object $bean): int {
            $type = $bean->_type ?? (isset($bean->client_id) ? 'Invoice' : 'Transaction');
            if ($type === 'Transaction' && !isset($bean->id)) {
                $bean->id = $this->nextTxId++;
            }
            $id = (int) ($bean->id ?? 1);
            $this->stored[$type][$id] = $bean;
            return $id;
        }
    };
    $clientSvc = new class($addedFundsCalls) {
        public array $calls;
        public function __construct(array &$calls) { $this->calls = &$calls; }
        public function addFunds(object $client, string $amount, string $description, array $txData): void {
            $this->calls[] = ['amount' => $amount, 'txData' => $txData];
        }
    };
    $invoiceSvc = new class($paidInvoices) {
        public array $paid;
        public function __construct(array &$paid) { $this->paid = &$paid; }
        public function getTotalWithTax(object $inv): string { return (string) $inv->total; }
        public function isInvoiceTypeDeposit(object $inv): bool { return false; }
        public function payInvoiceWithCredits(object $inv): void { $inv->status = 'paid'; $this->paid[] = $inv->id; }
    };
    // Hybrid 0.8.9 DI: Doctrine $em exists (returns null for Invoice!) AND RedBean $db holds Invoice
    $emNull = new class {
        public int $flushCount = 0;
        public function find(string $class, int $id) { return null; }
        public function flush(): void { $this->flushCount++; }
    };
    $di089 = [
        'em' => $emNull,
        'db' => $rbDb,
        'mod_service' => static fn (string $name) => $name === 'client' ? $clientSvc : $invoiceSvc,
    ];
    $cbs = CallbackProcessor::createFossBillingCallbacks($di089, null, null, static fn (): int => 1795640000);
    ($cbs['applyPayment'])([
        'invoice_id' => 901,
        'client_id' => 77,
        'amount' => '25.00',
        'currency' => 'USD',
        'status' => 'paid',
        'transaction_id' => 'inv_5qWlrtQPq1RRhIBMbUMsLD_long_hash',
        'paymos_invoice_id' => 'inv_5qWlrtQPq1RRhIBMbUMsLD',
    ]);
    TestRunner::assertEquals(1, count($addedFundsCalls));
    TestRunner::assertEquals('41', $addedFundsCalls[0]['txData']['rel_id'], 'rel_id must use created numeric Transaction ID');
    TestRunner::assertTrue(strlen($addedFundsCalls[0]['txData']['rel_id']) <= 20);
    TestRunner::assertEquals('processed', $storedModels['Transaction'][41]->status ?? null);
    TestRunner::assertEquals('paid', $invoiceBean->status);

    // Also verify clamping when no Transaction row can be created
    TestRunner::assertTrue(strlen(CallbackProcessor::formatClientBalanceRelId('', [], 'inv_5qWlrtQPq1RRhIBMbUMsLD_extra')) <= 20);

    // #FB-6: Hybrid 0.8.9 full refund MUST persist via RedBean $db->store() when Doctrine $em->find() returned null
    $driver = new InMemoryDbDriver();
    $credStore = new CredentialStore($driver, 'salt-hybrid-089');
    $snapshots = new SnapshotStore($driver);
    $native089 = RefundAdapter::createFossBillingNativeHandler($di089, $credStore, $snapshots, static fn (): int => 1795640000);
    $res = $native089('refund_invoice_full', [
        'invoice_id' => 901,
        'withdrawal_id' => 'wdr_hybrid_089',
        'operation_id' => 'op_hybrid_089',
        'note' => 'Paymos refund completed',
        'amount_decimal' => '25.00',
        'amount_minor' => '2500',
        'currency' => 'USD',
    ]);
    TestRunner::assertEquals('fossbilling_07_fallback', $res['via']);
    TestRunner::assertEquals('refunded', $storedModels['Invoice'][901]->status ?? null, 'Hybrid 0.8.9 must call $db->store() to persist refunded status');
    TestRunner::assertEquals(0, $emNull->flushCount, 'Doctrine flush must NOT be called when invoice was loaded via RedBean');
    TestRunner::assertContains('Paymos refund completed', (string) ($storedModels['Invoice'][901]->notes ?? ''));
});

// ---------------------------------------------------------------------------
// 7. Feature audit 2026-11-28 (CMSP-02/03/04/09/10/11/13/19/20/38/40)
// ---------------------------------------------------------------------------

/**
 * RedBean-style FOSSBilling double: one invoice bean, one client bean, and an invoice
 * service that behaves like FOSSBilling 0.8.9 (tryPayWithCredits skips unapproved invoices).
 *
 * @return array{di: array<string, mixed>, state: \stdClass}
 */
function auditFossBillingHost(object $invoiceBean, object $clientBean): array
{
    $state = new \stdClass();
    $state->funds = [];
    $state->calls = [];
    $state->deposit = false;
    $state->failPayOnce = false;
    $state->refuseToPay = false;
    $state->onAddFunds = null;
    $state->rejectAddFundsOnce = null;
    // client_balance rows: what FOSSBilling's addFunds() persisted (client_id, type, rel_id).
    $state->ledger = [];
    $state->ledgerReadable = true;

    $db = new class($invoiceBean, $clientBean, $state) {
        private object $invoice;
        private object $client;
        private \stdClass $st;
        public function __construct(object $invoice, object $client, \stdClass $st) { $this->invoice = $invoice; $this->client = $client; $this->st = $st; }
        public function getExistingModelById(string $type, int $id): object {
            if ($type === 'Invoice' && $id === (int) $this->invoice->id) { return $this->invoice; }
            if ($type === 'Client' && $id === (int) $this->client->id) { return $this->client; }
            throw new \RuntimeException('Not found: ' . $type . ' #' . $id);
        }
        public function findOne(string $type, string $where, array $bindings = []) {
            if ($type !== 'ClientBalance' || !$this->st->ledgerReadable) {
                throw new \RuntimeException('Unsupported lookup: ' . $type);
            }
            foreach ($this->st->ledger as $row) {
                if ((string) $row['client_id'] === (string) ($bindings[':client_id'] ?? '')
                    && $row['type'] === ($bindings[':type'] ?? '')
                    && $row['rel_id'] === (string) ($bindings[':rel_id'] ?? '')
                ) {
                    return (object) $row;
                }
            }
            return null;
        }
    };

    $clientService = new class($state) {
        private \stdClass $st;
        public function __construct(\stdClass $st) { $this->st = $st; }
        public function addFunds(object $client, string $amount, string $description, array $data): void {
            if ($this->st->rejectAddFundsOnce !== null) {
                // FOSSBilling validates before persisting (e.g. the client has no currency yet).
                $message = $this->st->rejectAddFundsOnce;
                $this->st->rejectAddFundsOnce = null;
                throw new \RuntimeException($message);
            }
            $this->st->ledger[] = ['client_id' => (int) $client->id, 'type' => (string) ($data['type'] ?? 'gift'), 'rel_id' => (string) ($data['rel_id'] ?? ''), 'amount' => $amount];
            $this->st->funds[] = $amount;
            if ($this->st->onAddFunds !== null) {
                $hook = $this->st->onAddFunds;
                $this->st->onAddFunds = null;
                $hook();
            }
        }
    };

    $invoiceService = new class($state) {
        private \stdClass $st;
        public function __construct(\stdClass $st) { $this->st = $st; }
        public function getTotalWithTax(object $m): string { return (string) $m->total; }
        public function isInvoiceTypeDeposit(object $m): bool { return $this->st->deposit; }
        public function markAsPaid(object $m): void { $this->st->calls[] = 'markAsPaid'; $m->status = 'paid'; }
        public function approveInvoice(object $m, array $data): bool { $this->st->calls[] = 'approveInvoice:' . json_encode($data); $m->approved = 1; return true; }
        public function payInvoiceWithCredits(object $m): bool {
            $this->st->calls[] = 'payInvoiceWithCredits';
            if ($this->st->failPayOnce) {
                $this->st->failPayOnce = false;
                throw new \RuntimeException('Invoice credit payment lock timeout');
            }
            if (!$this->st->refuseToPay && !empty($m->approved)) {
                $m->status = 'paid';
            }
            return true;
        }
    };

    return [
        'di' => [
            'db' => $db,
            'mod_service' => static fn (string $name): object => $name === 'client' ? $clientService : $invoiceService,
        ],
        'state' => $state,
    ];
}

/** @return array<string, mixed> */
function auditSnapshotRow(int $invoiceId, string $paymosInvoiceId, string $amount, int $now, string $status = 'awaiting_payment'): array
{
    return [
        'external_order_id' => 'fossbilling_' . $invoiceId . '_0',
        'paymos_invoice_id' => $paymosInvoiceId,
        'platform_ref' => (string) $invoiceId,
        'client_id' => '61',
        'amount' => $amount,
        'invoice_total' => $amount,
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'payment_url' => 'https://checkout.paymos.io/' . $paymosInvoiceId,
        'status' => $status,
        'renewal_attempt' => 0,
        'created_at' => $now - 60,
        'updated_at' => $now - 60,
    ];
}

/** @return array<string, mixed> */
function auditVerifiedInvoice(int $invoiceId, string $paymosInvoiceId, string $amount, string $status = 'paid'): array
{
    return [
        'invoice_id' => $paymosInvoiceId,
        'project_id' => 'prj_sandbox_fossbilling_001',
        'status' => $status,
        'is_test' => true,
        'order' => ['external_id' => 'fossbilling_' . $invoiceId . '_0', 'amount' => $amount, 'currency' => 'USD'],
        'payment' => ['transfers' => [['status' => 'confirmed', 'tx_hash' => '0xaudit_' . $invoiceId]]],
    ];
}

function auditWebhookBody(string $eventId, string $eventType, array $data, int $now): string
{
    return (string) json_encode([
        'event_id' => $eventId,
        'event_type' => $eventType,
        'version' => 1,
        'occurred_at' => $now,
        'data' => $data,
    ]);
}

$runner->test('Audit CMSP-02: admin actions accept the CSRF token FOSSBilling keeps under csrf_token and sends as X-CSRF-Token', function (): void {
    $db = new InMemoryDbDriver();
    $salt = 'salt-cmsp02';
    $store = new CredentialStore($db, $salt);
    $transport = new QueueTransport();
    // FOSSBilling core keeps the token under `csrf_token` and strips `CSRFToken` from params before the module runs.
    $session = new class {
        public function get(string $key) { return $key === 'csrf_token' ? 'core-csrf-0889' : null; }
    };
    $di = [
        'config' => ['salt' => $salt, 'url' => 'https://billing.example'],
        'auth' => new class { public function isAdminLoggedIn(): bool { return true; } },
        'session' => $session,
    ];
    $api = new AdminApi();
    $api->setDi($di);
    $api->setDbDriver($db);
    $api->setCredentialStore($store);
    $api->setConnectClient(new DeviceConnectClient('https://app.paymos.io', $transport));

    $transport->enqueue(200, [
        'device_code' => 'dc_cmsp02_device',
        'user_code' => 'CSRF-0889',
        'verification_uri_complete' => 'https://app.paymos.io/connect/approve?code=CSRF-0889',
        'expires_in' => 600,
        'interval' => 5,
    ]);
    $previousHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    try {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'core-csrf-0889';
        $start = $api->connect_start([]);
        TestRunner::assertEquals('CSRF-0889', $start['user_code'], 'Connect must start with the core csrf_token');

        $saved = $api->settings_save(['mode' => 'live']);
        TestRunner::assertTrue($saved['saved'] === true, 'Settings must save with the core csrf_token');

        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'forged-token';
        TestRunner::assertThrows(\RuntimeException::class, static fn () => $api->settings_save(['mode' => 'sandbox']), 'csrf_invalid');
    } finally {
        if ($previousHeader === null) {
            unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        } else {
            $_SERVER['HTTP_X_CSRF_TOKEN'] = $previousHeader;
        }
    }

    // The admin pages render the same core token for their JavaScript.
    $renderStub = new class {
        public array $vars = [];
        public function render(string $tpl, array $vars): array { $this->vars = $vars; return $vars; }
    };
    $ctrl = new AdminController();
    $ctrl->setDi(['is_admin_logged' => true, 'session' => $session]);
    $ctrl->get_refunds($renderStub);
    TestRunner::assertEquals('core-csrf-0889', $renderStub->vars['csrf_token'] ?? null, 'Refunds page must carry the core csrf_token');
});

$runner->test('Audit CMSP-03: an add-funds invoice is marked paid and an unapproved invoice is approved before paying from credit', function (): void {
    // Deposit ("Add funds") invoice: balance +amount once, invoice marked paid via markAsPaid (no second credit).
    $deposit = (object) ['id' => 950, 'client_id' => 61, 'status' => 'unpaid', 'approved' => 1, 'total' => '50.00', 'currency' => 'USD', 'notes' => ''];
    $host = auditFossBillingHost($deposit, (object) ['id' => 61]);
    $host['state']->deposit = true;
    $cbs = CallbackProcessor::createFossBillingCallbacks($host['di'], null, null, static fn (): int => 1760060000);
    ($cbs['applyPayment'])([
        'invoice_id' => 950, 'client_id' => 61, 'amount' => '50', 'currency' => 'USD', 'status' => 'paid',
        'transaction_id' => '0xdeposit_950', 'paymos_invoice_id' => 'inv_dep_950',
    ]);
    TestRunner::assertEquals(['50'], $host['state']->funds, 'Deposit must credit the balance exactly once');
    TestRunner::assertEquals(['markAsPaid'], $host['state']->calls, 'Deposit invoice must be closed with markAsPaid');
    TestRunner::assertEquals('paid', $deposit->status, 'Paid add-funds invoice must not stay unpaid');

    // Unapproved invoice: approve without auto-credit, then pay from the credited balance.
    $draft = (object) ['id' => 951, 'client_id' => 61, 'status' => 'unpaid', 'approved' => 0, 'total' => '30.00', 'currency' => 'USD', 'notes' => ''];
    $host2 = auditFossBillingHost($draft, (object) ['id' => 61]);
    $cbs2 = CallbackProcessor::createFossBillingCallbacks($host2['di'], null, null, static fn (): int => 1760060000);
    ($cbs2['applyPayment'])([
        'invoice_id' => 951, 'client_id' => 61, 'amount' => '30', 'currency' => 'USD', 'status' => 'paid',
        'transaction_id' => '0xdraft_951', 'paymos_invoice_id' => 'inv_draft_951',
    ]);
    TestRunner::assertEquals(['approveInvoice:{"use_credits":false}', 'payInvoiceWithCredits'], $host2['state']->calls);
    TestRunner::assertEquals('paid', $draft->status, 'Unapproved invoice must end up paid from the credited balance');

    // FOSSBilling silently declines to pay: the payment must not be reported as applied.
    $stuck = (object) ['id' => 952, 'client_id' => 61, 'status' => 'unpaid', 'approved' => 1, 'total' => '20.00', 'currency' => 'USD', 'notes' => ''];
    $host3 = auditFossBillingHost($stuck, (object) ['id' => 61]);
    $host3['state']->refuseToPay = true;
    $cbs3 = CallbackProcessor::createFossBillingCallbacks($host3['di'], null, null, static fn (): int => 1760060000);
    TestRunner::assertThrows(\RuntimeException::class, static fn () => ($cbs3['applyPayment'])([
        'invoice_id' => 952, 'client_id' => 61, 'amount' => '20', 'currency' => 'USD', 'status' => 'paid',
        'transaction_id' => '0xstuck_952', 'paymos_invoice_id' => 'inv_stuck_952',
    ]), 'manual_review');
    TestRunner::assertEquals('unpaid', $stuck->status);
});

$runner->test('Audit CMSP-04: a retry after a failed credit payment and a cron pass racing the webhook credit the client once', function (): void {
    $db = new InMemoryDbDriver();
    $now = 1760050000;
    $clock = static function () use (&$now): int { return $now; };
    $credStore = new CredentialStore($db, 'salt-cmsp04', $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    $events = new PdoEventStore($db, $clock);
    $config = Config::forWebhooks($credStore);
    $transport = new QueueTransport();

    // (A) addFunds succeeds, payInvoiceWithCredits throws, Paymos retries the same event.
    $bean = (object) ['id' => 960, 'client_id' => 61, 'status' => 'unpaid', 'approved' => 1, 'total' => '40.00', 'currency' => 'USD', 'notes' => ''];
    $host = auditFossBillingHost($bean, (object) ['id' => 61]);
    $host['state']->failPayOnce = true;
    $cbs = CallbackProcessor::createFossBillingCallbacks($host['di'], null, null, $clock);
    $makeProcessor = static fn (): CallbackProcessor => new CallbackProcessor(
        $config, $events, $snapshots, $cbs['loadInvoice'], $cbs['applyPayment'], $cbs['addNote'],
        static fn (string $env): Client => new Client($config->clientConfig($env), $transport), null, $clock
    );
    $snapshots->save(auditSnapshotRow(960, 'inv_cmsp04_960', '40', $now));
    $verified960 = auditVerifiedInvoice(960, 'inv_cmsp04_960', '40');
    $body960 = auditWebhookBody('evt_cmsp04_960', 'invoice.paid', $verified960, $now);
    $sig960 = signWebhook('whsec_sandbox_fossbilling_secret_001', $body960, $now);

    $transport->enqueue(200, $verified960);
    $first = $makeProcessor()->handle($body960, $sig960, $now);
    TestRunner::assertTrue($first['http_status'] !== 200, 'Failed credit payment must be retried by Paymos');
    TestRunner::assertEquals(['40'], $host['state']->funds);
    TestRunner::assertEquals('unpaid', $bean->status);

    $transport->enqueue(200, $verified960);
    $retry = $makeProcessor()->handle($body960, $sig960, $now);
    TestRunner::assertEquals(200, $retry['http_status'], 'Retry must finish the payment');
    TestRunner::assertEquals('paid', $bean->status);
    TestRunner::assertEquals(['40'], $host['state']->funds, 'Retry after a crash must not credit the client a second time');
    TestRunner::assertEquals('paid', $snapshots->findByExternalOrderId('fossbilling_960_0')['final_status']);

    // (B) The cron reconciler reaches the same invoice while the webhook is crediting it.
    $bean961 = (object) ['id' => 961, 'client_id' => 61, 'status' => 'unpaid', 'approved' => 1, 'total' => '15.00', 'currency' => 'USD', 'notes' => ''];
    $host961 = auditFossBillingHost($bean961, (object) ['id' => 61]);
    $cbs961 = CallbackProcessor::createFossBillingCallbacks($host961['di'], null, null, $clock);
    $make961 = static fn (): CallbackProcessor => new CallbackProcessor(
        $config, $events, $snapshots, $cbs961['loadInvoice'], $cbs961['applyPayment'], $cbs961['addNote'],
        static fn (string $env): Client => new Client($config->clientConfig($env), $transport), null, $clock
    );
    $snapshots->save(auditSnapshotRow(961, 'inv_cmsp04_961', '15', $now));
    $verified961 = auditVerifiedInvoice(961, 'inv_cmsp04_961', '15');
    $cronOutcome = null;
    $host961['state']->onAddFunds = static function () use (&$cronOutcome, $make961, $snapshots, $verified961, $now): void {
        try {
            $cronOutcome = $make961()->applyTrustedInvoice($verified961, (array) $snapshots->findByExternalOrderId('fossbilling_961_0'), $now);
        } catch (\Throwable $e) {
            $cronOutcome = $e;
        }
    };
    $body961 = auditWebhookBody('evt_cmsp04_961', 'invoice.paid', $verified961, $now);
    $transport->enqueue(200, $verified961);
    $webhook961 = $make961()->handle($body961, signWebhook('whsec_sandbox_fossbilling_secret_001', $body961, $now), $now);
    TestRunner::assertEquals(200, $webhook961['http_status']);
    TestRunner::assertEquals(['15'], $host961['state']->funds, 'Webhook and cron racing on one invoice must credit once');
    TestRunner::assertTrue($cronOutcome !== true, 'The racing cron pass must not apply the payment again');
    TestRunner::assertTrue($make961()->applyTrustedInvoice($verified961, (array) $snapshots->findByExternalOrderId('fossbilling_961_0'), $now) === false, 'A later cron pass must see the payment as already applied');
    TestRunner::assertEquals(['15'], $host961['state']->funds);
});

$runner->test('Audit CMSP-09: a final 4xx on create ends the creating intent; the next attempt is a fresh invoice with the current amount', function (): void {
    $db = new InMemoryDbDriver();
    $now = 1760070000;
    $clock = static function () use (&$now): int { return $now; };
    $credStore = new CredentialStore($db, 'salt-cmsp09', $clock);
    $credStore->saveCredentials(sampleCredentials());
    $config = Config::fromCredentialStore($credStore, ['mode' => 'sandbox']);
    $snapshots = new SnapshotStore($db, $clock);
    $transport = new QueueTransport();
    $checkout = new CheckoutService($config, $snapshots, static fn (Config $c, ?string $env): Client => new Client($c->clientConfig($env), $transport), null, $clock);
    $belowMinimum = [
        'type' => 'https://paymos.io/docs/errors/codes#invoice_amount_below_minimum',
        'title' => 'Bad Request',
        'status' => 400,
        'detail' => 'Invoice amount is below the minimum.',
        'code' => 'invoice_amount_below_minimum',
        'field' => 'amount',
    ];

    // An intent for 0.50 USD stuck in `creating`; the admin has since raised the invoice to 15 USD.
    $snapshots->save([
        'external_order_id' => 'fossbilling_970_0',
        'paymos_invoice_id' => 'pending:fossbilling_970_0',
        'platform_ref' => '970',
        'amount' => '0.5',
        'invoice_total' => '0.5',
        'currency' => 'USD',
        'environment' => 'sandbox',
        'project_id' => 'prj_sandbox_fossbilling_001',
        'payment_url' => '',
        'status' => CheckoutService::STATUS_CREATING,
        'renewal_attempt' => 0,
        'created_at' => $now - 3600,
        'updated_at' => $now - 3600,
    ]);
    $transport->enqueue(400, $belowMinimum, ['content-type' => 'application/problem+json']);
    $transport->enqueue(201, [
        'invoice_id' => 'inv_fb_970_1',
        'payment_url' => 'https://checkout.paymos.io/inv_fb_970_1',
        'status' => 'awaiting_client',
        'expires_at' => $now + 1800,
    ]);
    $res = $checkout->createCheckout(['id' => 970, 'total' => '15.00', 'currency' => 'USD', 'client_id' => 61]);
    TestRunner::assertEquals('inv_fb_970_1', $res['paymos_invoice_id'], 'A rejected intent must not block the next payment');
    $bodies = array_map(static fn (array $r): array => json_decode((string) $r['body'], true), $transport->requests());
    TestRunner::assertEquals('0.5', $bodies[0]['amount'], 'The stored intent is replayed once');
    TestRunner::assertEquals('fossbilling_970_1', $bodies[1]['external_order_id']);
    TestRunner::assertEquals('15', $bodies[1]['amount'], 'The new attempt uses the current invoice amount');

    // A fresh create rejected with 4xx shows the error now and starts a new attempt next time, without replaying.
    $transport->enqueue(400, $belowMinimum, ['content-type' => 'application/problem+json']);
    TestRunner::assertThrows(\Paymos\Exception\ApiException::class, static fn () => $checkout->createCheckout(['id' => 971, 'total' => '0.40', 'currency' => 'USD']));
    TestRunner::assertTrue(
        !CheckoutService::isPendingIntent((array) $snapshots->findByExternalOrderId('fossbilling_971_0')),
        'A definitive 4xx must end the creating intent'
    );
    $before = count($transport->requests());
    $transport->enqueue(201, [
        'invoice_id' => 'inv_fb_971_1',
        'payment_url' => 'https://checkout.paymos.io/inv_fb_971_1',
        'status' => 'awaiting_client',
        'expires_at' => $now + 1800,
    ]);
    $next = $checkout->createCheckout(['id' => 971, 'total' => '12.00', 'currency' => 'USD']);
    TestRunner::assertEquals('inv_fb_971_1', $next['paymos_invoice_id']);
    TestRunner::assertEquals($before + 1, count($transport->requests()), 'The rejected attempt must not be replayed');
    TestRunner::assertEquals('fossbilling_971_1', json_decode((string) $transport->requests()[$before]['body'], true)['external_order_id']);

    // 5xx stays a lost-response case: the same intent is replayed later.
    $transport->enqueue(503, ['type' => 'about:blank', 'title' => 'Service Unavailable', 'status' => 503, 'detail' => 'Try later.', 'code' => 'unavailable']);
    TestRunner::assertThrows(\Paymos\Exception\ApiException::class, static fn () => $checkout->createCheckout(['id' => 972, 'total' => '9.00', 'currency' => 'USD']));
    TestRunner::assertTrue(CheckoutService::isPendingIntent((array) $snapshots->findByExternalOrderId('fossbilling_972_0')), '5xx must keep the intent for replay');
});

$runner->test('Audit CMSP-10: a stale local expires_at reads the invoice back and reuses it while it is still payable', function (): void {
    $db = new InMemoryDbDriver();
    $now = 1760080000;
    $clock = static function () use (&$now): int { return $now; };
    $credStore = new CredentialStore($db, 'salt-cmsp10', $clock);
    $credStore->saveCredentials(sampleCredentials());
    $config = Config::fromCredentialStore($credStore, ['mode' => 'sandbox']);
    $snapshots = new SnapshotStore($db, $clock);
    $transport = new QueueTransport();
    $checkout = new CheckoutService($config, $snapshots, static fn (Config $c, ?string $env): Client => new Client($c->clientConfig($env), $transport), null, $clock);

    // Created at t0 with expires_at t0+30m; the buyer picked a network at t0+25m, the server moved the deadline, no webhook.
    $row = auditSnapshotRow(980, 'inv_fb_980_0', '20', $now, 'awaiting_client');
    $row['expires_at'] = $now - 360;
    $snapshots->save($row);
    $transport->enqueue(200, [
        'invoice_id' => 'inv_fb_980_0',
        'status' => 'awaiting_payment',
        'expires_at' => $now + 1140,
    ]);
    $res = $checkout->createCheckout(['id' => 980, 'total' => '20.00', 'currency' => 'USD', 'client_id' => 61]);
    TestRunner::assertEquals('https://checkout.paymos.io/inv_fb_980_0', $res['payment_url'], 'A still payable invoice must be reused');
    TestRunner::assertEquals(1, count($transport->requests()));
    TestRunner::assertEquals('GET', $transport->requests()[0]['method'], 'The stale deadline must be read back from Paymos');
});

$runner->test('Audit CMSP-11: an invoice paid another way keeps a later real Paymos payment visible', function (): void {
    $db = new InMemoryDbDriver();
    $now = 1760090000;
    $clock = static function () use (&$now): int { return $now; };
    $credStore = new CredentialStore($db, 'salt-cmsp11', $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    $events = new PdoEventStore($db, $clock);
    $config = Config::forWebhooks($credStore);
    $transport = new QueueTransport();
    $applied = 0;
    $notes = [];
    $processor = new CallbackProcessor(
        $config,
        $events,
        $snapshots,
        static fn (int $id): array => ['id' => $id, 'amount' => '25.00', 'currency' => 'USD', 'paid' => true, 'status' => 'paid', 'client_id' => 61],
        static function () use (&$applied): void { $applied++; },
        static function (int $id, string $note) use (&$notes): void { $notes[] = $note; },
        static fn (string $env): Client => new Client($config->clientConfig($env), $transport),
        null,
        $clock
    );
    $snapshots->save(auditSnapshotRow(965, 'inv_cmsp11_965', '25', $now));

    // The client paid by card; Paymos still reports the crypto payment confirming.
    $confirming = auditVerifiedInvoice(965, 'inv_cmsp11_965', '25', 'confirming');
    $confirmingBody = auditWebhookBody('evt_cmsp11_conf', 'invoice.confirming', $confirming, $now);
    $res1 = $processor->handle($confirmingBody, signWebhook('whsec_sandbox_fossbilling_secret_001', $confirmingBody, $now), $now);
    TestRunner::assertEquals(200, $res1['http_status']);
    TestRunner::assertEquals(null, $snapshots->findByExternalOrderId('fossbilling_965_0')['final_status'], 'A non-final event must not record a final paid status');

    // The crypto payment completes: it is recorded with its transaction and flagged for the admin, never credited.
    $paid = auditVerifiedInvoice(965, 'inv_cmsp11_965', '25', 'paid');
    $paidBody = auditWebhookBody('evt_cmsp11_paid', 'invoice.paid', $paid, $now);
    $transport->enqueue(200, $paid);
    $res2 = $processor->handle($paidBody, signWebhook('whsec_sandbox_fossbilling_secret_001', $paidBody, $now), $now);
    TestRunner::assertEquals(200, $res2['http_status']);
    $snap = $snapshots->findByExternalOrderId('fossbilling_965_0');
    TestRunner::assertEquals('paid', $snap['final_status']);
    TestRunner::assertEquals('0xaudit_965', $snap['transaction_id'], 'The real Paymos payment must keep its transaction for refunds');
    TestRunner::assertEquals(0, $applied, 'An already paid invoice must never be credited again');
    TestRunner::assertTrue(count(array_filter($notes, static fn (string $n): bool => strpos($n, 'Manual review') === 0)) === 1, 'The admin must see a manual review note');
});

$runner->test('Audit CMSP-13: a fresh create answered with an already final invoice is not bound to this invoice', function (): void {
    $db = new InMemoryDbDriver();
    $now = 1760100000;
    $clock = static function () use (&$now): int { return $now; };
    $credStore = new CredentialStore($db, 'salt-cmsp13', $clock);
    $credStore->saveCredentials(sampleCredentials());
    $config = Config::fromCredentialStore($credStore, ['mode' => 'sandbox']);
    $snapshots = new SnapshotStore($db, $clock);
    $transport = new QueueTransport();
    $checkout = new CheckoutService($config, $snapshots, static fn (Config $c, ?string $env): Client => new Client($c->clientConfig($env), $transport), null, $clock);

    // A reinstalled FOSSBilling reuses invoice number 990; Paymos answers the old, already paid invoice for that key.
    $transport->enqueue(200, [
        'invoice_id' => 'inv_old_install_990',
        'payment_url' => 'https://checkout.paymos.io/inv_old_install_990',
        'status' => 'paid',
    ]);
    $transport->enqueue(201, [
        'invoice_id' => 'inv_fb_990_1',
        'payment_url' => 'https://checkout.paymos.io/inv_fb_990_1',
        'status' => 'awaiting_client',
        'expires_at' => $now + 1800,
    ]);
    $res = $checkout->createCheckout(['id' => 990, 'total' => '100.00', 'currency' => 'USD']);
    TestRunner::assertEquals('inv_fb_990_1', $res['paymos_invoice_id'], 'The foreign paid invoice must not become this checkout');
    $old = $snapshots->findByExternalOrderId('fossbilling_990_0');
    TestRunner::assertTrue($old['paymos_invoice_id'] !== 'inv_old_install_990', 'The foreign invoice must not be bound to the snapshot');
    TestRunner::assertEquals(null, $snapshots->findByPaymosInvoiceId('inv_old_install_990'));
});

$runner->test('Audit CMSP-19: FOSSBilling 0.8.9 full refund runs the native refundInvoice($invoice, $note)', function (): void {
    $bean = (object) ['id' => 930, 'client_id' => 61, 'status' => 'paid', 'total' => '25.00', 'currency' => 'USD', 'notes' => ''];
    $refundCalls = [];
    $invoiceSvc = new class($refundCalls) {
        public array $calls;
        public function __construct(array &$calls) { $this->calls = &$calls; }
        public function getTotalWithTax(object $m): string { return (string) $m->total; }
        public function refundInvoice(object $invoice, $note = null): ?int { $this->calls[] = [(int) $invoice->id, $note]; return 931; }
    };
    $stored = [];
    $db = new class($bean, $stored) {
        private object $bean;
        public array $stored;
        public function __construct(object $bean, array &$stored) { $this->bean = $bean; $this->stored = &$stored; }
        public function getExistingModelById(string $type, int $id): object {
            if ($type === 'Invoice' && $id === 930) { return $this->bean; }
            throw new \RuntimeException('Not found');
        }
        public function store(object $bean): int { $this->stored[] = $bean; return (int) ($bean->id ?? 0); }
    };
    $di = ['db' => $db, 'mod_service' => static fn (string $name): object => $invoiceSvc];
    $driver = new InMemoryDbDriver();
    $native = RefundAdapter::createFossBillingNativeHandler($di, new CredentialStore($driver, 'salt-cmsp19'), new SnapshotStore($driver), static fn (): int => 1760110000);
    $res = $native('refund_invoice_full', [
        'invoice_id' => 930,
        'withdrawal_id' => 'wdr_cmsp19_930',
        'operation_id' => 'op_cmsp19_930',
        'note' => 'Full refund completed through Paymos (withdrawal wdr_cmsp19_930).',
        'amount_decimal' => '25.00',
        'amount_minor' => '2500',
        'currency' => 'USD',
    ]);
    TestRunner::assertEquals([[930, 'Full refund completed through Paymos (withdrawal wdr_cmsp19_930).']], $refundCalls, 'refundInvoice must run once with the refund note');
    TestRunner::assertEquals('refunded', $bean->status);
    TestRunner::assertEquals('paymos_refund_wdr_cmsp19_930', $res['native_id']);
});

$runner->test('Audit CMSP-20: mode and button text come from the Paymos module page, not the gateway form', function (): void {
    $form = Payment_Adapter_Paymos::getConfig()['form'];
    TestRunner::assertTrue(!array_key_exists('mode', $form), 'Gateway form must not offer a second mode switch');
    TestRunner::assertTrue(!array_key_exists('button_text', $form), 'Gateway form must not offer a second button text');

    $db = new InMemoryDbDriver();
    $credStore = new CredentialStore($db, 'salt-cmsp20');
    $credStore->saveCredentials(sampleCredentials());

    // Installations that saved Sandbox in the gateway form before and Live on the module page later.
    $credStore->saveSettings(['mode' => 'live', 'button_text' => 'Pay in USDT']);
    $adapter = new Payment_Adapter_Paymos(['mode' => 'sandbox', 'button_text' => 'Old gateway text', 'test_mode' => 0]);
    $adapter->setDbDriver($db);
    $adapter->setCredentialStore($credStore);
    $resolved = $adapter->getResolvedConfig();
    TestRunner::assertEquals('live', $resolved->environment(), 'Checkout must follow the mode saved on the module page');
    TestRunner::assertEquals('Pay in USDT', $resolved->buttonText());

    // Installations that never saved the module page keep what the gateway form held.
    $legacyDb = new InMemoryDbDriver();
    $legacyStore = new CredentialStore($legacyDb, 'salt-cmsp20-legacy');
    $legacyStore->saveCredentials(sampleCredentials());
    $legacy = new Payment_Adapter_Paymos(['mode' => 'live', 'button_text' => 'Legacy text']);
    $legacy->setDbDriver($legacyDb);
    $legacy->setCredentialStore($legacyStore);
    TestRunner::assertEquals('live', $legacy->getResolvedConfig()->environment());
});

$runner->test('Audit CMSP-38: the reconciler moves past rows it skipped and reaches newer missed payments', function (): void {
    $db = new InMemoryDbDriver();
    $now = 1760120000;
    $clock = static function () use (&$now): int { return $now; };
    $credStore = new CredentialStore($db, 'salt-cmsp38', $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    $config = Config::forWebhooks($credStore);
    for ($i = 1; $i <= Reconciler::BATCH_LIMIT; $i++) {
        $row = auditSnapshotRow(1000 + $i, 'inv_idle_' . $i, '10', $now, 'awaiting_client');
        $row['updated_at'] = $now - 7200 + $i;
        $snapshots->save($row);
    }
    $missed = auditSnapshotRow(1100, 'inv_missed_1100', '10', $now, 'confirming');
    $missed['updated_at'] = $now - 600;
    $snapshots->save($missed);

    $gets = [];
    $transportApi = new class($gets) implements TransportInterface {
        public array $gets;
        public function __construct(array &$gets) { $this->gets = &$gets; }
        public function request($method, $url, array $headers, $body, $timeoutSeconds) {
            $id = (string) basename((string) parse_url($url, PHP_URL_PATH));
            $this->gets[] = $id;
            $n = (int) preg_replace('/\D+/', '', $id);
            $platformRef = $id === 'inv_missed_1100' ? 1100 : 1000 + $n;
            return new HttpResponse(200, (string) json_encode([
                'invoice_id' => $id,
                'project_id' => 'prj_sandbox_fossbilling_001',
                'status' => $id === 'inv_missed_1100' ? 'paid' : 'awaiting_client',
                'is_test' => true,
                'order' => ['external_id' => 'fossbilling_' . $platformRef . '_0', 'amount' => '10', 'currency' => 'USD'],
            ]), []);
        }
    };
    $paid = [];
    $processor = new CallbackProcessor(
        $config,
        new PdoEventStore($db, $clock),
        $snapshots,
        static fn (int $id): array => ['id' => $id, 'amount' => '10.00', 'currency' => 'USD', 'paid' => false, 'client_id' => 61],
        static function (array $p) use (&$paid): void { $paid[] = $p['invoice_id']; },
        static function (): void {},
        static fn (string $env): Client => new Client($config->clientConfig($env), $transportApi),
        null,
        $clock
    );
    $reconciler = new Reconciler($config, $credStore, $snapshots, $processor, static fn (string $env): Client => new Client($config->clientConfig($env), $transportApi), $clock);

    TestRunner::assertEquals(0, $reconciler->run($now), 'The first batch holds only idle invoices');
    $now += Reconciler::THROTTLE_SECONDS;
    TestRunner::assertEquals(1, $reconciler->run($now), 'The next pass must reach the newer missed payment');
    TestRunner::assertEquals([1100], $paid);
});

$runner->test('Audit CMSP-40: the cron refund sync runs at most once every 10 minutes', function (): void {
    $db = new InMemoryDbDriver();
    $now = 1760130000;
    $clock = static function () use (&$now): int { return $now; };
    $credStore = new CredentialStore($db, 'salt-cmsp40', $clock);
    $credStore->saveCredentials(sampleCredentials());
    $refundListCalls = 0;
    $transport = new class($refundListCalls) implements TransportInterface {
        public int $calls;
        public function __construct(int &$calls) { $this->calls = &$calls; }
        public function request($method, $url, array $headers, $body, $timeoutSeconds) {
            if ($method === 'GET' && str_contains($url, '/v1/refunds')) {
                $this->calls++;
                return new HttpResponse(200, (string) json_encode(['items' => [], 'next_cursor' => null]), []);
            }
            throw new \RuntimeException("Unexpected request: {$method} {$url}");
        }
    };
    $svc = new ModuleService();
    $svc->setDi(['config' => ['salt' => 'salt-cmsp40', 'url' => 'https://billing.example']]);
    $svc->setDbDriver($db);
    $svc->setCredentialStore($credStore);
    $svc->setClock($clock);
    $svc->setClientFactory(static fn (string $env): Client => new Client(Config::forWebhooks($credStore)->clientConfig($env), $transport));

    TestRunner::assertTrue($svc->handleAfterAdminCronRun(null, $now)['refunds_ran']);
    $afterFirst = $refundListCalls;
    TestRunner::assertTrue($afterFirst > 0);

    $now += 60;
    TestRunner::assertTrue(!$svc->handleAfterAdminCronRun(null, $now)['refunds_ran'], 'A cron tick one minute later must not sync refunds again');
    TestRunner::assertEquals($afterFirst, $refundListCalls);

    // The admin's manual "run now" still syncs at once.
    TestRunner::assertTrue($svc->handleAfterAdminCronRun(null, $now, true)['refunds_ran']);

    $now += Reconciler::THROTTLE_SECONDS;
    TestRunner::assertTrue($svc->handleAfterAdminCronRun(null, $now)['refunds_ran'], 'After 10 minutes the sync runs again');
});

/**
 * Shared webhook rig for the review regressions: one sandbox snapshot, a real settlement
 * callback over auditFossBillingHost(), and a processor factory.
 *
 * @return array{processor: callable(): CallbackProcessor, host: array{di: array<string, mixed>, state: \stdClass}, snapshots: SnapshotStore, transport: QueueTransport, notes: \ArrayObject, body: string, sig: string, verified: array<string, mixed>, bean: object}
 */
function reviewWebhookRig(int $invoiceId, string $amount, int $now): array
{
    $db = new InMemoryDbDriver();
    $clock = static fn (): int => $now;
    $credStore = new CredentialStore($db, 'salt-review-' . $invoiceId, $clock);
    $credStore->saveCredentials(sampleCredentials());
    $snapshots = new SnapshotStore($db, $clock);
    $events = new PdoEventStore($db, $clock);
    $config = Config::forWebhooks($credStore);
    $transport = new QueueTransport();
    $bean = (object) ['id' => $invoiceId, 'client_id' => 61, 'status' => 'unpaid', 'approved' => 1, 'total' => $amount . '.00', 'currency' => 'USD', 'notes' => ''];
    $host = auditFossBillingHost($bean, (object) ['id' => 61]);
    $cbs = CallbackProcessor::createFossBillingCallbacks($host['di'], null, null, $clock);
    $notes = new \ArrayObject();
    $addNote = static function (int $id, string $note) use ($cbs, $notes): void {
        $notes[] = $note;
        ($cbs['addNote'])($id, $note);
    };
    $snapshots->save(auditSnapshotRow($invoiceId, 'inv_review_' . $invoiceId, $amount, $now));
    $verified = auditVerifiedInvoice($invoiceId, 'inv_review_' . $invoiceId, $amount);
    $body = auditWebhookBody('evt_review_' . $invoiceId, 'invoice.paid', $verified, $now);

    return [
        'processor' => static fn (): CallbackProcessor => new CallbackProcessor(
            $config, $events, $snapshots, $cbs['loadInvoice'], $cbs['applyPayment'], $addNote,
            static fn (string $env): Client => new Client($config->clientConfig($env), $transport), null, $clock
        ),
        'host' => $host,
        'snapshots' => $snapshots,
        'transport' => $transport,
        'notes' => $notes,
        'body' => $body,
        'sig' => signWebhook('whsec_sandbox_fossbilling_secret_001', $body, $now),
        'verified' => $verified,
        'bean' => $bean,
    ];
}

$runner->test('Review R-plugins-04: until the module page is saved it shows and keeps the mode set in the gateway form', function (): void {
    $db = new InMemoryDbDriver();
    $salt = 'salt-review-04';
    $store = new CredentialStore($db, $salt);
    $store->saveCredentials(sampleCredentials());
    // An earlier build kept Live in the gateway form (pay_gateway.config).
    $payGateways = new class {
        public function findOne(string $type, string $where, array $bindings = []) {
            if ($type === 'PayGateway' && ($bindings[':gw'] ?? '') === 'Paymos') {
                return (object) ['gateway' => 'Paymos', 'config' => json_encode(['mode' => 'live', 'button_text' => 'Pay in crypto'])];
            }
            return null;
        }
    };
    $session = new class {
        public function get(string $key) { return $key === 'csrf_token' ? 'review-04-token' : null; }
    };
    $api = new AdminApi();
    $api->setDi([
        'config' => ['salt' => $salt, 'url' => 'https://billing.example'],
        'auth' => new class { public function isAdminLoggedIn(): bool { return true; } },
        'session' => $session,
        'db' => $payGateways,
    ]);
    $api->setDbDriver($db);
    $api->setCredentialStore($store);

    $status = $api->status([]);
    TestRunner::assertEquals('live', $status['mode'], 'The module page must show the mode the checkout really uses');
    TestRunner::assertEquals('Pay in crypto', $status['button_text']);

    $previousHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    try {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'review-04-token';
        // The admin only changes the button text; the request carries no mode.
        $saved = $api->settings_save(['button_text' => 'Pay now']);
    } finally {
        if ($previousHeader === null) {
            unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        } else {
            $_SERVER['HTTP_X_CSRF_TOKEN'] = $previousHeader;
        }
    }
    TestRunner::assertEquals('live', $saved['settings']['mode'], 'Saving without touching the mode must keep Live');
    TestRunner::assertEquals('Pay now', $saved['settings']['button_text']);

    $adapter = new Payment_Adapter_Paymos(['mode' => 'live']);
    $adapter->setDbDriver($db);
    $adapter->setCredentialStore($store);
    TestRunner::assertEquals('live', $adapter->getResolvedConfig()->environment());
});

$runner->test('Review R-plugins-09: a credit attempt that never reached the balance is retried, a credit that did is not repeated', function (): void {
    $now = 1760140000;

    // (A) FOSSBilling refuses before writing the balance; the next delivery credits normally.
    $rig = reviewWebhookRig(940, '18', $now);
    $rig['host']['state']->rejectAddFundsOnce = "You must define the client's currency before adding funds.";
    $rig['transport']->enqueue(200, $rig['verified']);
    $first = ($rig['processor'])()->handle($rig['body'], $rig['sig'], $now);
    TestRunner::assertTrue($first['http_status'] !== 200);
    $rig['transport']->enqueue(200, $rig['verified']);
    $retry = ($rig['processor'])()->handle($rig['body'], $rig['sig'], $now);
    TestRunner::assertEquals(200, $retry['http_status'], 'Once the cause is fixed the payment must settle by itself');
    TestRunner::assertEquals(['18'], $rig['host']['state']->funds);
    TestRunner::assertEquals('paid', $rig['bean']->status);

    // (B) addFunds wrote the balance, then the request died before the plugin recorded it.
    $rig2 = reviewWebhookRig(941, '22', $now);
    $rig2['snapshots']->recordFundsCredit('fossbilling_941_0', SnapshotStore::FUNDS_CREDIT_PENDING, '', '941');
    $rig2['host']['state']->ledger[] = ['client_id' => 61, 'type' => 'transaction', 'rel_id' => '941', 'amount' => '22'];
    $rig2['transport']->enqueue(200, $rig2['verified']);
    $recovered = ($rig2['processor'])()->handle($rig2['body'], $rig2['sig'], $now);
    TestRunner::assertEquals(200, $recovered['http_status'], 'A credit found on the balance must be finished, not sent to review');
    TestRunner::assertEquals([], $rig2['host']['state']->funds, 'The balance already holds the payment: no second addFunds');
    TestRunner::assertEquals('paid', $rig2['bean']->status);
});

$runner->test('Review R-plugins-10: a manual review note is written once per case, not on every retry', function (): void {
    $now = 1760150000;
    // The balance cannot be checked, so an unconfirmed credit stays in review across retries.
    $rig = reviewWebhookRig(942, '12', $now);
    $rig['host']['state']->ledgerReadable = false;
    $rig['snapshots']->recordFundsCredit('fossbilling_942_0', SnapshotStore::FUNDS_CREDIT_PENDING, '', '942');
    for ($i = 0; $i < 3; $i++) {
        $rig['transport']->enqueue(200, $rig['verified']);
        $res = ($rig['processor'])()->handle($rig['body'], $rig['sig'], $now);
        TestRunner::assertEquals('manual_review', $res['status']);
    }
    TestRunner::assertEquals([], $rig['host']['state']->funds, 'An unconfirmed credit must never be repeated');
    $manual = array_filter($rig['notes']->getArrayCopy(), static fn (string $n): bool => strpos($n, 'Manual review') === 0);
    TestRunner::assertEquals(1, count($manual), 'Three deliveries of one case must leave one note');
});

echo "\nResults: {$runner->passed} passed, {$runner->failed} failed.\n";
exit($runner->failed === 0 ? 0 : 1);
