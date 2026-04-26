<?php

defined('ABSPATH') || exit;

/**
 * Registers the [signdocs] shortcode and enqueues frontend assets.
 */
final class Signdocs_Shortcode
{
    private static bool $enqueued = false;

    public function register(): void
    {
        add_shortcode('signdocs', [$this, 'render']);
    }

    /**
     * Renders the shortcode. Also used by the Gutenberg block via ServerSideRender.
     */
    public function render(array|string $atts = []): string
    {
        $atts = shortcode_atts([
            'document_id' => 0,
            'policy' => get_option('signdocs_default_policy', 'CLICK_ONLY'),
            'locale' => get_option('signdocs_default_locale', 'pt-BR'),
            'mode' => get_option('signdocs_default_mode', 'redirect'),
            'button_text' => __('Assinar Documento', 'signdocs-brasil'),
            'return_url' => '',
            'show_form' => 'false',
            'class' => '',
        ], $atts, 'signdocs');

        $document_id = absint($atts['document_id']);

        // Show admin notice if no document selected
        if ($document_id === 0 && current_user_can('edit_posts')) {
            return '<p style="color:#d63638"><strong>SignDocs:</strong> ' .
                esc_html__('Nenhum documento configurado. Adicione document_id ao shortcode.', 'signdocs-brasil') .
                '</p>';
        }

        if ($document_id === 0) {
            return '';
        }

        $this->enqueue_assets();

        $show_form = filter_var($atts['show_form'], FILTER_VALIDATE_BOOLEAN);
        $return_url = $atts['return_url'] ?: get_permalink();
        $widget_id = 'signdocs-widget-' . wp_unique_id();

        $config = [
            'widgetId' => $widget_id,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('signdocs_create_session'),
            'documentId' => $document_id,
            'policy' => sanitize_text_field($atts['policy']),
            'locale' => sanitize_text_field($atts['locale']),
            'mode' => sanitize_text_field($atts['mode']),
            'returnUrl' => esc_url($return_url),
            'showForm' => $show_form,
            'i18n' => [
                'loading' => __('Preparando...', 'signdocs-brasil'),
                'success' => __('Documento assinado com sucesso!', 'signdocs-brasil'),
                'error' => __('Erro ao iniciar assinatura.', 'signdocs-brasil'),
                'closed' => __('Assinatura cancelada.', 'signdocs-brasil'),
                'nameRequired' => __('Nome é obrigatório.', 'signdocs-brasil'),
                'emailRequired' => __('Email é obrigatório.', 'signdocs-brasil'),
                'cpfOrCnpjRequired' => __('CPF ou CNPJ é obrigatório.', 'signdocs-brasil'),
                'cpfInvalid' => __('CPF deve ter 11 dígitos.', 'signdocs-brasil'),
                'cnpjInvalid' => __('CNPJ deve ter 14 dígitos.', 'signdocs-brasil'),
            ],
        ];

        // Pre-fill signer data from logged-in user
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $config['signerName'] = $user->display_name;
            $config['signerEmail'] = $user->user_email;
        }

        $btn_class = 'signdocs-sign-btn';
        if ($atts['class'] !== '') {
            $btn_class .= ' ' . sanitize_html_class($atts['class']);
        }

        ob_start();
        ?>
        <div class="signdocs-signing-widget" id="<?php echo esc_attr($widget_id); ?>"
             data-signdocs-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <?php if ($show_form): ?>
            <div class="signdocs-form">
                <p>
                    <label><?php esc_html_e('Nome completo', 'signdocs-brasil'); ?></label>
                    <input type="text" class="signdocs-field-name"
                           value="<?php echo esc_attr($config['signerName'] ?? ''); ?>"
                           placeholder="<?php esc_attr_e('Nome completo', 'signdocs-brasil'); ?>" required>
                </p>
                <p>
                    <label><?php esc_html_e('Email', 'signdocs-brasil'); ?></label>
                    <input type="email" class="signdocs-field-email"
                           value="<?php echo esc_attr($config['signerEmail'] ?? ''); ?>"
                           placeholder="<?php esc_attr_e('Email', 'signdocs-brasil'); ?>" required>
                </p>
                <p>
                    <label><?php esc_html_e('CPF', 'signdocs-brasil'); ?></label>
                    <input type="text" class="signdocs-field-cpf" inputmode="numeric"
                           placeholder="<?php esc_attr_e('000.000.000-00', 'signdocs-brasil'); ?>"
                           maxlength="14">
                </p>
                <p>
                    <label><?php esc_html_e('CNPJ (se pessoa jurídica)', 'signdocs-brasil'); ?></label>
                    <input type="text" class="signdocs-field-cnpj" inputmode="numeric"
                           placeholder="<?php esc_attr_e('00.000.000/0000-00', 'signdocs-brasil'); ?>"
                           maxlength="18">
                </p>
            </div>
            <?php endif; ?>
            <button type="button" class="<?php echo esc_attr($btn_class); ?>">
                <?php echo esc_html($atts['button_text']); ?>
            </button>
            <div class="signdocs-status" style="display:none" role="status"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function enqueue_assets(): void
    {
        if (self::$enqueued) {
            return;
        }
        self::$enqueued = true;

        $env = get_option('signdocs_environment', 'hml');
        $cdn_url = $env === 'prod'
            ? 'https://cdn.signdocs.com.br/v1/signdocs-brasil.js'
            : 'https://cdn-hml.signdocs.com.br/v1/signdocs-brasil.js';

        wp_enqueue_script('signdocs-brasil-sdk', $cdn_url, [], null, true);
        wp_enqueue_script(
            'signdocs-frontend',
            SIGNDOCS_PLUGIN_URL . 'assets/js/signdocs-frontend.js',
            ['signdocs-brasil-sdk'],
            SIGNDOCS_VERSION,
            true,
        );
        wp_enqueue_style(
            'signdocs-frontend',
            SIGNDOCS_PLUGIN_URL . 'assets/css/signdocs-frontend.css',
            [],
            SIGNDOCS_VERSION,
        );
    }
}
