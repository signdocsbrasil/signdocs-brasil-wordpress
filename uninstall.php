<?php
/**
 * Runs when the plugin is deleted via the WordPress admin.
 * Removes all plugin data from the database.
 *
 * uninstall.php runs at the script top level, so every loop variable
 * looks like a global to PCP's prefix heuristic — they're all locals
 * with file-scoped lifetime. Disabled file-wide to avoid noise.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

defined('WP_UNINSTALL_PLUGIN') || exit;

// Delete options
$options = [
    'signdocs_client_id_enc',
    'signdocs_client_secret_enc',
    'signdocs_environment',
    'signdocs_default_policy',
    'signdocs_default_locale',
    'signdocs_default_mode',
    'signdocs_default_expiration',
    'signdocs_allow_anonymous',
    'signdocs_webhook_secret_enc',
    'signdocs_brand_color',
    'signdocs_logo_url',
    'signdocs_trusted_proxies',       // v1.1.0
    'signdocs_deprecation_notices',   // v1.1.0
    'signdocs_auth_method',           // v1.2.0
    'signdocs_private_key_enc',       // v1.2.0
    'signdocs_key_id_enc',            // v1.2.0
    'signdocs_webhook_secret_prev_enc',    // v1.2.0
    'signdocs_webhook_secret_rotated_at',  // v1.2.0
];

foreach ($options as $option) {
    delete_option($option);
}

// Delete all CPT posts and their meta
$posts = get_posts([
    'post_type' => 'signdocs_signing',
    'numberposts' => -1,
    'post_status' => 'any',
    'fields' => 'ids',
]);

foreach ($posts as $post_id) {
    wp_delete_post($post_id, true);
}

// Clean up rate limiting / webhook dedup / token cache transients.
// Bulk LIKE delete is the right tool: there's no core API to delete
// transients by prefix, and `delete_transient` per key requires knowing
// every key (we don't — they're hashed). Direct query / no caching are
// inherent to a one-shot uninstall on the options table.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_signdocs_rate_%'
        OR option_name LIKE '_transient_timeout_signdocs_rate_%'
        OR option_name LIKE '_transient_signdocs_wh_%'
        OR option_name LIKE '_transient_timeout_signdocs_wh_%'
        OR option_name LIKE '_transient_signdocs_oauth_%'
        OR option_name LIKE '_transient_timeout_signdocs_oauth_%'
        OR option_name LIKE '_transient_signdocs_rate_headers'
        OR option_name LIKE '_transient_timeout_signdocs_rate_headers'
        OR option_name LIKE '_transient_signdocs_quota_notice'
        OR option_name LIKE '_transient_timeout_signdocs_quota_notice'"
);

// Drop the v1.1.0 audit log table — no core API for dropping plugin tables;
// SchemaChange flag is unavoidable for any plugin that owns its own tables.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}signdocs_log");

// Remove custom capabilities from all roles.
if (function_exists('wp_roles')) {
    $roles = wp_roles();
    $caps = ['signdocs_manage', 'signdocs_send', 'signdocs_verify', 'signdocs_view_logs'];
    foreach (array_keys($roles->role_names) as $role_name) {
        $role = get_role($role_name);
        if ($role === null) {
            continue;
        }
        foreach ($caps as $cap) {
            $role->remove_cap($cap);
        }
    }
}

// Clear any orphan cron events.
$hook = 'signdocs_prune_logs';
$timestamp = wp_next_scheduled($hook);
while ($timestamp !== false) {
    wp_unschedule_event($timestamp, $hook);
    $timestamp = wp_next_scheduled($hook);
}
