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
	 * @param array<int|string,int|string|null> $parts
	 */
	public static function forAction( string $action, array $parts = array() ): string {
		$userId = 0;
		if ( function_exists( 'get_current_user_id' ) ) {
			$userId = (int) \get_current_user_id();
		}

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
				(string) $userId,
				$action,
				implode( ';', $canonicalParts ),
			)
		);

		return 'sdb-wp-' . substr( hash( 'sha256', $material ), 0, 32 );
	}
}
