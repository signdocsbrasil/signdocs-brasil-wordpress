<?php

defined('ABSPATH') || exit;

use SignDocsBrasil\Api\Models\CreateSigningSessionRequest;
use SignDocsBrasil\Api\Models\Owner;
use SignDocsBrasil\Api\Models\Policy;
use SignDocsBrasil\Api\Models\Signer;

/**
 * Optional WooCommerce integration.
 *
 * - Adds a "SignDocs Assinatura" product data tab to select a PDF for signing.
 * - On order completion, creates a signing session and emails the link.
 * - On webhook completion, adds an order note with the evidence ID.
 */
final class Signdocs_WooCommerce
{
    public function register(): void
    {
        // Product data tab
        add_filter('woocommerce_product_data_tabs', [$this, 'add_product_tab']);
        add_action('woocommerce_product_data_panels', [$this, 'render_product_panel']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_meta']);

        // Order completion hook
        add_action('woocommerce_order_status_completed', [$this, 'on_order_completed']);

        // Listen for signing completion from webhook
        add_action('signdocs_signing_completed', [$this, 'on_signing_completed'], 10, 2);

        // Add signing link to order emails
        add_action('woocommerce_email_after_order_table', [$this, 'email_signing_link'], 10, 4);
    }

    // --- Product Data Tab ---

    public function add_product_tab(array $tabs): array
    {
        $tabs['signdocs'] = [
            'label' => __('SignDocs Assinatura', 'signdocs-brasil'),
            'target' => 'signdocs_product_data',
            'class' => [],
            'priority' => 80,
        ];
        return $tabs;
    }

    public function render_product_panel(): void
    {
        global $post;
        echo '<div id="signdocs_product_data" class="panel woocommerce_options_panel">';

        woocommerce_wp_checkbox([
            'id' => '_signdocs_wc_enabled',
            'label' => __('Requerer assinatura', 'signdocs-brasil'),
            'description' => __('Solicitar assinatura de documento após a compra.', 'signdocs-brasil'),
        ]);

        woocommerce_wp_text_input([
            'id' => '_signdocs_wc_document_id',
            'label' => __('ID do Documento (anexo)', 'signdocs-brasil'),
            'description' => __('ID do anexo PDF na biblioteca de mídia.', 'signdocs-brasil'),
            'type' => 'number',
            'custom_attributes' => ['min' => 0],
        ]);

        $policies = Signdocs_Settings::get_policy_options();
        woocommerce_wp_select([
            'id' => '_signdocs_wc_policy',
            'label' => __('Perfil de Assinatura', 'signdocs-brasil'),
            'options' => array_merge(['' => __('Padrão (configurações)', 'signdocs-brasil')], $policies),
        ]);

        echo '</div>';
    }

    public function save_product_meta(int $post_id): void
    {
        $enabled = isset($_POST['_signdocs_wc_enabled']) ? 'yes' : 'no';
        update_post_meta($post_id, '_signdocs_wc_enabled', $enabled);
        update_post_meta($post_id, '_signdocs_wc_document_id', absint($_POST['_signdocs_wc_document_id'] ?? 0));
        update_post_meta($post_id, '_signdocs_wc_policy', sanitize_text_field($_POST['_signdocs_wc_policy'] ?? ''));
    }

    // --- Order Completion ---

    public function on_order_completed(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Check if any product requires signing
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $enabled = get_post_meta($product_id, '_signdocs_wc_enabled', true);
            if ($enabled !== 'yes') {
                continue;
            }

            $document_id = absint(get_post_meta($product_id, '_signdocs_wc_document_id', true));
            if ($document_id === 0) {
                continue;
            }

            $policy = get_post_meta($product_id, '_signdocs_wc_policy', true);
            if ($policy === '') {
                $policy = get_option('signdocs_default_policy', 'CLICK_ONLY');
            }

            $this->create_signing_for_order($order, $document_id, $policy);
        }
    }

