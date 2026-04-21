<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Api;

use SignDocsBrasil\Api\TokenCache\CachedToken;
use SignDocsBrasil\Api\TokenCache\TokenCacheInterface;

/**
 * WordPress-transient-backed implementation of the SDK's
 * {@see TokenCacheInterface}. Tokens are shared across every PHP-FPM
 * worker on the same WordPress site, eliminating the one-OAuth-call-
 * per-request pattern that hurts at scale.
 *
 * Transient TTL matches the token's remaining lifetime minus a 60s
 * safety margin — so the cache auto-evicts before the token can expire
 * mid-request.
 */
final class WpTransientTokenCache implements TokenCacheInterface {

	private const TRANSIENT_PREFIX      = 'signdocs_oauth_';
	private const SAFETY_MARGIN_SECONDS = 60;

	public function get( string $key ): ?CachedToken {
		$raw = \get_transient( self::transientKey( $key ) );
		if ( ! is_array( $raw ) || ! isset( $raw['accessToken'], $raw['expiresAt'] ) ) {
			return null;
		}

		return new CachedToken(
			accessToken: (string) $raw['accessToken'],
			expiresAt: (float) $raw['expiresAt'],
		);
	}

	public function set( string $key, CachedToken $token ): void {
		$ttl = (int) floor( $token->expiresAt - microtime( true ) - self::SAFETY_MARGIN_SECONDS );
		if ( $ttl <= 0 ) {
			return;
		}

		\set_transient(
			self::transientKey( $key ),
			array(
				'accessToken' => $token->accessToken,
				'expiresAt'   => $token->expiresAt,
			),
			$ttl,
		);
	}

	public function delete( string $key ): void {
		\delete_transient( self::transientKey( $key ) );
	}

	/**
	 * Keep the transient name short (<=172 chars total) and free of
	 * characters that WP's transient infrastructure disallows. The
	 * SDK's key is already SHA-256-truncated, so we just prefix.
	 */
	private static function transientKey( string $sdkKey ): string {
		return self::TRANSIENT_PREFIX . substr( $sdkKey, 0, 150 );
	}
}
