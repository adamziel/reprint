<?php

namespace Reprint\Exporter\E2E;

/**
 * Load test hooks from a well-known path relative to the site root.
 *
 * The hook file can define callbacks such as:
 * - test_hook_before_sql_batch(&$sql, $cursor)
 * - test_hook_before_file_chunk($path, $offset, &$data)
 * - test_hook_after_gzip_init($gz, $boundary)
 * - test_hook_before_completion($status, $gz, $boundary)
 * - test_hook_before_index_batch(&$batch_items, $stack)
 * - test_hook_during_dir_scan($dir, &$entries)
 *
 * @param array<string, mixed> $config
 */
function load_test_hooks_if_needed(array $config): void
{
    static $loaded = false;
    if ($loaded || !getenv('SITE_EXPORT_TEST_MODE')) {
        return;
    }

    $candidates = [];
    if (isset($config['directory'])) {
        $dirs = is_array($config['directory']) ? $config['directory'] : [$config['directory']];
        foreach ($dirs as $d) {
            if (is_string($d) && $d !== '') {
                $candidates[] = rtrim($d, '/') . '/wp-content/plugins/site-export/test-hooks.php';
            }
        }
    }
    $candidates[] = dirname(__DIR__) . '/test-hooks.php';

    foreach ($candidates as $candidate) {
        if (file_exists($candidate)) {
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($candidate, true);
            }
            require $candidate;
            $loaded = true;
            return;
        }
    }
}

/**
 * @param array<int, mixed> $args
 */
function call_hook(string $name, array &$args = []): void
{
    if (getenv('SITE_EXPORT_TEST_MODE') && function_exists($name)) {
        call_user_func_array($name, $args);
    }
}
