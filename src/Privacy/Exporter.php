<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Privacy;

/**
 * GDPR / LGPD personal-data exporter. Returns every `signdocs_signing`
 * CPT row whose signer email matches the export subject.
 *
 * Registered via the `wp_privacy_personal_data_exporters` filter; WP
 * Core invokes this in pages of 100 rows.
 */
final class Exporter {

	public const EXPORTER_KEY = 'signdocs-brasil';
	private const PAGE_SIZE   = 100;

	/**
	 * @param array<string,array{exporter_friendly_name:string,callback:callable}> $exporters
	 * @return array<string,array{exporter_friendly_name:string,callback:callable}>
	 */
	public static function register( array $exporters ): array {
		$exporters[ self::EXPORTER_KEY ] = array(
			'exporter_friendly_name' => __( 'SignDocs Brasil — Signing Sessions', 'signdocs-brasil' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @return array{data: list<array{group_id:string,group_label:string,item_id:string,data:list<array{name:string,value:string}>}>, done: bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		$offset = ( $page - 1 ) * self::PAGE_SIZE;

		// LGPD/GDPR exporter is invoked from wp-admin's Privacy panel as a
		// one-shot admin operation, never on the request path. meta_query
		// is the only WP-supported way to find posts by signer email; the
		// "slow query" warning targets hot-path code, not this lookup.
		$posts = \get_posts(
			array(
				'post_type'   => 'signdocs_signing',
				'post_status' => 'any',
				'numberposts' => self::PAGE_SIZE,
				'offset'      => $offset,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'  => array(
					array(
						'key'     => '_signdocs_signer_email',
						'value'   => $email,
						'compare' => '=',
					),
				),
			)
		);

		$data = array();
		foreach ( $posts as $post ) {
			$sessionId     = (string) \get_post_meta( $post->ID, '_signdocs_session_id', true );
			$transactionId = (string) \get_post_meta( $post->ID, '_signdocs_transaction_id', true );
			$evidenceId    = (string) \get_post_meta( $post->ID, '_signdocs_evidence_id', true );
			$status        = (string) \get_post_meta( $post->ID, '_signdocs_status', true );
			$completedAt   = (string) \get_post_meta( $post->ID, '_signdocs_completed_at', true );
			$signerName    = (string) \get_post_meta( $post->ID, '_signdocs_signer_name', true );

			$data[] = array(
				'group_id'    => 'signdocs-brasil',
				'group_label' => __( 'SignDocs Brasil — Signing Sessions', 'signdocs-brasil' ),
				'item_id'     => 'signdocs-signing-' . $post->ID,
				'data'        => array(
					array(
						'name'  => __( 'Session ID', 'signdocs-brasil' ),
						'value' => $sessionId,
					),
					array(
						'name'  => __( 'Transaction ID', 'signdocs-brasil' ),
						'value' => $transactionId,
					),
					array(
						'name'  => __( 'Evidence ID', 'signdocs-brasil' ),
						'value' => $evidenceId,
					),
					array(
						'name'  => __( 'Status', 'signdocs-brasil' ),
						'value' => $status,
					),
					array(
						'name'  => __( 'Completed at', 'signdocs-brasil' ),
						'value' => $completedAt,
					),
					array(
						'name'  => __( 'Signer name', 'signdocs-brasil' ),
						'value' => $signerName,
					),
					array(
						'name'  => __( 'Signer email', 'signdocs-brasil' ),
						'value' => $email,
					),
				),
			);
		}

		$done = count( $posts ) < self::PAGE_SIZE;

		return array(
			'data' => $data,
			'done' => $done,
		);
	}
}
