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
