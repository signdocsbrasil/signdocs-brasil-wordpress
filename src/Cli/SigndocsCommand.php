<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Cli;

use SignDocsBrasil\Api\Models\CreateSigningSessionRequest;
use SignDocsBrasil\Api\Models\Signer;
use SignDocsBrasil\Api\Models\Policy;

/**
 * WP-CLI commands for operating the SignDocs Brasil plugin from
 * the shell. Registered only when WP_CLI is loaded.
 *
 *   wp signdocs health
 *   wp signdocs send --document=<id> --email=<e> [--policy=CLICK_ONLY]
 *   wp signdocs status <sessionId>
 *   wp signdocs webhook-test <webhookId>
 *   wp signdocs log-tail [--events=...] [--level=warning] [--limit=50]
 */
final class SigndocsCommand
{
    public static function register(): void
    {
        if (!class_exists(\WP_CLI::class)) {
            return;
        }
        \WP_CLI::add_command('signdocs', self::class);
    }

    /**
     * Hit GET /health against the configured environment.
     *
     * ## EXAMPLES
     *
     *     wp signdocs health
     */
    public function health(array $args, array $assoc): void
    {
        $client = $this->client();
        try {
            $resp = $client->health->check();
            \WP_CLI::success('status: ' . ($resp->status ?? 'unknown'));
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Send a signing session.
     *
     * ## OPTIONS
     *
     * --document=<id>
     * : Document ID to have signed
     *
     * --email=<email>
     * : Signer email
     *
     * [--policy=<policy>]
     * : CLICK_ONLY, CLICK_PLUS_OTP, BIOMETRIC, BIOMETRIC_PLUS_OTP,
     *   DIGITAL_CERTIFICATE, BIOMETRIC_SERPRO, BIOMETRIC_SERPRO_AUTO_FALLBACK
     *
     * ## EXAMPLES
     *
     *     wp signdocs send --document=doc_abc --email=joao@example.com
     */
    public function send(array $args, array $assoc): void
    {
        $documentId = (string) ($assoc['document'] ?? '');
        $email = (string) ($assoc['email'] ?? '');
        $policy = (string) ($assoc['policy'] ?? 'CLICK_ONLY');

        if ($documentId === '' || $email === '') {
            \WP_CLI::error('--document and --email are required');
        }

        $client = $this->client();

        try {
            $request = new CreateSigningSessionRequest(
                documentId: $documentId,
                signer: new Signer(name: $email, email: $email),
                policy: new Policy(profile: $policy),
            );
            $session = $client->signingSessions->create($request);
            \WP_CLI::success('session: ' . ($session->sessionId ?? '?') . ' url: ' . ($session->url ?? '?'));
        } catch (\Throwable $e) {
            \WP_CLI::error('create failed: ' . $e->getMessage());
        }
    }

    /**
     * Look up the status of a signing session by ID.
     *
     * ## OPTIONS
     *
     * <sessionId>
     * : Session ID
     */
    public function status(array $args, array $assoc): void
    {
        $sessionId = (string) ($args[0] ?? '');
        if ($sessionId === '') {
            \WP_CLI::error('session ID required');
        }
        $client = $this->client();
        try {
            $resp = $client->signingSessions->status($sessionId);
            \WP_CLI::line(\wp_json_encode($resp));
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Trigger a test delivery for a registered webhook.
     *
     * ## OPTIONS
     *
     * <webhookId>
     * : Webhook ID
     */
    public function webhook_test(array $args, array $assoc): void
    {
        $webhookId = (string) ($args[0] ?? '');
        if ($webhookId === '') {
            \WP_CLI::error('webhook ID required');
        }
        $client = $this->client();
        try {
            $resp = $client->webhooks->test($webhookId);
            \WP_CLI::success('test queued: ' . \wp_json_encode($resp));
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Show recent entries from the signdocs_log audit table.
     *
     * ## OPTIONS
     *
     * [--events=<csv>]
     * : Comma-separated event_type filter
     *
     * [--level=<level>]
     * : Minimum level (debug|info|warning|error)
     *
     * [--limit=<n>]
     * : Max rows (default 50)
     */
    public function log_tail(array $args, array $assoc): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'signdocs_log';
        $limit = max(1, min(500, (int) ($assoc['limit'] ?? 50)));
        $where = ['1=1'];
        $params = [];

        if (isset($assoc['events']) && is_string($assoc['events'])) {
            $events = array_filter(array_map('trim', explode(',', $assoc['events'])));
            if ($events !== []) {
                $placeholders = implode(',', array_fill(0, count($events), '%s'));
                $where[] = "event_type IN ({$placeholders})";
                $params = array_merge($params, $events);
            }
        }
        if (isset($assoc['level']) && is_string($assoc['level'])) {
            $where[] = 'level = %s';
            $params[] = $assoc['level'];
        }

        $params[] = $limit;
        $query = "SELECT created_at, level, event_type, message FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($query, ...$params));

        foreach (array_reverse($rows ?: []) as $row) {
            \WP_CLI::line(sprintf('%s [%s] %s — %s', $row->created_at, $row->level, $row->event_type, $row->message));
        }
    }

    private function client(): \SignDocsBrasil\Api\SignDocsBrasilClient
    {
        if (!class_exists('Signdocs_Client_Factory')) {
            \WP_CLI::error('Plugin not fully loaded');
        }
        $client = \Signdocs_Client_Factory::get();
        if ($client === null) {
            \WP_CLI::error('Credentials not configured');
        }
        return $client;
    }
}
