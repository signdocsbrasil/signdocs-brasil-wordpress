=== SignDocs Brasil ===
Contributors: signdocsbrasil
Donate link: https://signdocs.com.br
Tags: assinatura eletronica, electronic signature, assinatura digital, contrato, woocommerce
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Assinatura eletrônica com validade jurídica: aceite simples, OTP, biometria, certificado ICP-Brasil, envelopes multi-signatário, auditoria e WP-CLI.

== Description ==

SignDocs Brasil é o plugin WordPress mais completo para **assinatura eletrônica com validade jurídica no Brasil**. Incorpore fluxos de assinatura em qualquer página com shortcode ou bloco Gutenberg, envie envelopes multi-signatário (sequenciais ou paralelos), verifique evidências assinadas dentro do próprio admin do WordPress, e acompanhe tudo via log de auditoria com exportação em CSV.

Construído sobre o SDK PHP oficial do SignDocs Brasil (`signdocs-brasil/signdocs-brasil-php`), o plugin aproveita cache de token OAuth compartilhado entre workers do PHP-FPM, idempotência determinística, rotação de webhook secret com janela de graça, e observabilidade via headers `RateLimit-*` / `Deprecation` / `Sunset`.

= Por que SignDocs Brasil? =

* **Conformidade brasileira** — MP 2.200-2/2001, pacote de evidências PKCS#7/CMS, certificado ICP-Brasil A1/A3, fluxo NT65/ITI para consignado INSS
* **Sete políticas de verificação** — CLICK_ONLY, CLICK_PLUS_OTP, BIOMETRIC, BIOMETRIC_PLUS_OTP, DIGITAL_CERTIFICATE, BIOMETRIC_SERPRO, BIOMETRIC_SERPRO_AUTO_FALLBACK
* **Multi-signatário** — envelopes sequenciais (cada signatário aguarda o anterior) ou paralelos (todos podem assinar simultaneamente), com download consolidado `.p7s` ou PDF combinado
* **Dois modos de autenticação** — OAuth2 `client_credentials` (simples) ou Private Key JWT ES256 (para clientes regulados que não podem armazenar secrets compartilhados)
* **Integração WooCommerce** — envia link de assinatura automaticamente após a conclusão do pedido
* **Auditoria completa** — log de todos os eventos da API + webhooks em tabela dedicada, com interface WP_List_Table filtrável e exportação em CSV
* **LGPD** — exportador e apagador de dados pessoais do signatário registrados no painel de privacidade do WordPress
* **Observabilidade** — headers RateLimit capturados em widget do dashboard; avisos de obsolescência (RFC 8594 Deprecation/Sunset) surgem como admin notice automático
* **Zero código** — configure tudo pelo painel do WordPress

= Funcionalidades =

* Shortcode `[signdocs]` e bloco Gutenberg para incorporar o botão de assinatura em qualquer post ou página
* Custom Post Type `signdocs_envelope` para workflows multi-signatário com repetidor de signatários
* Página "Verificar Documento" — cole um evidence ID ou envelope ID e veja identidades dos signatários, CNPJ do tenant, downloads consolidados
* Log de auditoria com filtros por nível, tipo de evento e intervalo de datas + exportação em CSV (streaming via `php://output`, seguro para GB)
* Rotação de webhook secret com janela de graça de 7 dias — ambos os secrets (atual + anterior) aceitos durante a rotação
* 17 tipos de evento de webhook cobertos, incluindo os eventos NT65 (`STEP.PURPOSE_DISCLOSURE_SENT`, `TRANSACTION.DEADLINE_APPROACHING`)
* Capacidades customizadas (`signdocs_manage`, `signdocs_send`, `signdocs_verify`, `signdocs_view_logs`) atribuídas automaticamente a administrator / editor / author
* WP-CLI (`wp signdocs health | send | status | webhook-test | log-tail`) para operações via shell
* Integração WooCommerce — aba de produto "SignDocs Assinatura", email automático com link, notas de pedido após assinatura
* Popup, redirecionamento ou overlay — escolha o modo que melhor funciona no seu tema
* Assinatura anônima (opcional) com rate limiting
* Credenciais criptografadas em AES-256-CBC no `wp_options`
* Webhook hardening: verificação de drift de timestamp (≤300s), HMAC-SHA256 timing-safe, dedup por `X-SignDocs-Webhook-Id`
* Cache de token OAuth compartilhado via transients (`WpTransientTokenCache` implementa `TokenCacheInterface` do SDK) — um único token reutilizado por todos os workers do PHP-FPM
* Idempotency key determinística em toda criação de recurso — retries de AJAX não criam sessões duplicadas
* Observador de deprecação (RFC 8594) que surge como admin notice quando a API sinaliza que um endpoint será removido
* Multilíngue: Português (Brasil), English, Español

