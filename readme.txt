=== SignDocs Brasil ===
Contributors: signdocsbrasil
Donate link: https://signdocs.com.br
Tags: assinatura eletronica, electronic signature, assinatura digital, contrato, woocommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Assinatura eletrônica de documentos PDF direto no seu site WordPress. Aceite simples, OTP, biometria e certificado digital ICP-Brasil.

== Description ==

SignDocs Brasil é a forma mais simples de adicionar **assinatura eletrônica** ao seu site WordPress. Com um shortcode ou bloco Gutenberg, seus visitantes podem assinar contratos, termos de uso e qualquer documento PDF sem sair do seu site.

= Por que SignDocs Brasil? =

* **Conformidade brasileira** — Assinaturas com validade jurídica conforme MP 2.200-2, com pacote de evidências criptográfico (PKCS#7)
* **Múltiplos níveis de verificação** — Aceite simples, OTP por email, biometria facial (AWS Rekognition), certificado digital ICP-Brasil A1
* **Integração WooCommerce** — Envie contratos para assinatura automaticamente após a compra
* **Zero código** — Configure tudo pelo painel WordPress ou pelo editor Gutenberg
* **LGPD** — Dados do signatário processados em conformidade com a LGPD

= Funcionalidades =

* Shortcode `[signdocs]` para qualquer página ou post
* Bloco Gutenberg com preview ao vivo e configuração visual
* Painel administrativo para acompanhar status de todas as assinaturas
* Webhooks automáticos — status atualizado em tempo real quando o documento é assinado
* Popup, redirecionamento ou overlay — escolha o modo que melhor funciona no seu tema
* Pré-preenche nome e email de usuários logados
* Suporte a assinatura anônima com rate limiting
* Integração WooCommerce: aba de produto, email com link de assinatura, notas de pedido
* Multilíngue: Português (Brasil), English, Español

= Casos de Uso =

* **Advogados e escritórios** — Envie procurações e contratos para assinatura online
* **Imobiliárias** — Contratos de locação e compra/venda assinados digitalmente
* **E-commerce** — Termos de serviço, contratos de fornecedor, NDA pós-compra
* **RH e departamento pessoal** — Contratos de trabalho, termos de confidencialidade
* **Educação** — Matrículas e termos de responsabilidade
* **SaaS** — Termos de uso e contratos de licença no onboarding

= Como Funciona =

1. Você configura suas credenciais da API SignDocs Brasil no painel WordPress
2. Adiciona um shortcode ou bloco Gutenberg em qualquer página
3. O visitante clica em "Assinar Documento" e uma janela de assinatura segura abre
4. O documento é assinado no domínio seguro sign.signdocs.com.br
5. Você recebe a confirmação via webhook — o status atualiza automaticamente no painel

= Links =

* [Site oficial](https://signdocs.com.br)
* [Documentação da API](https://docs.signdocs.com.br)
* [Suporte](https://signdocs.com.br/suporte)
* [Criar conta gratuita](https://app.signdocs.com.br/cadastro)

== Installation ==

= Instalação automática =

1. No painel WordPress, vá em Plugins > Adicionar Novo
2. Pesquise por "SignDocs Brasil"
3. Clique em "Instalar" e depois "Ativar"

= Instalação manual =

1. Faça upload da pasta `signdocs-brasil` para `/wp-content/plugins/`
2. Ative o plugin em Plugins > Plugins Instalados

= Configuração =

1. Acesse Configurações > SignDocs Brasil
2. Insira suas credenciais da API (Client ID e Client Secret) — [obtenha aqui](https://app.signdocs.com.br)
3. Clique em "Testar Conexão" para verificar
4. Selecione o ambiente: HML (sandbox para testes) ou Produção
5. Configure os padrões de assinatura (perfil, idioma, modo)
6. Clique em "Registrar Webhook" para receber atualizações de status automaticamente

== Usage ==

= Shortcode =

Adicione em qualquer página ou post:

`[signdocs document_id="123" policy="CLICK_ONLY" button_text="Assinar Contrato"]`

Com formulário de nome e email:

`[signdocs document_id="123" show_form="true" policy="CLICK_PLUS_OTP"]`

**Atributos disponíveis:**

* `document_id` (obrigatório) — ID do anexo PDF na biblioteca de mídia
* `policy` — Perfil: CLICK_ONLY, CLICK_PLUS_OTP, BIOMETRIC, DIGITAL_CERTIFICATE
* `locale` — Idioma: pt-BR, en, es
* `mode` — Modo: redirect (padrão), popup, overlay
* `button_text` — Texto do botão (padrão: "Assinar Documento")
* `show_form` — "true" para exibir campos de nome/email
* `return_url` — URL de retorno após assinatura
* `class` — Classe CSS adicional para o botão

= Gutenberg Block =

1. No editor de blocos, clique em "+" para adicionar um bloco
2. Pesquise por "SignDocs" ou "Assinatura"
3. Selecione um PDF pela barra lateral
4. Configure o perfil de assinatura, idioma e modo
5. Publique a página

= WooCommerce =

1. Edite um produto e acesse a aba "SignDocs Assinatura"
2. Marque "Requerer assinatura" e selecione o PDF
3. Quando o pedido for concluído, o link de assinatura é enviado ao cliente automaticamente
4. Após a assinatura, uma nota é adicionada ao pedido com o ID de evidência

= Para Desenvolvedores =

Hooks disponíveis para personalização:

* `signdocs_signing_completed` — Ação disparada quando uma assinatura é concluída
* `signdocs_signing_cancelled` — Ação disparada quando uma assinatura é cancelada
* `signdocs_signing_expired` — Ação disparada quando uma assinatura expira
* `signdocs_wc_signing_completed` — Ação disparada quando assinatura de pedido WooCommerce é concluída

== Frequently Asked Questions ==

= Preciso de uma conta SignDocs Brasil? =

Sim. [Crie sua conta gratuita](https://app.signdocs.com.br/cadastro) para obter credenciais de API. O plano gratuito inclui documentos para teste.

= A assinatura tem validade jurídica? =

Sim. As assinaturas eletrônicas do SignDocs Brasil seguem a Medida Provisória 2.200-2/2001 e geram pacotes de evidência criptográficos (PKCS#7/CMS) com trilha de auditoria completa.

= O plugin funciona sem WooCommerce? =

Sim. A integração com WooCommerce é opcional e só é carregada quando o WooCommerce está ativo. O shortcode e bloco Gutenberg funcionam independentemente.

= Posso testar sem custos? =

Sim. Configure o ambiente como "HML (Sandbox)" nas configurações. Dados de teste, OTP simulado e nenhuma cobrança.

= Qual o tamanho máximo do PDF? =

O plugin aceita PDFs de até 15 MB. Para arquivos maiores, ajuste `upload_max_filesize` e `memory_limit` no PHP.

= Funciona com qualquer tema WordPress? =

Sim. O plugin usa estilos mínimos e respeita a hierarquia CSS do seu tema. O botão pode ser personalizado via classe CSS.

= Os dados do signatário são seguros? =

Sim. As credenciais da API são criptografadas no banco de dados (AES-256). A assinatura acontece no domínio seguro sign.signdocs.com.br. Nenhuma credencial é exposta ao navegador.

= Posso personalizar o visual da página de assinatura? =

Sim. Configure cor da marca e logotipo nas configurações do plugin. A página de assinatura hospedada exibirá sua identidade visual.

== Screenshots ==

1. Página de configurações do plugin
2. Shortcode renderizado na página com botão de assinatura
3. Bloco Gutenberg no editor com painel de configuração
4. Popup de assinatura aberto pelo @signdocs-brasil/js SDK
5. Lista de assinaturas no painel administrativo com status
6. Aba de produto WooCommerce com configuração de assinatura
7. Email de pedido WooCommerce com link de assinatura

== Changelog ==

= 1.2.0 =

Enterprise feature parity with the external API.

* **Multi-signer envelopes** — new `signdocs_envelope` CPT (parent of `signdocs_signing`), `EnvelopeService` wrapping the SDK's envelope resource, deterministic idempotency keys on create. Sequential and parallel signing flows.
* **Verification admin UI** — "Verificar" submenu under SignDocs, accepts an evidence ID or envelope ID and renders signer identities, tenant CNPJ, consolidated downloads (`.p7s` or combined PDF). Uses SDK 1.3.0's `verifyEnvelope`.
* **Private Key JWT auth** — alternative to `client_secret`. Store a PEM-encoded ES256 private key + key ID; ClientFactory branches on `signdocs_auth_method`. Preferred by regulated customers who can't store shared secrets at rest.
* **Audit log UI** — `WP_List_Table` over `wp_signdocs_log` with filters (level, event type, date range) and CSV export, gated by `signdocs_view_logs`. CSV streams from `php://output` with chunked reads — safe for multi-GB exports.
* **Webhook secret rotation** — `SecretResolver` accepts both the primary and a previous secret during a 7-day grace window. Daily cron `signdocs_expire_prev_secret` clears the previous secret once the window expires. Controller's authorize step tries each configured secret before rejecting.

= 1.1.0 =

Hardening release + alignment with SignDocs PHP SDK 1.3.0.

* **Shared OAuth token cache** — SDK `TokenCacheInterface` is implemented by `WpTransientTokenCache`, so a single token is reused across every PHP-FPM worker instead of one token fetch per request.
* **Webhook hardening** — timestamp drift gate (≤300s), replay de-duplication via `X-SignDocs-Webhook-Id` transient lock (7-day TTL), proper `permission_callback` that runs HMAC before any business logic, input-shape guard on session / transaction IDs.
* **Full 17-event webhook coverage** — added `TRANSACTION.CREATED`, `TRANSACTION.FALLBACK`, `STEP.STARTED/COMPLETED/FAILED`, `QUOTA.WARNING`, `API.DEPRECATION_NOTICE`, `SIGNING_SESSION.CREATED`, plus the two NT65 INSS-consignado events `STEP.PURPOSE_DISCLOSURE_SENT` and `TRANSACTION.DEADLINE_APPROACHING`.
* **Observability** — `Deprecation` / `Sunset` (RFC 8594) response headers surface as admin notices; `RateLimit-*` headers are captured in a transient for the dashboard widget; structured log table `{prefix}signdocs_log` with 30-day retention and a daily cron prune.
* **Idempotency** — `X-Idempotency-Key` is now sent on every resource-creating call, derived deterministically from site URL + user + action + resource, so AJAX retries no longer create duplicate sessions.
* **Capability model** — four new caps (`signdocs_manage`, `signdocs_send`, `signdocs_verify`, `signdocs_view_logs`) instead of raw `manage_options` / `edit_posts`. Granted to administrator / editor / author on activation via `Capabilities::install()`; `map_meta_cap` wires them to CPT operations.
* **LGPD / GDPR** — `wp_privacy_personal_data_exporters` and `wp_privacy_personal_data_erasers` are registered. The eraser redacts signer name + email but preserves evidence IDs and completion timestamps for legal retention.
* **WP-CLI** — `wp signdocs health|send|status|webhook-test|log-tail`.
* **Test suite** — PHPUnit (Brain Monkey unit tests), PHPStan level 5 (with `phpstan-wordpress`), PHPCS (WordPress-Extra + PHPCompatibilityWP), GitHub Actions matrix on PHP 8.1 / 8.2 / 8.3.

= 1.0.0 =
* Versão inicial
* Shortcode `[signdocs]` com 8 atributos configuráveis
* Bloco Gutenberg com preview ao vivo (ServerSideRender)
* Custom Post Type `signdocs_signing` com colunas de status, signatário e perfil
* Webhook receiver REST com verificação HMAC-SHA256
* Integração WooCommerce: aba de produto, email automático, notas de pedido
* Criptografia AES-256-CBC para credenciais armazenadas
* Suporte a popup, redirecionamento e overlay
* Rate limiting para assinatura anônima
* Trilíngue: pt-BR, en, es

== Upgrade Notice ==

= 1.0.0 =
Versão inicial do plugin SignDocs Brasil para WordPress.
