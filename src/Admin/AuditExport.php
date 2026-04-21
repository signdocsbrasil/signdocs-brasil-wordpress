<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Admin;

use SignDocsBrasil\WordPress\Auth\Capabilities;
use SignDocsBrasil\WordPress\Support\Logger;

/**
 * CSV export endpoint for the audit log. Streams rows to php://output
 * so large exports don't fill memory. Reuses AuditTable's WHERE builder
 * so "what you export" exactly matches "what the table shows".
 */
final class AuditExport
{
    private const CHUNK_SIZE = 500;

    public static function handle(): void
    {
        if (!\current_user_can(Capabilities::VIEW_LOGS)) {
            \wp_die(\esc_html__('Sem permissão.', 'signdocs-brasil'), 403);
        }

        \check_admin_referer('signdocs_audit_export');

        global $wpdb;
        $table = Logger::tableName();
        [$where, $params] = AuditTable::buildWhere();

        \nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="signdocs-audit-' . gmdate('Ymd-His') . '.csv"');

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            \wp_die('Failed to open output stream', 500);
        }
        // BOM for Excel compatibility on UTF-8.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['created_at', 'level', 'event_type', 'message', 'context']);

        $offset = 0;
        while (true) {
            $batchParams = $params;
            $batchParams[] = $offset;
            $batchParams[] = self::CHUNK_SIZE;

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT created_at, level, event_type, message, context
                       FROM {$table}
                      WHERE {$where}
                   ORDER BY id DESC
                      LIMIT %d, %d",
                    ...$batchParams,
                ),
                ARRAY_A,
            );
            if (!is_array($rows) || $rows === []) {
                break;
            }
            foreach ($rows as $row) {
                fputcsv($out, [
                    (string) $row['created_at'],
                    (string) $row['level'],
                    (string) $row['event_type'],
                    (string) $row['message'],
                    (string) ($row['context'] ?? ''),
                ]);
            }
            if (count($rows) < self::CHUNK_SIZE) {
                break;
            }
            $offset += self::CHUNK_SIZE;
        }

        fclose($out);
        exit;
    }
}
