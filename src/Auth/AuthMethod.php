<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Auth;

/**
 * Value object describing the chosen OAuth2 authentication method.
 * Passed from the settings layer to the client factory.
 *
 * Two modes are supported, matching SignDocs PHP SDK 1.3.0:
 *
 *   client_secret    — classic OAuth2 client_credentials grant with
 *                      a shared secret. Simple to set up; requires
 *                      storing the secret at rest (AES-256-CBC).
 *
 *   private_key_jwt  — ES256-signed JWT assertion. The plugin holds
 *                      a PEM-encoded ECDSA P-256 private key and a
 *                      key ID (`kid`); the public key is registered
 *                      with SignDocs out-of-band. Preferred by
 *                      regulated customers who can't store shared
 *                      secrets at rest.
 */
final class AuthMethod
{
    public const METHOD_CLIENT_SECRET = 'client_secret';
    public const METHOD_PRIVATE_KEY_JWT = 'private_key_jwt';

    /**
     * @param "client_secret"|"private_key_jwt" $method
     */
    public function __construct(
        public readonly string $method,
        public readonly string $clientId,
        public readonly ?string $clientSecret,
        public readonly ?string $privateKeyPem,
        public readonly ?string $keyId,
    ) {
    }

    /**
     * Read from WP options. Returns null if credentials are incomplete
     * for the selected method.
     *
     * @param callable(string):string $decrypt Same AES-256-CBC helper
     *                                         used by Signdocs_Credentials.
     */
    public static function fromOptions(callable $decrypt): ?self
    {
        $method = (string) \get_option('signdocs_auth_method', self::METHOD_CLIENT_SECRET);
        if (!in_array($method, [self::METHOD_CLIENT_SECRET, self::METHOD_PRIVATE_KEY_JWT], true)) {
            $method = self::METHOD_CLIENT_SECRET;
        }

        $clientId = $decrypt((string) \get_option('signdocs_client_id_enc', ''));
        if ($clientId === '') {
            return null;
        }

        if ($method === self::METHOD_CLIENT_SECRET) {
            $clientSecret = $decrypt((string) \get_option('signdocs_client_secret_enc', ''));
            if ($clientSecret === '') {
                return null;
            }
            return new self(
                method: $method,
                clientId: $clientId,
                clientSecret: $clientSecret,
                privateKeyPem: null,
                keyId: null,
            );
        }

        // private_key_jwt
        $privateKey = $decrypt((string) \get_option('signdocs_private_key_enc', ''));
        $keyId = $decrypt((string) \get_option('signdocs_key_id_enc', ''));
        if ($privateKey === '' || $keyId === '') {
            return null;
        }
        return new self(
            method: $method,
            clientId: $clientId,
            clientSecret: null,
            privateKeyPem: $privateKey,
            keyId: $keyId,
        );
    }
}
