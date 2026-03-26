<?php
/**
 * Runtime applier for WordPress Playground CLI (@wp-playground/cli).
 *
 * Writes:
 * 1. {output_dir}/runtime.php     — constants, server vars, route handlers
 * 2. {output_dir}/blueprint.json  — minimal Playground Blueprint
 * 3. {output_dir}/start.sh        — shell script with the full CLI invocation
 *
 * Unlike nginx-fpm and php-builtin which write server config files,
 * Playground CLI handles most configuration through command-line flags.
 * The start.sh script uses --mount-before-install to assemble a standard
 * WordPress layout at /wordpress in the VFS from the real directories
 * on the host.
 *
 * For WPCloud and similar hosts where WordPress core lives in a separate
 * directory from the document root, the applier creates multiple mounts:
 * WordPress core at /wordpress, then wp-content and wp-config.php from
 * the document root overlaid on top. Symlinks inside wp-content (themes,
 * plugins, mu-plugins pointing to shared host directories) are resolved
 * to their real host paths and mounted individually — this replaces
 * Playground's --follow-symlinks with deterministic, explicit mounts.
 *
 * runtime.php uses /wordpress as the fs-root (the VFS path inside
 * Playground), not the host filesystem path.
 *
 * SQLite is handled natively by Playground — the applier does NOT
 * generate the custom lazy-loader proxy that other runtimes use.
 *
 * The developer just runs: bash {output_dir}/start.sh
 */
class PlaygroundCliApplier implements RuntimeApplier
{
    /**
     * Playground's internal document root path in the virtual filesystem.
     */
    private const VFS_ROOT = '/wordpress';

    public function apply(RuntimeManifest $manifest, string $fs_root, string $output_dir, array $options = []): array
    {
        $port = (int) ($options['port'] ?? 9400);

        $summary = [];

        // Playground handles SQLite natively — suppress the custom
        // lazy-loader proxy that other runtimes (nginx-fpm, php-builtin)
        // need. Without this, our loader conflicts with Playground's own
        // SQLite integration and causes "Error connecting to the SQLite
        // database" during boot.
        $saved_sqlite = $manifest->sqlite;
        $manifest->sqlite = null;

        // 1. Write runtime.php using the VFS path (/wordpress) as fs-root.
        //    Inside Playground, the host's fs-root is mounted at /wordpress,
        //    so all resolved {fs-root} paths must reference /wordpress.
        $runtime_path = $output_dir . '/runtime.php';
        $runtime = generate_runtime_php($manifest, self::VFS_ROOT);

        // Suppress display of PHP warnings and deprecation notices.
        // Imported sites often have broken symlinks to host-specific
        // mu-plugins (e.g. WPCloud's wpcomsh-loader.php) and plugins
        // with deprecated syntax. These are expected in an imported
        // environment and shouldn't clutter the output.
        $runtime = str_replace(
            "<?php\n",
            "<?php\n@ini_set('display_errors', '0');\n",
            $runtime,
        );

        write_runtime_file($runtime_path, $runtime);
        $summary[] = "Wrote {$runtime_path}";

        // Restore sqlite on the manifest so the caller can still report it.
        $manifest->sqlite = $saved_sqlite;

        // 2. Write blueprint.json (minimal — most config is in start.sh flags)
        $blueprint_path = $output_dir . '/blueprint.json';
        $blueprint = $this->generate_blueprint();
        write_runtime_file($blueprint_path, json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $summary[] = "Wrote {$blueprint_path}";

        // 3. Write start.sh
        $start_path = $output_dir . '/start.sh';
        $start_script = $this->generate_start_script($manifest, $fs_root, $output_dir, $runtime_path, $port, $options);
        write_runtime_file($start_path, $start_script);
        chmod($start_path, 0755);
        $summary[] = "Wrote {$start_path}";

        $summary[] = '';
        $summary[] = 'Start the server:';
        $summary[] = "  bash {$start_path}";
        $summary[] = '';
        $summary[] = "Then open http://localhost:{$port} in your browser.";

        return $summary;
    }

    /**
     * Generate a minimal Playground Blueprint.
     *
     * Most configuration happens through CLI flags in start.sh rather
     * than Blueprint properties — the Blueprint just sets the landing
     * page and marks this as a schema-valid file.
     */
    private function generate_blueprint(): array
    {
        return [
            '$schema' => 'https://playground.wordpress.net/blueprint-schema.json',
            'landingPage' => '/',
        ];
    }

    /**
     * Build the list of --mount-before-install flags that assemble a
     * standard WordPress layout at /wordpress in Playground's VFS.
     *
     * For standard WordPress sites where the document root IS the
     * WordPress directory, a single mount covers everything.
     *
     * For WPCloud and similar hosts where WordPress core lives in a
     * separate directory, we mount the real WordPress core directory
     * at /wordpress, then overlay wp-content and wp-config.php from
     * the document root. Symlinks inside wp-content (themes, plugins,
     * mu-plugins) are resolved to their real host paths and mounted
     * individually — this replaces --follow-symlinks with explicit,
     * deterministic mounts.
     */
    private function build_mounts(string $fs_root, array $options): array
    {
        $mounts = [];

        $wordpress_index = $options['wordpress_index'] ?? '';
        $wordpress_core_dir = '';

        if ($wordpress_index !== '') {
            // Resolve through any symlinks to get the real path.
            $real_index = realpath($wordpress_index);
            if ($real_index !== false) {
                $wordpress_core_dir = dirname($real_index);
            }
        }

        $real_fs_root = realpath($fs_root) ?: $fs_root;

        // When the WordPress core directory differs from the document
        // root (e.g. WPCloud where ABSPATH != document_root), we need
        // multiple mounts to assemble a standard layout:
        // 1. WordPress core → /wordpress (gives us index.php, wp-load.php,
        //    wp-admin/, wp-includes/, wp-settings.php)
        // 2. Document root's wp-content → /wordpress/wp-content (the
        //    imported content, plugins, themes, uploads)
        // 3. Document root's wp-config.php → /wordpress/wp-config.php
        //    (the site's configuration)
        // 4. Any symlinks inside wp-content → individual mounts for
        //    each resolved target (themes, plugins, mu-plugins that
        //    point to shared host directories)
        if ($wordpress_core_dir !== '' && $wordpress_core_dir !== $real_fs_root) {
            $mounts[] = $wordpress_core_dir . ':/wordpress';

            $wp_content = $real_fs_root . '/wp-content';
            if (is_dir($wp_content)) {
                $mounts[] = $wp_content . ':/wordpress/wp-content';

                // Resolve symlinks inside wp-content so they work
                // without --follow-symlinks.
                $symlink_mounts = $this->resolve_wp_content_symlinks($wp_content);
                foreach ($symlink_mounts as $mount) {
                    $mounts[] = $mount;
                }
            }

            $wp_config = $real_fs_root . '/wp-config.php';
            if (file_exists($wp_config)) {
                $mounts[] = $wp_config . ':/wordpress/wp-config.php';
            }
        } else {
            // Standard layout: the document root IS the WordPress
            // directory. One mount covers everything.
            $mounts[] = $real_fs_root . ':/wordpress';
        }

        return $mounts;
    }

    /**
     * Scan wp-content subdirectories for symlinks and return mount
     * pairs that map each symlink's real host target to its VFS path.
     *
     * On WPCloud, themes/plugins/mu-plugins are symlinks to shared
     * directories (e.g. themes/iotix → ../../../wordpress/themes/pub/iotix).
     * Without --follow-symlinks these are broken in the VFS. This method
     * resolves each symlink to its real host path and creates an explicit
     * mount, making --follow-symlinks unnecessary.
     *
     * Only scans one level deep inside themes/, plugins/, and mu-plugins/
     * — WordPress doesn't support nested plugin/theme directories.
     */
    private function resolve_wp_content_symlinks(string $wp_content_path): array
    {
        $mounts = [];
        $subdirs = ['themes', 'plugins', 'mu-plugins'];

        foreach ($subdirs as $subdir) {
            $dir = $wp_content_path . '/' . $subdir;
            if (!is_dir($dir)) {
                continue;
            }
            $entries = @scandir($dir);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full_path = $dir . '/' . $entry;
                if (!is_link($full_path)) {
                    continue;
                }
                $real = realpath($full_path);
                if ($real === false) {
                    // Dangling symlink — target doesn't exist on the
                    // host. Skip it; WordPress will log a warning but
                    // continue.
                    continue;
                }
                $vfs_path = '/wordpress/wp-content/' . $subdir . '/' . $entry;
                $mounts[] = $real . ':' . $vfs_path;
            }
        }

        return $mounts;
    }

