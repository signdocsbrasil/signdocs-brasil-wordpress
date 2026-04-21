<?php

defined('ABSPATH') || exit;

use SignDocsBrasil\WordPress\Admin\AuditTable;
use SignDocsBrasil\WordPress\Admin\VerifyPage;
use SignDocsBrasil\WordPress\Auth\Capabilities;
use SignDocsBrasil\WordPress\Cli\SigndocsCommand;
use SignDocsBrasil\WordPress\Cpt\EnvelopeCpt;
use SignDocsBrasil\WordPress\Privacy\Eraser;
use SignDocsBrasil\WordPress\Privacy\Exporter;
use SignDocsBrasil\WordPress\Support\Logger;
use SignDocsBrasil\WordPress\Webhook\Controller as WebhookController;
use SignDocsBrasil\WordPress\Webhook\SecretResolver;

/**
 * Main plugin orchestrator. Wires up all components.
 */
final class Signdocs_Plugin
{
    private static ?self $instance = null;

    private function __construct()
    {
        (new Signdocs_Settings())->register();
        (new Signdocs_CPT())->register();
        (new Signdocs_Meta_Boxes())->register();
        (new Signdocs_Ajax())->register();
        (new Signdocs_Shortcode())->register();

        // Hardened webhook controller. v1.2.0: supports rotation-aware
        // multi-secret resolution via SecretResolver.
        $decrypt = static fn(string $enc): string => (string) Signdocs_Credentials::decrypt($enc);
        $resolver = new SecretResolver($decrypt);
        /** @return list<string> */
        $secretResolver = static fn(): array => $resolver->all();
        (new WebhookController($secretResolver))->register();

        // Expire the rotation "previous secret" after the grace window.
        add_action(SecretResolver::EXPIRE_CRON_HOOK, [SecretResolver::class, 'expireIfDue']);

        // v1.2.0: Envelope CPT + admin pages.
        (new EnvelopeCpt())->register();
        (new VerifyPage())->register();
        AuditTable::registerPage();

        // Privacy hooks (LGPD / GDPR)
        add_filter('wp_privacy_personal_data_exporters', [Exporter::class, 'register']);
        add_filter('wp_privacy_personal_data_erasers', [Eraser::class, 'register']);

        // Custom capability → meta cap mapping for CPT operations.
        add_filter('map_meta_cap', [Capabilities::class, 'mapMetaCap'], 10, 4);

        // Daily log-prune cron target.
        add_action('signdocs_prune_logs', [Logger::class, 'prune']);

        // WP-CLI surface.
        SigndocsCommand::register();

        add_action('init', [$this, 'register_block']);
        add_action('before_woocommerce_init', [$this, 'declare_wc_compatibility']);

        if (class_exists('WooCommerce')) {
            $woo_file = SIGNDOCS_PLUGIN_DIR . 'includes/class-signdocs-woocommerce.php';
            if (file_exists($woo_file)) {
                require_once $woo_file;
                (new Signdocs_WooCommerce())->register();
            }
        }
    }

    public function declare_wc_compatibility(): void
    {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                SIGNDOCS_PLUGIN_DIR . 'signdocs-brasil.php',
                true,
            );
        }
    }

    public function register_block(): void
    {
        $block_dir = SIGNDOCS_PLUGIN_DIR . 'assets/js/block';
        if (!file_exists($block_dir . '/block.json')) {
            return;
        }

        register_block_type($block_dir, [
            'render_callback' => function (array $attributes): string {
                return (new Signdocs_Shortcode())->render($attributes);
            },
        ]);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate(): void
    {
        (new Signdocs_CPT())->register_post_type();
        (new EnvelopeCpt())->registerPostType();
        Capabilities::install();
        Logger::installSchema();
        if (!wp_next_scheduled(SecretResolver::EXPIRE_CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', SecretResolver::EXPIRE_CRON_HOOK);
        }
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        foreach (['signdocs_prune_logs', SecretResolver::EXPIRE_CRON_HOOK] as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp !== false) {
                wp_unschedule_event($timestamp, $hook);
            }
        }
        flush_rewrite_rules();
    }
}
