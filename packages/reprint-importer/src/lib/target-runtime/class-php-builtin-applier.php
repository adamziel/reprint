<?php

namespace Reprint\Importer\TargetRuntime;

use Reprint\Importer\Host\RuntimeManifest;

/**
 * Runtime applier for PHP's built-in development server.
 *
 * Writes:
 * 1. {output_dir}/runtime.php — constants, server vars, route handlers,
 *                                and CLI-server routing logic
 * 2. {output_dir}/start.sh    — shell script with the exact php -S command
 *
 * runtime.php is the router script passed to php -S. It defines constants,
 * runs route handlers, then handles request routing. PHP files are served
 * via require (not return false) so they execute in the same scope where
 * constants are already defined — no auto_prepend_file needed.
 *
 * The developer just runs: bash {output_dir}/start.sh
 */
class PhpBuiltinApplier implements RuntimeApplier
{
    public function apply(RuntimeManifest $manifest, string $fs_root, string $output_dir, array $options = []): array
    {
        $host = $options['host'] ?? 'localhost';
        $port = (int) ($options['port'] ?? 8881);

        $summary = [];

        // 1. Write runtime.php (base layers + CLI-server routing)
        $runtime_path = $output_dir . '/runtime.php';
        $runtime = generate_runtime_php($manifest, $fs_root);
        $runtime .= $this->generate_cli_server_routing($options);
        write_runtime_file($runtime_path, $runtime);
        $summary[] = "Wrote {$runtime_path}";

        // 2. Write start.sh
        $start_path = $output_dir . '/start.sh';
        $start_script = $this->generate_start_script($manifest, $fs_root, $runtime_path, $host, $port);
        write_runtime_file($start_path, $start_script);
        chmod($start_path, 0755);
        $summary[] = "Wrote {$start_path}";

        $summary[] = '';
        $summary[] = 'Start the server:';
        $summary[] = "  bash {$start_path}";
        $summary[] = '';
        $summary[] = "Then open http://{$host}:{$port} in your browser.";

        return $summary;
    }