    /**
     * Generate a shell script that starts Playground CLI with the right
     * mount points and flags.
     */
    private function generate_start_script(
        RuntimeManifest $manifest,
        string $fs_root,
        string $output_dir,
        string $runtime_path,
        int $port,
        array $options
    ): string {
        $lines = [];
        $lines[] = '#!/usr/bin/env bash';
        $lines[] = '# Start WordPress Playground CLI for the imported site.';
        $lines[] = '# Generated by apply-runtime — do not edit.';
        $lines[] = '#';
        $lines[] = '# Source host: ' . $manifest->source;
        $lines[] = '';
        $lines[] = 'set -euo pipefail';
        $lines[] = '';

        $args = [];
        $args[] = 'npx @wp-playground/cli@latest server';

        // Mount the WordPress directory layout. For standard sites this
        // is a single mount. For WPCloud-style sites, we assemble the
        // layout from multiple real directories — no symlinks needed.
        foreach ($this->build_mounts($fs_root, $options) as $mount) {
            $args[] = '--mount-before-install=' . escapeshellarg($mount);
        }

        // Mount runtime.php as a mu-plugin. The 0- prefix ensures it loads
        // before other mu-plugins. mu-plugins don't need a Plugin Name
        // header and load on every request.
        $args[] = '--mount=' . escapeshellarg($runtime_path . ':/wordpress/wp-content/mu-plugins/0-playground-runtime.php');

        // The site is already installed — don't run Playground's WordPress
        // installer or download a fresh copy.
        $args[] = '--wordpress-install-mode=do-not-attempt-installing';

        // Disable symlink following. Our multi-mount approach resolves
        // all symlinks to explicit mounts, so Playground doesn't need
        // to resolve them (which would map files to /internal/symlinks/
        // paths and break relative path resolution).
        $args[] = '--follow-symlinks=false';

        $args[] = '--blueprint=' . escapeshellarg($output_dir . '/blueprint.json');
        $args[] = '--port=' . $port;

        $lines[] = 'echo "Starting WordPress Playground CLI..."';
        $lines[] = 'echo "  http://localhost:' . $port . '"';
        $lines[] = 'echo ""';
        $lines[] = '';
        $lines[] = implode(" \\\n    ", $args);
        $lines[] = '';

        return implode("\n", $lines);
    }
}
