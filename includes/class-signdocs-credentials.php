<?php

defined('ABSPATH') || exit;

/**
 * Encrypts and decrypts sensitive values stored in wp_options.
 * Uses AES-256-CBC with the WordPress auth salt as the key.
 */
final class Signdocs_Credentials
{
    private const CIPHER = 'aes-256-cbc';

    public static function encrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $key = self::derive_key();
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::CIPHER));
        $encrypted = openssl_encrypt($value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            return '';
        }

        return base64_encode($iv . $encrypted);
    }

    public static function decrypt(string $ciphertext): string
    {
        if ($ciphertext === '') {
            return '';
        }

        $raw = base64_decode($ciphertext, true);
        if ($raw === false) {
            return '';
        }

        $iv_length = openssl_cipher_iv_length(self::CIPHER);
        if (strlen($raw) <= $iv_length) {
            return '';
        }

        $iv = substr($raw, 0, $iv_length);
        $encrypted = substr($raw, $iv_length);
        $key = self::derive_key();

        $decrypted = openssl_decrypt($encrypted, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        return $decrypted !== false ? $decrypted : '';
    }

    private static function derive_key(): string
    {
        return hash('sha256', wp_salt('auth'), true);
    }
}
