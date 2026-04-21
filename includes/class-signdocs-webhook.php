<?php

defined('ABSPATH') || exit;

use SignDocsBrasil\Api\WebhookVerifier;

/**
 * REST API endpoint to receive SignDocs webhook events.
 *
 * POST /wp-json/signdocs/v1/webhook
 */
final class Signdocs_Webhook
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_route']);
    }

    public function register_route(): void
    {
        register_rest_route('signdocs/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle'],
            'permission_callback' => '__return_true', // Auth is via HMAC signature
        ]);
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        nocache_headers();

        $body = $request->get_body();
        $signature = $request->get_header('X-SignDocs-Signature') ?? '';
        $timestamp = $request->get_header('X-SignDocs-Timestamp') ?? '';

        // Verify HMAC signature
        $secret = Signdocs_Credentials::decrypt(get_option('signdocs_webhook_secret_enc', ''));
        if ($secret === '') {
            return new \WP_REST_Response(['error' => 'Webhook secret not configured'], 500);
        }

        if (!class_exists(WebhookVerifier::class)) {
            return new \WP_REST_Response(['error' => 'SDK not available'], 500);
        }

        $valid = WebhookVerifier::verify(
            body: $body,
            signatureHeader: $signature,
            timestampHeader: $timestamp,
            secret: $secret,
        );

        if (!$valid) {
            return new \WP_REST_Response(['error' => 'Invalid signature'], 401);
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return new \WP_REST_Response(['error' => 'Invalid JSON'], 400);
        }

        $event_type = $payload['eventType'] ?? '';
        $session_id = $payload['data']['sessionId'] ?? '';
        $transaction_id = $payload['data']['transactionId'] ?? $payload['transactionId'] ?? '';

        // Find the CPT post by session ID or transaction ID
        $post_id = $this->find_post($session_id, $transaction_id);
        if ($post_id === 0) {
            // Not found — acknowledge anyway to prevent retries
            return new \WP_REST_Response(['received' => true, 'matched' => false], 200);
        }

        // Update based on event type
        switch ($event_type) {
            case 'TRANSACTION.COMPLETED':
            case 'SIGNING_SESSION.COMPLETED':
                $evidence_id = $payload['data']['evidenceId'] ?? '';
                $completed_at = $payload['data']['completedAt'] ?? $payload['timestamp'] ?? '';

                update_post_meta($post_id, '_signdocs_status', 'COMPLETED');
                update_post_meta($post_id, '_signdocs_evidence_id', $evidence_id);
                update_post_meta($post_id, '_signdocs_completed_at', $completed_at);
                update_post_meta($post_id, '_signdocs_webhook_payload', wp_json_encode($payload));

                /**
                 * Fires when a signing is completed.
                 *
                 * @param int   $post_id  The CPT post ID.
                 * @param array $payload  The full webhook payload.
                 */
                do_action('signdocs_signing_completed', $post_id, $payload);
                break;

            case 'TRANSACTION.CANCELLED':
            case 'SIGNING_SESSION.CANCELLED':
                update_post_meta($post_id, '_signdocs_status', 'CANCELLED');
                update_post_meta($post_id, '_signdocs_webhook_payload', wp_json_encode($payload));
                do_action('signdocs_signing_cancelled', $post_id, $payload);
                break;

            case 'TRANSACTION.EXPIRED':
            case 'SIGNING_SESSION.EXPIRED':
                update_post_meta($post_id, '_signdocs_status', 'EXPIRED');
                update_post_meta($post_id, '_signdocs_webhook_payload', wp_json_encode($payload));
                do_action('signdocs_signing_expired', $post_id, $payload);
                break;

            case 'TRANSACTION.FAILED':
            case 'SIGNING_SESSION.FAILED':
                update_post_meta($post_id, '_signdocs_status', 'FAILED');
                update_post_meta($post_id, '_signdocs_webhook_payload', wp_json_encode($payload));
                do_action('signdocs_signing_failed', $post_id, $payload);
                break;
        }

        return new \WP_REST_Response(['received' => true, 'matched' => true], 200);
    }

    private function find_post(string $session_id, string $transaction_id): int
    {
        // Try by session ID first
        if ($session_id !== '') {
            $found = $this->query_by_meta('_signdocs_session_id', $session_id);
            if ($found > 0) {
                return $found;
            }
        }

        // Fallback to transaction ID
        if ($transaction_id !== '') {
            return $this->query_by_meta('_signdocs_transaction_id', $transaction_id);
        }

        return 0;
    }

    private function query_by_meta(string $key, string $value): int
    {
        global $wpdb;

        $post_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND meta_value = %s
                 LIMIT 1",
                $key,
                $value,
            )
        );

        return $post_id ? (int) $post_id : 0;
    }
}
