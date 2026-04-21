<?php

declare(strict_types=1);

namespace SignDocsBrasil\WordPress\Privacy;

/**
 * GDPR / LGPD personal-data exporter. Returns every `signdocs_signing`
 * CPT row whose signer email matches the export subject.
 *
 * Registered via the `wp_privacy_personal_data_exporters` filter; WP
 * Core invokes this in pages of 100 rows.
 */
final class Exporter
{
    public const EXPORTER_KEY = 'signdocs-brasil';
    private const PAGE_SIZE = 100;

    /**
     * @param array<string,array{exporter_friendly_name:string,callback:callable}> $exporters
     * @return array<string,array{exporter_friendly_name:string,callback:callable}>
     */
    public static function register(array $exporters): array
    {
        $exporters[self::EXPORTER_KEY] = [
            'exporter_friendly_name' => __('SignDocs Brasil — Signing Sessions', 'signdocs-brasil'),
            'callback' => [self::class, 'export'],
        ];
        return $exporters;
    }

    /**
     * @return array{data: list<array{group_id:string,group_label:string,item_id:string,data:list<array{name:string,value:string}>}>, done: bool}
     */
    public static function export(string $email, int $page = 1): array
    {
        $offset = ($page - 1) * self::PAGE_SIZE;

        $posts = \get_posts([
            'post_type' => 'signdocs_signing',
            'post_status' => 'any',
            'numberposts' => self::PAGE_SIZE,
            'offset' => $offset,
            'meta_query' => [
                [
                    'key' => '_signdocs_signer_email',
                    'value' => $email,
                    'compare' => '=',
                ],
            ],
        ]);

        $data = [];
        foreach ($posts as $post) {
            $sessionId = (string) \get_post_meta($post->ID, '_signdocs_session_id', true);
            $transactionId = (string) \get_post_meta($post->ID, '_signdocs_transaction_id', true);
            $evidenceId = (string) \get_post_meta($post->ID, '_signdocs_evidence_id', true);
            $status = (string) \get_post_meta($post->ID, '_signdocs_status', true);
            $completedAt = (string) \get_post_meta($post->ID, '_signdocs_completed_at', true);
            $signerName = (string) \get_post_meta($post->ID, '_signdocs_signer_name', true);

            $data[] = [
                'group_id' => 'signdocs-brasil',
                'group_label' => __('SignDocs Brasil — Signing Sessions', 'signdocs-brasil'),
                'item_id' => 'signdocs-signing-' . $post->ID,
                'data' => [
                    ['name' => __('Session ID', 'signdocs-brasil'), 'value' => $sessionId],
                    ['name' => __('Transaction ID', 'signdocs-brasil'), 'value' => $transactionId],
                    ['name' => __('Evidence ID', 'signdocs-brasil'), 'value' => $evidenceId],
                    ['name' => __('Status', 'signdocs-brasil'), 'value' => $status],
                    ['name' => __('Completed at', 'signdocs-brasil'), 'value' => $completedAt],
                    ['name' => __('Signer name', 'signdocs-brasil'), 'value' => $signerName],
                    ['name' => __('Signer email', 'signdocs-brasil'), 'value' => $email],
                ],
            ];
        }

        $done = count($posts) < self::PAGE_SIZE;

        return ['data' => $data, 'done' => $done];
    }
}
