<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Support;

/**
 * PSR-3-ish logger that writes to both `error_log` and a bounded custom
 * table `{$wpdb->prefix}signdocs_log`. The table keeps the last 30 days
 * (pruned by a daily cron) and is the backing store for the admin audit
 * log UI shipped in R2.
 *
 * Never logs: access tokens, client secrets, webhook secrets, or raw
 * signer PII — callers are expected to redact before passing `context`.
 */
final class Logger {

	public const LEVEL_DEBUG   = 'debug';
	public const LEVEL_INFO    = 'info';
	public const LEVEL_WARNING = 'warning';
	public const LEVEL_ERROR   = 'error';

	private const CRON_HOOK      = 'signdocs_prune_logs';
	private const RETENTION_DAYS = 30;

	public static function tableName(): string {
		global $wpdb;
		return $wpdb->prefix . 'signdocs_log';
	}

	/**
	 * Create the log table on plugin activation. Idempotent via dbDelta.
	 */
	public static function installSchema(): void {
		global $wpdb;
		$table   = self::tableName();
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(64) NOT NULL DEFAULT '',
            level VARCHAR(16) NOT NULL DEFAULT 'info',
            message VARCHAR(255) NOT NULL DEFAULT '',
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_created_at (created_at),
            KEY idx_event_type (event_type),
            KEY idx_level (level)
        ) {$charset};";

		\dbDelta( $sql );

		if ( ! \wp_next_scheduled( self::CRON_HOOK ) ) {
			\wp_schedule_event( time() + 3600, 'daily', self::CRON_HOOK );
		}
	}

	public static function dropSchema(): void {
		global $wpdb;
		$table = self::tableName();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + plugin-owned constant, never user input.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		$timestamp = \wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp !== false ) {
			\wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Daily cron callback — keeps only the last RETENTION_DAYS entries.
	 */
	public static function prune(): void {
		global $wpdb;
		$table = self::tableName();
		// $table is $wpdb->prefix + plugin-owned constant; retention-days uses %d placeholder.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				self::RETENTION_DAYS,
			)
		);
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public static function debug( string $eventType, string $message, array $context = array() ): void {
		self::log( self::LEVEL_DEBUG, $eventType, $message, $context );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public static function info( string $eventType, string $message, array $context = array() ): void {
		self::log( self::LEVEL_INFO, $eventType, $message, $context );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public static function warning( string $eventType, string $message, array $context = array() ): void {
		self::log( self::LEVEL_WARNING, $eventType, $message, $context );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public static function error( string $eventType, string $message, array $context = array() ): void {
		self::log( self::LEVEL_ERROR, $eventType, $message, $context );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public static function log( string $level, string $eventType, string $message, array $context = array() ): void {
		if ( function_exists( 'error_log' ) ) {
			$serialized = $context === array() ? '' : ' ' . wp_json_encode( $context );
			error_log( sprintf( '[signdocs/%s] %s %s%s', $level, $eventType, $message, $serialized ) );
		}

		if ( ! isset( $GLOBALS['wpdb'] ) ) {
			return;
		}
		global $wpdb;

		$wpdb->insert(
			self::tableName(),
			array(
				'event_type' => substr( $eventType, 0, 64 ),
				'level'      => substr( $level, 0, 16 ),
				'message'    => substr( $message, 0, 255 ),
				'context'    => $context === array() ? null : wp_json_encode( $context ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
