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
	 * : WordPress attachment ID of the PDF to sign
	 *
	 * --email=<email>
	 * : Signer email
	 *
	 * [--name=<name>]
	 * : Signer full name (defaults to email if omitted)
	 *
	 * [--cpf=<cpf>]
	 * : Signer CPF (11 digits, dots/dashes optional). At least one of
	 *   --cpf or --cnpj is required by the API.
	 *
	 * [--cnpj=<cnpj>]
	 * : Signer CNPJ (14 digits, formatting optional). Use instead of
	 *   --cpf for legal entities.
	 *
	 * [--policy=<policy>]
	 * : CLICK_ONLY, CLICK_PLUS_OTP, BIOMETRIC, BIOMETRIC_PLUS_OTP,
	 *   DIGITAL_CERTIFICATE, BIOMETRIC_SERPRO, BIOMETRIC_SERPRO_AUTO_FALLBACK
	 *
	 * ## EXAMPLES
	 *
	 *     wp signdocs send --document=42 --email=joao@example.com --cpf=12345678901
	 *     wp signdocs send --document=42 --email=contrato@acme.com --cnpj=12345678000190 --policy=CLICK_PLUS_OTP
	 */
	public function send( array $args, array $assoc ): void {
		$documentId = (int) ( $assoc['document'] ?? 0 );
		$email      = (string) ( $assoc['email'] ?? '' );
		$name       = (string) ( $assoc['name'] ?? '' );
		$cpf        = self::digitsOnly( (string) ( $assoc['cpf'] ?? '' ) );
		$cnpj       = self::digitsOnly( (string) ( $assoc['cnpj'] ?? '' ) );
		$policy     = (string) ( $assoc['policy'] ?? 'CLICK_ONLY' );

		if ( $documentId <= 0 || $email === '' ) {
			\WP_CLI::error( '--document (WordPress attachment ID) and --email are required' );
		}
		if ( $cpf === '' && $cnpj === '' ) {
			\WP_CLI::error( '--cpf or --cnpj is required (the SignDocs API requires at least one)' );
		}
		if ( $cpf !== '' && strlen( $cpf ) !== 11 ) {
			\WP_CLI::error( '--cpf must be 11 digits (got ' . strlen( $cpf ) . ')' );
		}
		if ( $cnpj !== '' && strlen( $cnpj ) !== 14 ) {
			\WP_CLI::error( '--cnpj must be 14 digits (got ' . strlen( $cnpj ) . ')' );
		}

		$filePath = get_attached_file( $documentId );
		if ( ! $filePath || ! file_exists( $filePath ) ) {
			\WP_CLI::error( 'attachment ' . $documentId . ' not found or unreadable' );
		}
		$pdfContent = file_get_contents( $filePath );
		if ( $pdfContent === false ) {
			\WP_CLI::error( 'failed to read attachment ' . $documentId );
		}

		$client = $this->client();

		try {
			$request = new CreateSigningSessionRequest(
				purpose: 'DOCUMENT_SIGNATURE',
				policy: new Policy( profile: $policy ),
				signer: new Signer(
					name: $name !== '' ? $name : $email,
					userExternalId: 'wp_cli_' . md5( $email ),
					cpf: $cpf !== '' ? $cpf : null,
					cnpj: $cnpj !== '' ? $cnpj : null,
					email: $email,
				),
				document: array(
					'content'  => base64_encode( $pdfContent ),
					'filename' => basename( $filePath ),
				),
			);
			$session = $client->signingSessions->create( $request );

			// Build the shareable signing URL. The base URL alone is NOT
			// usable — it requires the embed token (clientSecret) appended
			// as the `cs` query parameter. See sdks/docs/ for the contract.
			$baseUrl       = (string) ( $session->url ?? '' );
			$clientSecret  = (string) ( $session->clientSecret ?? '' );
			$signingUrl    = $baseUrl;
			if ( $baseUrl !== '' && $clientSecret !== '' ) {
				$separator   = ( strpos( $baseUrl, '?' ) === false ) ? '?' : '&';
				$signingUrl .= $separator . 'cs=' . rawurlencode( $clientSecret );
			}

			\WP_CLI::success( 'session: ' . ( $session->sessionId ?? '?' ) );
			\WP_CLI::line( 'sign at: ' . $signingUrl );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( 'create failed: ' . $e->getMessage() );
		}
	}

	private static function digitsOnly( string $raw ): string {
		return preg_replace( '/\D+/', '', $raw ) ?? '';
	}

	/**
	 * Look up the status of a signing session by ID.
	 *
	 * Note: GET /v1/signing-sessions/{id}/status authenticates via the
	 * embed token (clientSecret) returned by the create call, not the
	 * tenant's OAuth bearer. To use this command you must supply the
	 * clientSecret your application stored after creating the session.
	 * If you only have the sessionId, look it up in WP Admin >
	 * Signatures (the CPT meta `_signdocs_session_url` carries the
	 * full embed URL with cs=).
	 *
	 * ## OPTIONS
	 *
	 * <sessionId>
	 * : Session ID returned by `wp signdocs send` or the API
	 *
	 * --client-secret=<secret>
	 * : The embed token (clientSecret) returned by the create call.
	 *   Required because the status endpoint authenticates via embed
	 *   token, not the tenant OAuth bearer.
	 */
	public function status( array $args, array $assoc ): void {
		$sessionId    = (string) ( $args[0] ?? '' );
		$clientSecret = (string) ( $assoc['client-secret'] ?? '' );
		if ( $sessionId === '' ) {
			\WP_CLI::error( 'session ID required' );
		}
		if ( $clientSecret === '' ) {
			\WP_CLI::error(
				'--client-secret is required (the status endpoint authenticates via the session\'s embed token, not the tenant OAuth bearer)'
			);
		}
		\WP_CLI::warning(
			'Per-session embed-token auth is not yet wrapped by the SDK; raw HTTP call coming in a follow-up release.'
		);
		\WP_CLI::error( 'not implemented in v1.3.x' );
	}

	/**
	 * Trigger a test delivery for a registered webhook.
	 *
	 * ## OPTIONS
	 *
	 * <webhookId>
	 * : Webhook ID
	 *
	 * @subcommand webhook-test
	 */
	public function webhook_test( array $args, array $assoc ): void {
		$webhookId = (string) ( $args[0] ?? '' );
		if ( $webhookId === '' ) {
			\WP_CLI::error( 'webhook ID required' );
		}
		$client = $this->client();
		try {
			// The SDK's typed WebhookTestResponse model is misaligned with the
			// actual API shape (`{webhookId, testDelivery: {httpStatus, success,
			// timestamp}}`), so it returns all-empty fields. Until the SDK is
			// fixed, bypass it and call the raw HTTP path directly via reflection.
			$ref  = new \ReflectionClass( $client->webhooks );
			$prop = $ref->getProperty( 'http' );
			$prop->setAccessible( true );
			$http = $prop->getValue( $client->webhooks );
			$data = $http->request( 'POST', '/v1/webhooks/' . rawurlencode( $webhookId ) . '/test' );

			$delivery = is_array( $data['testDelivery'] ?? null ) ? $data['testDelivery'] : array();
			$status   = (int) ( $delivery['httpStatus'] ?? 0 );
			$success  = (bool) ( $delivery['success'] ?? false );

			if ( $success ) {
				\WP_CLI::success( sprintf( 'test delivered (HTTP %d) at %s', $status, (string) ( $delivery['timestamp'] ?? '' ) ) );
			} else {
				\WP_CLI::warning( sprintf( 'test endpoint responded HTTP %d', $status ) );
			}
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
	 *
	 * @subcommand log-tail
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
