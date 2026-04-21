<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Admin;

use SignDocsBrasil\WordPress\Auth\Capabilities;
use SignDocsBrasil\WordPress\Support\Logger;

// The WP_List_Table class lives in wp-admin/includes/ and is not
// autoloaded. The admin page that instantiates this must require the
// core class first — guarded here for safety.
if ( ! class_exists( \WP_List_Table::class ) ) {
	$core = \defined( 'ABSPATH' ) ? \ABSPATH . 'wp-admin/includes/class-wp-list-table.php' : '';
	if ( $core !== '' && file_exists( $core ) ) {
		require_once $core;
	}
}

/**
 * Admin list table over the `wp_signdocs_log` audit table.
 *
 * Filters: level, event_type, date range. CSV export is handled by
 * {@see AuditExport}, a separate admin-post.php endpoint that reuses
 * the same WHERE clause so "export" matches "view".
 */
final class AuditTable extends \WP_List_Table {

	private const PER_PAGE = 50;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'signdocs_log',
				'plural'   => 'signdocs_logs',
				'ajax'     => false,
			)
		);
	}

	/** @return array<string,string> */
	public function get_columns(): array {
		return array(
			'created_at' => __( 'Data', 'signdocs-brasil' ),
			'level'      => __( 'Nível', 'signdocs-brasil' ),
			'event_type' => __( 'Evento', 'signdocs-brasil' ),
			'message'    => __( 'Mensagem', 'signdocs-brasil' ),
			'context'    => __( 'Contexto', 'signdocs-brasil' ),
		);
	}

	/** @return array<string,array{0:string,1:bool}> */
	protected function get_sortable_columns(): array {
		return array(
			'created_at' => array( 'created_at', true ),
			'level'      => array( 'level', false ),
			'event_type' => array( 'event_type', false ),
		);
	}

	public function prepare_items(): void {
		global $wpdb;
		$table = Logger::tableName();

		[$where, $params] = self::buildWhere();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only pagination/sort; no state change
		$page   = max( 1, (int) ( $_REQUEST['paged'] ?? 1 ) );
		$offset = ( $page - 1 ) * self::PER_PAGE;

		$orderby = in_array( $_REQUEST['orderby'] ?? '', array( 'created_at', 'level', 'event_type' ), true )
			? (string) $_REQUEST['orderby']
			: 'created_at';
		$order   = ( ( $_REQUEST['order'] ?? '' ) === 'asc' ) ? 'ASC' : 'DESC';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $table is our own prefix+const; $where is built in buildWhere() with %s/%d placeholders; $orderby/$order are strict-whitelisted above; $params is a safe spread.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$params )
		);

		$params[] = $offset;
		$params[] = self::PER_PAGE;
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d, %d",
				...$params,
			),
			ARRAY_A,
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$this->items = is_array( $rows ) ? $rows : array();
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	/**
	 * @param array<string,mixed> $item
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'created_at':
				return \esc_html( (string) $item['created_at'] );
			case 'level':
				return '<code>' . \esc_html( (string) $item['level'] ) . '</code>';
			case 'event_type':
				return \esc_html( (string) $item['event_type'] );
			case 'message':
				return \esc_html( (string) $item['message'] );
			case 'context':
				$ctx = (string) ( $item['context'] ?? '' );
				return $ctx === '' ? '' : '<code>' . \esc_html( mb_strimwidth( $ctx, 0, 180, '…' ) ) . '</code>';
		}
		return '';
	}

	/**
	 * Build the WHERE clause shared by the table and the CSV exporter.
	 * Centralized so "what you see" matches "what you export".
	 *
	 * @return array{0:string,1:array<int,int|string>}
	 */
	public static function buildWhere(): array {
		$where  = array( '1=1' );
		$params = array();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters; each value is sanitized/whitelisted below
		$level = isset( $_REQUEST['level'] ) ? \sanitize_text_field( wp_unslash( (string) $_REQUEST['level'] ) ) : '';
		if ( in_array( $level, array( 'debug', 'info', 'warning', 'error' ), true ) ) {
			$where[]  = 'level = %s';
			$params[] = $level;
		}

		$event = isset( $_REQUEST['event_type'] ) ? \sanitize_text_field( wp_unslash( (string) $_REQUEST['event_type'] ) ) : '';
		if ( $event !== '' && preg_match( '/^[A-Za-z0-9._-]{1,64}$/', $event ) ) {
			$where[]  = 'event_type = %s';
			$params[] = $event;
		}

		$from = isset( $_REQUEST['from'] ) ? \sanitize_text_field( wp_unslash( (string) $_REQUEST['from'] ) ) : '';
		if ( $from !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $from . ' 00:00:00';
		}
		$to = isset( $_REQUEST['to'] ) ? \sanitize_text_field( wp_unslash( (string) $_REQUEST['to'] ) ) : '';
		if ( $to !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $to . ' 23:59:59';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array( implode( ' AND ', $where ), $params );
	}

	public static function registerPage(): void {
		\add_action(
			'admin_menu',
			static function (): void {
				\add_submenu_page(
					'edit.php?post_type=signdocs_signing',
					__( 'Audit Log', 'signdocs-brasil' ),
					__( 'Audit Log', 'signdocs-brasil' ),
					Capabilities::VIEW_LOGS,
					'signdocs-audit-log',
					array( self::class, 'render' ),
				);
			}
		);

		// CSV export via admin-post.php
		\add_action( 'admin_post_signdocs_audit_export', array( AuditExport::class, 'handle' ) );
	}

	public static function render(): void {
		if ( ! \current_user_can( Capabilities::VIEW_LOGS ) ) {
			\wp_die( \esc_html__( 'Sem permissão.', 'signdocs-brasil' ) );
		}

		$table = new self();
		$table->prepare_items();

		echo '<div class="wrap"><h1>' . \esc_html__( 'SignDocs Audit Log', 'signdocs-brasil' ) . '</h1>';
		echo '<form method="get">';
		echo '<input type="hidden" name="post_type" value="signdocs_signing" />';
		echo '<input type="hidden" name="page" value="signdocs-audit-log" />';
		$table->display();
		echo '</form>';

		$exportUrl = \wp_nonce_url(
			\admin_url( 'admin-post.php?action=signdocs_audit_export' ),
			'signdocs_audit_export',
		);
		echo '<p><a href="' . \esc_url( $exportUrl ) . '" class="button">' . \esc_html__( 'Export CSV', 'signdocs-brasil' ) . '</a></p>';
		echo '</div>';
	}
}
