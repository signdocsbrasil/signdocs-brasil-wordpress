<?php

defined('ABSPATH') || exit;

/**
 * Registers the Settings > SignDocs Brasil admin page.
 */
final class Signdocs_Settings
{
    private const PAGE_SLUG = 'signdocs-brasil';
    private const OPTION_GROUP = 'signdocs_settings';

    private const ENCRYPTED_OPTIONS = [
        'signdocs_client_id_enc',
        'signdocs_client_secret_enc',
        'signdocs_webhook_secret_enc',
    ];

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_signdocs_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_signdocs_register_webhook', [$this, 'ajax_register_webhook']);

        // Preserve existing encrypted values when password fields are left blank
        foreach (self::ENCRYPTED_OPTIONS as $opt) {
            add_filter("pre_update_option_{$opt}", function (string $new, string $old) {
                return ($new === '') ? $old : $new;
            }, 10, 2);
        }
    }

    public function add_menu(): void
    {
        add_options_page(
            __('SignDocs Brasil', 'signdocs-brasil'),
            __('SignDocs Brasil', 'signdocs-brasil'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page'],
        );
    }

    public function register_settings(): void
    {
        // --- API Credentials ---
        add_settings_section('signdocs_credentials', __('Credenciais da API', 'signdocs-brasil'), '__return_false', self::PAGE_SLUG);

        register_setting(self::OPTION_GROUP, 'signdocs_client_id_enc', [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize_encrypt'],
        ]);
        register_setting(self::OPTION_GROUP, 'signdocs_client_secret_enc', [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize_encrypt'],
        ]);
        register_setting(self::OPTION_GROUP, 'signdocs_environment', [
            'type' => 'string',
            'default' => 'hml',
            'sanitize_callback' => function (string $val): string {
                return in_array($val, ['hml', 'prod'], true) ? $val : 'hml';
            },
        ]);

        add_settings_field('signdocs_client_id_enc', __('Client ID', 'signdocs-brasil'), [$this, 'render_text_field'], self::PAGE_SLUG, 'signdocs_credentials', [
            'name' => 'signdocs_client_id_enc',
            'type' => 'text',
        ]);
        add_settings_field('signdocs_client_secret_enc', __('Client Secret', 'signdocs-brasil'), [$this, 'render_text_field'], self::PAGE_SLUG, 'signdocs_credentials', [
            'name' => 'signdocs_client_secret_enc',
            'type' => 'password',
        ]);
        add_settings_field('signdocs_environment', __('Ambiente', 'signdocs-brasil'), [$this, 'render_environment_field'], self::PAGE_SLUG, 'signdocs_credentials');

        // --- Signing Defaults ---
        add_settings_section('signdocs_defaults', __('Padrões de Assinatura', 'signdocs-brasil'), '__return_false', self::PAGE_SLUG);

        register_setting(self::OPTION_GROUP, 'signdocs_default_policy', [
            'type' => 'string',
            'default' => 'CLICK_ONLY',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting(self::OPTION_GROUP, 'signdocs_default_locale', [
            'type' => 'string',
            'default' => 'pt-BR',
            'sanitize_callback' => function (string $val): string {
                return in_array($val, ['pt-BR', 'en', 'es'], true) ? $val : 'pt-BR';
            },
        ]);
        register_setting(self::OPTION_GROUP, 'signdocs_default_mode', [
            'type' => 'string',
            'default' => 'redirect',
            'sanitize_callback' => function (string $val): string {
                return in_array($val, ['popup', 'redirect', 'overlay'], true) ? $val : 'redirect';
            },
        ]);
        register_setting(self::OPTION_GROUP, 'signdocs_default_expiration', [
            'type' => 'integer',
            'default' => 60,
            'sanitize_callback' => 'absint',
        ]);
        register_setting(self::OPTION_GROUP, 'signdocs_allow_anonymous', [
            'type' => 'boolean',
            'default' => false,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]);

        add_settings_field('signdocs_default_policy', __('Perfil de Assinatura', 'signdocs-brasil'), [$this, 'render_policy_field'], self::PAGE_SLUG, 'signdocs_defaults');
        add_settings_field('signdocs_default_locale', __('Idioma', 'signdocs-brasil'), [$this, 'render_locale_field'], self::PAGE_SLUG, 'signdocs_defaults');
        add_settings_field('signdocs_default_mode', __('Modo de Assinatura', 'signdocs-brasil'), [$this, 'render_mode_field'], self::PAGE_SLUG, 'signdocs_defaults');
        add_settings_field('signdocs_default_expiration', __('Expiração (minutos)', 'signdocs-brasil'), [$this, 'render_number_field'], self::PAGE_SLUG, 'signdocs_defaults', [
            'name' => 'signdocs_default_expiration',
            'min' => 5,
            'max' => 10080,
        ]);
        add_settings_field('signdocs_allow_anonymous', __('Assinatura Anônima', 'signdocs-brasil'), [$this, 'render_checkbox_field'], self::PAGE_SLUG, 'signdocs_defaults', [
            'name' => 'signdocs_allow_anonymous',
            'label' => __('Permitir visitantes não autenticados iniciarem assinatura', 'signdocs-brasil'),
        ]);

        // --- Webhooks ---
        add_settings_section('signdocs_webhooks', __('Webhooks', 'signdocs-brasil'), '__return_false', self::PAGE_SLUG);

        register_setting(self::OPTION_GROUP, 'signdocs_webhook_secret_enc', [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize_encrypt'],
        ]);

        add_settings_field('signdocs_webhook_url', __('URL do Webhook', 'signdocs-brasil'), [$this, 'render_webhook_url'], self::PAGE_SLUG, 'signdocs_webhooks');
        add_settings_field('signdocs_webhook_secret_enc', __('Webhook Secret', 'signdocs-brasil'), [$this, 'render_text_field'], self::PAGE_SLUG, 'signdocs_webhooks', [
            'name' => 'signdocs_webhook_secret_enc',
            'type' => 'password',
        ]);

        // --- Appearance ---
        add_settings_section('signdocs_appearance', __('Aparência', 'signdocs-brasil'), '__return_false', self::PAGE_SLUG);

        register_setting(self::OPTION_GROUP, 'signdocs_brand_color', [
            'type' => 'string',
            'default' => '#0066FF',
            'sanitize_callback' => 'sanitize_hex_color',
        ]);
        register_setting(self::OPTION_GROUP, 'signdocs_logo_url', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'esc_url_raw',
        ]);

        add_settings_field('signdocs_brand_color', __('Cor da Marca', 'signdocs-brasil'), [$this, 'render_color_field'], self::PAGE_SLUG, 'signdocs_appearance');
        add_settings_field('signdocs_logo_url', __('URL do Logotipo', 'signdocs-brasil'), [$this, 'render_text_field'], self::PAGE_SLUG, 'signdocs_appearance', [
            'name' => 'signdocs_logo_url',
            'type' => 'url',
        ]);
    }

    // --- Field Renderers ---

    public function render_text_field(array $args): void
    {
        $name = $args['name'];
        $type = $args['type'] ?? 'text';
        $stored = get_option($name, '');
        $placeholder = ($type === 'password' && $stored !== '') ? '••••••••' : '';
        printf(
            '<input type="%s" name="%s" value="" placeholder="%s" class="regular-text" autocomplete="off">',
            esc_attr($type),
            esc_attr($name),
            esc_attr($placeholder),
        );
        if ($type === 'password' && $stored !== '') {
            echo '<p class="description">' . esc_html__('Deixe em branco para manter o valor atual.', 'signdocs-brasil') . '</p>';
        }
    }

    public function render_environment_field(): void
    {
        $current = get_option('signdocs_environment', 'hml');
        $options = [
            'hml' => __('HML (Sandbox)', 'signdocs-brasil'),
            'prod' => __('Produção', 'signdocs-brasil'),
        ];
        foreach ($options as $value => $label) {
            printf(
                '<label style="margin-right:16px"><input type="radio" name="signdocs_environment" value="%s" %s> %s</label>',
                esc_attr($value),
                checked($current, $value, false),
                esc_html($label),
            );
        }
    }

    public function render_policy_field(): void
    {
        $current = get_option('signdocs_default_policy', 'CLICK_ONLY');
        $policies = self::get_policy_options();
        echo '<select name="signdocs_default_policy">';
        foreach ($policies as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($current, $value, false), esc_html($label));
        }
        echo '</select>';
    }

    public function render_locale_field(): void
    {
        $current = get_option('signdocs_default_locale', 'pt-BR');
        $locales = ['pt-BR' => 'Português (Brasil)', 'en' => 'English', 'es' => 'Español'];
        echo '<select name="signdocs_default_locale">';
        foreach ($locales as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($current, $value, false), esc_html($label));
        }
        echo '</select>';
    }

    public function render_mode_field(): void
    {
        $current = get_option('signdocs_default_mode', 'redirect');
        $modes = [
            'redirect' => __('Redirecionamento (recomendado)', 'signdocs-brasil'),
            'popup' => __('Popup', 'signdocs-brasil'),
            'overlay' => __('Overlay', 'signdocs-brasil'),
        ];
        echo '<select name="signdocs_default_mode">';
        foreach ($modes as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($current, $value, false), esc_html($label));
        }
        echo '</select>';
    }

    public function render_number_field(array $args): void
    {
        $name = $args['name'];
        $value = get_option($name, $args['min'] ?? 0);
        printf(
            '<input type="number" name="%s" value="%s" min="%s" max="%s" class="small-text">',
            esc_attr($name),
            esc_attr($value),
            esc_attr($args['min'] ?? 0),
            esc_attr($args['max'] ?? 99999),
        );
    }

    public function render_checkbox_field(array $args): void
    {
        $name = $args['name'];
        $checked = get_option($name, false);
        printf(
            '<label><input type="checkbox" name="%s" value="1" %s> %s</label>',
            esc_attr($name),
            checked($checked, true, false),
            esc_html($args['label']),
        );
    }

    public function render_color_field(): void
    {
        $value = get_option('signdocs_brand_color', '#0066FF');
        printf('<input type="color" name="signdocs_brand_color" value="%s">', esc_attr($value));
    }

    public function render_webhook_url(): void
    {
        $url = rest_url('signdocs/v1/webhook');
        printf('<code>%s</code>', esc_html($url));
        echo '<p class="description">' . esc_html__('Configure esta URL no painel SignDocs ou clique em "Registrar Webhook" abaixo.', 'signdocs-brasil') . '</p>';
    }

    // --- Page Render ---

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_enqueue_script('signdocs-admin', SIGNDOCS_PLUGIN_URL . 'assets/js/signdocs-admin.js', [], SIGNDOCS_VERSION, true);
        wp_localize_script('signdocs-admin', 'signdocsAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('signdocs_admin'),
            'i18n' => [
                'testing' => __('Testando...', 'signdocs-brasil'),
                'success' => __('Conexão OK!', 'signdocs-brasil'),
                'error' => __('Erro na conexão.', 'signdocs-brasil'),
                'registering' => __('Registrando...', 'signdocs-brasil'),
                'registered' => __('Webhook registrado!', 'signdocs-brasil'),
                'registerError' => __('Erro ao registrar webhook.', 'signdocs-brasil'),
            ],
        ]);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('SignDocs Brasil', 'signdocs-brasil') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_GROUP);
        do_settings_sections(self::PAGE_SLUG);
        submit_button();
        echo '</form>';

        echo '<hr>';
        echo '<h2>' . esc_html__('Ações', 'signdocs-brasil') . '</h2>';
        echo '<p>';
        echo '<button type="button" class="button" id="signdocs-test-connection">' . esc_html__('Testar Conexão', 'signdocs-brasil') . '</button> ';
        echo '<button type="button" class="button" id="signdocs-register-webhook">' . esc_html__('Registrar Webhook', 'signdocs-brasil') . '</button>';
        echo ' <span id="signdocs-action-result"></span>';
        echo '</p>';
        echo '</div>';
    }

    // --- AJAX Handlers ---

    public function ajax_test_connection(): void
    {
        check_ajax_referer('signdocs_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $client = Signdocs_Client_Factory::get_client();
        if ($client === null) {
            wp_send_json_error(['message' => __('Credenciais não configuradas.', 'signdocs-brasil')]);
        }

        try {
            $client->health->check();
            wp_send_json_success();
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_register_webhook(): void
    {
        check_ajax_referer('signdocs_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $client = Signdocs_Client_Factory::get_client();
        if ($client === null) {
            wp_send_json_error(['message' => __('Credenciais não configuradas.', 'signdocs-brasil')]);
        }

        try {
            $result = $client->webhooks->register(
                new \SignDocsBrasil\Api\Models\RegisterWebhookRequest(
                    url: rest_url('signdocs/v1/webhook'),
                    events: ['TRANSACTION.COMPLETED', 'TRANSACTION.CANCELLED', 'TRANSACTION.EXPIRED'],
                ),
            );

            if (!empty($result->secret)) {
                update_option('signdocs_webhook_secret_enc', Signdocs_Credentials::encrypt($result->secret));
            }

            wp_send_json_success(['webhookId' => $result->webhookId ?? '']);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    // --- Helpers ---

    public static function sanitize_encrypt(?string $value): string
    {
        if ($value === null || $value === '') {
            // Keep existing value if blank submitted (password fields)
            return '';
        }
        return Signdocs_Credentials::encrypt($value);
    }

    public static function get_policy_options(): array
    {
        return [
            'CLICK_ONLY' => __('Aceite Simples (Click)', 'signdocs-brasil'),
            'CLICK_PLUS_OTP' => __('Aceite + OTP por Email', 'signdocs-brasil'),
            'BIOMETRIC' => __('Verificação Facial', 'signdocs-brasil'),
            'BIOMETRIC_PLUS_OTP' => __('Biometria + OTP', 'signdocs-brasil'),
            'DIGITAL_CERTIFICATE' => __('Certificado Digital A1', 'signdocs-brasil'),
            'BIOMETRIC_SERPRO' => __('Biometria + SERPRO', 'signdocs-brasil'),
            'BIOMETRIC_SERPRO_AUTO_FALLBACK' => __('Biometria SERPRO (Fallback)', 'signdocs-brasil'),
        ];
    }
}
