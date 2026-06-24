<?php

namespace Reprint\Importer\TargetRuntime;

use InvalidArgumentException;
use Reprint\Importer\Host\RuntimeManifest;
use Reprint\Importer\Observability\AuditLogger;
use RuntimeException;
use function Reprint\Importer\Host\host_analyzer_for;
use function Reprint\Importer\SQLite\resolve_sqlite_integration_plugin_path;

final class RuntimeConfigurationApplier
{
    private AuditLogger $audit;

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    /**
     * Apply the target runtime configuration for an imported site.
     *
     * @param array $config Runtime application options.
     * @return array{
     *     runtime: string,
     *     webhost: string,
     *     webhost_source: string,
     *     target_engine: mixed,
     *     paths_removed: string[],
     *     extra_directories: string[],
     *     start_config: mixed,
     *     summary: string[]
     * }
     */
    public function apply(array $config): array
    {
        $runtime = $this->require_string(
            $config,
            'runtime',
            'apply-runtime requires --runtime=RUNTIME.',
        );
        $output_dir = $this->require_string(
            $config,
            'output_dir',
            'apply-runtime requires --output-dir=DIR to write runtime configuration files',
        );
        $fs_root = $this->require_string(
            $config,
            'fs_root',
            'apply-runtime requires an fs root.',
        );

        $preflight_data = $config['preflight_data'] ?? null;
        if (!is_array($preflight_data)) {
            throw new InvalidArgumentException(
                'apply-runtime requires preflight data.',
            );
        }

        $webhost = $config['webhost'] ?? 'other';
        if (!is_string($webhost) || $webhost === '') {
            $webhost = 'other';
        }

        $apply_state = $config['apply_state'] ?? [];
        if (!is_array($apply_state)) {
            $apply_state = [];
        }

        $flat_document_root = $config['flat_document_root'] ?? null;
        $effective_fs_root = $this->resolve_effective_fs_root(
            $fs_root,
            is_string($flat_document_root) ? $flat_document_root : null,
            $preflight_data,
        );

        $abs_output_dir = realpath($output_dir) ?: $output_dir;
        $abs_fs_root = realpath($effective_fs_root) ?: $effective_fs_root;

        if (!is_dir($abs_output_dir)) {
            if (!mkdir($abs_output_dir, 0755, true) && !is_dir($abs_output_dir)) {
                throw new RuntimeException(
                    "Failed to create output directory: {$abs_output_dir}",
                );
            }
            $resolved_output_dir = realpath($abs_output_dir);
            if ($resolved_output_dir !== false) {
                $abs_output_dir = $resolved_output_dir;
            }
        }

        $analyzer = host_analyzer_for($webhost);
        $manifest = $analyzer->analyze($preflight_data);
        $this->maybe_enable_remote_upload_proxy($manifest, $preflight_data, $config);

        $target_engine = $this->merge_target_database_config($manifest, $apply_state);

        $this->audit("APPLY-RUNTIME | analyzed preflight (source={$manifest->source}, webhost={$webhost})");

        [$host, $port] = $this->resolve_target_host_and_port($config, $apply_state);
        $wordpress_index = $this->resolve_wordpress_index(
            $preflight_data,
            is_string($flat_document_root) ? $flat_document_root : null,
            $abs_fs_root,
            $fs_root,
        );

        $applier = runtime_applier_for($runtime);
        $applier_options = [];
        if ($wordpress_index !== '') {
            $applier_options['wordpress_index'] = $wordpress_index;
        }
        if ($host !== null) {
            $applier_options['host'] = $host;
        }
        if ($port !== null) {
            $applier_options['port'] = (int) $port;
        }

        if ($manifest->sqlite !== null) {
            $copied_plugin = copy_sqlite_plugin(
                $manifest->sqlite['plugin_source'],
                $abs_output_dir,
            );
            $manifest->sqlite['plugin_dir'] = $copied_plugin;
            $manifest->sqlite['db_dir'] = resolve_runtime_placeholders(
                $manifest->sqlite['db_dir'],
                $abs_fs_root,
            );
        }

        $summary = $applier->apply($manifest, $abs_fs_root, $abs_output_dir, $applier_options);

        if ($manifest->sqlite !== null) {
            $summary[] = "Copied sqlite-database-integration to {$abs_output_dir}/sqlite-database-integration";
        }

        $summary = array_merge(
            $summary,
            $this->remove_production_paths($manifest->paths_to_remove, $abs_fs_root),
        );

        foreach ($summary as $line) {
            $this->audit("APPLY-RUNTIME | {$line}");
        }

        $start_config = null;
        $start_config_path = $abs_output_dir . '/start.json';
        if (file_exists($start_config_path)) {
            $start_config = json_decode(file_get_contents($start_config_path), true);
        }

        return [
            'runtime' => $runtime,
            'webhost' => $webhost,
            'webhost_source' => $manifest->source,
            'target_engine' => $target_engine,
            'paths_removed' => $manifest->paths_to_remove,
            'extra_directories' => $manifest->extra_directories,
            'start_config' => $start_config,
            'summary' => $summary,
        ];
    }

