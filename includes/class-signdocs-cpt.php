<?php

defined('ABSPATH') || exit;

/**
 * Registers the signdocs_signing Custom Post Type and admin columns.
 */
final class Signdocs_CPT
{
    public const POST_TYPE = 'signdocs_signing';

    public function register(): void
    {
        add_action('init', [$this, 'register_post_type']);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'column_content'], 10, 2);
    }

    public function register_post_type(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Assinaturas', 'signdocs-brasil'),
                'singular_name' => __('Assinatura', 'signdocs-brasil'),
                'add_new_item' => __('Nova Assinatura', 'signdocs-brasil'),
                'edit_item' => __('Editar Assinatura', 'signdocs-brasil'),
                'view_item' => __('Ver Assinatura', 'signdocs-brasil'),
                'search_items' => __('Buscar Assinaturas', 'signdocs-brasil'),
                'not_found' => __('Nenhuma assinatura encontrada.', 'signdocs-brasil'),
                'menu_name' => __('SignDocs', 'signdocs-brasil'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-media-document',
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public function columns(array $columns): array
    {
        $new = [];
        $new['cb'] = $columns['cb'];
        $new['title'] = $columns['title'];
        $new['signdocs_status'] = __('Status', 'signdocs-brasil');
        $new['signdocs_signer'] = __('Signatário', 'signdocs-brasil');
        $new['signdocs_policy'] = __('Perfil', 'signdocs-brasil');
        $new['date'] = $columns['date'];
        return $new;
    }

    public function column_content(string $column, int $post_id): void
    {
        switch ($column) {
            case 'signdocs_status':
                $status = get_post_meta($post_id, '_signdocs_status', true);
                $badges = [
                    'ACTIVE' => '<span style="color:#0073aa">&#9679; ' . esc_html__('Ativa', 'signdocs-brasil') . '</span>',
                    'COMPLETED' => '<span style="color:#00a32a">&#10003; ' . esc_html__('Concluída', 'signdocs-brasil') . '</span>',
                    'CANCELLED' => '<span style="color:#d63638">&#10005; ' . esc_html__('Cancelada', 'signdocs-brasil') . '</span>',
                    'EXPIRED' => '<span style="color:#996800">&#9202; ' . esc_html__('Expirada', 'signdocs-brasil') . '</span>',
                    'FAILED' => '<span style="color:#d63638">&#9888; ' . esc_html__('Falhou', 'signdocs-brasil') . '</span>',
                ];
                echo $badges[$status] ?? esc_html($status ?: '—');
                break;

            case 'signdocs_signer':
                $name = get_post_meta($post_id, '_signdocs_signer_name', true);
                $email = get_post_meta($post_id, '_signdocs_signer_email', true);
                echo esc_html($name ?: '—');
                if ($email) {
                    echo '<br><small>' . esc_html($email) . '</small>';
                }
                break;

            case 'signdocs_policy':
                echo esc_html(get_post_meta($post_id, '_signdocs_policy', true) ?: '—');
                break;
        }
    }
}
