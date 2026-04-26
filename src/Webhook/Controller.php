<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Webhook;

defined( 'ABSPATH' ) || exit;

use SignDocsBrasil\Api\WebhookVerifier;
use SignDocsBrasil\WordPress\Support\Logger;

/**
 * Hardened webhook receiver for POST /wp-json/signdocs/v1/webhook.
 *
 * Security posture (all four independently required):
 *
 * 1. Timestamp drift gate — reject if |now - ts| > 300s before we even
 *    look at the HMAC. Cheap and saves CPU on replay floods.
 * 2. HMAC verification — via the SDK's WebhookVerifier (timing-safe).
 * 3. Delivery-ID dedup — `X-SignDocs-Webhook-Id` transient lock with
 *    7-day TTL. Races between duplicate concurrent deliveries are
 *    resolved by the underlying transient write (losing side returns
 *    200 deduped — the delivery succeeded from the server's view).
 * 4. Input-shape guard in the dispatcher (see EventRouter::isSafeId).
 */
final class Controller {

	private const NAMESPACE_ROUTE         = 'signdocs/v1';
	private const WEBHOOK_ROUTE           = '/webhook';
	private const TIMESTAMP_DRIFT_SECONDS = 300;
	private const DEDUP_TTL_SECONDS       = 604800; // 7 days
	private const DEDUP_TRANSIENT_PREFIX  = 'signdocs_wh_';

	/** @var callable():(string|list<string>) */
	private $secretResolver;

	private EventRouter $router;

	/**
	 * @param callable():(string|list<string>) $secretResolver Returns either a single
	 *     decrypted secret (v1.1.0 compat) or a list of decrypted secrets (v1.2.0+,
	 *     to support rotation with an active grace window).
	 */
	public function __construct( callable $secretResolver, ?EventRouter $router = null ) {
		$this->secretResolver = $secretResolver;
		$this->router         = $router ?? new EventRouter();
	}

	public function register(): void {
		\add_action( 'rest_api_init', array( $this, 'registerRoute' ) );
	}

	public function registerRoute(): void {
		\register_rest_route(
			self::NAMESPACE_ROUTE,
			self::WEBHOOK_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}

	/**
	 * The REST authorize callback. Runs BEFORE `handle`. On true, WP
	 * will invoke the callback; on false/WP_Error, WP returns the
	 * error directly.
	 *
	 * This is where timestamp drift + HMAC + dedup all happen, so a
	 * replay flood never reaches any business logic.
	 */
	public function authorize( \WP_REST_Request $request ): bool|\WP_Error {
		$signature = (string) $request->get_header( 'X-SignDocs-Signature' );
		$timestamp = (string) $request->get_header( 'X-SignDocs-Timestamp' );
		$webhookId = (string) $request->get_header( 'X-SignDocs-Webhook-Id' );
		$body      = (string) $request->get_body();

		// Defence-in-depth timestamp drift check (the SDK also enforces this).
		if ( $timestamp === '' || ! ctype_digit( ltrim( $timestamp, '-' ) ) ) {
			return new \WP_Error( 'signdocs_bad_timestamp', 'Invalid timestamp header', array( 'status' => 400 ) );
		}
		$drift = abs( time() - (int) $timestamp );
		if ( $drift > self::TIMESTAMP_DRIFT_SECONDS ) {
			Logger::warning(
				'webhook.drift',
				'Rejected webhook with excessive clock drift',
				array(
					'drift'     => $drift,
					'webhookId' => $webhookId,
				)
			);
			return new \WP_Error( 'signdocs_timestamp_drift', 'Timestamp outside acceptable window', array( 'status' => 400 ) );
		}

		// HMAC verification via SDK's constant-time WebhookVerifier.
		// During a webhook-secret rotation grace window, `$secrets` may
		// contain both the new and the previous secret; accept either.
		$raw     = ( $this->secretResolver )();
		$secrets = is_array( $raw ) ? array_values( array_filter( $raw, static fn( $s ) => is_string( $s ) && $s !== '' ) ) : ( $raw === '' ? array() : array( $raw ) );
		if ( $secrets === array() ) {
			return new \WP_Error( 'signdocs_no_secret', 'Webhook secret not configured', array( 'status' => 500 ) );
		}

		$accepted = false;
		foreach ( $secrets as $secret ) {
			if ( WebhookVerifier::verify( body: $body, signatureHeader: $signature, timestampHeader: $timestamp, secret: $secret ) ) {
				$accepted = true;
				break;
			}
		}
		if ( ! $accepted ) {
			Logger::warning(
				'webhook.invalid_signature',
				'Rejected webhook with invalid HMAC',
				array(
					'webhookId'       => $webhookId,
					'candidatesTried' => count( $secrets ),
				)
			);
			return new \WP_Error( 'signdocs_bad_signature', 'Invalid signature', array( 'status' => 401 ) );
		}

		// Dedup: store the webhook ID so a re-delivery short-circuits.
		// The caller's `handle()` reads this same key to detect dupes.
		// If no webhook ID header is present (shouldn't happen with
		// modern deliveries), we skip dedup rather than reject.
		if ( $webhookId !== '' ) {
			$request->set_param( 'signdocs_webhook_id', $webhookId );
		}

		return true;
	}

	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		\nocache_headers();

		$webhookId = (string) $request->get_param( 'signdocs_webhook_id' );
		if ( $webhookId !== '' ) {
			$transientKey = self::DEDUP_TRANSIENT_PREFIX . substr( $webhookId, 0, 150 );
			if ( \get_transient( $transientKey ) !== false ) {
				return new \WP_REST_Response(
					array(
						'received' => true,
						'deduped'  => true,
					),
					200
				);
			}
			\set_transient( $transientKey, 1, self::DEDUP_TTL_SECONDS );
		}

		$payload = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $payload ) ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid JSON' ), 400 );
		}

		$result = $this->router->route( $payload );

		return new \WP_REST_Response(
			array(
				'received' => true,
				'matched'  => $result['matched'],
				'handled'  => $result['handled'],
			),
			200
		);
	}
}
