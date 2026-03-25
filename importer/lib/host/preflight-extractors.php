<?php
/**
 * Shared helpers for extracting runtime requirements from preflight data.
 *
 * These are used by host analyzer implementations to read INI directives,
 * PHP constants, and server variables from the preflight response. They're
 * standalone functions rather than methods on the HostAnalyzer interface
 * because they're shared implementation details, not part of the contract.
 */

/**
 * Extract selected INI directives from preflight's ini_get_all.
 * Only includes values that are likely to affect whether a migrated
 * site works or breaks.
 */
function extract_php_ini(array $preflight_data): array
{
    $ini_all = $preflight_data['runtime']['ini_get_all'] ?? [];
    if (empty($ini_all)) {
        return [];
    }

    $interesting_keys = [
        'memory_limit',
        'upload_max_filesize',
        'post_max_size',
        'max_execution_time',
        'max_input_vars',
        'max_input_time',
    ];

    $result = [];
    foreach ($interesting_keys as $key) {
        if (isset($ini_all[$key]) && $ini_all[$key] !== '') {
            $result[$key] = (string) $ini_all[$key];
        }
    }
    return $result;
}

/**
 * Extract PHP constants from preflight that need to be defined on the
 * target. Reads paths_urls from the preflight response.
 *
 * Returns only constants where the source value is a path that differs
 * from the standard WordPress layout (meaning WordPress won't derive
 * the right value on its own).
 */
function extract_constants(array $preflight_data): array
{
    $paths_urls = $preflight_data['database']['wp']['paths_urls'] ?? [];
    $abspath = rtrim($paths_urls['abspath'] ?? '', '/');
    $content_dir = $paths_urls['content_dir'] ?? '';

    $result = [];

    // WP_CONTENT_DIR: if wp-content lives outside ABSPATH on the source
    // (e.g. wpcloud has ABSPATH at /wordpress/core/X.Y.Z/ but wp-content
    // at /srv/htdocs/wp-content), we need to explicitly set it.
    if ($content_dir !== '' && $abspath !== '' && strpos($content_dir, $abspath) !== 0) {
        $result['WP_CONTENT_DIR'] = '{docroot}/wp-content';
    }

    return $result;
}