    private function require_string(array $config, string $key, string $message): string
    {
        $value = $config[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private function resolve_effective_fs_root(
        string $fs_root,
        ?string $flat_document_root,
        array $preflight_data
    ): string {
        if (!empty($flat_document_root)) {
            return rtrim($flat_document_root, '/');
        }

        $remote_doc_root = $preflight_data['runtime']['document_root'] ?? '';
        if (is_string($remote_doc_root)) {
            $remote_doc_root = rtrim($remote_doc_root, '/');
        } else {
            $remote_doc_root = '';
        }

        if ($remote_doc_root !== '') {
            $effective_fs_root = $fs_root . $remote_doc_root;
        } else {
            $effective_fs_root = $fs_root;
        }

        if (!is_dir($effective_fs_root)) {
            throw new RuntimeException(
                "Effective fs root does not exist: {$effective_fs_root}\n" .
                "The remote document_root was: {$remote_doc_root}\n" .
                "If you used flat-docroot, pass the flattened directory " .
                "with --flat-document-root instead of --fs-root.",
            );
        }

        return $effective_fs_root;
    }

    private function merge_target_database_config(RuntimeManifest $manifest, array $apply_state)
    {
        $target_engine = $apply_state['target_engine'] ?? null;
        if ($target_engine === 'mysql') {
            $manifest->constants['DB_NAME'] = $apply_state['target_db'] ?? '';
            $manifest->constants['DB_USER'] = $apply_state['target_user'] ?? '';
            $manifest->constants['DB_PASSWORD'] = $apply_state['target_pass'] ?? '';
            $host_value = $apply_state['target_host'] ?? '127.0.0.1';
            $port_value = (int) ($apply_state['target_port'] ?? 3306);
            if ($port_value !== 3306) {
                $host_value .= ':' . $port_value;
            }
            $manifest->constants['DB_HOST'] = $host_value;
            $manifest->has_db_constants = true;
        } elseif ($target_engine === 'sqlite') {
            $db_name = $apply_state['target_db'] ?? 'sqlite_database';
            if (!is_string($db_name) || $db_name === '') {
                $db_name = 'sqlite_database';
            }
            $sqlite_path = $apply_state['target_sqlite_path'] ?? null;
            if ($sqlite_path !== null && $sqlite_path !== '') {
                $db_dir = rtrim(dirname($sqlite_path), '/') . '/';
                $db_file = basename($sqlite_path);
            } else {
                $db_dir = '{fs-root}/wp-content/database/';
                $db_file = '.ht.sqlite';
            }
            $manifest->constants['DB_NAME'] = $db_name;
            $manifest->has_db_constants = true;
            $manifest->sqlite = [
                'plugin_source' => resolve_sqlite_integration_plugin_path(),
                'plugin_dir' => '',
                'db_dir' => $db_dir,
                'db_file' => $db_file,
            ];
        }

        return $target_engine;
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    private function resolve_target_host_and_port(array $config, array $apply_state): array
    {
        $host = $config['host'] ?? null;
        $port = $config['port'] ?? null;
        if ($host === null || $port === null) {
            $rewrite_map = $apply_state['rewrite_url'] ?? [];
            $first_target = !empty($rewrite_map) ? reset($rewrite_map) : null;
            if (is_string($first_target)) {
                $parsed = parse_url($first_target);
                if (is_array($parsed)) {
                    if ($host === null) {
                        $host = $parsed['host'] ?? null;
                    }
                    if ($port === null && isset($parsed['port'])) {
                        $port = $parsed['port'];
                    }
                }
            }
        }

        return [$host, $port];
    }

    private function resolve_wordpress_index(
        array $preflight_data,
        ?string $flat_document_root,
        string $abs_fs_root,
        string $fs_root
    ): string {
        $paths_urls = $preflight_data['database']['wp']['paths_urls'] ?? [];
        $abspath = rtrim($paths_urls['abspath'] ?? '', '/');
        if (!empty($flat_document_root)) {
            return $abs_fs_root . '/index.php';
        }

        if ($abspath !== '') {
            return realpath($fs_root . $abspath . '/index.php') ?: '';
        }

        return $abs_fs_root . '/index.php';
    }

    /**
     * @return string[]
     */
    private function remove_production_paths(array $paths_to_remove, string $abs_fs_root): array
    {
        $summary = [];
        foreach ($paths_to_remove as $rel_path) {
            $full_path = $abs_fs_root . '/' . ltrim($rel_path, '/');
            if (!file_exists($full_path) && !is_link($full_path)) {
                continue;
            }

            $this->remove_path_without_following_symlinks($full_path);
            $summary[] = "Removed production drop-in: {$rel_path}";
            $this->audit("APPLY-RUNTIME | removed {$rel_path} (production-only)");
        }

        return $summary;
    }

    private function remove_path_without_following_symlinks(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (is_dir($path) && !is_link($path)) {
            $entries = scandir($path);
            if ($entries === false) {
                return;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->remove_path_without_following_symlinks($path . '/' . $entry);
            }
            @rmdir($path);
            return;
        }

        unlink($path);
    }

    private function maybe_enable_remote_upload_proxy(
        RuntimeManifest $manifest,
        array $preflight_data,
        array $config
    ): void {
        if (empty($config['enable_remote_upload_proxy'])) {
            return;
        }

        $base_url = $this->remote_upload_proxy_base_url($preflight_data);
        if ($base_url === null) {
            $this->audit(
                'APPLY-RUNTIME | remote upload proxy skipped (no source uploads URL available)',
                true,
            );
            return;
        }

        $state_dir = $config['state_dir'] ?? '';
        if (!is_string($state_dir) || $state_dir === '') {
            throw new InvalidArgumentException(
                'apply-runtime requires a state dir when remote upload proxy is enabled.',
            );
        }

        $manifest->constants['STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_BASEURL'] = $base_url;
        $resolved_state_dir = realpath($state_dir) ?: $state_dir;
        $manifest->constants['STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_STATE_FILE'] =
            rtrim($resolved_state_dir, '/') . '/.reprint/run.json';
        $manifest->constants['STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_SKIPPED_FILE'] =
            rtrim($resolved_state_dir, '/') . '/.import-download-list-skipped.jsonl';
        $manifest->routes[] = [
            'handler' => 'remote-upload-proxy',
            'path_pattern' => '/wp-content/uploads/.*',
            'condition' => 'file_not_found',
            'description' => 'Proxy missing uploads from the source site until files-pull completes',
        ];
        $this->audit(
            "APPLY-RUNTIME | enabled remote upload proxy ({$base_url})",
            true,
        );
    }

    private function audit(string $message, bool $to_console = true): void
    {
        $this->audit->record($message, $to_console);
    }

    private function remote_upload_proxy_base_url(array $preflight_data): ?string
    {
        $paths_urls = $preflight_data['database']['wp']['paths_urls'] ?? [];
        $uploads_baseurl = $paths_urls['uploads']['baseurl'] ?? null;
        if (is_string($uploads_baseurl) && $uploads_baseurl !== '') {
            return rtrim($uploads_baseurl, '/');
        }

        $site_urls = [
            $paths_urls['home_url'] ?? null,
            $paths_urls['site_url'] ?? null,
            $preflight_data['database']['wp']['home'] ?? null,
            $preflight_data['database']['wp']['siteurl'] ?? null,
        ];
        foreach ($site_urls as $site_url) {
            if (is_string($site_url) && $site_url !== '') {
                return rtrim($site_url, '/') . '/wp-content/uploads';
            }
        }

        return null;
    }
}
