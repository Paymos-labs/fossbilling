<?php

declare(strict_types=1);

namespace PaymosFossBilling;

final class Translation
{
    /** @var array<int, string> */
    private const SUPPORTED_LOCALES = ['en', 'ru', 'de', 'es', 'tr', 'zh-Hans'];

    /** @var array<string, string> */
    private const LOCALE_MAP = [
        'en' => 'en',
        'en-us' => 'en',
        'en-gb' => 'en',
        'english' => 'en',
        'ru' => 'ru',
        'ru-ru' => 'ru',
        'russian' => 'ru',
        'de' => 'de',
        'de-de' => 'de',
        'de-at' => 'de',
        'de-ch' => 'de',
        'german' => 'de',
        'deutsch' => 'de',
        'es' => 'es',
        'es-es' => 'es',
        'es-mx' => 'es',
        'es-ar' => 'es',
        'spanish' => 'es',
        'espanol' => 'es',
        'tr' => 'tr',
        'tr-tr' => 'tr',
        'turkish' => 'tr',
        'zh' => 'zh-Hans',
        'zh-cn' => 'zh-Hans',
        'zh-sg' => 'zh-Hans',
        'zh-hans' => 'zh-Hans',
        'zh-hans-cn' => 'zh-Hans',
        'chinese' => 'zh-Hans',
    ];

    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /**
     * @return array<int, string>
     */
    public static function supportedLocales(): array
    {
        return self::SUPPORTED_LOCALES;
    }

    public static function normalizeLocale(?string $locale): string
    {
        if ($locale === null) {
            return 'en';
        }

        $normalized = strtolower(str_replace('_', '-', trim($locale)));
        if ($normalized === '') {
            return 'en';
        }

        if (isset(self::LOCALE_MAP[$normalized])) {
            return self::LOCALE_MAP[$normalized];
        }

        $primary = explode('-', $normalized)[0];
        return self::LOCALE_MAP[$primary] ?? 'en';
    }

    /**
     * Resolve active locale from explicit argument, FOSSBilling DI container, or PHP globals.
     *
     * @param mixed $di
     */
    public static function resolveLocale(?string $explicitLocale = null, $di = null): string
    {
        $candidates = [];
        if ($explicitLocale !== null && trim($explicitLocale) !== '') {
            $candidates[] = $explicitLocale;
        }

        if (isset($_COOKIE['BBLANG']) && is_scalar($_COOKIE['BBLANG'])) {
            $candidates[] = (string) $_COOKIE['BBLANG'];
        }

        if (isset($_SESSION['locale']) && is_scalar($_SESSION['locale'])) {
            $candidates[] = (string) $_SESSION['locale'];
        }

        $config = self::readDiKey($di, 'config');
        if (is_array($config)) {
            if (isset($config['i18n']['locale']) && is_scalar($config['i18n']['locale'])) {
                $candidates[] = (string) $config['i18n']['locale'];
            } elseif (isset($config['locale']) && is_scalar($config['locale'])) {
                $candidates[] = (string) $config['locale'];
            }
        }

        foreach ($candidates as $candidate) {
            $trimmed = trim((string) $candidate);
            if ($trimmed !== '') {
                return self::normalizeLocale($trimmed);
            }
        }

        return 'en';
    }

    public static function text(string $key, ?string $locale = null): string
    {
        $resolved = self::normalizeLocale($locale);
        $catalog = self::catalog($resolved);
        if (isset($catalog[$key]) && is_string($catalog[$key]) && $catalog[$key] !== '') {
            return $catalog[$key];
        }

        $fallback = self::catalog('en');
        if (isset($fallback[$key]) && is_string($fallback[$key])) {
            return $fallback[$key];
        }

        return $key;
    }

    /**
     * @return array<string, string>
     */
    public static function all(?string $locale = null): array
    {
        $resolved = self::normalizeLocale($locale);
        $base = self::catalog('en');
        if ($resolved === 'en') {
            return $base;
        }

        return array_merge($base, self::catalog($resolved));
    }

    public static function isEnglish(?string $locale = null): bool
    {
        return self::normalizeLocale($locale) === 'en';
    }

    /**
     * @return array<string, string>
     */
    private static function catalog(string $locale): array
    {
        if (isset(self::$cache[$locale])) {
            return self::$cache[$locale];
        }

        $path = dirname(__DIR__) . '/lang/' . $locale . '.php';
        if (!is_file($path)) {
            self::$cache[$locale] = [];
            return [];
        }

        $loaded = require $path;
        self::$cache[$locale] = is_array($loaded) ? $loaded : [];
        return self::$cache[$locale];
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
