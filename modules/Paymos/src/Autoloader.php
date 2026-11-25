<?php

declare(strict_types=1);

namespace PaymosFossBilling;

final class Autoloader
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        spl_autoload_register(static function (string $class): void {
            $pluginPrefix = 'PaymosFossBilling\\';
            if (strncmp($class, $pluginPrefix, strlen($pluginPrefix)) === 0) {
                $relative = substr($class, strlen($pluginPrefix));
                $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
                if (is_file($path)) {
                    require_once $path;
                }
                return;
            }

            $modulePrefix = 'Box\\Mod\\Paymos\\';
            if (strncmp($class, $modulePrefix, strlen($modulePrefix)) === 0) {
                $relative = substr($class, strlen($modulePrefix));
                $path = dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
                if (is_file($path)) {
                    require_once $path;
                }
                return;
            }

            $sdkPrefix = 'Paymos\\';
            if (strncmp($class, $sdkPrefix, strlen($sdkPrefix)) !== 0) {
                return;
            }

            $relative = substr($class, strlen($sdkPrefix));
            $relativePath = str_replace('\\', '/', $relative) . '.php';

            $candidates = [
                dirname(__DIR__) . '/vendor/paymos/php-sdk/src/' . $relativePath,
                dirname(__DIR__, 3) . '/vendor/paymos/php-sdk/src/' . $relativePath,
            ];

            $envSdkSrc = getenv('PAYMOS_SDK_SRC');
            if (is_string($envSdkSrc) && trim($envSdkSrc) !== '') {
                $envBase = rtrim(trim($envSdkSrc), '/\\');
                $candidates[] = $envBase . '/' . $relativePath;
                $candidates[] = $envBase . '/src/' . $relativePath;
            }

            $candidates[] = dirname(__DIR__, 4) . '/php-sdk/src/' . $relativePath;

            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    require_once $candidate;
                    return;
                }
            }
        });
    }
}
