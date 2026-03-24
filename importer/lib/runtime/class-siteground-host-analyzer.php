<?php
/**
 * Host analyzer for SiteGround (and generic shared hosting).
 *
 * SiteGround sites use a standard WordPress directory layout. The main
 * concern is preserving PHP INI settings that the source host had
 * (memory limits, upload sizes, etc.) since the target runtime may have
 * different defaults.
 *
 * This analyzer also serves as the fallback for unrecognized hosts — any
 * site with a standard WordPress layout benefits from having its INI
 * settings carried over.
 */
class SitegroundHostAnalyzer extends HostAnalyzer
{
    public function analyze(array $preflight_data, string $state_dir): RuntimeManifest
    {
        $manifest = new RuntimeManifest('siteground');
        $manifest->php_ini = $this->extract_php_ini($preflight_data);

        // Standard WordPress layout — wp-content is in the docroot.
        // Only set these if the preflight confirms non-standard paths;
        // for vanilla layouts we leave them unset so WordPress uses its
        // own defaults.
        $paths_urls = $preflight_data['database']['wp']['paths_urls'] ?? [];
        $abspath = $paths_urls['abspath'] ?? '';
        $content_dir = $paths_urls['content_dir'] ?? '';

        // If wp-content lives outside ABSPATH, we need to tell WordPress
        // where to find it on the target.
        if ($content_dir !== '' && $abspath !== '' && strpos($content_dir, $abspath) !== 0) {
            $manifest->constants['WP_CONTENT_DIR'] = '{docroot}/wp-content';
        }

        return $manifest;
    }
}
