<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Admin;

use SignDocsBrasil\WordPress\Auth\Capabilities;

/**
 * CSV export endpoint for the audit log.
 *
 * Streams rows to php://output via {@see AuditQuery::chunks()} so
 * large exports don't fill memory. All SQL lives in AuditQuery; this
 * file is purely HTTP + serialization.
 */
final class AuditExport {

	private const CHUNK_SIZE = 500;

	public static function handle(): void {
		if ( ! \current_user_can( Capabilities::VIEW_LOGS ) ) {
			\wp_die( \esc_html__( 'Sem permissão.', 'signdocs-brasil' ), '', array( 'response' => 403 ) );
		}

		\check_admin_referer( 'signdocs_audit_export' );

		$filters = Filters::fromRequest();

		\nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="signdocs-audit-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'wb' );
		if ( $out === false ) {
			\wp_die( 'Failed to open output stream', '', array( 'response' => 500 ) );
		}

		// BOM for Excel compatibility on UTF-8.
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'created_at', 'level', 'event_type', 'message', 'context' ) );

		foreach ( AuditQuery::chunks( $filters, self::CHUNK_SIZE ) as $rows ) {
			foreach ( $rows as $row ) {
				fputcsv(
					$out,
					array(
						(string) $row['created_at'],
						(string) $row['level'],
						(string) $row['event_type'],
						(string) $row['message'],
						(string) ( $row['context'] ?? '' ),
					)
				);
			}
		}

		fclose( $out );
		exit;
	}
}
