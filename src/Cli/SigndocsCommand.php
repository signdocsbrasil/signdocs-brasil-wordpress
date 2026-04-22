<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Cli;

use SignDocsBrasil\Api\Models\CreateSigningSessionRequest;
use SignDocsBrasil\Api\Models\Signer;
use SignDocsBrasil\Api\Models\Policy;
use SignDocsBrasil\WordPress\Admin\AuditQuery;
use SignDocsBrasil\WordPress\Admin\Filters;

/**
 * WP-CLI commands for operating the SignDocs Brasil plugin from
 * the shell. Registered only when WP_CLI is loaded.
 *
 *   wp signdocs health
 *   wp signdocs send --document=<id> --email=<e> [--policy=CLICK_ONLY]
 *   wp signdocs status <sessionId>
 *   wp signdocs webhook-test <webhookId>
 *   wp signdocs log-tail [--events=...] [--level=warning] [--limit=50]
 */
final class SigndocsCommand {

	public static function register(): void {
		if ( ! class_exists( \WP_CLI::class ) ) {
			return;
		}
		\WP_CLI::add_command( 'signdocs', self::class );
	}

	/**
	 * Hit GET /health against the configured environment.
	 *
	 * ## EXAMPLES
	 *
	 *     wp signdocs health
	 */
	public function health( array $args, array $assoc ): void {
		$client = $this->client();
		try {
			$resp = $client->health->check();
			\WP_CLI::success( 'status: ' . ( $resp->status ?? 'unknown' ) );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Send a signing session.
	 *
	 * ## OPTIONS
	 *
	 * --document=<id>
	 * : Document ID to have signed
	 *
	 * --email=<email>
	 * : Signer email
	 *
	 * [--policy=<policy>]
	 * : CLICK_ONLY, CLICK_PLUS_OTP, BIOMETRIC, BIOMETRIC_PLUS_OTP,
	 *   DIGITAL_CERTIFICATE, BIOMETRIC_SERPRO, BIOMETRIC_SERPRO_AUTO_FALLBACK
	 *
	 * ## EXAMPLES
	 *
	 *     wp signdocs send --document=doc_abc --email=joao@example.com
	 */
	public function send( array $args, array $assoc ): void {
		$documentId = (string) ( $assoc['document'] ?? '' );
		$email      = (string) ( $assoc['email'] ?? '' );
		$policy     = (string) ( $assoc['policy'] ?? 'CLICK_ONLY' );

		if ( $documentId === '' || $email === '' ) {
			\WP_CLI::error( '--document and --email are required' );
		}

		$client = $this->client();

		try {
			$request = new CreateSigningSessionRequest(
				documentId: $documentId,
				signer: new Signer( name: $email, email: $email ),
				policy: new Policy( profile: $policy ),
			);
			$session = $client->signingSessions->create( $request );
			\WP_CLI::success( 'session: ' . ( $session->sessionId ?? '?' ) . ' url: ' . ( $session->url ?? '?' ) );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( 'create failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Look up the status of a signing session by ID.
	 *
	 * ## OPTIONS
	 *
	 * <sessionId>
	 * : Session ID
	 */
	public function status( array $args, array $assoc ): void {
		$sessionId = (string) ( $args[0] ?? '' );
		if ( $sessionId === '' ) {
			\WP_CLI::error( 'session ID required' );
		}
		$client = $this->client();
		try {
			$resp = $client->signingSessions->status( $sessionId );
			\WP_CLI::line( \wp_json_encode( $resp ) );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Trigger a test delivery for a registered webhook.
	 *
	 * ## OPTIONS
	 *
	 * <webhookId>
	 * : Webhook ID
	 */
	public function webhook_test( array $args, array $assoc ): void {
		$webhookId = (string) ( $args[0] ?? '' );
		if ( $webhookId === '' ) {
			\WP_CLI::error( 'webhook ID required' );
		}
		$client = $this->client();
		try {
			$resp = $client->webhooks->test( $webhookId );
			\WP_CLI::success( 'test queued: ' . \wp_json_encode( $resp ) );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Show recent entries from the signdocs_log audit table.
	 *
	 * ## OPTIONS
	 *
	 * [--events=<csv>]
	 * : Comma-separated event_type filter
	 *
	 * [--level=<level>]
	 * : Minimum level (debug|info|warning|error)
	 *
	 * [--limit=<n>]
	 * : Max rows (default 50)
	 */
	public function log_tail( array $args, array $assoc ): void {
		$limit = max( 1, min( 500, (int) ( $assoc['limit'] ?? 50 ) ) );

		// Build a Filters from the CLI args — same validation guarantees
		// as the admin UI.
		$requestLike = array();
		if ( isset( $assoc['level'] ) && is_string( $assoc['level'] ) ) {
			$requestLike['level'] = $assoc['level'];
		}
		if ( isset( $assoc['events'] ) && is_string( $assoc['events'] ) ) {
			// Pre-1.3.0 CLI accepted multiple events; Filters accepts one.
			// If multiple were passed, use the first — the rest are quietly
			// dropped with a log line so the operator notices.
			$events = array_values( array_filter( array_map( 'trim', explode( ',', $assoc['events'] ) ) ) );
			if ( count( $events ) > 1 ) {
				\WP_CLI::warning( 'Only the first event is applied; multi-event filter was removed in v1.3.0.' );
			}
			if ( $events !== array() ) {
				$requestLike['event_type'] = $events[0];
			}
		}

		$filters = Filters::fromRequest( $requestLike );
		$rows    = AuditQuery::select( $filters, 0, $limit );

		foreach ( array_reverse( $rows ) as $row ) {
			\WP_CLI::line(
				sprintf(
					'%s [%s] %s — %s',
					(string) $row['created_at'],
					(string) $row['level'],
					(string) $row['event_type'],
					(string) $row['message'],
				)
			);
		}
	}

	private function client(): \SignDocsBrasil\Api\SignDocsBrasilClient {
		if ( ! class_exists( 'Signdocs_Client_Factory' ) ) {
			\WP_CLI::error( 'Plugin not fully loaded' );
		}
		$client = \Signdocs_Client_Factory::get();
		if ( $client === null ) {
			\WP_CLI::error( 'Credentials not configured' );
		}
		return $client;
	}
}
