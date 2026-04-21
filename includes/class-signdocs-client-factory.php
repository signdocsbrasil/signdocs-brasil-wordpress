<?php

defined('ABSPATH') || exit;

use SignDocsBrasil\Api\Config;
use SignDocsBrasil\Api\SignDocsBrasilClient;
use SignDocsBrasil\WordPress\Api\ResponseObserver;
use SignDocsBrasil\WordPress\Api\WpTransientTokenCache;
use SignDocsBrasil\WordPress\Auth\AuthMethod;

/**
 * Builds and caches a SignDocsBrasilClient from WordPress settings.
 *
 * v1.1.0: wires the SDK 1.3.0 TokenCacheInterface (WpTransientTokenCache)
 * and onResponse observer (ResponseObserver) so OAuth tokens are shared
 * across PHP-FPM workers and RateLimit/Deprecation headers are surfaced
 * in the WordPress admin.
 */
final class Signdocs_Client_Factory
{
    private static ?SignDocsBrasilClient $instance = null;

    public static function get_client(): ?SignDocsBrasilClient
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $auth = AuthMethod::fromOptions(
            static fn(string $enc): string => Signdocs_Credentials::decrypt($enc),
        );
        if ($auth === null) {
            return null;
        }

        $environment = get_option('signdocs_environment', 'hml');
        $base_url = $environment === 'prod'
            ? 'https://api.signdocs.com.br'
            : 'https://api-hml.signdocs.com.br';

        try {
            $config = new Config(
                clientId: $auth->clientId,
                clientSecret: $auth->clientSecret,
                privateKey: $auth->privateKeyPem,
                kid: $auth->keyId,
                baseUrl: $base_url,
                tokenCache: new WpTransientTokenCache(),
                onResponse: \Closure::fromCallable(new ResponseObserver()),
            );

            self::$instance = new SignDocsBrasilClient($config);
            return self::$instance;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Alias matching the WP-CLI command convention (get vs get_client).
     */
    public static function get(): ?SignDocsBrasilClient
    {
        return self::get_client();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
