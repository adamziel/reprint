<?php
/**
 * Base class for host analyzers.
 *
 * A host analyzer does two things:
 *
 * 1. Scores how likely it is that the source site runs on its hosting
 *    platform, based on preflight data (the static score() method).
 * 2. Reads preflight data and produces a RuntimeManifest describing
 *    what the site needs to run (the analyze() method).
 *
 * The base class provides the detection loop (detect()) and shared
 * extraction helpers. Subclasses implement score() and analyze().
 */
abstract class HostAnalyzer
{
    /**
     * Score how likely this host matches the given preflight data.
     *
     * Each subclass examines preflight signals relevant to its platform
     * and returns a float between 0.0 (no match) and 1.0 (certain match).
     * A score >= 0.5 is considered a viable candidate.
     *
     * @param array $preflight_data The preflight response data.
     * @return float Likelihood score between 0.0 and 1.0.
     */
    abstract public static function score(array $preflight_data): float;

    /**
     * Analyze preflight data and produce a runtime manifest.
     *
     * @param array $preflight_data The preflight response data.
     * @return RuntimeManifest
     */
    abstract public function analyze(array $preflight_data): RuntimeManifest;

    /**
     * All known host analyzers, in order of specificity.
     * More specific hosts should come first so they win ties.
     *
     * @return array<string, class-string<HostAnalyzer>>
     */
    private static function registry(): array
    {
        return [
            'wpcloud' => WpcloudHostAnalyzer::class,
            'siteground' => SitegroundHostAnalyzer::class,
        ];
    }

    /**
     * Detect the source host from preflight data using likelihood scoring.
     *
     * Each registered host analyzer scores the preflight data independently.
     * The host with the highest score wins, provided it reaches the minimum
     * threshold of 0.5. Returns "other" if no host qualifies.
     *
     * @param array $preflight_data The preflight response data.
     * @return string The detected host name ("wpcloud", "siteground", "other").
     */
    public static function detect(array $preflight_data): string
    {
        $threshold = 0.5;
        $best_host = 'other';
        $best_score = 0.0;

        foreach (self::registry() as $name => $class) {
            $score = $class::score($preflight_data);
            if ($score >= $threshold && $score > $best_score) {
                $best_host = $name;
                $best_score = $score;
            }
        }

        return $best_host;
    }

    /**
     * Instantiate the right analyzer for a detected host name.
     *
     * @param string $webhost "wpcloud", "siteground", or "other".
     * @return self
     */
    public static function for_host(string $webhost): self
    {
        $registry = self::registry();
        if (isset($registry[$webhost])) {
            return new $registry[$webhost]();
        }
        // "other" and unrecognized hosts fall back to the generic analyzer.
        return new SitegroundHostAnalyzer();
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
