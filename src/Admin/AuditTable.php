<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Admin;

use SignDocsBrasil\WordPress\Auth\Capabilities;

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
 * Admin list view over the signdocs audit log.
 *
 * Rendering only — all SQL lives in {@see AuditQuery}. The table
 * constructs a {@see Filters} from the current request and delegates
 * count / select to the query layer.
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
		// Reading $_REQUEST here without a nonce is explicitly allowed
		// by the file-scoped phpcs.xml.dist exclusion: this is WP's own
		// list-table pagination pattern and carries no state change.
		// Every value is then strictly allow-list-validated by Filters.
		$request = \wp_unslash( $_REQUEST );

		$filters = Filters::fromRequest( is_array( $request ) ? $request : array() );

		$page   = max( 1, (int) ( is_array( $request ) && isset( $request['paged'] ) ? $request['paged'] : 1 ) );
		$offset = ( $page - 1 ) * self::PER_PAGE;

		$total = AuditQuery::count( $filters );
		$rows  = AuditQuery::select( $filters, $offset, self::PER_PAGE );

		$this->items = $rows;
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
