<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Privacy;

/**
 * GDPR / LGPD personal-data eraser.
 *
 * Policy: redact signer identity (`name`, `email`) to a one-way hash
 * marker, but PRESERVE the evidence_id, transaction_id, session_id,
 * and timestamps. Rationale: under Brazilian law and most electronic-
 * signature frameworks, signed evidence must be retained for the
 * legal retention period even after the data subject requests erasure.
 * The evidence pack on the server is what proves authenticity; the
 * WP-side row just indexes it. Redacting identity here breaks the
 * local searchable link to the subject while leaving the server-side
 * audit trail intact.
 */
final class Eraser {

	public const ERASER_KEY = 'signdocs-brasil';
	private const PAGE_SIZE = 100;

	/**
	 * @param array<string,array{eraser_friendly_name:string,callback:callable}> $erasers
	 * @return array<string,array{eraser_friendly_name:string,callback:callable}>
	 */
	public static function register( array $erasers ): array {
		$erasers[ self::ERASER_KEY ] = array(
			'eraser_friendly_name' => __( 'SignDocs Brasil — Signing Sessions', 'signdocs-brasil' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		$posts  = \get_posts(
			array(
				'post_type'   => 'signdocs_signing',
				'post_status' => 'any',
				'numberposts' => self::PAGE_SIZE,
				'offset'      => $offset,
				'meta_query'  => array(
					array(
						'key'     => '_signdocs_signer_email',
						'value'   => $email,
						'compare' => '=',
					),
				),
			)
		);

		$itemsRetained = false;
		$messages      = array();
		$token         = substr( hash( 'sha256', $email ), 0, 8 );
		$redactedEmail = '[redacted-' . $token . ']';

		foreach ( $posts as $post ) {
			\update_post_meta( $post->ID, '_signdocs_signer_email', $redactedEmail );
			\update_post_meta( $post->ID, '_signdocs_signer_name', '[redacted-' . $token . ']' );
			$itemsRetained = true;
		}

		if ( $itemsRetained ) {
			$messages[] = __(
				'SignDocs Brasil: signer identity redacted but evidence IDs and completion timestamps retained under the electronic-signature legal retention requirement.',
				'signdocs-brasil',
			);
		}

		$done = count( $posts ) < self::PAGE_SIZE;

		return array(
			'items_removed'  => false,
			'items_retained' => $itemsRetained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}
}
