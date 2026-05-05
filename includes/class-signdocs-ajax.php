<?php

defined('ABSPATH') || exit;

use SignDocsBrasil\Api\Models\CreateSigningSessionRequest;
use SignDocsBrasil\Api\Models\Owner;
use SignDocsBrasil\Api\Models\Policy;
use SignDocsBrasil\Api\Models\Signer;

/**
 * AJAX handler for creating signing sessions from the frontend.
 */
final class Signdocs_Ajax
{
    private const MAX_FILE_SIZE = 15 * 1024 * 1024; // 15 MB

    public function register(): void
    {
        add_action('wp_ajax_signdocs_create_session', [$this, 'handle_create_session']);
        add_action('wp_ajax_nopriv_signdocs_create_session', [$this, 'handle_create_session_nopriv']);
    }

    public function handle_create_session(): void
    {
        check_ajax_referer('signdocs_create_session', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permissão insuficiente.', 'signdocs-brasil')], 403);
        }

        $this->create_session('shortcode');
    }

    public function handle_create_session_nopriv(): void
    {
        if (!get_option('signdocs_allow_anonymous', false)) {
            wp_send_json_error(['message' => __('Assinatura anônima não permitida.', 'signdocs-brasil')], 403);
        }

        check_ajax_referer('signdocs_create_session', 'nonce');

        // Rate limiting: 5 requests per IP per hour
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        $transient_key = 'signdocs_rate_' . md5($ip);
        $count = (int) get_transient($transient_key);
        if ($count >= 5) {
            wp_send_json_error(['message' => __('Limite de requisições excedido. Tente novamente em breve.', 'signdocs-brasil')], 429);
        }
        set_transient($transient_key, $count + 1, HOUR_IN_SECONDS);

        $this->create_session('anonymous');
    }

    private function create_session(string $source): void
    {
        // Nonce already verified by both public callers
        // (handle_create_session / handle_create_session_nopriv) via
        // check_ajax_referer() before they delegate here. PCP cannot
        // trace through the indirection, hence the disable below.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $client = Signdocs_Client_Factory::get_client();
        if ($client === null) {
            wp_send_json_error(['message' => __('Plugin não configurado. Verifique as credenciais da API.', 'signdocs-brasil')]);
        }

        // Validate required fields
        $document_id = absint($_POST['document_id'] ?? 0);
        $signer_name = sanitize_text_field(wp_unslash($_POST['signer_name'] ?? ''));
        $signer_email = sanitize_email(wp_unslash($_POST['signer_email'] ?? ''));
        // digits_only() runs the input through sanitize_text_field() then
        // strips every non-digit, but PCP only recognizes core's named
        // sanitize_*() helpers and treats this as raw $_POST.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $signer_cpf = self::digits_only(wp_unslash($_POST['signer_cpf'] ?? ''));
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $signer_cnpj = self::digits_only(wp_unslash($_POST['signer_cnpj'] ?? ''));

        if ($document_id === 0) {
            wp_send_json_error(['message' => __('Documento não especificado.', 'signdocs-brasil')]);
        }
        if ($signer_name === '' || $signer_email === '') {
            wp_send_json_error(['message' => __('Nome e email do signatário são obrigatórios.', 'signdocs-brasil')]);
        }
        if ($signer_cpf === '' && $signer_cnpj === '') {
            wp_send_json_error(['message' => __('CPF ou CNPJ é obrigatório (a API exige pelo menos um).', 'signdocs-brasil')]);
        }
        if ($signer_cpf !== '' && strlen($signer_cpf) !== 11) {
            wp_send_json_error(['message' => __('CPF deve ter 11 dígitos.', 'signdocs-brasil')]);
        }
        if ($signer_cnpj !== '' && strlen($signer_cnpj) !== 14) {
            wp_send_json_error(['message' => __('CNPJ deve ter 14 dígitos.', 'signdocs-brasil')]);
        }

        // Read PDF from WordPress attachment
        $file_path = get_attached_file($document_id);
        if (!$file_path || !file_exists($file_path)) {
            wp_send_json_error(['message' => __('Documento não encontrado.', 'signdocs-brasil')], 404);
        }

        $file_size = filesize($file_path);
        if ($file_size > self::MAX_FILE_SIZE) {
            wp_send_json_error(['message' => __('Arquivo excede o limite de 15 MB.', 'signdocs-brasil')]);
        }

        $pdf_content = file_get_contents($file_path);
        if ($pdf_content === false) {
            wp_send_json_error(['message' => __('Erro ao ler o documento.', 'signdocs-brasil')]);
        }

        $policy = sanitize_text_field(wp_unslash($_POST['policy'] ?? get_option('signdocs_default_policy', 'CLICK_ONLY')));
        $locale = sanitize_text_field(wp_unslash($_POST['locale'] ?? get_option('signdocs_default_locale', 'pt-BR')));
        $expiration = absint($_POST['expiration'] ?? get_option('signdocs_default_expiration', 60));
        $return_url = esc_url_raw(wp_unslash($_POST['return_url'] ?? ''));
        $filename = basename($file_path);

        // Build signer external ID from email
        $user_external_id = 'wp_' . md5($signer_email);

        try {
            // Optional owner identity — pulled from settings. When set, the backend
            // auto-sends an invite email to the signer (if their email differs) and
            // notifies the owner on completion. Omit to keep prior behavior (caller
            // delivers the signing URL themselves + relies on webhooks).
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
                    userExternalId: $user_external_id,
                    cpf: $signer_cpf !== '' ? $signer_cpf : null,
                    cnpj: $signer_cnpj !== '' ? $signer_cnpj : null,
                    email: $signer_email,
                ),
                document: [
                    'content'  => base64_encode($pdf_content),
                    'filename' => $filename,
                ],
                returnUrl: $return_url ?: null,
                metadata: [
                    'wp_source'      => $source,
                    'wp_document_id' => (string) $document_id,
                    'wp_site_url'    => home_url(),
                ],
                locale: $locale,
                expiresInMinutes: $expiration ?: null,
                owner: $owner,
            );

            $session = $client->signingSessions->create($request);

            // Create CPT post to track this signing
            $post_id = wp_insert_post([
                'post_type' => Signdocs_CPT::POST_TYPE,
                'post_title' => $signer_name . ' — ' . $filename,
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
                update_post_meta($post_id, '_signdocs_source', $source);
            }

            wp_send_json_success([
                'clientSecret' => $session->clientSecret ?? '',
                'sessionId' => $session->sessionId ?? '',
                'sessionUrl' => $session->url ?? '',
                'postId' => $post_id,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    /**
     * Strip everything except digits — CPFs/CNPJs are commonly entered with
     * dots, dashes, or slashes (123.456.789-01 / 12.345.678/0001-90); the
     * API expects digits-only.
     */
    private static function digits_only(string $raw): string
    {
        return preg_replace('/\D+/', '', sanitize_text_field($raw)) ?? '';
    }
}