    /**
     * Generate the CLI-server routing block that handles static files,
     * PHP file execution with PATH_INFO support, and WordPress
     * pretty-permalink fallback.
     *
     * This code only runs under php -S (guarded by php_sapi_name check).
     * It's appended to runtime.php after the base layers.
     */
    private function generate_cli_server_routing(array $options): string
    {
        $wp_index = $options['wordpress_index'] ?? '';
        $escaped_wp_index = addslashes($wp_index);

        $lines = [];
        $lines[] = '// CLI-server routing (php -S only).';
        $lines[] = '// Handles static files, PHP execution, and WordPress pretty permalinks.';
        $lines[] = '$path = parse_url($_SERVER[\'REQUEST_URI\'] ?? \'/\', PHP_URL_PATH);';
        $lines[] = '$document_root = rtrim($_SERVER[\'DOCUMENT_ROOT\'] ?? \'\', \'/\');';
        $lines[] = '$wp_dir = rtrim($_SERVER[\'WP_DIR\'] ?? \'\', \'/\');';
        $lines[] = '$resolve_runtime_path = function ($request_path) use ($document_root, $wp_dir) {';
        $lines[] = '    $candidates = [];';
        $lines[] = '    if ($document_root !== \'\') {';
        $lines[] = '        $candidates[] = $document_root . $request_path;';
        $lines[] = '    }';
        $lines[] = '    if ($wp_dir !== \'\') {';
        $lines[] = '        $candidates[] = $wp_dir . $request_path;';
        $lines[] = '    }';
        $lines[] = '    foreach ($candidates as $candidate) {';
        $lines[] = '        if (file_exists($candidate)) {';
        $lines[] = '            return $candidate;';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '    return $document_root . $request_path;';
        $lines[] = '};';
        $lines[] = '$serve_runtime_static_file = function ($file) {';
        $lines[] = '    $content_type = function_exists(\'mime_content_type\') ? mime_content_type($file) : false;';
        $lines[] = '    if ($content_type) {';
        $lines[] = '        header(\'Content-Type: \' . $content_type);';
        $lines[] = '    }';
        $lines[] = '    header(\'Content-Length: \' . filesize($file));';
        $lines[] = '    readfile($file);';
        $lines[] = '};';
        $lines[] = '';
        $lines[] = '// Support PATH_INFO URLs like /file.php/extra/path.';
        $lines[] = '// Walk the path segments to find the first .php file that exists,';
        $lines[] = '// then split into SCRIPT_NAME and PATH_INFO.';
        $lines[] = '$script_file = null;';
        $lines[] = '$path_info = \'\';';
        $lines[] = 'if (preg_match(\'#\\.php(?=/|$)#\', $path)) {';
        $lines[] = '    $segments = explode(\'/\', $path);';
        $lines[] = '    $check = \'\';';
        $lines[] = '    foreach ($segments as $i => $seg) {';
        $lines[] = '        $check .= ($i > 0 ? \'/\' : \'\') . $seg;';
        $lines[] = '        $candidate = $resolve_runtime_path($check);';
        $lines[] = '        if (is_file($candidate) && preg_match(\'/\\.php$/\', $check)) {';
        $lines[] = '            $script_file = $candidate;';
        $lines[] = '            $path_info = substr($path, strlen($check));';
        $lines[] = '            $_SERVER[\'SCRIPT_NAME\'] = $check;';
        $lines[] = '            $_SERVER[\'SCRIPT_FILENAME\'] = $candidate;';
        $lines[] = '            $_SERVER[\'PATH_INFO\'] = $path_info ?: null;';
        $lines[] = '            break;';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'if ($script_file) {';
        $lines[] = '    require $script_file;';
        $lines[] = '    return;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '$file = $resolve_runtime_path($path);';
        $lines[] = '';
        $lines[] = '// Existing non-PHP files: let php -S serve document-root files.';
        $lines[] = '// Core assets outside the document root are streamed by the router.';
        $lines[] = 'if ($path !== \'/\' && file_exists($file) && is_file($file)) {';
        $lines[] = '    if ($document_root !== \'\' && strpos($file, $document_root . \'/\') === 0) {';
        $lines[] = '        return false;';
        $lines[] = '    }';
        $lines[] = '    $serve_runtime_static_file($file);';
        $lines[] = '    return;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '// If the request resolved to a core symlink in the document root,';
        $lines[] = '// returning false lets php -S handle byte serving.';
        $lines[] = '$document_file = $document_root . $path;';
        $lines[] = 'if ($path !== \'/\' && file_exists($document_file) && is_file($document_file)) {';
        $lines[] = '    return false;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '// Directory requests: look for index.php or index.html.';
        $lines[] = 'if (is_dir($file)) {';
        $lines[] = '    if (file_exists($file . \'/index.php\')) {';
        $lines[] = '        $_SERVER[\'SCRIPT_NAME\'] = rtrim($path, \'/\') . \'/index.php\';';
        $lines[] = '        $_SERVER[\'SCRIPT_FILENAME\'] = $file . \'/index.php\';';
        $lines[] = '        require $file . \'/index.php\';';
        $lines[] = '        return;';
        $lines[] = '    }';
        $lines[] = '    if (file_exists($file . \'/index.html\') && $document_root !== \'\' && strpos($file, $document_root . \'/\') === 0) {';
        $lines[] = '        return false;';
        $lines[] = '    }';
        $lines[] = '    if (file_exists($file . \'/index.html\')) {';
        $lines[] = '        $serve_runtime_static_file($file . \'/index.html\');';
        $lines[] = '        return;';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '// Everything else: WordPress pretty permalinks via index.php.';
        $lines[] = '// WordPress index.php may live outside the document root (e.g.';
        $lines[] = '// WPCloud where ABSPATH differs from the document root).';
        $lines[] = '$_SERVER[\'SCRIPT_NAME\'] = \'/index.php\';';
        $lines[] = "require '{$escaped_wp_index}';";
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Generate a shell script that starts the built-in server.
     * runtime.php is the router — all PHP files are require'd through it
     * so they share the same scope with constants already defined.
     */
    private function generate_start_script(
        RuntimeManifest $manifest,
        string $fs_root,
        string $runtime_path,
        string $host,
        int $port
    ): string {
        $lines = [];
        $lines[] = '#!/usr/bin/env bash';
        $lines[] = '# Start the PHP built-in server for the imported site.';
        $lines[] = '# Generated by apply-runtime — do not edit.';
        $lines[] = '#';
        $lines[] = '# Source host: ' . $manifest->source;
        $lines[] = '';
        $lines[] = 'set -euo pipefail';
        $lines[] = '';

        $php_args = [];
        $php_args[] = 'php';

        foreach ($manifest->php_ini as $key => $value) {
            $php_args[] = "-d {$key}={$value}";
        }

        $php_args[] = '-S ' . $host . ':' . $port;
        $php_args[] = '-t ' . escapeshellarg($fs_root);
        $php_args[] = escapeshellarg($runtime_path);

        $lines[] = 'echo "Starting PHP built-in server..."';
        $lines[] = 'echo "  http://' . $host . ':' . $port . '"';
        $lines[] = 'echo ""';
        $lines[] = '';
        $lines[] = implode(" \\\n    ", $php_args);
        $lines[] = '';

        return implode("\n", $lines);
    }
}
