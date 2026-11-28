<?php

declare(strict_types=1);

namespace Box\Mod\Paymos\Controller;

require_once dirname(__DIR__) . '/src/Autoloader.php';
\PaymosFossBilling\Autoloader::register();

use Box\Mod\Paymos\Api\Admin as AdminApi;
use Box\Mod\Paymos\Service;
use PaymosFossBilling\Config;
use PaymosFossBilling\Translation;

if (!interface_exists(__NAMESPACE__ . '\\ControllerDiInterface', false)) {
    if (interface_exists('\\FOSSBilling\\Core\\Container\\InjectionAwareInterface')) {
        class_alias('\\FOSSBilling\\Core\\Container\\InjectionAwareInterface', __NAMESPACE__ . '\\ControllerDiInterface');
    } elseif (interface_exists('\\FOSSBilling\\InjectionAwareInterface')) {
        class_alias('\\FOSSBilling\\InjectionAwareInterface', __NAMESPACE__ . '\\ControllerDiInterface');
    } else {
        interface ControllerDiInterface
        {
            /** @param mixed $di */
            public function setDi($di): void;

            /** @return mixed */
            public function getDi();
        }
    }
}

class Admin implements ControllerDiInterface
{
    /** @var mixed */
    protected $di = null;

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

    /**
     * Register Paymos settings and refunds navigation entries in FOSSBilling Admin.
     *
     * @return array<string, mixed>
     */
    public function fetchNavigation(): array
    {
        $url = self::readDiKey($this->di, 'url');
        $adminLink = static function (string $path) use ($url): string {
            if (is_object($url) && method_exists($url, 'adminLink')) {
                return (string) $url->adminLink($path);
            }
            return '/admin/' . ltrim($path, '/');
        };

        return [
            'subpages' => [
                [
                    'location' => 'extensions',
                    'label' => 'Paymos',
                    'index' => 1500,
                    'uri' => $adminLink('paymos'),
                    'class' => '',
                ],
                [
                    'location' => 'invoice',
                    'label' => 'Paymos Refunds',
                    'index' => 1510,
                    'uri' => $adminLink('paymos/refunds'),
                    'class' => '',
                ],
            ],
        ];
    }

    /**
     * Mount Paymos admin routes on FOSSBilling's Box_App router.
     *
     * @param mixed $app
     */
    public function register(&$app): void
    {
        if (!is_object($app) || !method_exists($app, 'get')) {
            return;
        }

        $app->get('/paymos', 'get_index', [], static::class);
        $app->get('/paymos/refunds', 'get_refunds', [], static::class);
        $app->get('/paymos/refunds/:id', 'get_invoice_refunds', ['id' => '[0-9]+'], static::class);
    }

    /**
     * Render Paymos Connect & settings view (zero manual secret inputs).
     *
     * @param mixed $app
     * @return mixed
     */
    public function get_index($app)
    {
        $this->requireAdminSession();
        $locale = Translation::resolveLocale(null, $this->di);
        $storeUrl = Service::resolveStoreUrl($this->di);

        $api = new AdminApi();
        $api->setDi($this->di);
        $status = $api->status(['source_url' => $storeUrl]);

        $vars = [
            'status' => $status,
            'callback_url' => (string) ($status['callback_url'] ?? Config::CALLBACK_PATH),
            'csrf_token' => $this->csrfToken(),
            'locale' => $locale,
            'labels' => Translation::all($locale),
            'api_connect_start' => $this->adminApiUrl('paymos/connect_start'),
            'api_connect_poll' => $this->adminApiUrl('paymos/connect_poll'),
            'api_settings_save' => $this->adminApiUrl('paymos/settings_save'),
        ];

        return $app->render('mod_paymos_settings', $vars);
    }

    /**
     * Render Paymos Refunds lookup page.
     *
     * @param mixed $app
     * @return mixed
     */
    public function get_refunds($app)
    {
        $invoiceId = isset($_GET['invoice_id']) && is_numeric($_GET['invoice_id']) ? (int) $_GET['invoice_id'] : 0;
        return $this->renderRefunds($app, $invoiceId);
    }

    /**
     * Render Paymos Refunds page for a specific invoice ID.
     *
     * @param mixed $app
     * @param mixed $id
     * @return mixed
     */
    public function get_invoice_refunds($app, $id)
    {
        return $this->renderRefunds($app, (int) $id);
    }

    /**
     * @param mixed $app
     * @return mixed
     */
    private function renderRefunds($app, int $invoiceId)
    {
        $this->requireAdminSession();
        $locale = Translation::resolveLocale(null, $this->di);
        $panel = null;
        $panelError = '';

        if ($invoiceId > 0) {
            try {
                $api = new AdminApi();
                $api->setDi($this->di);
                $panel = $api->refund_panel([
                    'invoice_id' => $invoiceId,
                    'locale' => $locale,
                ]);
            } catch (\Throwable $e) {
                $panelError = Config::redactStatic($e->getMessage());
            }
        }

        $vars = [
            'invoice_id' => $invoiceId,
            'panel' => $panel,
            'panel_error' => $panelError,
            'csrf_token' => $this->csrfToken(),
            'locale' => $locale,
            'labels' => Translation::all($locale),
            'api_refund_submit' => $this->adminApiUrl('paymos/refund_submit'),
        ];

        return $app->render('mod_paymos_refunds', $vars);
    }

    private function adminApiUrl(string $endpoint): string
    {
        $clean = ltrim($endpoint, '/');
        $url = self::readDiKey($this->di, 'url');
        if (is_object($url) && method_exists($url, 'link')) {
            $built = (string) $url->link('api/admin/' . $clean);
            if (trim($built) !== '') {
                return $built;
            }
        }

        $storeUrl = Service::resolveStoreUrl($this->di);
        if ($storeUrl !== '') {
            $parsed = parse_url($storeUrl);
            $basePath = is_array($parsed) && isset($parsed['path']) ? rtrim((string) $parsed['path'], '/') : '';
            if ($basePath !== '' && $basePath !== '/') {
                return $basePath . '/index.php?_url=/api/admin/' . $clean;
            }
        }

        return '/index.php?_url=/api/admin/' . $clean;
    }

    private function requireAdminSession(): void
    {
        $flag = self::readDiKey($this->di, 'is_admin_logged');
        if ($flag === true) {
            return;
        }
        if ($flag === false) {
            throw new \RuntimeException('paymos_admin_access_denied', 403);
        }

        $auth = self::readDiKey($this->di, 'auth');
        if (is_object($auth) && method_exists($auth, 'isAdminLoggedIn') && $auth->isAdminLoggedIn()) {
            return;
        }

        throw new \RuntimeException('paymos_admin_access_denied', 403);
    }

    /**
     * FOSSBilling core keeps the admin CSRF token in the session under `csrf_token`
     * (TwigFactory::getCsrfToken, Api controller _checkCSRFToken).
     */
    private function csrfToken(): string
    {
        $session = self::readDiKey($this->di, 'session');
        foreach (['csrf_token', 'CSRFToken'] as $key) {
            if (is_object($session) && method_exists($session, 'get')) {
                $token = $session->get($key);
                if (is_scalar($token) && trim((string) $token) !== '') {
                    return (string) $token;
                }
            }
            if (isset($_SESSION[$key]) && is_scalar($_SESSION[$key]) && trim((string) $_SESSION[$key]) !== '') {
                return (string) $_SESSION[$key];
            }
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