= Casos de Uso =

* **Advogados e escritórios** — procurações, contratos, termos, envelopes multi-parte
* **Imobiliárias** — contratos de locação e compra/venda assinados por inquilino, proprietário e fiador (envelope sequencial)
* **E-commerce** — termos de serviço, contratos de fornecedor, NDA pós-compra
* **RH e departamento pessoal** — contratos de trabalho, termos de confidencialidade, ficha de admissão
* **Educação** — matrículas e termos de responsabilidade (pais + aluno em envelope paralelo)
* **SaaS** — termos de uso e contratos de licença no onboarding
* **Crédito consignado INSS** — fluxo NT65 com certificado biométrico SERPRO e notificação de divulgação de finalidade
* **Bancos e instituições financeiras** — Private Key JWT permite assinar sem armazenar secret compartilhado no banco de dados

= Como Funciona =

1. Configure suas credenciais da API SignDocs Brasil no painel WordPress (Client ID + Secret, ou Private Key + Key ID)
2. Adicione um shortcode, bloco Gutenberg ou crie um envelope multi-signatário no admin
3. O signatário clica em "Assinar Documento" e é redirecionado para o domínio seguro `sign.signdocs.com.br` (a assinatura **nunca acontece dentro do seu WordPress** — isso isola sua instalação de qualquer compromisso)
4. O signatário completa o fluxo de acordo com a política configurada (click, OTP, biometria, certificado digital)
5. Webhooks atualizam o status no painel WordPress em tempo real; o pacote de evidências `.p7m` fica disponível para download e verificação

= Links =

