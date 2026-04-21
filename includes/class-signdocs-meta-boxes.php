<?php

defined('ABSPATH') || exit;

/**
 * Admin meta boxes for the signdocs_signing CPT detail view.
 */
final class Signdocs_Meta_Boxes
{
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
    }

    public function add_meta_boxes(): void
    {
        add_meta_box(
            'signdocs_signing_details',
            __('Detalhes da Assinatura', 'signdocs-brasil'),
            [$this, 'render'],
            Signdocs_CPT::POST_TYPE,
            'normal',
            'high',
        );
    }

    public function render(\WP_Post $post): void
    {
        $fields = [
            '_signdocs_session_id' => __('Session ID', 'signdocs-brasil'),
            '_signdocs_transaction_id' => __('Transaction ID', 'signdocs-brasil'),
            '_signdocs_status' => __('Status', 'signdocs-brasil'),
            '_signdocs_signer_name' => __('Signatário', 'signdocs-brasil'),
            '_signdocs_signer_email' => __('Email', 'signdocs-brasil'),
            '_signdocs_policy' => __('Perfil', 'signdocs-brasil'),
            '_signdocs_evidence_id' => __('Evidence ID', 'signdocs-brasil'),
            '_signdocs_completed_at' => __('Concluída em', 'signdocs-brasil'),
            '_signdocs_session_url' => __('URL de Assinatura', 'signdocs-brasil'),
            '_signdocs_source' => __('Origem', 'signdocs-brasil'),
        ];

        echo '<table class="form-table">';
        foreach ($fields as $key => $label) {
            $value = get_post_meta($post->ID, $key, true);
            if ($value === '') {
                continue;
            }
            echo '<tr>';
            echo '<th scope="row">' . esc_html($label) . '</th>';
            echo '<td>';
            if ($key === '_signdocs_session_url') {
                printf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url($value), esc_html($value));
            } else {
                echo '<code>' . esc_html($value) . '</code>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
}
