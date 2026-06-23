<?php
/**
 * PHPStan bootstrap – stub WordPress symbols that lib.php depends on
 * but that aren't available during static analysis.
 */

define('ABSPATH', '/tmp/wordpress/');

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string {
        return trailingslashit(dirname($file));
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string {
        return rtrim($value, '/\\') . '/';
    }
}

if (!function_exists('get_option')) {
    function get_option(string $option, $default = false) {
        return $default;
    }
}

if (!function_exists('_doing_it_wrong')) {
    function _doing_it_wrong(string $function_name, string $message, string $version): void {}
}
