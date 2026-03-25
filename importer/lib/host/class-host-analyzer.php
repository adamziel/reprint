<?php
/**
 * Base class for host analyzers.
 *
 * A host analyzer reads preflight data from the source site and produces
 * a RuntimeManifest describing the environment that site needs to run.
 * Each hosting provider has its own subclass that knows which constants,
 * INI settings, and request interceptors the source site relied on.
 */
abstract class HostAnalyzer
{
    /**
     * Analyze preflight data and produce a runtime manifest.
     *
     * @param array  $preflight_data The preflight response data (from .import-state.json).
     * @param string $state_dir      Absolute path to the state directory.
     * @return RuntimeManifest
     */
    abstract public function analyze(array $preflight_data, string $state_dir): RuntimeManifest;

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
     * Only includes values that differ from common PHP defaults.
     */
    protected function extract_php_ini(array $preflight_data): array
    {
        $ini_all = $preflight_data['runtime']['ini_get_all'] ?? [];
        if (empty($ini_all)) {
            return [];
        }

        // INI keys worth preserving — these are the ones most likely to
        // affect whether a migrated site works or breaks.
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
}
