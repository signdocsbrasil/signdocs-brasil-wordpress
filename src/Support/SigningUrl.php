<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Support;

/**
 * Assembles the shareable signing link from a created session.
 *
 * `POST /v1/signing-sessions` (and `POST /v1/envelopes/{id}/sessions`)
 * return `url` and `clientSecret` as two separate fields. The `url`
 * alone is NOT a usable link — the hosted page at `/s/:sessionId`
 * rejects the request with HTTP 400 unless the embed token travels
 * with it as the `cs` query parameter:
 *
 *     signingUrl = url + '?cs=' + rawurlencode(clientSecret)
 *
 * The API deliberately keeps the two apart rather than returning a
 * pre-assembled link: `clientSecret` is short-lived, single-use, and
 * specific to one signer's session, and the two-field shape forces
 * callers to notice they are handling an auth-bearing value instead
 * of treating it like an ordinary URL.
 *
 * Every place that surfaces a link to a signer — order emails, admin
 * columns, WP-CLI output — must go through this helper. Before v1.3.8
 * the WooCommerce path and the CPT meta stored the bare `url`, so the
 * "Assinar Documento" button in the WooCommerce order email led to a
 * 400.
 */
final class SigningUrl {

	/**
	 * Build the signable URL for a session.
	 *
	 * Degrades to the bare `$url` when no secret is available rather
	 * than emitting a malformed `?cs=` — an empty secret means the
	 * caller has nothing to append, and a link that 400s is still
	 * better diagnosed than one carrying an empty token.
	 */
	public static function build( string $url, string $clientSecret ): string {
		if ( $url === '' || $clientSecret === '' ) {
			return $url;
		}

		$separator = ( strpos( $url, '?' ) === false ) ? '?' : '&';

		return $url . $separator . 'cs=' . rawurlencode( $clientSecret );
	}

	/**
	 * Build the signable URL from anything shaped like a created
	 * session — `SigningSession` and `EnvelopeSession` both expose
	 * readonly `url` / `clientSecret` properties but share no
	 * interface, so this accepts either (or null, for a failed call).
	 */
	public static function fromSession( ?object $session ): string {
		if ( $session === null ) {
			return '';
		}

		return self::build(
			(string) ( $session->url ?? '' ),
			(string) ( $session->clientSecret ?? '' ),
		);
	}
}
