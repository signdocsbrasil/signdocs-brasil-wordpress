<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Webhook;

use SignDocsBrasil\WordPress\Support\Logger;

/**
 * Dispatches verified webhook payloads to per-event handlers. Handles
 * all 17 canonical event types, including the two NT65 consignado
 * events added in OpenAPI 1.1.0 / SDK 1.3.0.
 *
 * Handlers update the per-session CPT record (meta + status) and fire
 * WordPress actions for external code to subscribe to. The router
 * NEVER 5xxs on unknown events — it logs and returns a 200 "unknown"
 * acknowledgement, which matches the delivery contract (a 5xx causes
 * the server to retry indefinitely).
 */
final class EventRouter
{
    private const MAX_STEP_LOG_ENTRIES = 50;

    /**
     * @param array<string,mixed> $payload Full webhook body
     * @return array{matched:bool,event:string,handled:bool}
     */
    public function route(array $payload): array
    {
        $eventType = (string) ($payload['eventType'] ?? '');
        $sessionId = (string) ($payload['data']['sessionId'] ?? '');
        $transactionId = (string) ($payload['data']['transactionId'] ?? $payload['transactionId'] ?? '');

        $postId = $this->findPost($sessionId, $transactionId);

        // Events that target a specific session CPT record.
        switch ($eventType) {
            case 'TRANSACTION.CREATED':
            case 'SIGNING_SESSION.CREATED':
                $postId = $this->ensureCpt($sessionId, $transactionId, $postId, $payload);
                $this->setStatus($postId, 'PENDING', $payload);
                \do_action('signdocs_session_created', $postId, $payload);
                return ['matched' => $postId !== 0, 'event' => $eventType, 'handled' => true];

            case 'TRANSACTION.COMPLETED':
            case 'SIGNING_SESSION.COMPLETED':
                if ($postId === 0) {
                    break;
                }
                $evidenceId = (string) ($payload['data']['evidenceId'] ?? '');
                $completedAt = (string) ($payload['data']['completedAt'] ?? $payload['timestamp'] ?? '');
                \update_post_meta($postId, '_signdocs_status', 'COMPLETED');
                \update_post_meta($postId, '_signdocs_evidence_id', $evidenceId);
                \update_post_meta($postId, '_signdocs_completed_at', $completedAt);
                \update_post_meta($postId, '_signdocs_webhook_payload', \wp_json_encode($payload));
                \do_action('signdocs_signing_completed', $postId, $payload);
                return ['matched' => true, 'event' => $eventType, 'handled' => true];

            case 'TRANSACTION.CANCELLED':
            case 'SIGNING_SESSION.CANCELLED':
                if ($postId === 0) {
                    break;
                }
                $this->setStatus($postId, 'CANCELLED', $payload);
                \do_action('signdocs_signing_cancelled', $postId, $payload);
                return ['matched' => true, 'event' => $eventType, 'handled' => true];

            case 'TRANSACTION.EXPIRED':
            case 'SIGNING_SESSION.EXPIRED':
                if ($postId === 0) {
                    break;
                }
                $this->setStatus($postId, 'EXPIRED', $payload);
                \do_action('signdocs_signing_expired', $postId, $payload);
                return ['matched' => true, 'event' => $eventType, 'handled' => true];

            case 'TRANSACTION.FAILED':
            case 'SIGNING_SESSION.FAILED':
                if ($postId === 0) {
                    break;
                }
                $this->setStatus($postId, 'FAILED', $payload);
                \do_action('signdocs_signing_failed', $postId, $payload);
                return ['matched' => true, 'event' => $eventType, 'handled' => true];

            case 'TRANSACTION.FALLBACK':
                if ($postId === 0) {
                    break;
                }
                $reason = (string) ($payload['data']['fallbackReason'] ?? '');
                \update_post_meta($postId, '_signdocs_fallback_reason', $reason);
                \update_post_meta($postId, '_signdocs_webhook_payload', \wp_json_encode($payload));
                \do_action('signdocs_transaction_fallback', $postId, $payload);
                return ['matched' => true, 'event' => $eventType, 'handled' => true];

            case 'TRANSACTION.DEADLINE_APPROACHING':
                // NT65 consignado INSS: ≤2 business days until submission.
                if ($postId === 0) {
                    break;
                }
                \update_post_meta($postId, '_signdocs_deadline_warning', (string) ($payload['data']['submissionDeadline'] ?? ''));
                \do_action('signdocs_deadline_approaching', $postId, $payload);
                Logger::warning('nt65.deadline_approaching', 'NT65 submission deadline approaching', [
                    'transactionId' => $transactionId,
                    'deadline' => $payload['data']['submissionDeadline'] ?? null,
                ]);
                return ['matched' => true, 'event' => $eventType, 'handled' => true];

            case 'STEP.STARTED':
            case 'STEP.COMPLETED':
            case 'STEP.FAILED':
                if ($postId === 0) {
                    break;
                }
                $this->appendStepLog($postId, $eventType, $payload);
                \do_action('signdocs_step_' . strtolower((string) explode('.', $eventType)[1]), $postId, $payload);
                return ['matched' => true, 'event' => $eventType, 'handled' => true];

            case 'STEP.PURPOSE_DISCLOSURE_SENT':
                // NT65 consignado INSS: notification of purpose delivered.
                if ($postId === 0) {
                    break;
                }
                $this->appendStepLog($postId, $eventType, $payload);
                \do_action('signdocs_purpose_disclosure_sent', $postId, $payload);
                return ['matched' => true, 'event' => $eventType, 'handled' => true];

            // Events that DON'T target a specific CPT record.
            case 'QUOTA.WARNING':
                \set_transient('signdocs_quota_notice', $payload, DAY_IN_SECONDS);
                Logger::warning('api.quota_warning', 'Tenant quota threshold crossed', [
                    'threshold' => $payload['data']['threshold'] ?? null,
                    'usage' => $payload['data']['usage'] ?? null,
                ]);
                \do_action('signdocs_quota_warning', $payload);
                return ['matched' => false, 'event' => $eventType, 'handled' => true];

            case 'API.DEPRECATION_NOTICE':
                Logger::warning('api.deprecation_notice', 'API deprecation webhook received', [
                    'data' => $payload['data'] ?? null,
                ]);
                \do_action('signdocs_api_deprecation_notice', $payload);
                return ['matched' => false, 'event' => $eventType, 'handled' => true];
        }

        Logger::info('webhook.unmatched', "Webhook event did not match any CPT or handler", [
            'eventType' => $eventType,
            'sessionId' => $sessionId,
            'transactionId' => $transactionId,
        ]);

        return ['matched' => false, 'event' => $eventType, 'handled' => false];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function setStatus(int $postId, string $status, array $payload): void
    {
        if ($postId === 0) {
            return;
        }
        \update_post_meta($postId, '_signdocs_status', $status);
        \update_post_meta($postId, '_signdocs_webhook_payload', \wp_json_encode($payload));
    }

    /**
     * Idempotently upsert a CPT row for an API-side-created session.
     * Returns the post ID.
     *
     * @param array<string,mixed> $payload
     */
    private function ensureCpt(string $sessionId, string $transactionId, int $existing, array $payload): int
    {
        if ($existing > 0) {
            return $existing;
        }

        if ($sessionId === '' && $transactionId === '') {
            return 0;
        }

        $postId = \wp_insert_post([
            'post_type' => 'signdocs_signing',
            'post_status' => 'publish',
            'post_title' => $sessionId !== '' ? $sessionId : $transactionId,
        ], true);

        if (!is_int($postId) || $postId < 1) {
            return 0;
        }

        if ($sessionId !== '') {
            \update_post_meta($postId, '_signdocs_session_id', $sessionId);
        }
        if ($transactionId !== '') {
            \update_post_meta($postId, '_signdocs_transaction_id', $transactionId);
        }
        \update_post_meta($postId, '_signdocs_webhook_payload', \wp_json_encode($payload));

        return $postId;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function appendStepLog(int $postId, string $eventType, array $payload): void
    {
        $raw = \get_post_meta($postId, '_signdocs_step_log', true);
        $log = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($log)) {
            $log = [];
        }

        $log[] = [
            'event' => $eventType,
            'stepId' => $payload['data']['stepId'] ?? null,
            'timestamp' => $payload['timestamp'] ?? gmdate('c'),
        ];

        if (count($log) > self::MAX_STEP_LOG_ENTRIES) {
            $log = array_slice($log, -self::MAX_STEP_LOG_ENTRIES);
        }

        \update_post_meta($postId, '_signdocs_step_log', \wp_json_encode($log));
    }

    private function findPost(string $sessionId, string $transactionId): int
    {
        if ($sessionId !== '' && $this->isSafeId($sessionId)) {
            $id = $this->queryByMeta('_signdocs_session_id', $sessionId);
            if ($id > 0) {
                return $id;
            }
        }
        if ($transactionId !== '' && $this->isSafeId($transactionId)) {
            return $this->queryByMeta('_signdocs_transaction_id', $transactionId);
        }
        return 0;
    }

    /**
     * Guard against pathological postmeta scans. Session/transaction IDs
     * emitted by the API are always short alphanumeric-with-separators
     * strings; anything else can't be a legitimate match and would only
     * thrash the index.
     */
    private function isSafeId(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $value);
    }

    private function queryByMeta(string $key, string $value): int
    {
        global $wpdb;
        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND meta_value = %s
                 LIMIT 1",
                $key,
                $value,
            )
        );
        return $id ? (int) $id : 0;
    }
}
