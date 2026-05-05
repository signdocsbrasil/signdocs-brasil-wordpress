=== SignDocs Brasil ===
Contributors: signdocsbrasil
Donate link: https://signdocs.com.br
Tags: electronic signature, digital signature, woocommerce, contracts, icp-brasil
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.3.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Legally-binding e-signature for Brazil: OTP, biometrics, ICP-Brasil, multi-signer envelopes, audit log, WP-CLI, WooCommerce.

== Description ==

SignDocs Brasil is the most complete WordPress plugin for **legally-binding electronic signatures in Brazil**. Embed signing flows on any page with a shortcode or Gutenberg block, send multi-signer envelopes (sequential or parallel), verify signed evidence directly from the WordPress admin, and track everything through an audit log with CSV export.

Built on top of the official SignDocs Brasil PHP SDK (`signdocs-brasil/signdocs-brasil-php`), the plugin leverages OAuth token caching shared across PHP-FPM workers, deterministic idempotency, webhook secret rotation with a grace window, and observability via `RateLimit-*` / `Deprecation` / `Sunset` response headers.

The plugin targets the Brazilian market (compliance with MP 2.200-2/2001, ICP-Brasil, NT65/ITI for INSS payroll loans), but works for any signing workflow worldwide. The signing UI itself is hosted on `sign.signdocs.com.br`, isolated from your WordPress install, so a compromised WordPress site cannot forge signatures.

= Why SignDocs Brasil? =

* **Brazilian compliance** — MP 2.200-2/2001, PKCS#7/CMS evidence package, ICP-Brasil A1/A3 certificate support, NT65/ITI flow for INSS payroll loans
* **Seven verification policies** — CLICK_ONLY, CLICK_PLUS_OTP, BIOMETRIC, BIOMETRIC_PLUS_OTP, DIGITAL_CERTIFICATE, BIOMETRIC_SERPRO, BIOMETRIC_SERPRO_AUTO_FALLBACK
* **Multi-signer envelopes** — sequential (each signer waits for the previous one) or parallel (everyone signs simultaneously), with consolidated `.p7s` or combined PDF download when complete
* **Two authentication modes** — OAuth2 `client_credentials` (simple) or Private Key JWT ES256 (for regulated customers who cannot store shared secrets at rest)
* **WooCommerce integration** — automatically emails the signing link after order completion
* **Complete audit trail** — every API call and webhook delivery is logged in a dedicated table with a filterable WP_List_Table view and CSV export
* **GDPR / LGPD** — data exporter and eraser handlers registered with the WordPress privacy panel
* **Observability** — `RateLimit-*` headers captured for the dashboard widget; deprecation warnings (RFC 8594 `Deprecation` / `Sunset`) surface as admin notices
* **Zero code** — configure everything from the WordPress admin

= Features =

