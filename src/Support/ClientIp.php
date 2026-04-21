<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Support;

/**
 * Derives the "real" client IP, honoring an optional list of trusted
 * proxy CIDR ranges from the `signdocs_trusted_proxies` option.
 *
 * Default behavior (no trusted proxies configured): returns
 * `$_SERVER['REMOTE_ADDR']` verbatim. When the request chain traverses
 * a trusted proxy (e.g. CloudFront, Cloudflare), walks the
 * X-Forwarded-For header from right to left, skipping addresses in
 * any configured trusted range.
 */
final class ClientIp {

	/**
	 * @param array<string,mixed>|null $server optional override (defaults to $_SERVER)
	 */
	public static function resolve( ?array $server = null ): string {
		$server ??= $_SERVER;
		$remote   = isset( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : '';

		$trusted = self::trustedProxies();
		if ( $trusted === array() || ! self::isInRanges( $remote, $trusted ) ) {
			return $remote;
		}

		$forwarded = isset( $server['HTTP_X_FORWARDED_FOR'] ) ? (string) $server['HTTP_X_FORWARDED_FOR'] : '';
		if ( $forwarded === '' ) {
			return $remote;
		}

		$parts = array_map( 'trim', explode( ',', $forwarded ) );
		for ( $i = count( $parts ) - 1; $i >= 0; $i-- ) {
			$candidate = $parts[ $i ];
			if ( $candidate === '' || ! self::isValidIp( $candidate ) ) {
				continue;
			}
			if ( ! self::isInRanges( $candidate, $trusted ) ) {
				return $candidate;
			}
		}

		return $remote;
	}

	/**
	 * @return list<string> CIDR ranges, normalized
	 */
	private static function trustedProxies(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$raw = \get_option( 'signdocs_trusted_proxies', '' );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return array();
		}
		$parts = preg_split( '/[\s,]+/', $raw ) ?: array();
		return array_values( array_filter( array_map( 'trim', $parts ), static fn( $p ) => $p !== '' ) );
	}

	/**
	 * @param list<string> $ranges
	 */
	private static function isInRanges( string $ip, array $ranges ): bool {
		if ( $ip === '' || ! self::isValidIp( $ip ) ) {
			return false;
		}
		foreach ( $ranges as $range ) {
			if ( self::ipInCidr( $ip, $range ) ) {
				return true;
			}
		}
		return false;
	}

	private static function isValidIp( string $ip ): bool {
		return filter_var( $ip, FILTER_VALIDATE_IP ) !== false;
	}

	private static function ipInCidr( string $ip, string $cidr ): bool {
		if ( ! str_contains( $cidr, '/' ) ) {
			return $ip === $cidr;
		}
		[$subnet, $bits] = explode( '/', $cidr, 2 );
		$bits            = (int) $bits;
		$ipPacked        = @inet_pton( $ip );
		$subnetPacked    = @inet_pton( $subnet );
		if ( $ipPacked === false || $subnetPacked === false || strlen( $ipPacked ) !== strlen( $subnetPacked ) ) {
			return false;
		}
		$byteCount    = intdiv( $bits, 8 );
		$bitRemainder = $bits % 8;
		if ( $byteCount > 0 && substr( $ipPacked, 0, $byteCount ) !== substr( $subnetPacked, 0, $byteCount ) ) {
			return false;
		}
		if ( $bitRemainder === 0 ) {
			return true;
		}
		$mask = ~( ( 1 << ( 8 - $bitRemainder ) ) - 1 ) & 0xff;
		return ( ord( $ipPacked[ $byteCount ] ) & $mask ) === ( ord( $subnetPacked[ $byteCount ] ) & $mask );
	}
}
