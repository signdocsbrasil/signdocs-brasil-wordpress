<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Admin;

use SignDocsBrasil\WordPress\Support\Logger;

/**
 * Single owner of the `{$wpdb->prefix}signdocs_log` query surface.
 *
 * Every raw SQL fragment that touches the audit table lives here. The
 * table's consumers (AuditTable list view, AuditExport CSV streamer,
 * WP-CLI `log-tail`) call the public methods below — they never build
 * SQL themselves. That gives security review a single file to audit.
 *
 * Invariants enforced at the class boundary:
 *
 *   1. The only interpolated identifier is the table name, which is
 *      `$wpdb->prefix . 'signdocs_log'` — a plugin-owned constant,
 *      never user-influenced. MySQL does not accept identifier
 *      placeholders in `$wpdb->prepare()`, so interpolation is
 *      necessary and safe here.
 *
 *   2. Every comparison value goes through `$wpdb->prepare()` with
 *      `%s` / `%d` placeholders. Values passed to `select()`/`chunks()`
 *      via the `Filters` value object are not re-interpolated into
 *      the SQL string; they flow only through the placeholder path.
 *
 *   3. Order column + direction come from {@see Filters::validatedOrderBy}
 *      and {@see Filters::validatedOrder}, both of which strict-whitelist
 *      against a fixed set of allowed values. Non-matching input falls
 *      back to a safe default (`created_at DESC`).
 *
 *   4. Filter values ({@see Filters::level}, `eventType`, date bounds)
 *      are validated by regex/allowlist at {@see Filters::fromRequest}
 *      BEFORE reaching this class. By the time a `Filters` object is
 *      constructed, every string has already been length-bounded and
 *      pattern-checked.
 */
final class AuditQuery {

	private const ALLOWED_ORDER_COLUMNS = array( 'created_at', 'level', 'event_type' );

	public static function tableName(): string {
		return Logger::tableName();
	}

	public static function count( Filters $filters ): int {
		global $wpdb;
		$table            = self::tableName();
		[$where, $params] = self::buildWhere( $filters );

		// {$table} is a constant ({$wpdb->prefix}signdocs_log). {$where} is
		// built from `Filters` whose accessors return either '1=1' or
		// "<allow-listed-column> <op> %s/%d" fragments — never user input.
		// PCP can't see through this dynamic-prepare pattern; placeholders
		// inside {$where} are matched at runtime by ...$params. Direct
		// query / no caching are inherent to a custom plugin table.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( $params === array() ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$params )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Return one page of rows as associative arrays.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function select( Filters $filters, int $offset, int $limit ): array {
		global $wpdb;
		$table            = self::tableName();
		[$where, $params] = self::buildWhere( $filters );

		$orderBy = $filters->validatedOrderBy( self::ALLOWED_ORDER_COLUMNS );
		$order   = $filters->validatedOrder();

		$offset = max( 0, $offset );
		$limit  = max( 1, min( 500, $limit ) );

		$params[] = $offset;
		$params[] = $limit;

		// {$table} is a constant ({$wpdb->prefix}signdocs_log). {$where}
		// is built from `Filters` allow-listed columns ('level = %s' etc.),
		// {$orderBy} is whitelisted via Filters::validatedOrderBy() against
		// ALLOWED_ORDER_COLUMNS, {$order} via Filters::validatedOrder()
		// (returns 'ASC' or 'DESC' only). PCP can't see through the dynamic
		// prepare; %s placeholders in {$where} are matched at runtime.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, level, event_type, message, context
                   FROM {$table}
                  WHERE {$where}
               ORDER BY {$orderBy} {$order}
                  LIMIT %d, %d",
				...$params,
			),
			ARRAY_A,
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Generator that streams rows in chunks for CSV export. Never
	 * materializes the full result set; safe for multi-GB exports.
	 *
	 * @return \Generator<int, list<array<string,mixed>>, mixed, void>
	 */
	public static function chunks( Filters $filters, int $chunkSize = 500 ): \Generator {
		$chunkSize = max( 1, min( 5000, $chunkSize ) );
		$offset    = 0;
		while ( true ) {
			$rows = self::select( $filters, $offset, $chunkSize );
			if ( $rows === array() ) {
				return;
			}
			yield $rows;
			if ( count( $rows ) < $chunkSize ) {
				return;
			}
			$offset += $chunkSize;
		}
	}

	/**
	 * Build the WHERE clause from a {@see Filters}. Returns a
	 * parameterized SQL fragment plus the values for its placeholders.
	 * Public only so the fuzz test can assert invariants on the output.
	 *
	 * @return array{0:string,1:list<string|int>}
	 */
	public static function buildWhere( Filters $filters ): array {
		$where  = array( '1=1' );
		$params = array();

		if ( $filters->level !== null ) {
			$where[]  = 'level = %s';
			$params[] = $filters->level;
		}
		if ( $filters->eventType !== null ) {
			$where[]  = 'event_type = %s';
			$params[] = $filters->eventType;
		}
		if ( $filters->from !== null ) {
			$where[]  = 'created_at >= %s';
			$params[] = $filters->from . ' 00:00:00';
		}
		if ( $filters->to !== null ) {
			$where[]  = 'created_at <= %s';
			$params[] = $filters->to . ' 23:59:59';
		}

		return array( implode( ' AND ', $where ), $params );
	}
}