* Shortcode `[signdocs]` and Gutenberg block to embed the signing button on any post or page
* Custom post type `signdocs_envelope` for multi-signer workflows with a signer repeater
* "Verify Document" admin page — paste an evidence ID or envelope ID and inspect signer identities, tenant CNPJ, consolidated downloads
* Audit log with filters by level, event type, and date range, plus streaming CSV export (via `php://output`, safe for multi-GB exports)
* Webhook secret rotation with a 7-day grace window — both secrets (current + previous) are accepted during rotation
* All 17 webhook event types covered, including the NT65 events (`STEP.PURPOSE_DISCLOSURE_SENT`, `TRANSACTION.DEADLINE_APPROACHING`)
* Custom capabilities (`signdocs_manage`, `signdocs_send`, `signdocs_verify`, `signdocs_view_logs`) automatically granted to administrator / editor / author
* WP-CLI commands (`wp signdocs health | send | status | webhook-test | log-tail`) for shell automation
* WooCommerce integration — "SignDocs Signature" product tab, automatic email with the signing link, order notes after completion
* Popup, redirect, or overlay — pick the embed mode that fits your theme
* Optional anonymous signing with rate limiting
* Credentials encrypted with AES-256-CBC in `wp_options`
* Hardened webhook receiver: timestamp drift gate (≤300s), HMAC-SHA256 timing-safe verification, replay de-duplication via `X-SignDocs-Webhook-Id`
* OAuth token cache shared via WordPress transients (`WpTransientTokenCache` implements the SDK's `TokenCacheInterface`) — a single token reused by every PHP-FPM worker
* Deterministic idempotency keys on every resource-creating call — AJAX retries never create duplicate sessions
* Deprecation observer (RFC 8594) that surfaces an admin notice when the API signals an endpoint is being removed
* Translatable: English, Portuguese (Brazil), Spanish

= Use cases =

* **Law firms** — powers of attorney, contracts, terms, multi-party envelopes
* **Real estate** — rental and sale contracts signed by tenant, landlord, and guarantor (sequential envelope)
* **E-commerce** — terms of service, supplier contracts, post-purchase NDAs
* **HR and people ops** — employment contracts, NDAs, onboarding paperwork
* **Education** — enrollment forms and parental consent (parents + student in a parallel envelope)
* **SaaS** — terms of use and license agreements at onboarding
* **INSS payroll loans (Brazil-specific)** — NT65 flow with SERPRO biometric verification and purpose disclosure notification
* **Banks and financial institutions** — Private Key JWT lets you sign without storing a shared secret in the database

= How it works =

1. Configure your SignDocs Brasil API credentials in the WordPress admin (Client ID + Secret, or Private Key + Key ID)
2. Add a shortcode, Gutenberg block, or create a multi-signer envelope from the admin
3. The signer clicks "Sign Document" and is redirected to the secure domain `sign.signdocs.com.br` (signing **never happens inside your WordPress site** — this isolates your install from any compromise)
4. The signer completes the flow according to the configured policy (click, OTP, biometrics, digital certificate)
5. Webhooks update the status in the WordPress admin in real time; the `.p7m` evidence package becomes available for download and verification

= Links =

* [Official site](https://signdocs.com.br)
* [API documentation](https://docs.signdocs.com.br)
* [Support](https://signdocs.com.br/suporte)
* [Create a free account](https://app.signdocs.com.br/cadastro)
* [GitHub repository](https://github.com/signdocsbrasil/signdocs-brasil-wordpress)

== Installation ==

= Automatic install =

1. In the WordPress admin, go to Plugins > Add New
2. Search for "SignDocs Brasil"
3. Click "Install" and then "Activate"

= Manual install =

1. Upload the `signdocs-brasil` folder to `/wp-content/plugins/` (or use Plugins > Upload Plugin with the release ZIP)
2. Activate the plugin in Plugins > Installed Plugins

On activation, the plugin:

* Creates the `{prefix}signdocs_log` table for the audit log
* Registers the `signdocs_signing` and `signdocs_envelope` custom post types
* Grants the custom capabilities (`signdocs_manage`, `signdocs_send`, `signdocs_verify`, `signdocs_view_logs`) to administrator / editor / author
* Schedules the daily cron jobs for log pruning and rotated-secret expiration

= Configuration =

1. Open Settings > SignDocs Brasil
2. Choose the authentication method:
   * **Client Secret** (default) — Client ID + Client Secret obtained from [app.signdocs.com.br](https://app.signdocs.com.br)
   * **Private Key JWT (ES256)** — PEM-encoded private key + Key ID; the public key is registered separately with SignDocs. Preferred by regulated customers that cannot store a shared secret in the database
3. Click "Test Connection"
4. Select the environment: HML (sandbox for testing) or Production
5. Configure the signing defaults (policy, locale, mode, brand color, logo)
6. Click "Register Webhook" — the plugin calls the API endpoint, receives the HMAC secret, and stores it encrypted
7. (Optional) Configure `signdocs_trusted_proxies` with trusted CIDR ranges if your site sits behind CloudFront, Cloudflare, or an nginx proxy, so the anonymous-signing rate limiter and audit log see the real client IP

== Usage ==

= Shortcode =

Add to any page or post:

`[signdocs document_id="123" policy="CLICK_ONLY" button_text="Sign Contract"]`

With name / email / CPF form:

`[signdocs document_id="123" show_form="true" policy="CLICK_PLUS_OTP"]`

**Available attributes:**

* `document_id` (required) — ID of the PDF attachment in the media library
* `policy` — one of: `CLICK_ONLY`, `CLICK_PLUS_OTP`, `BIOMETRIC`, `BIOMETRIC_PLUS_OTP`, `DIGITAL_CERTIFICATE`, `BIOMETRIC_SERPRO`, `BIOMETRIC_SERPRO_AUTO_FALLBACK`
* `locale` — language: `pt-BR`, `en`, `es`
* `mode` — embed mode: `redirect` (default), `popup`, `overlay`
* `button_text` — button label (default: "Sign Document")
* `show_form` — `"true"` to display name / email / CPF / CNPJ inputs
* `return_url` — URL to redirect to after signing
* `class` — additional CSS class for the button

= Gutenberg block =

1. In the block editor, click "+" to add a block
2. Search for "SignDocs" or "Signature"
3. Pick a PDF in the right sidebar
4. Configure the policy, locale, and mode
5. Publish the page

= Multi-signer envelopes =

For contracts with more than one signer (for example, landlord + tenant + guarantor), use the **Envelopes** menu:

1. WP Admin > Signatures > Envelopes > Add New
2. Select the signing mode:
   * **SEQUENTIAL** — each signer signs in order; the next signer only receives their link when the previous one completes
   * **PARALLEL** — all signers can sign simultaneously, in any order
3. Add the signers (name + email + CPF or CNPJ + optional per-signer policy)
4. Attach the PDF and publish
5. Each signer receives an email with their individual link; the admin sees the envelope status update as each signature completes
6. After everyone has signed, a combined stamped PDF (or consolidated `.p7s` for non-PDF documents) becomes available for download

The webhook events `STEP.STARTED`, `STEP.COMPLETED`, and `STEP.FAILED` are recorded per signer in each envelope's log.

= WooCommerce =

1. Edit a product and open the "SignDocs Signature" tab
2. Check "Requires signature" and select the PDF
3. Configure the verification policy
4. When an order completes, the signing link is automatically emailed to the customer
5. After signing, an order note is added with the evidence ID

> The customer's CPF or CNPJ must be present in the order. The plugin reads the standard `_billing_cpf` / `_billing_cnpj` order meta keys used by the [Brazilian Market on WooCommerce](https://wordpress.org/plugins/woocommerce-extra-checkout-fields-for-brazil/) extension. If neither is present, the plugin adds an order note explaining the requirement and skips session creation.

= Document verification =

The **Signatures > Verify** page (requires the `signdocs_verify` capability):

1. Paste an `evidence_id` (single signature) or `envelope_id` (multi-signer)
2. The plugin calls `GET /v1/verify/{id}` or `GET /v1/verify/envelope/{id}` and renders:
   * Identities of every signer (name, CPF/CNPJ)
   * Tenant CNPJ
   * Timestamps for each step
   * The applied policy profile
   * Download links: evidence package (`.p7m`), signed PDF, consolidated `.p7s` (envelopes), combined PDF (envelopes)
3. Use the evidence files in external validators (ITI Validador, Adobe Acrobat) for independent confirmation

= Audit log =

The **Signatures > Audit Log** page (requires the `signdocs_view_logs` capability):

* WP_List_Table view over `{prefix}signdocs_log`
* Filters: level (debug / info / warning / error), event type, date range
* CSV export via `admin-post.php` (chunked streaming, safe for multi-GB exports)
* Automatic 30-day retention via the daily `signdocs_prune_logs` cron
* Every API call, webhook delivery, and deprecation warning is recorded with JSON context

= WP-CLI =

For shell-based operations (useful for automation, CI/CD, and troubleshooting):

`wp signdocs health`
— check connectivity to the API in the configured environment

`wp signdocs send --document=42 --email=alice@example.com --cpf=12345678901 --policy=CLICK_PLUS_OTP`
— create a signing session from a WordPress attachment and print the session ID and URL

`wp signdocs status <sessionId>`
— look up the status of a session by ID

`wp signdocs webhook-test <webhookId>`
— send a test delivery to a registered webhook

`wp signdocs log-tail --level=warning --limit=20`
— show the last N entries of the audit log filtered by level

= Webhook secret rotation =

1. In Settings > SignDocs Brasil, click "Rotate Secret"
2. The plugin requests a new secret from the API; the previous secret becomes the "previous secret" with a 7-day grace window
3. During the window, the `/wp-json/signdocs/v1/webhook` endpoint accepts **both** secrets — in-flight deliveries are not rejected
4. After 7 days, the daily `signdocs_expire_prev_secret` cron removes the old secret
5. The rotation status is visible in the admin (with a countdown)

= For developers =

**Available hooks:**

Session lifecycle:

* `signdocs_session_created` — Session created (via the API, not necessarily via WordPress)
* `signdocs_signing_completed` — Signing completed successfully
* `signdocs_signing_cancelled` — Signing cancelled by the integrator or the signer
* `signdocs_signing_expired` — Session expired without completion
* `signdocs_signing_failed` — Signing failed (unrecoverable error)
* `signdocs_transaction_fallback` — Fallback was triggered (e.g., SERPRO unavailable)

Per-step (for envelopes and custom flows):

* `signdocs_step_started` — Step started (OTP sent, biometric capture, etc.)
* `signdocs_step_completed` — Step completed
* `signdocs_step_failed` — Step failed
* `signdocs_purpose_disclosure_sent` — (NT65) Purpose disclosure notification delivered to the beneficiary
* `signdocs_deadline_approaching` — (NT65) ≤2 business days left before the INSS submission deadline

Tenant / API:

* `signdocs_quota_warning` — Tenant usage crossed a threshold (80 / 90 / 100%)
* `signdocs_api_deprecation_notice` — API signaled a deprecated endpoint

WooCommerce:

* `signdocs_wc_signing_completed` — A WooCommerce order signing completed

Each action receives `$post_id` (of the `signdocs_signing` or `signdocs_envelope` CPT) and `$payload` (the raw webhook array) as arguments, except `signdocs_quota_warning` and `signdocs_api_deprecation_notice` which receive only the payload.

**Capabilities:**

* `signdocs_manage` — Configure credentials, webhook, branding; manage other users' envelopes
* `signdocs_send` — Create sessions and envelopes
* `signdocs_verify` — Use the Verify page and inspect evidence
* `signdocs_view_logs` — Access the audit log and export CSV

Use `current_user_can('signdocs_send')` instead of `manage_options` / `edit_posts` when adding custom functionality.

**PHP SDK:**

The configured SDK client (with encrypted credentials and shared token cache) is available via:

`$client = Signdocs_Client_Factory::get(); // SignDocsBrasil\Api\SignDocsBrasilClient or null`

See the [PHP SDK documentation](https://github.com/signdocsbrasil/signdocs-brasil-php) for the full surface (transactions, envelopes, verification, users, documentGroups, webhooks, etc.).

== Frequently Asked Questions ==

= Do I need a SignDocs Brasil account? =

Yes. [Create your free account](https://app.signdocs.com.br/cadastro) to obtain API credentials. The free plan includes test documents in the HML (sandbox) environment.

= Are these signatures legally binding? =

Yes. SignDocs Brasil electronic signatures comply with Brazilian Provisional Measure 2.200-2/2001 and produce cryptographic evidence packages (PKCS#7/CMS) with a complete audit trail. For high-value documents or where ICP-Brasil is required, use the `DIGITAL_CERTIFICATE` policy with the signer's A1 or A3 certificate.

= Where does the signing actually happen? =

On the secure domain `sign.signdocs.com.br`, **not inside your WordPress site**. The plugin creates the session via the API server-side (credentials never reach the browser), hands a URL + `clientSecret` to the browser, and receives a webhook when complete. This means that even if your WordPress site were compromised, an attacker could not forge signatures — the authentication flow (OTP, biometrics, certificate) happens on a completely separate domain under SignDocs' control.

= How do multi-signer envelopes work? =

Each envelope has N signers. **SEQUENTIAL** mode: the next signer only receives their link after the previous one completes (useful for hierarchical flows like power of attorney → witness → notary). **PARALLEL** mode: everyone can sign simultaneously (useful for multi-party NDAs, partnership agreements). The envelope admin panel shows each signer's status in real time as the `STEP.*` webhooks arrive. After the last signer, a combined PDF (or consolidated `.p7s` for non-PDFs) becomes available via the Verify page.

= Can I use this without storing a shared secret? =

Yes. In the authentication tab of the settings, choose **Private Key JWT (ES256)**. You generate an ECDSA P-256 key pair locally, register only the public key with SignDocs (via the [app.signdocs.com.br](https://app.signdocs.com.br) panel), and the plugin stores only the PEM private key (encrypted with AES-256-CBC). On every API call the plugin signs a short-lived JWT with the private key — no shared secret in the database. This mode is required by some regulated customers (banks, fintechs).

= What does the audit log capture? =

Every API call the plugin makes (method, path, status, duration, rate-limit remaining), every webhook delivery received (ID, type, signer, match), every deprecation warning emitted by the API (RFC 8594 `Deprecation` / `Sunset`), and every admin operation (create session, rotate secret, etc.). 30-day retention; pruned by a daily cron. Exportable to CSV for an external SIEM.

= What about LGPD / GDPR? =

The plugin registers handlers in `wp_privacy_personal_data_exporters` and `wp_privacy_personal_data_erasers`:

* **Exporter** — returns every session associated with the data subject's email (name, emails, session ID, evidence ID, status, timestamps)
* **Eraser** — redacts the signer's name and email to `[redacted-<hash8>]`, but **preserves** the evidence ID, transaction ID, session ID, and timestamps. Reason: electronic-signature law requires evidence retention for the legal retention period, even after a request to erase. Identity is redacted locally; the evidence package on the server stays intact for future legal audits.

= Does the plugin work without WooCommerce? =

Yes. The WooCommerce integration is optional and only loads when WooCommerce is active. The shortcode, Gutenberg block, envelopes, Verify page, audit log, and WP-CLI all work independently.

= Can I test for free? =

Yes. Set the environment to "HML (Sandbox)" in the settings. Test data, simulated OTP (`000000` or `123456` are always accepted), mocked biometrics, no charges.

= What is the maximum PDF size? =

The plugin accepts PDFs up to 15 MB. For larger files, increase `upload_max_filesize` and `memory_limit` in PHP and ensure your tenant is configured for large documents on SignDocs.

= Does it work with any WordPress theme? =

Yes. The plugin uses minimal styles and respects your theme's CSS hierarchy. The button can be customized via a CSS class or via the brand color setting.

= Is signer data secure? =

Yes. API credentials are encrypted in the database (AES-256-CBC with a key derived from `wp_salt`). OAuth tokens live in transients (never in permanent options). Webhook secrets are encrypted. The JWT private key (when used) is also encrypted. Webhook HMAC verification is constant-time (handled by the SDK). Webhook de-duplication via a transient lock prevents replay attacks.

= Can I customize the look of the signing page? =

Yes. Configure the brand color and logo in the plugin settings. The page hosted at `sign.signdocs.com.br` will display your visual identity. For deeper customization, contact support — corporate-level theming is available on the Enterprise plan.

= Does it work behind CloudFront / Cloudflare / nginx proxy? =

Yes. Set `signdocs_trusted_proxies` to a list of trusted CIDR ranges (e.g., `10.0.0.0/8, 172.16.0.0/12`). The plugin uses `X-Forwarded-For` only when `REMOTE_ADDR` is in a trusted range, preventing IP spoofing in the rate limiter and audit log.

= Is the plugin available in Portuguese? =

Yes. All user-facing strings are translatable (`signdocs-brasil` text domain) and the plugin ships with Brazilian Portuguese (`pt_BR`) and Spanish (`es_ES`) translations. WordPress automatically loads the right language pack based on your site's locale setting.

== Screenshots ==

1. Settings page — credentials, environment, authentication method (client_secret or Private Key JWT), signing defaults
2. Gutenberg block in the editor with the configuration panel on the right
3. Shortcode rendered with the signing button on the front-end
4. Signing popup opened by the `@signdocs-brasil/js` SDK on `sign.signdocs.com.br`
5. Signatures list in the admin panel with colored status, signer, policy
6. "Verify Document" page — evidence ID pasted, result with signers, tenant CNPJ, downloads
7. Multi-signer envelope panel with the signer repeater and sequential / parallel toggle
8. Audit log (WP_List_Table) with level / event / date filters and the CSV export button
9. WooCommerce product tab with required-signature configuration
10. WooCommerce order email with the signing link

== Changelog ==

= 1.3.4 =

Plugin Check (PCP) hardening pass for WP.org submission. No runtime behavior changes — every fix in this release is either annotation, defensive cleanup, or removal of a benign-but-noisy header.

* **Dropped `Domain Path: /languages` header** — the plugin doesn't ship `.pot` / `.mo` files yet (translations are loaded via WP.org's automatic language packs once approved), and the empty `languages/` folder doesn't exist in the distribution zip. PCP rightly flagged the header as pointing to a non-existent path.
* **`wp_unslash()` + explicit sanitization on every `$_POST` / `$_SERVER` read** — added across `class-signdocs-ajax.php` and `class-signdocs-woocommerce.php`. The values were already being passed through `sanitize_text_field()` / `absint()` / `sanitize_email()` / `esc_url_raw()`, but `wp_unslash()` is the WPCS-canonical pattern and reviewers expect it.
* **Documented `phpcs:ignore` annotations on the custom-table queries.** `AuditQuery`, `Logger`, `webhook` controller, and `uninstall.php` all touch `{$wpdb->prefix}signdocs_log` (or `$wpdb->postmeta` for the webhook lookup). PCP's `WordPress.DB.DirectDatabaseQuery.*` is unavoidable for plugins with their own tables — every annotation now states the table being touched and why core's caching/query API doesn't apply.
* **`AuditQuery` dynamic-prepare pattern documented inline.** `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` and `PreparedSQLPlaceholders.*` warnings on `AuditQuery::count()` / `select()` are PCP false positives — the `{$where}` fragment is built only from `Filters` allow-listed columns and the `{$orderBy}` / `{$order}` are validated against `ALLOWED_ORDER_COLUMNS` and `validatedOrder()`. Annotated explicitly so future readers (and reviewers) don't have to re-discover this.
* **`wp_enqueue_script` for the CDN-hosted browser SDK now passes a version arg** instead of `null` (`includes/class-signdocs-shortcode.php:140`). The CDN already serves immutable `v1`-pinned bundles, but a version argument silences PCP's `EnqueuedResourceParameters.MissingVersion` and keeps the dev-tools network panel readable.

Plugin Check status after this release: **0 ERRORs, ~30 WARNINGs** (down from 79; remaining warnings are documented false positives — webhook HMAC-vs-nonce, server-side hooks, plugin-specific custom-table queries, and the local-vs-global naming heuristic).

= 1.3.3 =

Cleanup pass after the v1.3.2 acceptance run.

* **`wp signdocs webhook-test` actually works.** The SDK's typed `WebhookTestResponse` model expects `{deliveryId, status, statusCode}` but the API returns `{webhookId, testDelivery: {httpStatus, success, timestamp}}`, so the typed call returned all-empty fields. CLI now bypasses the typed wrapper and reads the raw response, printing the real HTTP status + delivery timestamp. SDK fix tracked separately; the CLI unblocks operators today.
* **Dispatcher: dropped dead `SIGNING_SESSION.*` branches.** The OpenAPI spec lists these but the server never emits them — the lifecycle is communicated entirely through the corresponding `TRANSACTION.*` events. Same cleanup applied to the legacy webhook controller in `includes/`. No behavior change; just removes confusion for anyone reading the dispatch table.
* **Audit log writes on success, not just on warnings.** A `webhook.completed` info row now lands in `signdocs_log` for every `TRANSACTION.COMPLETED`, capturing transaction ID, evidence ID, and the matched CPT post ID. Brings the table in line with the readme's "every API call recorded" claim.
* **WP-CLI `webhook-test` and `log-tail` now register with their dashed names** as documented in the class header (previously the `_` form silently took precedence).

= 1.3.2 =

Two production-acceptance fixes uncovered while running the v1.3.1 release against real HML webhooks and the verify admin UI.

* **Webhook dedup keyed off the wrong identifier** — `X-SignDocs-Webhook-Id` carries the *subscription* ID (`wh_*`), not a per-delivery ID. The previous dedup transient used that header as the key, so the first delivery for a subscription poisoned the cache for the full 7-day TTL and every subsequent webhook (including `TRANSACTION.COMPLETED`) returned 200/deduped without ever reaching the dispatcher. CPT records stayed stuck in `PENDING`. Now keys off the body's top-level `id` (the actual delivery ID, `del_*`).
* **Custom capabilities resolved to `do_not_allow`** — the envelope CPT's `'capabilities'` map remapped `read_post`/`edit_post` to `signdocs_verify`/`signdocs_send`/`signdocs_manage`, which registered them in WordPress's `$post_type_meta_caps` table as *meta* caps. Core's `map_meta_cap()` then short-circuited them to `do_not_allow` whenever called without a post argument — so even an administrator got HTTP 403 on the Verify admin page. Switched the envelope CPT to a custom `capability_type` and translate the generated CPT-cap names to the four primitive `signdocs_*` caps via `Capabilities::mapMetaCap`.
* **Verified end-to-end against HML**: full create → sign → `TRANSACTION.COMPLETED` webhook → CPT updated to COMPLETED with `evidenceId` → `verification->verify($evidenceId)` returns the signed evidence record (CPF, policy, completion timestamp).

= 1.3.1 =

WP.org submission readiness — Plugin Check (PCP) baseline + canonical English readme + complete CPF/CNPJ collection.

* **Plugin Check: 0 ERRORs** — fixed 12 PCP error-level findings: 4× missing `defined('ABSPATH')` guards in `src/Admin/{VerifyPage,AuditTable}.php`, `src/Cpt/EnvelopeCpt.php`, `src/Webhook/Controller.php`; 3× missing `translators:` comments on `__()` calls with placeholders in WooCommerce integration; 2× output escape gaps in WooCommerce email body; 1× output escape gap on the CPT status badge (now via `wp_kses_post()`); `strip_tags()` → `wp_strip_all_tags()` in the unit-test fallback path of `Filters`; documented `fopen` / `fwrite` / `fclose` in `AuditExport` as a streaming-CSV pattern with file-scoped `phpcs:ignore`.
* **Canonical readme rewritten in English.** WP.org policy 2025-07-28 requires the description, short description, and FAQ to be in English. The old Portuguese sections move to the standard i18n flow — `pt_BR` site visitors will see the localized strings via WordPress.org's automatic translation pack delivery once the plugin is approved.
* **Removed `load_plugin_textdomain()`** — discouraged since WordPress 4.6 for plugins on WP.org. WordPress core auto-loads the right language pack from the plugin slug.
* **CPF / CNPJ collection at every entry point** — the SignDocs API requires `signer.cpf` or `signer.cnpj` at session-create time. Added to: shortcode form (when `show_form="true"`), AJAX handler validation, frontend JS payload, `wp signdocs send --cpf=` / `--cnpj=` flags, WooCommerce integration (reads `_billing_cpf` / `_billing_cnpj` from order meta — works with the standard "Brazilian Market on WooCommerce" extension), envelope service per-signer.
* **`wp signdocs send` outputs the full shareable URL** — previously printed only the base session URL, which is not directly usable. Now appends `?cs=<clientSecret>` (URL-encoded) so the printed link can be opened directly to start signing.
* **`wp signdocs status` now requires `--client-secret`** — documented the embed-token authentication contract. Full implementation deferred to a follow-up release.
* **Acceptance test against real HML**: WP 6.9.x + MariaDB 11 in podman, plugin installed from the v1.3.1 zip, real HML credentials. Confirmed: `signingSessions->create` returns a valid `sessionId` + `clientSecret` + `url`; `envelopes->create` returns a valid `envelopeId` (PARALLEL, 2 signers); WP-CLI validates CPF/CNPJ inputs correctly; capabilities install on activation; plugin co-active with WooCommerce 10.7.

= 1.3.0 =

Alignment with PHP SDK 1.4.0 + complete English readme.

* **PHP SDK upgraded to `^1.4`** — SDK 1.4.0 fixed a model-shape divergence in `CreateSigningSessionRequest` / `CreateEnvelopeRequest` that had existed since 1.0.0: the correct field names accepted by the API are `purpose`, `policy`, `signer`, `document`, `returnUrl`, `cancelUrl`, `metadata`, `locale`, `expiresInMinutes`, `appearance`. Plugin call sites (CLI + AJAX) were updated.
* **CPF / CNPJ collection at every entry point** — the API requires `signer.cpf` or `signer.cnpj` at session creation. The shortcode form now exposes CPF + CNPJ fields when `show_form="true"`; the AJAX handler validates them; `wp signdocs send` accepts `--cpf` / `--cnpj`; the WooCommerce integration reads `_billing_cpf` / `_billing_cnpj` from the order (Brazilian Market on WooCommerce extension keys); the envelope service propagates per-signer CPF.
* **Readme rewritten in English** — per [WP.org policy from 2025-07-28](https://make.wordpress.org/plugins/2025/07/28/requiring-the-readme-to-be-written-in-english/), the canonical readme description must be in English. Portuguese localization is loaded automatically from the bundled `.po` / `.mo` translation files for `pt_BR` sites.
* **Plugin Check (PCP) errors fixed** — direct file access guards (`defined('ABSPATH')`) added to 4 source files; missing `translators:` comments added to 3 `__()` calls with placeholders; output escaping tightened in WooCommerce email and CPT badge rendering; `strip_tags()` swapped for `wp_strip_all_tags()` in non-test paths.
* **"Tested up to" updated** to WordPress 6.9 (the version used in the automated pen test).

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
* **Full webhook coverage** — added `TRANSACTION.CREATED`, `TRANSACTION.FALLBACK`, `STEP.STARTED/COMPLETED/FAILED`, `QUOTA.WARNING`, `API.DEPRECATION_NOTICE`, plus the two NT65 INSS-consignado events `STEP.PURPOSE_DISCLOSURE_SENT` and `TRANSACTION.DEADLINE_APPROACHING`. Covers the 13 events the server actually emits today.
* **Observability** — `Deprecation` / `Sunset` (RFC 8594) response headers surface as admin notices; `RateLimit-*` headers are captured in a transient for the dashboard widget; structured log table `{prefix}signdocs_log` with 30-day retention and a daily cron prune.
* **Idempotency** — `X-Idempotency-Key` is now sent on every resource-creating call, derived deterministically from site URL + user + action + resource, so AJAX retries no longer create duplicate sessions.
* **Capability model** — four new caps (`signdocs_manage`, `signdocs_send`, `signdocs_verify`, `signdocs_view_logs`) instead of raw `manage_options` / `edit_posts`. Granted to administrator / editor / author on activation via `Capabilities::install()`; `map_meta_cap` wires them to CPT operations.
* **LGPD / GDPR** — `wp_privacy_personal_data_exporters` and `wp_privacy_personal_data_erasers` are registered. The eraser redacts signer name + email but preserves evidence IDs and completion timestamps for legal retention.
* **WP-CLI** — `wp signdocs health|send|status|webhook-test|log-tail`.
* **Test suite** — PHPUnit (Brain Monkey unit tests), PHPStan level 5 (with `phpstan-wordpress`), PHPCS (WordPress-Extra + PHPCompatibilityWP), GitHub Actions matrix on PHP 8.1 / 8.2 / 8.3.

= 1.0.0 =
* Initial release
* `[signdocs]` shortcode with 8 configurable attributes
* Gutenberg block with live preview (ServerSideRender)
* `signdocs_signing` custom post type with status, signer, and policy columns
* REST webhook receiver with HMAC-SHA256 verification
* WooCommerce integration: product tab, automatic email, order notes
* AES-256-CBC encryption for stored credentials
* Popup, redirect, and overlay support
* Rate limiting for anonymous signing
* Trilingual: pt-BR, en, es

== Upgrade Notice ==

= 1.3.4 =
Plugin Check (PCP) hardening pass for WP.org submission. No behavior changes — defensive `wp_unslash()` on `$_POST` reads, documented `phpcs:ignore` annotations on the audit-log custom-table queries, dropped the unused `Domain Path` header.

= 1.3.3 =
Cleanup pass: `wp signdocs webhook-test` now prints actual delivery status; logger writes a row on every successful `TRANSACTION.COMPLETED`; legacy `SIGNING_SESSION.*` dispatch branches removed (server only emits `TRANSACTION.*` events).

= 1.3.2 =
Two production-blocking fixes: webhook dedup was keyed off the subscription ID (so `TRANSACTION.COMPLETED` never updated CPTs) and custom capabilities resolved to `do_not_allow` (locking admins out of the Verify page). Strongly recommended for any 1.3.x install.

= 1.3.0 =
Requires PHP SDK 1.4 (`composer update`); fixes the `CreateSigningSessionRequest` shape that returned 400 with SDK 1.3.x. Also adds CPF / CNPJ collection at every entry point — required by the API. Re-check custom integrations.

= 1.2.0 =
Adds multi-signer envelopes, the verification page, the audit log, Private Key JWT authentication, and webhook secret rotation. Plugin re-activation is required to grant the new capabilities (`signdocs_manage` / `_send` / `_verify` / `_view_logs`) to existing roles.

= 1.1.0 =
Hardening update + SDK 1.3.0 alignment. Re-activation is required to create the audit log table and install the custom capabilities.

= 1.0.0 =
Initial release of the SignDocs Brasil WordPress plugin.
