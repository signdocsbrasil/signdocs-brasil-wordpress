<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Admin;

use SignDocsBrasil\WordPress\Auth\Capabilities;
use SignDocsBrasil\WordPress\Support\Logger;

// The WP_List_Table class lives in wp-admin/includes/ and is not
// autoloaded. The admin page that instantiates this must require the
// core class first — guarded here for safety.
if (!class_exists(\WP_List_Table::class)) {
    $core = \defined('ABSPATH') ? \ABSPATH . 'wp-admin/includes/class-wp-list-table.php' : '';
    if ($core !== '' && file_exists($core)) {
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
final class AuditTable extends \WP_List_Table
{
    private const PER_PAGE = 50;

    public function __construct()
    {
        parent::__construct([
            'singular' => 'signdocs_log',
            'plural' => 'signdocs_logs',
            'ajax' => false,
        ]);
    }

    /** @return array<string,string> */
    public function get_columns(): array
    {
        return [
            'created_at' => __('Data', 'signdocs-brasil'),
            'level' => __('Nível', 'signdocs-brasil'),
            'event_type' => __('Evento', 'signdocs-brasil'),
            'message' => __('Mensagem', 'signdocs-brasil'),
            'context' => __('Contexto', 'signdocs-brasil'),
        ];
    }

    /** @return array<string,array{0:string,1:bool}> */
    protected function get_sortable_columns(): array
    {
        return [
            'created_at' => ['created_at', true],
            'level' => ['level', false],
            'event_type' => ['event_type', false],
        ];
    }

    public function prepare_items(): void
    {
        global $wpdb;
        $table = Logger::tableName();

        [$where, $params] = self::buildWhere();

        $page = max(1, (int) ($_REQUEST['paged'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $orderby = in_array($_REQUEST['orderby'] ?? '', ['created_at', 'level', 'event_type'], true)
            ? (string) $_REQUEST['orderby']
            : 'created_at';
        $order = (($_REQUEST['order'] ?? '') === 'asc') ? 'ASC' : 'DESC';

        $total = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$params)
        );

        $params[] = $offset;
        $params[] = self::PER_PAGE;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d, %d",
                ...$params,
            ),
            ARRAY_A,
        );

        $this->items = is_array($rows) ? $rows : [];
        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => self::PER_PAGE,
            'total_pages' => (int) ceil($total / self::PER_PAGE),
        ]);
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
    }

    /**
     * @param array<string,mixed> $item
     */
    public function column_default($item, $column_name): string
    {
        switch ($column_name) {
            case 'created_at':
                return \esc_html((string) $item['created_at']);
            case 'level':
                return '<code>' . \esc_html((string) $item['level']) . '</code>';
            case 'event_type':
                return \esc_html((string) $item['event_type']);
            case 'message':
                return \esc_html((string) $item['message']);
            case 'context':
                $ctx = (string) ($item['context'] ?? '');
                return $ctx === '' ? '' : '<code>' . \esc_html(mb_strimwidth($ctx, 0, 180, '…')) . '</code>';
        }
        return '';
    }

    /**
     * Build the WHERE clause shared by the table and the CSV exporter.
     * Centralized so "what you see" matches "what you export".
     *
     * @return array{0:string,1:array<int,int|string>}
     */
    public static function buildWhere(): array
    {
        $where = ['1=1'];
        $params = [];

        $level = isset($_REQUEST['level']) ? \sanitize_text_field((string) $_REQUEST['level']) : '';
        if (in_array($level, ['debug', 'info', 'warning', 'error'], true)) {
            $where[] = 'level = %s';
            $params[] = $level;
        }

        $event = isset($_REQUEST['event_type']) ? \sanitize_text_field((string) $_REQUEST['event_type']) : '';
        if ($event !== '' && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $event)) {
            $where[] = 'event_type = %s';
            $params[] = $event;
        }

        $from = isset($_REQUEST['from']) ? \sanitize_text_field((string) $_REQUEST['from']) : '';
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'created_at >= %s';
            $params[] = $from . ' 00:00:00';
        }
        $to = isset($_REQUEST['to']) ? \sanitize_text_field((string) $_REQUEST['to']) : '';
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'created_at <= %s';
            $params[] = $to . ' 23:59:59';
        }

        return [implode(' AND ', $where), $params];
    }

    public static function registerPage(): void
    {
        \add_action('admin_menu', static function (): void {
            \add_submenu_page(
                'edit.php?post_type=signdocs_signing',
                __('Audit Log', 'signdocs-brasil'),
                __('Audit Log', 'signdocs-brasil'),
                Capabilities::VIEW_LOGS,
                'signdocs-audit-log',
                [self::class, 'render'],
            );
        });

        // CSV export via admin-post.php
        \add_action('admin_post_signdocs_audit_export', [AuditExport::class, 'handle']);
    }

    public static function render(): void
    {
        if (!\current_user_can(Capabilities::VIEW_LOGS)) {
            \wp_die(\esc_html__('Sem permissão.', 'signdocs-brasil'));
        }

        $table = new self();
        $table->prepare_items();

        echo '<div class="wrap"><h1>' . \esc_html__('SignDocs Audit Log', 'signdocs-brasil') . '</h1>';
        echo '<form method="get">';
        echo '<input type="hidden" name="post_type" value="signdocs_signing" />';
        echo '<input type="hidden" name="page" value="signdocs-audit-log" />';
        $table->display();
        echo '</form>';

        $exportUrl = \wp_nonce_url(
            \admin_url('admin-post.php?action=signdocs_audit_export'),
            'signdocs_audit_export',
        );
        echo '<p><a href="' . \esc_url($exportUrl) . '" class="button">' . \esc_html__('Export CSV', 'signdocs-brasil') . '</a></p>';
        echo '</div>';
    }
}
