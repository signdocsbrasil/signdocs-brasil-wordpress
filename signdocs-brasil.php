<?php
/**
 * Plugin Name: SignDocs Brasil
 * Plugin URI:  https://signdocs.com.br
 * Description: Assinatura eletrônica integrada ao seu site WordPress via SignDocs Brasil.
 * Version:     1.2.3
 * Author:      SignDocs Brasil
 * Author URI:  https://signdocs.com.br
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: signdocs-brasil
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * WC requires at least: 8.0
 * WC tested up to: 9.6
 */

defined('ABSPATH') || exit;

define('SIGNDOCS_VERSION', '1.2.3');
define('SIGNDOCS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SIGNDOCS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SIGNDOCS_PLUGIN_BASENAME', plugin_basename(__FILE__));

$autoloader = SIGNDOCS_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
}

require_once SIGNDOCS_PLUGIN_DIR . 'includes/class-signdocs-credentials.php';
require_once SIGNDOCS_PLUGIN_DIR . 'includes/class-signdocs-client-factory.php';
require_once SIGNDOCS_PLUGIN_DIR . 'includes/class-signdocs-settings.php';
require_once SIGNDOCS_PLUGIN_DIR . 'includes/class-signdocs-cpt.php';
require_once SIGNDOCS_PLUGIN_DIR . 'includes/class-signdocs-ajax.php';
require_once SIGNDOCS_PLUGIN_DIR . 'includes/class-signdocs-shortcode.php';
require_once SIGNDOCS_PLUGIN_DIR . 'includes/class-signdocs-webhook.php';
require_once SIGNDOCS_PLUGIN_DIR . 'includes/class-signdocs-meta-boxes.php';
require_once SIGNDOCS_PLUGIN_DIR . 'includes/class-signdocs-plugin.php';

register_activation_hook(__FILE__, ['Signdocs_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Signdocs_Plugin', 'deactivate']);

add_action('plugins_loaded', function () {
    load_plugin_textdomain('signdocs-brasil', false, dirname(SIGNDOCS_PLUGIN_BASENAME) . '/languages');
    Signdocs_Plugin::instance();
});
