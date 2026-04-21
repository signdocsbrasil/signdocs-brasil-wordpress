<?php

declare(strict_types=1);

/**
 * Test bootstrap. Pure-unit only — no WP test scaffold. Brain Monkey
 * stubs the WordPress functions we touch (get_option, set_transient,
 * wp_json_encode, etc.) in-memory per test.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Redirect PHP error_log to /dev/null during tests so our Logger's
// error_log side-effects don't pollute PHPUnit's stdout. Individual
// tests can still assert against what was logged via the custom table.
ini_set('log_errors', '1');
ini_set('error_log', '/dev/null');

// Minimal WordPress constants referenced by the plugin's own code.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

// wp_json_encode is used across src/ — provide a passthrough to json_encode.
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string
    {
        $result = json_encode($data, $options, $depth);
        return $result === false ? '' : $result;
    }
}
