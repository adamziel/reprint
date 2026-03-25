<?php
/**
 * Host analyzer for WP Cloud (wpcom) sites.
 *
 * WP Cloud sites have a non-standard directory layout: WordPress core lives
 * at __wp__/ inside the document root, and wp-content is a separate tree.
 * The exported site only ships original-size uploads, so we need an on-the-fly
 * thumbnail generator to serve the sized variants WordPress references in
 * post meta (e.g. image-768x768.jpeg).
 */
class WpcloudHostAnalyzer extends HostAnalyzer
{
    public function analyze(array $preflight_data, string $state_dir): RuntimeManifest
    {
        $manifest = new RuntimeManifest('wpcloud');
        $manifest->php_ini = $this->extract_php_ini($preflight_data);

        // WP Cloud uses a __wp__ directory for WordPress core. The flattened
        // docroot layout places it at {docroot}/__wp__/.
        $manifest->server_vars['WP_DIR'] = '{docroot}/__wp__/';

        // wp-content and themes paths — standard locations after flatten-docroot.
        $manifest->constants['WP_CONTENT_DIR'] = '{docroot}/wp-content';
        $manifest->constants['THEMES_PATH_BASE'] = '{docroot}/wp-content/themes';

        // The thumbnail generator intercepts requests for sized image variants
        // (e.g. photo-300x200.jpg) that don't exist on disk, generates them
        // from the original using GD, caches them, and serves them — all before
        // WordPress boots. This is needed because the export only ships
        // original-size uploads.
        $this->install_thumbnail_interceptor($state_dir);
        $manifest->request_interceptors[] = [
            'name' => 'thumbnail-on-demand',
            'phase' => 'before-wordpress',
            'may_exit' => true,
            'script' => 'runtime/thumbnail-on-demand.php',
        ];

        return $manifest;
    }

    /**
     * Copy the thumbnail interceptor script into the state directory's
     * runtime/ folder so it ships alongside the manifest.
     */
    private function install_thumbnail_interceptor(string $state_dir): void
    {
        $runtime_dir = $state_dir . '/runtime';
        if (!is_dir($runtime_dir)) {
            mkdir($runtime_dir, 0755, true);
        }

        $source = __DIR__ . '/scripts/thumbnail-on-demand.php';
        $dest = $runtime_dir . '/thumbnail-on-demand.php';
        if (!file_exists($source)) {
            throw new RuntimeException(
                "Thumbnail interceptor script not found at {$source}"
            );
        }
        copy($source, $dest);
    }
}
