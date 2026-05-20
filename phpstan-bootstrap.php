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

if (!function_exists('wp_native_apis_rewrite_plain_text_literal_urls')) {
    /**
     * PHPStan bootstrap stub for the optional php-toolkit PHP.wasm native extension.
     *
     * @return false|string
     */
    function wp_native_apis_rewrite_plain_text_literal_urls(string $content, string $compact_mapping) {
        return false;
    }
}
