<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Support;

/**
 * Deterministic X-Idempotency-Key generator.
 *
 * The SDK auto-generates random UUIDs, which defeats dedup across AJAX
 * retries (the user clicks twice → two sessions created). This helper
 * produces the same key for the same logical request so the API
 * dedupes server-side.
 *
 * Key material: site URL, WordPress user ID (or 0 for anonymous),
 * action name, and any caller-provided identifying parts (document
 * ID, signer email, etc.). Hashed with SHA-256 so the output looks
 * like an opaque token and doesn't leak any input.
 */
final class IdempotencyKey {

	/**
	 * A key for something a specific user is doing.
	 *
	 * The current user is part of the material, so two administrators clicking
	 * the same button do not collide on one another's cached response.
	 *
	 * @param array<int|string,int|string|null> $parts
	 */
	public static function forAction( string $action, array $parts = array() ): string {
		$userId = 0;
		if ( function_exists( 'get_current_user_id' ) ) {
			$userId = (int) \get_current_user_id();
		}

		return self::build( $action, $parts, (string) $userId );
	}

	/**
	 * A key for something that belongs to a record rather than to a person.
	 *
	 * Deliberately excludes the current user. An order-driven flow is reached
	 * by whoever or whatever moved the order — an administrator, a payment
	 * gateway callback, WP-Cron, a REST request — and folding that identity in
	 * would hand each of them a different key for the same piece of work, which
	 * is exactly the duplicate this is meant to prevent.
	 *
	 * @param array<int|string,int|string|null> $parts
	 */
	public static function forResource( string $action, array $parts = array() ): string {
		return self::build( $action, $parts, '-' );
	}

	/**
	 * @param array<int|string,int|string|null> $parts
	 */
	private static function build( string $action, array $parts, string $actor ): string {
		$siteUrl = '';
		if ( function_exists( 'get_site_url' ) ) {
			$siteUrl = (string) \get_site_url();
		}

		$canonicalParts = array();
		foreach ( $parts as $k => $v ) {
			if ( $v === null ) {
				continue;
			}
			$canonicalParts[] = $k . '=' . (string) $v;
		}
		sort( $canonicalParts );

		$material = implode(
			'|',
			array(
				$siteUrl,
				$actor,
				$action,
				implode( ';', $canonicalParts ),
			)
		);

		return 'sdb-wp-' . substr( hash( 'sha256', $material ), 0, 32 );
	}
}
