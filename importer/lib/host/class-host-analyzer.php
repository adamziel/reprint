<?php
/**
 * Base class for host analyzers.
 *
 * A host analyzer reads preflight data from the source site and produces
 * a RuntimeManifest. The base class extracts everything that's common to
 * all hosts (INI directives, WP constants, paths). Subclasses override
 * analyze() to add host-specific concerns (e.g. wpcloud's thumbnail 404
 * handler and non-standard directory layout).
 */
abstract class HostAnalyzer
{
    /**
     * Analyze preflight data and produce a runtime manifest.
     *
     * @param array $preflight_data The preflight response data.
     * @return RuntimeManifest
     */
    abstract public function analyze(array $preflight_data): RuntimeManifest;

    /**
     * Pick the right analyzer for a detected webhost.
     *
     * @param string $webhost The detected host ("wpcloud", "siteground", "other").
     * @return self
     */
    public static function for_host(string $webhost): self
    {
        switch ($webhost) {
            case 'wpcloud':
                return new WpcloudHostAnalyzer();
            case 'siteground':
                return new SitegroundHostAnalyzer();
            default:
                return new SitegroundHostAnalyzer(); // generic fallback
        }
    }

    /**
     * Extract selected INI directives from preflight's ini_get_all.
     * Only includes values that are likely to affect whether a migrated
     * site works or breaks.
     */
    protected function extract_php_ini(array $preflight_data): array
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
     * Extract PHP constants from preflight that need to be defined on
     * the target. Reads constant_values and paths_urls from the preflight
     * response and maps source absolute paths to {docroot}-relative paths.
     *
     * Returns only constants where the source value is a path that differs
     * from the standard WordPress layout (meaning WordPress won't derive
     * the right value on its own).
     */
    protected function extract_constants(array $preflight_data): array
    {
        $constants = $preflight_data['database']['wp']['constant_values'] ?? [];
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

    /**
     * Extract $_SERVER variables from preflight that need to be set on
     * the target. Returns only entries that the source host relied on
     * and that WordPress doesn't set by default.
     */
    protected function extract_server_vars(array $preflight_data): array
    {
        // Base implementation returns nothing — most hosts don't need
        // custom server vars. Subclasses override for host-specific needs.
        return [];
    }
}