* [Site oficial](https://signdocs.com.br)
* [Documentação da API](https://docs.signdocs.com.br)
* [Suporte](https://signdocs.com.br/suporte)
* [Criar conta gratuita](https://app.signdocs.com.br/cadastro)
* [Repositório no GitHub](https://github.com/signdocsbrasil/signdocs-brasil-wordpress)

== Installation ==

= Instalação automática =

1. No painel WordPress, vá em Plugins > Adicionar Novo
2. Pesquise por "SignDocs Brasil"
3. Clique em "Instalar" e depois "Ativar"

= Instalação manual =

1. Faça upload da pasta `signdocs-brasil` para `/wp-content/plugins/` (ou use Plugins > Enviar Plugin com o ZIP da release)
2. Ative o plugin em Plugins > Plugins Instalados

Na ativação, o plugin:

* Cria a tabela `{prefix}signdocs_log` para auditoria
* Registra os CPTs `signdocs_signing` e `signdocs_envelope`
* Concede as capacidades customizadas (`signdocs_manage`, `signdocs_send`, `signdocs_verify`, `signdocs_view_logs`) a administrator / editor / author
* Agenda os crons diários de poda de log e expiração de secret rotacionado

= Configuração =

1. Acesse Configurações > SignDocs Brasil
2. Escolha o método de autenticação:
   * **Client Secret** (padrão) — Client ID + Client Secret obtidos em [app.signdocs.com.br](https://app.signdocs.com.br)
   * **Private Key JWT (ES256)** — chave privada PEM + Key ID; a chave pública é registrada separadamente com o SignDocs. Preferido por clientes regulados que não podem armazenar secrets compartilhados no banco
3. Clique em "Testar Conexão"
4. Selecione o ambiente: HML (sandbox para testes) ou Produção
5. Configure os padrões de assinatura (perfil, idioma, modo, cor da marca, logo)
6. Clique em "Registrar Webhook" — o plugin chama o endpoint da API, recebe o secret HMAC, e armazena encriptado
7. (Opcional) Configure `signdocs_trusted_proxies` com CIDRs confiáveis se seu site estiver atrás de CloudFront / Cloudflare / nginx proxy, para que o rate-limiter anônimo e o log de auditoria vejam o IP real do cliente

== Usage ==

= Shortcode =

Adicione em qualquer página ou post:

`[signdocs document_id="123" policy="CLICK_ONLY" button_text="Assinar Contrato"]`

Com formulário de nome e email:

`[signdocs document_id="123" show_form="true" policy="CLICK_PLUS_OTP"]`

**Atributos disponíveis:**

* `document_id` (obrigatório) — ID do anexo PDF na biblioteca de mídia
* `policy` — Perfil: `CLICK_ONLY`, `CLICK_PLUS_OTP`, `BIOMETRIC`, `BIOMETRIC_PLUS_OTP`, `DIGITAL_CERTIFICATE`, `BIOMETRIC_SERPRO`, `BIOMETRIC_SERPRO_AUTO_FALLBACK`
* `locale` — Idioma: `pt-BR`, `en`, `es`
* `mode` — Modo: `redirect` (padrão), `popup`, `overlay`
* `button_text` — Texto do botão (padrão: "Assinar Documento")
* `show_form` — "true" para exibir campos de nome/email
* `return_url` — URL de retorno após assinatura
* `class` — Classe CSS adicional para o botão

= Bloco Gutenberg =

1. No editor de blocos, clique em "+" para adicionar um bloco
2. Pesquise por "SignDocs" ou "Assinatura"
3. Selecione um PDF pela barra lateral
4. Configure o perfil de assinatura, idioma e modo
5. Publique a página

= Envelopes Multi-Signatário =

Para contratos com mais de um signatário (ex: locador + locatário + fiador), use o menu **Envelopes**:

1. WP Admin > Signatures > Envelopes > Adicionar Novo
2. Selecione o modo de assinatura:
   * **SEQUENTIAL** — cada signatário assina em ordem; o próximo só recebe o link quando o anterior completa
   * **PARALLEL** — todos os signatários podem assinar simultaneamente em qualquer ordem
3. Adicione os signatários (nome + email + política individual por signatário, se desejar)
4. Anexe o PDF e publique
5. Cada signatário recebe um email com seu link individual; o admin vê o status do envelope atualizar conforme cada assinatura é completada
6. Após todos assinarem, um PDF carimbado combinado (ou `.p7s` consolidado para documentos não-PDF) fica disponível para download

Eventos de webhook `STEP.STARTED`, `STEP.COMPLETED` e `STEP.FAILED` são registrados por signatário no log de cada envelope.

= WooCommerce =

1. Edite um produto e acesse a aba "SignDocs Assinatura"
2. Marque "Requer assinatura" e selecione o PDF
3. Configure a política de verificação
4. Quando o pedido for concluído, o link de assinatura é enviado ao cliente automaticamente
5. Após a assinatura, uma nota é adicionada ao pedido com o ID de evidência

= Verificação de Documentos =

Página **Signatures > Verificar** (requer capacidade `signdocs_verify`):

1. Cole um `evidence_id` (assinatura individual) ou `envelope_id` (multi-signatário)
2. O plugin chama `GET /v1/verify/{id}` ou `GET /v1/verify/envelope/{id}` e renderiza:
   * Identidades de todos os signatários (nome, CPF/CNPJ)
   * CNPJ do tenant
   * Timestamps de cada passo
   * Perfil de política aplicado
   * Links de download: pacote de evidências (`.p7m`), PDF assinado, `.p7s` consolidado (envelopes), PDF combinado (envelopes)
3. Use os arquivos de evidência em validadores externos (ITI Validador, Adobe Acrobat) para confirmação independente

= Log de Auditoria =

Página **Signatures > Audit Log** (requer capacidade `signdocs_view_logs`):

* Visualização WP_List_Table sobre `{prefix}signdocs_log`
* Filtros: nível (debug/info/warning/error), tipo de evento, intervalo de datas
* Exportação em CSV via admin-post.php (streaming em chunks, suporta exports multi-GB)
* Retenção automática de 30 dias (cron diário `signdocs_prune_logs`)
* Cada chamada à API, entrega de webhook, e aviso de obsolescência é registrado com contexto JSON

= WP-CLI =

Para operações via shell (útil para automação, CI/CD e troubleshooting):

`wp signdocs health`
— verifica conectividade com a API no ambiente configurado

`wp signdocs send --document=42 --email=joao@example.com --policy=CLICK_PLUS_OTP`
— cria uma sessão de assinatura a partir de um anexo do WordPress e imprime sessionId + URL

`wp signdocs status <sessionId>`
— consulta o status de uma sessão pelo ID

`wp signdocs webhook-test <webhookId>`
— dispara uma entrega de teste para o webhook registrado

`wp signdocs log-tail --level=warning --limit=20`
— mostra as últimas N entradas do log de auditoria filtradas por nível

= Rotação de Webhook Secret =

1. Em Configurações > SignDocs Brasil, clique em "Rotacionar Secret"
2. O plugin solicita um novo secret à API; o antigo passa a ser o "previous secret" com janela de graça de 7 dias
3. Durante a janela, o endpoint `/wp-json/signdocs/v1/webhook` aceita **ambos** os secrets — entregas em voo não são rejeitadas
4. Após 7 dias, o cron diário `signdocs_expire_prev_secret` remove o secret antigo
5. Status da rotação fica visível no admin (contador regressivo)

= Para Desenvolvedores =

**Hooks disponíveis:**

Lifecycle de sessão:

* `signdocs_session_created` — Sessão criada (via API, não necessariamente via WP)
* `signdocs_signing_completed` — Assinatura concluída com sucesso
* `signdocs_signing_cancelled` — Assinatura cancelada pelo integrador ou usuário
* `signdocs_signing_expired` — Sessão expirou sem conclusão
* `signdocs_signing_failed` — Assinatura falhou (erro irrecuperável)
* `signdocs_transaction_fallback` — Fallback acionado (ex: SERPRO sem biometria)

Per-step (para envelopes e fluxos customizados):

* `signdocs_step_started` — Etapa iniciada (OTP enviado, biometria capturada, etc.)
* `signdocs_step_completed` — Etapa concluída
* `signdocs_step_failed` — Etapa falhou
* `signdocs_purpose_disclosure_sent` — (NT65) Notificação de finalidade enviada ao beneficiário
* `signdocs_deadline_approaching` — (NT65) ≤2 dias úteis para o prazo de submissão ao INSS

Tenant/API:

* `signdocs_quota_warning` — Uso do tenant cruzou limiar (80/90/100%)
* `signdocs_api_deprecation_notice` — API sinalizou endpoint deprecated

WooCommerce:

* `signdocs_wc_signing_completed` — Assinatura de pedido WooCommerce concluída

Todas as actions recebem `$post_id` (do CPT `signdocs_signing` ou `signdocs_envelope`) e `$payload` (array com o webhook recebido) como argumentos, exceto `signdocs_quota_warning` e `signdocs_api_deprecation_notice` que recebem apenas o payload.

**Capacidades:**

* `signdocs_manage` — Configurar credenciais, webhook, branding; gerenciar envelopes de outros usuários
* `signdocs_send` — Criar sessões e envelopes
* `signdocs_verify` — Usar a página Verificar e inspecionar evidências
* `signdocs_view_logs` — Acessar o log de auditoria e exportar CSV

Use `current_user_can('signdocs_send')` em vez de `manage_options` / `edit_posts` ao adicionar funcionalidades custom.

**SDK PHP:**

O cliente SDK configurado com credenciais criptografadas e cache de token compartilhado está disponível via:

`$client = Signdocs_Client_Factory::get(); // SignDocsBrasil\Api\SignDocsBrasilClient ou null`

Consulte [a documentação do SDK PHP](https://github.com/signdocsbrasil/signdocs-brasil-php) para o surface completo (transactions, envelopes, verification, users, documentGroups, webhooks, etc.).

== Frequently Asked Questions ==

= Preciso de uma conta SignDocs Brasil? =

Sim. [Crie sua conta gratuita](https://app.signdocs.com.br/cadastro) para obter credenciais de API. O plano gratuito inclui documentos para teste no ambiente HML.

= A assinatura tem validade jurídica? =

Sim. As assinaturas eletrônicas do SignDocs Brasil seguem a Medida Provisória 2.200-2/2001 e geram pacotes de evidência criptográficos (PKCS#7/CMS) com trilha de auditoria completa. Para documentos de alto valor ou exigência de ICP-Brasil, use a política `DIGITAL_CERTIFICATE` com certificado A1 ou A3 do signatário.

= Onde a assinatura acontece de fato? =

No domínio seguro `sign.signdocs.com.br`, **não dentro do seu WordPress**. O plugin cria a sessão via API (server-side, credenciais nunca chegam ao navegador), entrega ao navegador uma URL + clientSecret, e recebe um webhook quando completa. Isso significa que, mesmo se seu WordPress fosse comprometido, um atacante não poderia forjar assinaturas — o fluxo de autenticação (OTP, biometria, certificado) acontece em um domínio completamente separado sob controle do SignDocs.

= Como funcionam os envelopes multi-signatário? =

Cada envelope tem N signatários. Modo **SEQUENTIAL**: o próximo signatário só recebe o link após o anterior completar (útil para fluxos hierárquicos como procuração → testemunha → tabelião). Modo **PARALLEL**: todos podem assinar simultaneamente (útil para NDAs multi-parte, contratos de sociedade). O painel de administração do envelope mostra o status de cada signatário em tempo real conforme os webhooks `STEP.*` chegam. Após o último signatário, um PDF combinado (ou `.p7s` consolidado para não-PDFs) fica disponível via a página "Verificar".

= Posso usar sem armazenar um secret compartilhado? =

Sim. Na aba de autenticação das configurações, escolha **Private Key JWT (ES256)**. Você gera um par de chaves ECDSA P-256 localmente, fornece apenas a chave pública ao SignDocs (via painel do [app.signdocs.com.br](https://app.signdocs.com.br)), e o plugin armazena apenas a chave privada PEM (criptografada em AES-256-CBC). A cada chamada à API, o plugin assina um JWT de curta duração com a chave privada — não há secret compartilhado no banco. Este modo é exigido por alguns clientes regulados (bancos, fintechs).

= O log de auditoria registra o quê? =

Cada chamada à API feita pelo plugin (método, caminho, status, duração, rate limit remaining), cada entrega de webhook recebida (ID, tipo, signer, match), cada aviso de obsolescência emitido pela API (RFC 8594 Deprecation/Sunset), e cada operação admin (criar sessão, rotacionar secret, etc.). Retenção de 30 dias; podado por cron diário. Exportável em CSV para SIEM externo.

= E a LGPD? =

O plugin registra handlers em `wp_privacy_personal_data_exporters` e `wp_privacy_personal_data_erasers`:

* **Exportador** — retorna todas as sessões associadas ao email do titular (nome, emails, session ID, evidence ID, status, timestamps)
* **Apagador** — redige nome e email do signatário para `[redacted-<hash8>]`, mas **preserva** o evidence ID, transaction ID, session ID e timestamps. Motivo: a legislação de assinatura eletrônica exige retenção da evidência pelo período legal, mesmo após pedido de apagamento. A identidade é redigida localmente; o pacote de evidências no servidor permanece íntegro para auditoria legal futura.

= O plugin funciona sem WooCommerce? =

Sim. A integração com WooCommerce é opcional e só é carregada quando o WooCommerce está ativo. Shortcode, bloco Gutenberg, envelopes, página Verificar, log de auditoria e WP-CLI funcionam independentemente.

= Posso testar sem custos? =

Sim. Configure o ambiente como "HML (Sandbox)" nas configurações. Dados de teste, OTP simulado (`000000` ou `123456` sempre aceitos), biometria mockada, nenhuma cobrança.

= Qual o tamanho máximo do PDF? =

O plugin aceita PDFs de até 15 MB. Para arquivos maiores, ajuste `upload_max_filesize` e `memory_limit` no PHP e o tenant precisa estar configurado para documentos grandes no SignDocs.

= Funciona com qualquer tema WordPress? =

Sim. O plugin usa estilos mínimos e respeita a hierarquia CSS do seu tema. O botão pode ser personalizado via classe CSS ou a cor da marca.

= Os dados do signatário são seguros? =

Sim. As credenciais da API são criptografadas no banco (AES-256-CBC com chave derivada de `wp_salt`). Tokens OAuth são armazenados em transients (nunca no banco de dados permanente). Secrets de webhook são criptografados. A chave privada JWT (quando usada) também é criptografada. O HMAC de webhook é validado em tempo constante (SDK). Dedup de webhook via transient lock previne replay attacks.

= Posso personalizar o visual da página de assinatura? =

Sim. Configure cor da marca e logotipo nas configurações do plugin. A página hospedada em `sign.signdocs.com.br` exibirá sua identidade visual. Para customização mais profunda, fale com o suporte — personalizações corporativas estão disponíveis no plano Enterprise.

= Funciona atrás de CloudFront / Cloudflare / nginx proxy? =

Sim. Defina `signdocs_trusted_proxies` como uma lista de CIDRs confiáveis (ex: `10.0.0.0/8, 172.16.0.0/12`). O plugin usa `X-Forwarded-For` apenas quando o `REMOTE_ADDR` está em um range confiável, evitando spoofing do IP do cliente no rate limiter e no log de auditoria.

== Screenshots ==

1. Página de configurações — credenciais, ambiente, método de autenticação (client_secret ou Private Key JWT), padrões de assinatura
2. Bloco Gutenberg no editor com painel de configuração à direita
3. Shortcode renderizado com botão de assinatura no front-end
4. Popup de assinatura aberto pelo `@signdocs-brasil/js` SDK em `sign.signdocs.com.br`
5. Lista de assinaturas no painel administrativo com status colorido, signatário, perfil
6. Página "Verificar Documento" — evidence ID colado, resultado com signatários, CNPJ do tenant, downloads
7. Painel de envelope multi-signatário com repetidor de signatários e toggle sequencial/paralelo
8. Log de auditoria (WP_List_Table) com filtros de nível, evento, data e botão de exportação CSV
9. Aba de produto WooCommerce com configuração de assinatura obrigatória
10. Email de pedido WooCommerce com link de assinatura

== Changelog ==

= 1.3.0 =

Alinhamento com PHP SDK 1.4.0 + readme completo.

* **SDK PHP atualizado para `^1.4`** — a release 1.4.0 do SDK corrigiu uma divergência de modelo em `CreateSigningSessionRequest` / `CreateEnvelopeRequest` que existia desde a 1.0.0: os campos corretos aceitos pela API são `purpose`, `policy`, `signer`, `document`, `returnUrl`, `cancelUrl`, `metadata`, `locale`, `expiresInMinutes`, `appearance`. Call sites do plugin (CLI + AJAX) foram atualizados.
* **Readme reescrito** — descrição, funcionalidades, instalação, uso, FAQ e screenshots agora refletem integralmente as features de v1.1.0, v1.2.0 e v1.2.x (envelopes multi-signatário, página Verificar, log de auditoria, Private Key JWT, rotação de secret, WP-CLI, capacidades customizadas, LGPD, observabilidade). Versão anterior do readme estava congelada no feature set da v1.0.0.
* **"Tested up to" atualizado** para WordPress 6.9 (o stack usado no pen test automatizado).

= 1.2.3 =

Security sniff cleanup — zero `phpcs:ignore` comments in source.

* **Consolidated exceptions** — the 7 remaining WPCS findings that can't be fixed through refactor (MySQL doesn't allow identifier placeholders; custom tables can't use `WP_Query`; admin audit logs shouldn't be cached; list-table pagination doesn't nonce in WP convention) are now declared as file-scoped exclusions in `phpcs.xml.dist` with written rationale for each. Zero line-level `phpcs:ignore` comments remain in `src/`.
* **`EventRouter::queryByMeta` refactored** — dropped the direct `$wpdb->postmeta` lookup in favor of `get_posts(['meta_query' => …, 'fields' => 'ids'])`. Slightly safer (adds post-type filter), eliminates two `DirectDatabaseQuery` warnings without suppression.
* **`Filters::fromRequest` signature tightened** — no longer falls back to `$_REQUEST` when called without arguments; callers must pass the request array explicitly. Moves the superglobal read-site up to the admin page, where CSRF/capability context is clear.
* **Net result:** zero security-category PHPCS findings, zero `phpcs:ignore` suppressions, and every exception documented in one auditable file.

= 1.2.2 =

Security audit + refactor.

* **AuditQuery refactor** — all raw SQL extracted into `src/Admin/AuditQuery.php`. New `src/Admin/Filters.php` value object enforces validation at its constructor, so by the time `AuditQuery` sees a value it's already allow-list-checked. `AuditTable`, `AuditExport`, and `SigndocsCommand::log_tail` are now thin consumers with zero raw SQL.
* **SQL-injection fuzz test suite** — `tests/Unit/AuditQueryFuzzTest.php` runs 24 SQL-injection payloads across every filter field (level, event_type, from, to, orderby, order) and asserts that every payload is either rejected by the allow-list or survives only via `%s` placeholders — never into a SQL literal. 47 total unit tests / 552 assertions, all green.
* **Black-box pen test** — `tests/pen_test.sh` exercises a running WordPress 6.9.x + MariaDB 11 stack (podman pod). Tests SQLi across audit filters + CSV export, webhook HMAC bypass attempts (no-sig, wrong-sig, stale-ts, garbage-ts, valid-sig, replay-dedup), CSRF on admin-post.php, and subscriber-role authorization against the audit log. All checks passed — runtime behavior matches the `phpcs:ignore` justifications.

= 1.2.1 =

WP.org submission-readiness pass.

* phpcbf auto-fixed ~3,300 cosmetic WPCS findings; remaining ~370 are pure style (snake_case / Yoda) and annotated as advisory in CI.
* Audited and annotated the 26 security-adjacent PHPCS findings (nonce verification, prepared SQL) — all verified as safe with justifying `phpcs:ignore` comments.
* Added `.wordpress-org/` asset bundle: icon-256, icon-128, and auto-generated branded banner-1544x500 / banner-772x250 (designer should replace banners before public launch).
* Added `.github/workflows/wp-org-deploy.yml` — `10up/action-wordpress-plugin-deploy` on tag push, gated by the `DEPLOY_TO_WPORG` repo variable so it's a no-op until WP.org approves.
* Added `DEPLOY.md` runbook for the WP.org submission and release flow.

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

= 1.3.0 =
Requer SDK PHP 1.4.0 (`^1.4`); corrige modelo de `CreateSigningSessionRequest` que retornaria 400 Bad Request com SDK 1.3.x. Readme reescrito — recomenda-se reler a seção "Para Desenvolvedores" se você tinha integrações custom.

= 1.2.0 =
Adiciona envelopes multi-signatário, página de verificação, log de auditoria, Private Key JWT auth e rotação de webhook secret. Requer re-ativação do plugin para conceder as novas capacidades (`signdocs_manage/_send/_verify/_view_logs`) aos papéis existentes.

= 1.1.0 =
Atualização de hardening + SDK 1.3.0. Re-ativação necessária para criar a tabela de log de auditoria e instalar as capacidades customizadas.

= 1.0.0 =
Versão inicial do plugin SignDocs Brasil para WordPress.
