<?php
/**
 * Runtime bootstrap for the importer library.
 *
 * Composer classmaps package classes when installed, but src/import.php can
 * also be executed directly from a checkout. Register a local classmap-style
 * autoloader with prepend=true so the checkout's classes win over any stale
 * vendor copy of this package.
 */

$reprint_importer_classmap = static function (string $lib_dir): array {
    static $maps = [];

    $key = realpath($lib_dir) ?: $lib_dir;
    if (isset($maps[$key])) {
        return $maps[$key];
    }

    $map = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($lib_dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $basename = $file->getBasename();
        if (
            $basename === 'load.php' ||
            $basename === 'bootstrap.php' ||
            $basename === 'functions.php' ||
            $basename === 'constants.php' ||
            $basename === 'curl-options.php' ||
            $basename === 'wp-stubs.php' ||
            $basename === 'version.php' ||
            strpos($path, DIRECTORY_SEPARATOR . 'route-handlers' . DIRECTORY_SEPARATOR) !== false
        ) {
            continue;
        }

        $source = file_get_contents($path);
        if (!is_string($source)) {
            continue;
        }

        $namespace = '';
        if (preg_match('/^\s*namespace\s+([^;]+);/m', $source, $namespace_match)) {
            $namespace = trim($namespace_match[1]);
        }

        if (!preg_match_all('/^\s*(?:abstract\s+|final\s+)?(?:class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $source, $matches)) {
            continue;
        }

        foreach ($matches[1] as $short_name) {
            $class = $namespace === '' ? $short_name : $namespace . '\\' . $short_name;
            $map[$class] = $path;
        }
    }

    $maps[$key] = $map;
    return $map;
};

$reprint_importer_lib_dir = __DIR__;

if (!class_exists('Composer\\Autoload\\ClassLoader', false)) {
    foreach ([
        $reprint_importer_lib_dir . '/../../../../vendor/autoload.php',
        $reprint_importer_lib_dir . '/../../../../autoload.php',
        $reprint_importer_lib_dir . '/../../vendor/autoload.php',
    ] as $reprint_importer_autoloader) {
        if (file_exists($reprint_importer_autoloader)) {
            require_once $reprint_importer_autoloader;
            break;
        }
    }
    unset($reprint_importer_autoloader);
}

$reprint_importer_load_mysql_parser = static function () use ($reprint_importer_lib_dir): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }

    if (!function_exists('Reprint\\Importer\\SQLite\\resolve_sqlite_integration_path')) {
        require_once $reprint_importer_lib_dir . '/sqlite/functions.php';
    }

    $loader = \Reprint\Importer\SQLite\resolve_sqlite_integration_path('/wp-pdo-mysql-on-sqlite.php');
    require_once $loader;
    $loaded = true;
};

spl_autoload_register(
    static function (string $class) use (
        $reprint_importer_classmap,
        $reprint_importer_lib_dir,
        $reprint_importer_load_mysql_parser
    ): void {
        if (strpos($class, 'Reprint\\Importer\\') === 0) {
            $map = $reprint_importer_classmap($reprint_importer_lib_dir);
            if (isset($map[$class])) {
                require_once $map[$class];
            }
            return;
        }

        if (
            strpos($class, 'WP_MySQL_') === 0 ||
            strpos($class, 'WP_Parser') === 0 ||
            strpos($class, 'WP_Grammar') === 0
        ) {
            $reprint_importer_load_mysql_parser();
        }
    },
    true,
    true
);

if (!function_exists('Reprint\\Importer\\SQLite\\resolve_sqlite_integration_path')) {
    require_once $reprint_importer_lib_dir . '/sqlite/functions.php';
}

require_once $reprint_importer_lib_dir . '/wp-stubs.php';
require_once $reprint_importer_lib_dir . '/protocol/constants.php';

if (!function_exists('Reprint\\Importer\\Transport\\reprint_apply_curl_proxy_from_env')) {
    require_once $reprint_importer_lib_dir . '/transport/curl-options.php';
}
if (!function_exists('Reprint\\Importer\\Host\\detect_host')) {
    require_once $reprint_importer_lib_dir . '/host/functions.php';
}
if (!function_exists('Reprint\\Importer\\TargetRuntime\\wpcloud_thumbnail_generator_code')) {
    require_once $reprint_importer_lib_dir . '/target-runtime/route-handlers/wpcloud-thumbnail-generator.php';
}
if (!function_exists('Reprint\\Importer\\TargetRuntime\\remote_upload_proxy_code')) {
    require_once $reprint_importer_lib_dir . '/target-runtime/route-handlers/remote-upload-proxy.php';
}
if (!defined('Reprint\\Importer\\TargetRuntime\\VALID_TARGET_RUNTIMES')) {
    require_once $reprint_importer_lib_dir . '/target-runtime/functions.php';
}
if (!function_exists('Reprint\\Importer\\get_importer_version')) {
    require_once $reprint_importer_lib_dir . '/version.php';
}

unset($reprint_importer_classmap, $reprint_importer_lib_dir, $reprint_importer_load_mysql_parser);
