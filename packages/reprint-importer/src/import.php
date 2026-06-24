#!/usr/bin/env php
<?php
/**
 * Importer for export.php.
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

// Load Composer autoloading, runtime helper functions, and importer bootstrap hooks.
require_once __DIR__ . '/lib/bootstrap.php';

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

// Load CLI entry point last so reusable classes can be included without running CLI code.
if (!defined('REPRINT_IMPORTER_SOURCE_ENTRY')) {
    define('REPRINT_IMPORTER_SOURCE_ENTRY', __FILE__);
}
require_once __DIR__ . '/lib/cli/entrypoint.php';
