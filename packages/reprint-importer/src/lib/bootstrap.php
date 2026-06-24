<?php
/**
 * Runtime bootstrap for the importer library.
 *
 * Composer owns importer class/function loading in normal installs. This file
 * makes sure the Composer autoloader is available when src/import.php is
 * executed directly, loads helper files for PHAR/package-path execution, and
 * registers fallback autoload hooks for paths Composer does not know about in
 * PHAR builds.
 */

if (defined('REPRINT_IMPORTER_BOOTSTRAPPED')) {
    return;
}
define('REPRINT_IMPORTER_BOOTSTRAPPED', true);

$reprint_importer_lib_dir = __DIR__;
$reprint_importer_exporter_src_dir = null;

foreach ([
    // Monorepo checkout and PHAR layout: packages/reprint-importer/src/lib -> packages/reprint-exporter/src.
    $reprint_importer_lib_dir . '/../../../reprint-exporter/src',
    // Root install layout: packages/reprint-importer/src/lib -> vendor/wp-php-toolkit/reprint-exporter/src.
    $reprint_importer_lib_dir . '/../../../../vendor/wp-php-toolkit/reprint-exporter/src',
] as $reprint_importer_exporter_src_candidate) {
    if (is_dir($reprint_importer_exporter_src_candidate)) {
        $reprint_importer_exporter_src_dir = $reprint_importer_exporter_src_candidate;
        break;
    }
}
unset($reprint_importer_exporter_src_candidate);

$reprint_importer_kebab_case = static function (string $name): string {
    $special = [
        'SQLitePreparedInsertBuilder' => 'sqlite-prepared-insert-builder',
        'WP_MySQL_FastQueryStream' => 'wp-mysql-fast-query-stream',
        'WP_MySQL_Naive_Query_Stream' => 'wp-mysql-naive-query-stream',
    ];
    if (isset($special[$name])) {
        return $special[$name];
    }

    $name = str_replace('_', '-', $name);
    $name = preg_replace('/(?<=[A-Z])(?=[A-Z][a-z])/', '-', $name);
    $name = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $name);
    return strtolower($name);
};

$reprint_importer_namespace_dir = static function (string $segment) use ($reprint_importer_kebab_case): string {
    $special = [
        'FileSync' => 'file-sync',
        'TargetRuntime' => 'target-runtime',
        'UrlRewrite' => 'url-rewrite',
        'UseCase' => 'use-case',
        'QueryStream' => 'mysql-query-stream',
        'Command' => 'commands',
    ];

    return $special[$segment] ?? $reprint_importer_kebab_case($segment);
};

$reprint_importer_class_file = static function (string $class_name) use (
    $reprint_importer_lib_dir,
    $reprint_importer_kebab_case,
    $reprint_importer_namespace_dir
): ?string {
    $prefix = 'Reprint\\Importer\\';
    if (strpos($class_name, $prefix) !== 0) {
        return null;
    }

    $relative = substr($class_name, strlen($prefix));
    $parts = explode('\\', $relative);
    $short_name = array_pop($parts);
    if ($short_name === null || $short_name === '') {
        return null;
    }

    $path_parts = array_map($reprint_importer_namespace_dir, $parts);
    $base_path = $reprint_importer_lib_dir;
    if ($path_parts !== []) {
        $base_path .= '/' . implode('/', $path_parts);
    }

    $file_base = $reprint_importer_kebab_case($short_name);
    foreach (['class', 'interface', 'trait'] as $type) {
        $path = "{$base_path}/{$type}-{$file_base}.php";
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
};

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

if (!class_exists('Composer\\Autoload\\ClassLoader', false)) {
    throw new RuntimeException(
        'Composer autoloader not found. Run composer install before running the importer.'
    );
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

$reprint_importer_require_exporter_file = static function (
    string $symbol_name,
    string $file
) use ($reprint_importer_exporter_src_dir): void {
    if ($reprint_importer_exporter_src_dir === null) {
        return;
    }

    if (function_exists($symbol_name) || class_exists($symbol_name, false)) {
        return;
    }

    $path = $reprint_importer_exporter_src_dir . '/' . $file;
    if (is_file($path)) {
        require_once $path;
    }
};

spl_autoload_register(
    static function (string $class_name) use (
        $reprint_importer_class_file,
        $reprint_importer_load_mysql_parser
    ): void {
        $file = $reprint_importer_class_file($class_name);
        if ($file !== null) {
            require_once $file;
            return;
        }

        if (
            strpos($class_name, 'WP_MySQL_') === 0 ||
            strpos($class_name, 'WP_Parser') === 0 ||
            strpos($class_name, 'WP_Grammar') === 0
        ) {
            $reprint_importer_load_mysql_parser();
        }
    },
    true,
    false
);

$reprint_importer_require_exporter_file('Reprint\\Exporter\\parse_size', 'utils.php');
$reprint_importer_require_exporter_file(
    'Reprint\\Exporter\\Site_Export_HMAC_Client',
    'class-hmac-client.php'
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

unset(
    $reprint_importer_class_file,
    $reprint_importer_exporter_src_dir,
    $reprint_importer_kebab_case,
    $reprint_importer_lib_dir,
    $reprint_importer_load_mysql_parser,
    $reprint_importer_namespace_dir,
    $reprint_importer_require_exporter_file
);
