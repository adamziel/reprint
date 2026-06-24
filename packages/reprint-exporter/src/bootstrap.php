<?php
/**
 * Runtime bootstrap for the exporter HTTP entrypoint.
 *
 * Composer normally loads classes and pure helper functions. This file covers
 * direct export.php execution and installs request-scoped streaming handlers.
 */

if (defined('REPRINT_EXPORTER_BOOTSTRAPPED')) {
    return;
}
define('REPRINT_EXPORTER_BOOTSTRAPPED', true);

$reprint_exporter_src_dir = __DIR__;

if (!class_exists('Composer\\Autoload\\ClassLoader', false)) {
    foreach ([
        $reprint_exporter_src_dir . '/../../../vendor/autoload.php',
        $reprint_exporter_src_dir . '/../../../autoload.php',
        $reprint_exporter_src_dir . '/../../vendor/autoload.php',
    ] as $reprint_exporter_autoloader) {
        if (file_exists($reprint_exporter_autoloader)) {
            require_once $reprint_exporter_autoloader;
            break;
        }
    }
    unset($reprint_exporter_autoloader);
}

$reprint_exporter_require_function_file = static function (string $function, string $file) use ($reprint_exporter_src_dir): void {
    if (!function_exists($function)) {
        require_once $reprint_exporter_src_dir . '/' . $file;
    }
};

$reprint_exporter_require_class_file = static function (string $class_name, string $file) use ($reprint_exporter_src_dir): void {
    if (!class_exists($class_name, false) && !interface_exists($class_name, false)) {
        require_once $reprint_exporter_src_dir . '/' . $file;
    }
};

if (!defined('REPRINT_EXPORTER_PROTOCOL_VERSION')) {
    require_once $reprint_exporter_src_dir . '/constants.php';
}

$reprint_exporter_require_function_file('Reprint\\Exporter\\json_encode_or_throw', 'utils.php');
require_once $reprint_exporter_src_dir . '/class-pdo-polyfill.php';
$reprint_exporter_require_function_file('Reprint\\Exporter\\require_int_range', 'validation.php');
$reprint_exporter_require_function_file('Reprint\\Exporter\\resolve_db_credentials', 'database.php');
$reprint_exporter_require_function_file('Reprint\\Exporter\\E2E\\load_test_hooks_if_needed', 'e2e-hooks.php');
$reprint_exporter_require_function_file('Reprint\\Exporter\\prepare_streaming_response', 'streaming.php');
$reprint_exporter_require_function_file('Reprint\\Exporter\\resolve_directories', 'filesystem.php');
$reprint_exporter_require_function_file('Reprint\\Exporter\\encode_index_stack', 'file-index.php');
$reprint_exporter_require_function_file('Reprint\\Exporter\\parse_http_config', 'http.php');

$reprint_exporter_require_class_file('Reprint\\Exporter\\GzipOutputStream', 'class-gzip-output-stream.php');
$reprint_exporter_require_class_file('Reprint\\Exporter\\ResourceBudget', 'class-resource-budget.php');
$reprint_exporter_require_class_file('Reprint\\Exporter\\MySQLDumpProducer', 'class-mysql-dump-producer.php');
$reprint_exporter_require_class_file('Reprint\\Exporter\\FileTreeProducer', 'class-file-tree-producer.php');
$reprint_exporter_require_class_file('Reprint\\Exporter\\SqliteDriverPDO', 'class-sqlite-driver-pdo.php');
$reprint_exporter_require_class_file('Reprint\\Exporter\\WpdbDriverPDO', 'class-wpdb-driver-pdo.php');
$reprint_exporter_require_class_file('Reprint\\Exporter\\Site_Export_HTTP_Server', 'class-http-server.php');

$reprint_exporter_require_class_file('Reprint\\Exporter\\Command\\ExportCommand', 'commands/class-export-command.php');
$reprint_exporter_require_class_file('Reprint\\Exporter\\Command\\BudgetedExportCommand', 'commands/class-budgeted-export-command.php');
$reprint_exporter_require_class_file('Reprint\\Exporter\\Command\\PreflightCommand', 'commands/class-preflight-command.php');
$reprint_exporter_require_class_file('Reprint\\Exporter\\Command\\FileIndexCommand', 'commands/class-file-index-command.php');
$reprint_exporter_require_class_file('Reprint\\Exporter\\Command\\FileFetchCommand', 'commands/class-file-fetch-command.php');
$reprint_exporter_require_class_file('Reprint\\Exporter\\Command\\SqlChunkCommand', 'commands/class-sql-chunk-command.php');
$reprint_exporter_require_class_file('Reprint\\Exporter\\Command\\DbIndexCommand', 'commands/class-db-index-command.php');
$reprint_exporter_require_class_file('Reprint\\Exporter\\Command\\ExportCommands', 'commands/class-export-commands.php');

if (!ob_get_level()) {
    ob_start();
}

\Reprint\Exporter\install_export_error_handlers();

unset(
    $reprint_exporter_require_class_file,
    $reprint_exporter_require_function_file,
    $reprint_exporter_src_dir
);