    private function create_signing_for_order(\WC_Order $order, int $document_id, string $policy): void
    {
        $client = Signdocs_Client_Factory::get_client();
        if ($client === null) {
            $order->add_order_note(__('SignDocs: Falha ao criar sessão — credenciais não configuradas.', 'signdocs-brasil'));
            return;
        }

        $file_path = get_attached_file($document_id);
        if (!$file_path || !file_exists($file_path)) {
            $order->add_order_note(__('SignDocs: Documento PDF não encontrado.', 'signdocs-brasil'));
            return;
        }

        $signer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $signer_email = $order->get_billing_email();
        $locale = get_option('signdocs_default_locale', 'pt-BR');
        $filename = basename($file_path);

        // CPF/CNPJ — required by the SignDocs API. Pulls from the order
        // meta keys used by the standard "Brazilian Market on WooCommerce"
        // (extra-checkout-fields-for-brazil) extension. If neither is
        // present, we abort with an order note rather than firing a doomed
        // API call that would 400.
        $signer_cpf = preg_replace('/\D+/', '', (string) $order->get_meta('_billing_cpf'));
        $signer_cnpj = preg_replace('/\D+/', '', (string) $order->get_meta('_billing_cnpj'));
        if ($signer_cpf === '' && $signer_cnpj === '') {
            $order->add_order_note(__(
                'SignDocs: pedido sem CPF/CNPJ no checkout. Instale "Brazilian Market on WooCommerce" (ou outro plugin que adicione _billing_cpf / _billing_cnpj) e reenvie.',
                'signdocs-brasil'
            ));
            return;
        }
        if ($signer_cpf !== '' && strlen($signer_cpf) !== 11) {
            $order->add_order_note(__('SignDocs: _billing_cpf inválido (não tem 11 dígitos).', 'signdocs-brasil'));
            return;
        }
        if ($signer_cnpj !== '' && strlen($signer_cnpj) !== 14) {
            $order->add_order_note(__('SignDocs: _billing_cnpj inválido (não tem 14 dígitos).', 'signdocs-brasil'));
            return;
        }

        try {
            // Optional owner identity — pulled from settings. When set, the backend
            // auto-sends an invite email to the customer (always differs from owner
            // here since order buyer is the signer) and notifies the owner on
            // completion. Omit to keep prior behavior.
            $owner_email = (string) get_option('signdocs_owner_email', '');
            $owner_name  = (string) get_option('signdocs_owner_name', '');
            $owner       = ($owner_email !== '' || $owner_name !== '')
                ? new Owner(
                    email: $owner_email !== '' ? $owner_email : null,
                    name: $owner_name !== '' ? $owner_name : null,
                )
                : null;

            $request = new CreateSigningSessionRequest(
                purpose: 'DOCUMENT_SIGNATURE',
                policy: new Policy(profile: $policy),
                signer: new Signer(
                    name: $signer_name,
                    userExternalId: 'wc_' . $order->get_billing_email(),
                    cpf: $signer_cpf !== '' ? $signer_cpf : null,
                    cnpj: $signer_cnpj !== '' ? $signer_cnpj : null,
                    email: $signer_email,
                ),
                document: [
                    'content'  => base64_encode(file_get_contents($file_path)),
                    'filename' => $filename,
                ],
                metadata: [
                    'wp_source'   => 'woocommerce',
                    'wc_order_id' => (string) $order->get_id(),
                    'wp_site_url' => home_url(),
                ],
                locale: $locale,
                owner: $owner,
            );

            $session = $client->signingSessions->create($request);

            // Store session URL on the order
            $order->update_meta_data('_signdocs_session_url', $session->url ?? '');
            $order->update_meta_data('_signdocs_session_id', $session->sessionId ?? '');
            $order->save();

            // Create CPT post
            $post_id = wp_insert_post([
                'post_type' => Signdocs_CPT::POST_TYPE,
                'post_title' => $signer_name . ' — Pedido #' . $order->get_order_number(),
                'post_status' => 'publish',
            ]);

            if (!is_wp_error($post_id)) {
                update_post_meta($post_id, '_signdocs_session_id', $session->sessionId ?? '');
                update_post_meta($post_id, '_signdocs_transaction_id', $session->transactionId ?? '');
                update_post_meta($post_id, '_signdocs_status', 'ACTIVE');
                update_post_meta($post_id, '_signdocs_signer_name', $signer_name);
                update_post_meta($post_id, '_signdocs_signer_email', $signer_email);
                update_post_meta($post_id, '_signdocs_policy', $policy);
                update_post_meta($post_id, '_signdocs_document_attachment_id', $document_id);
                update_post_meta($post_id, '_signdocs_session_url', $session->url ?? '');
                update_post_meta($post_id, '_signdocs_source', 'woocommerce');
                update_post_meta($post_id, '_signdocs_wc_order_id', $order->get_id());
            }

            $order->add_order_note(
                sprintf(
                    /* translators: %s = SignDocs session ID returned by the API */
                    __('SignDocs: Sessão de assinatura criada (ID: %s).', 'signdocs-brasil'),
                    $session->sessionId ?? ''
                )
            );
        } catch (\Throwable $e) {
            $order->add_order_note(
                sprintf(
                    /* translators: %s = exception message describing why session creation failed */
                    __('SignDocs: Erro ao criar sessão — %s', 'signdocs-brasil'),
                    $e->getMessage()
                )
            );
        }
    }

    // --- Webhook Completion ---

    public function on_signing_completed(int $post_id, array $payload): void
    {
        $order_id = (int) get_post_meta($post_id, '_signdocs_wc_order_id', true);
        if ($order_id === 0) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $evidence_id = $payload['data']['evidenceId'] ?? '';
        $order->update_meta_data('_signdocs_evidence_id', $evidence_id);
        $order->save();

        $order->add_order_note(
            sprintf(
                /* translators: %s = SignDocs evidence ID after the document was signed */
                __('SignDocs: Documento assinado! Evidence ID: %s', 'signdocs-brasil'),
                $evidence_id
            )
        );

        /**
         * Fires when a WooCommerce order's signing is completed.
         *
         * @param int    $order_id    WooCommerce order ID.
         * @param string $evidence_id SignDocs evidence ID.
         */
        do_action('signdocs_wc_signing_completed', $order_id, $evidence_id);
    }

    // --- Email Integration ---

    public function email_signing_link(\WC_Order $order, bool $sent_to_admin, bool $plain_text, $email): void
    {
        $signing_url = $order->get_meta('_signdocs_session_url');
        if (empty($signing_url) || $sent_to_admin) {
            return;
        }

        if ($plain_text) {
            echo "\n" . esc_html__('Assine seu documento:', 'signdocs-brasil') . "\n" . esc_url($signing_url) . "\n";
        } else {
            echo '<h2>' . esc_html__('Assinatura de Documento', 'signdocs-brasil') . '</h2>';
            echo '<p>' . esc_html__('Por favor, assine o documento associado ao seu pedido:', 'signdocs-brasil') . '</p>';
            echo '<p><a href="' . esc_url($signing_url) . '" style="background-color:#0066FF;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;display:inline-block">';
            echo esc_html__('Assinar Documento', 'signdocs-brasil');
            echo '</a></p>';
        }
    }
}
