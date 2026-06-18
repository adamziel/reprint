#!/usr/bin/env php
<?php
/**
 * Import client for export.php.
 *
 * Downloads SQL and files from a remote export.php script, with support for:
 * - Resumable downloads using cursors
 * - Streaming multipart parsing (no buffering)
 * - Progress reporting via JSON lines to stdout
 * - Three-phase import: files, SQL, then file deltas
 */

error_reporting(E_ALL);
ini_set("display_errors", "stderr");
ini_set("display_startup_errors", 1);

// Load composer autoloader for wp-php-toolkit dependencies
foreach ([
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
    __DIR__ . '/../vendor/autoload.php',
] as $autoloader) {
    if (file_exists($autoloader)) {
        require_once $autoloader;
        break;
    }
}

// Load vendored MySQL query stream (from sqlite-database-integration PR #264)
require_once __DIR__ . '/lib/mysql-query-stream/load.php';

// Load WordPress function stubs (needed by wp-php-toolkit outside WordPress)
require_once __DIR__ . '/lib/wp-stubs.php';

// Load URL rewriting components
require_once __DIR__ . '/lib/url-rewrite/load.php';

// Load host analyzers (produce a runtime manifest from preflight data)
require_once __DIR__ . '/lib/host/load.php';

// Load target runtime appliers (consume a manifest, write server config)
require_once __DIR__ . '/lib/target-runtime/load.php';

// External merge sort for large index files when exec() is unavailable
require_once __DIR__ . '/lib/class-external-merge-sort.php';

// Terminal progress rendering (spinner, progress lines, lifecycle messages)
require_once __DIR__ . '/lib/terminal-progress/class-terminal-progress.php';

// Small reusable helpers with no ImportClient dependency.
require_once __DIR__ . '/lib/support/load.php';
require_once __DIR__ . '/lib/filesystem/load.php';
require_once __DIR__ . '/lib/index/load.php';
require_once __DIR__ . '/lib/file-sync/load.php';
require_once __DIR__ . '/lib/sql/load.php';

// Session paths and state persistence helpers.
require_once __DIR__ . '/lib/session/load.php';

// Stateless command objects used by the importer CLI and pull pipeline.
require_once __DIR__ . '/lib/commands/load.php';
require_once __DIR__ . '/lib/pull/commands/load.php';

// Input and output adapters for CLI, web, and embedded consumers.
require_once __DIR__ . '/lib/input/load.php';
require_once __DIR__ . '/lib/output/load.php';

// Pull command — orchestrates the lower-level commands into a pipeline
require_once __DIR__ . '/lib/pull/class-pull.php';

// Load reusable importer modules.
require_once __DIR__ . '/lib/protocol/load.php';
require_once __DIR__ . '/lib/transport/load.php';
require_once __DIR__ . '/lib/sqlite/functions.php';
require_once __DIR__ . '/lib/tuning/class-adaptive-tuner.php';
require_once __DIR__ . '/lib/version.php';

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    $fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
    if (!($error['type'] & $fatal_types)) {
        return;
    }
    $json = json_encode([
        "error" => "Fatal: {$error['message']}",
        "file" => $error['file'],
        "line" => $error['line'],
        "type" => $error['type'],
    ]);
    if ($json === false) {
        $json = '{"error":"Fatal PHP error","file":"' . addslashes($error['file']) . '"}';
    }
    fwrite(STDERR, $json . "\n");
});

// Import client implementation.
require_once __DIR__ . '/class-import-client.php';

// Load CLI entry point last so reusable classes can be included without running CLI code.
if (!defined('REPRINT_IMPORTER_SOURCE_ENTRY')) {
    define('REPRINT_IMPORTER_SOURCE_ENTRY', __FILE__);
}
require_once __DIR__ . '/lib/cli/class-cli-command-result-renderer.php';
require_once __DIR__ . '/lib/cli/entrypoint.php';
