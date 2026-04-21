<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Api;

use SignDocsBrasil\Api\ResponseMetadata;
use SignDocsBrasil\WordPress\Support\Logger;

/**
 * Receives the SDK's onResponse observer callback (1.3.0+) and wires
 * response-level signals into the WordPress surface:
 *
 * - `RateLimit-*` headers → short-lived transient so the admin
 *   dashboard widget can render "N of M requests remaining"
 * - `Deprecation` / `Sunset` (RFC 8594) → structured log + persistent
 *   dismissible admin notice naming the deprecated endpoint and its
 *   sunset date
 * - Upstream request ID → attached to any log entry the caller
 *   produces later, so support tickets can correlate to backend logs
 *
 * Invariant: the SDK guarantees this observer is only called after the
 * request path has already completed. Any exception here is swallowed
 * by the SDK, but we still guard with try/catch as defence in depth.
 */
final class ResponseObserver {

	public const RATE_LIMIT_TRANSIENT      = 'signdocs_rate_headers';
	public const DEPRECATION_NOTICE_OPTION = 'signdocs_deprecation_notices';
	private const NOTICE_TTL_DAYS          = 30;

	public function __invoke( ResponseMetadata $metadata ): void {
		try {
			$this->captureRateLimit( $metadata );
			$this->captureDeprecation( $metadata );
		} catch ( \Throwable $e ) {
			Logger::warning(
				'response.observer.error',
				$e->getMessage(),
				array(
					'path'   => $metadata->path,
					'status' => $metadata->statusCode,
				)
			);
		}
	}

	private function captureRateLimit( ResponseMetadata $meta ): void {
		if ( $meta->rateLimitLimit === null && $meta->rateLimitRemaining === null ) {
			return;
		}

		\set_transient(
			self::RATE_LIMIT_TRANSIENT,
			array(
				'limit'      => $meta->rateLimitLimit,
				'remaining'  => $meta->rateLimitRemaining,
				'reset'      => $meta->rateLimitReset,
				'path'       => $meta->path,
				'capturedAt' => time(),
			),
			60,
		);
	}

	private function captureDeprecation( ResponseMetadata $meta ): void {
		if ( ! $meta->isDeprecated() ) {
			return;
		}

		$notices = \get_option( self::DEPRECATION_NOTICE_OPTION, array() );
		if ( ! is_array( $notices ) ) {
			$notices = array();
		}

		$id             = md5( $meta->method . ' ' . $meta->path );
		$notices[ $id ] = array(
			'method'      => $meta->method,
			'path'        => $meta->path,
			'deprecation' => $meta->deprecation?->format( 'c' ),
			'sunset'      => $meta->sunset?->format( 'c' ),
			'requestId'   => $meta->requestId,
			'firstSeen'   => $notices[ $id ]['firstSeen'] ?? time(),
			'lastSeen'    => time(),
		);

		// Prune entries older than NOTICE_TTL_DAYS that we haven't seen since.
		$cutoff  = time() - ( self::NOTICE_TTL_DAYS * DAY_IN_SECONDS );
		$notices = array_filter(
			$notices,
			static fn( array $n ) => ( $n['lastSeen'] ?? 0 ) >= $cutoff,
		);

		\update_option( self::DEPRECATION_NOTICE_OPTION, $notices, false );

		Logger::warning(
			'api.deprecation',
			"Deprecated endpoint {$meta->method} {$meta->path}",
			array(
				'sunset'    => $meta->sunset?->format( 'c' ),
				'requestId' => $meta->requestId,
			)
		);
	}
}
