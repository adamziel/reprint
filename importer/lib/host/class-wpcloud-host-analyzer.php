<?php
/**
 * Host analyzer for WP Cloud (wpcom) sites.
 *
 * WP Cloud sites have a non-standard directory layout: WordPress core lives
 * at a versioned path (/wordpress/core/X.Y.Z/) and wp-content is a separate
 * tree under /srv/htdocs/. After flatten-docroot, the local layout uses a
 * __wp__ directory for core.
 *
 * The export only ships original-size uploads, so the manifest declares a
 * 404 handler for thumbnail-sized image URLs. The target runtime decides
 * how to implement it.
 */
class WpcloudHostAnalyzer extends HostAnalyzer
{
    /**
     * Score how likely the source site is a WP Cloud site.
     *
     * Signals:
     * - __wp__ directory exists in the document root (0.5)
     * - WordPress detected at doc_root/__wp__/ (0.4)
     * - PRIVACY_MODEL environment variable is defined (0.5)
     */
    public static function score(array $preflight_data): float
    {
        $score = 0.0;

        $doc_root = $preflight_data['runtime']['document_root'] ?? null;

        // Signal: __wp__ directory exists in the document root.
        // WP Cloud sites have a __wp__ symlink in the document root pointing
        // to the WordPress core installation.
        if (is_string($doc_root) && $doc_root !== '') {
            $wp_dir = rtrim($doc_root, '/') . '/__wp__';
            $dir_checks = $preflight_data['filesystem']['directories'] ?? [];
            foreach ($dir_checks as $check) {
                $path = $check['path'] ?? '';
                if ($path === $wp_dir && ($check['exists'] ?? false)) {
                    $score += 0.5;
                    break;
                }
            }
        }

        // Signal: WordPress detected at __wp__ inside the document root.
        // WP Cloud typically has WordPress installed at doc_root/__wp__/.
        $wp_roots = $preflight_data['wp_detect']['roots'] ?? [];
        if (is_string($doc_root) && $doc_root !== '') {
            $wp_subdir = rtrim($doc_root, '/') . '/__wp__';
            foreach ($wp_roots as $root) {
                $path = $root['path'] ?? '';
                if ($path === $wp_subdir) {
                    $score += 0.4;
                    break;
                }
            }
        }

        // Signal: PRIVACY_MODEL environment variable is defined.
        // WP Cloud sets this env var; its mere presence is a strong hint.
        $env_names = $preflight_data['runtime']['env_names'] ?? [];
        if (in_array('PRIVACY_MODEL', $env_names, true)) {
            $score += 0.5;
        }

        return min($score, 1.0);
    }

    public function analyze(array $preflight_data): RuntimeManifest
    {
        $manifest = new RuntimeManifest('wpcloud');
        $manifest->php_ini = $this->extract_php_ini($preflight_data);
        $manifest->constants = $this->extract_constants($preflight_data);
        $manifest->server_vars = $this->extract_server_vars($preflight_data);

        // WP Cloud exports only ship full-size uploads. WordPress post meta
        // references sized variants like image-768x768.jpeg that don't exist
        // on disk. Declare a 404 handler so the target runtime can generate
        // them on-the-fly from the originals.
        $manifest->error_handlers[] = [
            'type' => 'thumbnail-generator',
            'path_pattern' => '/wp-content/uploads/.*-\d+x\d+\.\w+$',
            'description' => 'Generate missing WordPress thumbnail sizes from originals using GD',
        ];

        return $manifest;
    }

    protected function extract_server_vars(array $preflight_data): array
    {
        // WP Cloud uses a __wp__ directory for WordPress core. After
        // flatten-docroot, it lives at {docroot}/__wp__/.
        return [
            'WP_DIR' => '{docroot}/__wp__/',
        ];
    }

    protected function extract_constants(array $preflight_data): array
    {
        $result = parent::extract_constants($preflight_data);

        // WP Cloud always needs THEMES_PATH_BASE since themes are under
        // wp-content, not under the __wp__ ABSPATH.
        $result['THEMES_PATH_BASE'] = '{docroot}/wp-content/themes';

        return $result;
    }
}
