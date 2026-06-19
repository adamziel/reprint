<?php
/**
 * Unified export API for SQL and file operations.
 */

use Reprint\Exporter\FileTreeProducer;
use Reprint\Exporter\GzipOutputStream;
use Reprint\Exporter\MySQLDumpProducer;
use Reprint\Exporter\ResourceBudget;
use Reprint\Exporter\Command\ExportCommands;
use Reprint\Exporter\Site_Export_HTTP_Server;
use Reprint\Exporter\SqliteDriverPDO;
use Reprint\Exporter\WpdbDriverPDO;
use function Reprint\Exporter\assert_valid_path;
use function Reprint\Exporter\build_pdo_dsn;
use function Reprint\Exporter\json_encode_or_throw;
use function Reprint\Exporter\parse_size;

// Capture any accidental output before headers are set so we can discard it
// when switching to streaming mode later.
if (!ob_get_level()) {
    ob_start();
}


/**
 * The wire-protocol version this export plugin speaks.
 *
 * Both the export plugin (server) and the importer (client) are deployed
 * independently.  These two constants let them detect incompatibility at
 * preflight time instead of producing silent corruption.
 *
 * REPRINT_EXPORTER_PROTOCOL_VERSION is sent to the importer in the preflight JSON
 * response as `protocol_version`.  Bump it whenever a change to the wire
 * protocol (cursor encoding, multipart structure, header names, endpoint
 * parameters, response format) would break an older importer.
 */
define('REPRINT_EXPORTER_PROTOCOL_VERSION', 1);

/**
 * The oldest *importer* protocol version this export plugin can talk to.
 *
 * Sent to the importer in the preflight response as `protocol_min_version`.
 * The importer checks that its own REPRINT_IMPORTER_PROTOCOL_VERSION is >= this value;
 * if not, it tells the user to update the importer.
 *
 * Raise this when you drop backward-compatibility with old importers.
 * Keep it equal to REPRINT_EXPORTER_PROTOCOL_VERSION if no backward compat is needed.
 */
define('REPRINT_EXPORTER_MIN_IMPORT_VERSION', 1);

// File type mask + file type values (top bits of st_mode)
define('REPRINT_EXPORTER_STAT_TYPE_MASK',   0170000);
define('REPRINT_EXPORTER_STAT_TYPE_SOCKET', 0140000);
define('REPRINT_EXPORTER_STAT_TYPE_LINK',   0120000);
define('REPRINT_EXPORTER_STAT_TYPE_FILE',   0100000);
define('REPRINT_EXPORTER_STAT_TYPE_BLOCK',  0060000);
define('REPRINT_EXPORTER_STAT_TYPE_DIR',    0040000);
define('REPRINT_EXPORTER_STAT_TYPE_CHAR',   0020000);
define('REPRINT_EXPORTER_STAT_TYPE_FIFO',   0010000);

/**
 * Tracks time and memory limits for a single API request.
 *
 * Every export endpoint runs under resource constraints — a maximum
 * execution time and a memory ceiling.  Rather than threading four
 * separate values through every function signature and every
 * should_continue() call, this class bundles them into a single
 * object with a simple has_remaining() check.
 */
/**
 * Global streaming context. When set, the error handlers emit error chunks
 * into the active gzip multipart stream instead of sending plain JSON
 * (which would corrupt the compressed response).
 *
 * Set by each streaming endpoint right after creating $gz and $boundary.
 * Keys: 'gz' => GzipOutputStream, 'boundary' => string
 */
$streaming_context = null;

/**
 * Initializes a multipart/mixed streaming response, optionally with gzip compression.
 *
 * Every streaming endpoint needs the same setup: a unique boundary, the
 * Content-Type header, an output stream, and the global $streaming_context so
 * error handlers can emit structured error chunks mid-stream.
 *
 * @param bool $require_headers If true, throws when headers were already sent
 *                              (use for endpoints that can't degrade gracefully).
 * @param bool $gzip If true, emit Content-Encoding: gzip and compress the body.
 * @return array{gz: GzipOutputStream, boundary: string}
 */
function begin_multipart_stream(bool $require_headers = false, bool $gzip = true): array
{
    global $streaming_context;

    /**
     * We're choosing a random boundary without checking for its presence in the content.
     * This may seem to contradict RFC 2046, where it says:
     * 
     * > As stated previously, each body part is preceded by a boundary
     * > delimiter line that contains the boundary delimiter.  The boundary
     * > delimiter MUST NOT appear inside any of the encapsulated parts, on a
     * > line by itself or as the prefix of any line.  This implies that it is
     * > crucial that the composing agent be able to choose and specify a
     * > unique boundary parameter value that does not contain the boundary
     * > parameter value of an enclosing multipart as a prefix.
     * > 
     * > https://www.rfc-editor.org/rfc/rfc2046.html
     *
     * But in practice, we're okay. We use 128 bits of randomness. The chance of
     * it appearing in the data is about 1 in 2^128 — effectively zero. Curl does
     * the same here: 
     *
     *    https://github.com/curl/curl/blob/462244447e8ba3a53b1ba9f0ba7baa52d8777daa/lib/mime.c#L1179-L1236
     * 
     * Also, most chunks declare their Content-Length, so the client may skip the
     * boundary matching entirely and just consume that many bytes.
     */
    $boundary = "boundary-" . bin2hex(random_bytes(16));
    $can_send_headers = !headers_sent();

    if ($require_headers && !$can_send_headers) {
        throw new RuntimeException(
            "Cannot begin multipart stream: headers already sent",
        );
    }

    $gzip_enabled = $can_send_headers && $gzip;

    if ($can_send_headers) {
        @header("Content-Type: multipart/mixed; boundary=\"$boundary\"");
        if ($gzip_enabled) {
            @header("Content-Encoding: gzip");
        }
    }

    $gz = new GzipOutputStream($gzip_enabled);
    $streaming_context = ['gz' => $gz, 'boundary' => $boundary];

    return $streaming_context;
}

/**
 * Resolves database credentials from PHP constants and environment variables.
 *
 * Never reads from $config / HTTP parameters — credentials must come from
 * the server environment (PHP constants or environment variables).
 *
 * @return array{db_host: string, db_name: string, db_user: string, db_password: string,
 *               wp_config_path: ?string, table_prefix: ?string}
 * @throws InvalidArgumentException When required credentials are missing.
 */
function resolve_db_credentials(): array
{
    $db_host = defined("DB_HOST") ? DB_HOST : getenv("DB_HOST");
    $db_name = defined("DB_NAME") ? DB_NAME : getenv("DB_NAME");
    $db_user = defined("DB_USER") ? DB_USER : getenv("DB_USER");
    $db_password = defined("DB_PASSWORD") ? DB_PASSWORD : getenv("DB_PASSWORD");

    $wp_config_path = null;
    $table_prefix = $GLOBALS['table_prefix'] ?? null;

    // On SQLite sites, the driver is already loaded by WordPress via the
    // db.php drop-in. We just need to confirm it's available and skip the
    // MySQL credential requirements.
    if (is_sqlite_site()) {
        return [
            "db_engine" => "sqlite",
            "db_host" => "",
            "db_name" => $db_name ?: "wordpress",
            "db_user" => "",
            "db_password" => "",
            "wp_config_path" => $wp_config_path,
            "table_prefix" => $table_prefix,
        ];
    }

    $missing = [];
    if (!$db_host) { $missing[] = "db_host"; }
    if (!$db_name) { $missing[] = "db_name"; }
    if (!$db_user) { $missing[] = "db_user"; }
    if ($db_password === false || $db_password === null) {
        $missing[] = "db_password";
    }
    if (!empty($missing)) {
        throw new InvalidArgumentException(
            "Database credentials not found. Please provide via environment variables, " .
                "PHP constants, or ensure wp-config.php exists with valid credentials. " .
                "Missing: " . implode(", ", $missing),
        );
    }

    return [
        "db_engine" => "mysql",
        "db_host" => $db_host,
        "db_name" => $db_name,
        "db_user" => $db_user,
        "db_password" => $db_password,
        "wp_config_path" => $wp_config_path,
        "table_prefix" => $table_prefix,
    ];
}

/**
 * Returns true when the current WordPress site uses the SQLite backend.
 *
 * Detection is based on the WP_SQLite_Driver class being loaded and
 * $wpdb->dbh being an instance of it. This is set up automatically by
 * the sqlite-database-integration plugin's db.php drop-in when WordPress
 * boots.
 */
function is_sqlite_site(): bool
{
    global $wpdb;
    // @TODO: Actually check for the WP_SQLite_Driver class being used here.
    return defined('SQLITE_DB_DROPIN_VERSION') && isset($GLOBALS['@pdo']);
}

/**
 * Creates a database connection appropriate for the detected backend.
 *
 * For MySQL sites, returns a standard PDO connection.
 * For SQLite sites, wraps the WP_SQLite_Driver that WordPress already
 * loaded (via $wpdb->dbh) in a PDO-compatible adapter. The driver's
 * AST-based translator converts every MySQL query to SQLite on the fly,
 * so MySQLDumpProducer sees MySQL-shaped results and produces valid
 * MySQL SQL output.
 *
 * @param array $creds   Credentials from resolve_db_credentials().
 * @param array $options PDO options (only used for MySQL connections).
 * @return PDO A real PDO for MySQL, or a PDO-compatible adapter for SQLite.
 */
function create_db_connection(array $creds, array $options = [])
{
    if (($creds["db_engine"] ?? "mysql") === "sqlite") {
        return create_sqlite_pdo_adapter();
    }

    // Gate on pdo_mysql, not pdo: ext-pdo core without the mysql driver
    // can't drive MySQL exports.
    if (!extension_loaded('pdo_mysql')) {
        return create_wpdb_pdo_adapter();
    }

    // MySQL path (also works for HyperDB — wp-config.php credentials
    // point to the write master).
    $default_options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ];
    $merged_options = $options + $default_options;

    return new PDO(
        "mysql:host={$creds['db_host']};dbname={$creds['db_name']};charset=utf8mb4",
        $creds["db_user"],
        $creds["db_password"],
        $merged_options,
    );
}

/**
 * Wraps the already-loaded WP_SQLite_Driver in a PDO-compatible adapter.
 *
 * Validates that the sqlite-database-integration plugin version is in the
 * supported range, then extracts the driver and raw PDO from $wpdb->dbh.
 *
 * @return object PDO-compatible adapter (SqliteDriverPDO).
 * @throws RuntimeException If the driver is not available or unsupported.
 */
function create_sqlite_pdo_adapter()
{
    global $wpdb;

    /**
     * Minimum sqlite-database-integration version that exposes the API we
     * depend on: WP_SQLite_Driver::query(), get_query_results(),
     * get_connection()->get_pdo().
     */
    $min_version = '2.1.0';

    require_once __DIR__ . "/class-sqlite-driver-pdo.php";

    if (!isset($wpdb) || !($wpdb->dbh instanceof WP_SQLite_Driver)) {
        throw new RuntimeException(
            "SQLite export requires WordPress loaded with the " .
            "sqlite-database-integration plugin active."
        );
    }

    // Verify the plugin version is in the supported range.
    if (defined('SQLITE_DRIVER_VERSION')) {
        if (version_compare(SQLITE_DRIVER_VERSION, $min_version, '<')) {
            throw new RuntimeException(
                "sqlite-database-integration plugin version " . SQLITE_DRIVER_VERSION .
                " is too old. Minimum required: " . $min_version
            );
        }
    }

    $driver = $wpdb->dbh;
    $raw_pdo = $driver->get_connection()->get_pdo();

    return new SqliteDriverPDO($driver, $raw_pdo);
}

/**
 * Wraps the global $wpdb in a PDO-shaped adapter.
 *
 * Used on hosts without ext-pdo_mysql. Requires WordPress to be loaded
 * (so $wpdb is available); throws otherwise.
 */
function create_wpdb_pdo_adapter()
{
    global $wpdb;

    require_once __DIR__ . "/class-wpdb-driver-pdo.php";

    // Guard against a clobbered/half-initialized $wpdb: isset() alone passes
    // for non-object scalars, which would fatal inside the adapter constructor.
    if (!isset($wpdb) || !is_object($wpdb)) {
        throw new RuntimeException(
            "MySQL export without PDO requires WordPress \$wpdb to be initialized."
        );
    }

    return new WpdbDriverPDO($wpdb);
}

// Guard with existence checks: when loaded via Composer autoloader, both
// files are already included from a path that may differ from __DIR__
// (e.g. symlink vs realpath). With opcache.revalidate_path=0 (default),
// require_once does not resolve symlinks, so the same physical file can
// be loaded twice through different paths, causing "Cannot redeclare"
// fatal errors.
if (!function_exists('Reprint\\Exporter\\build_pdo_dsn')) {
    require_once __DIR__ . "/utils.php";
}
if (!class_exists(ExportCommands::class, false)) {
    require_once __DIR__ . "/commands/load.php";
}
if (!class_exists(Site_Export_HTTP_Server::class, false)) {
    require_once __DIR__ . "/class-http-server.php";
}

/**
 * Emits an error chunk into a gzip multipart stream.
 */
function emit_error_chunk($gz, string $boundary, string $message): void
{
    $json = json_encode([
        "error_type" => "php_error",
        "path" => "",
        "message" => $message,
    ]);
    if ($json === false) {
        $json = '{"error_type":"php_error","path":"","message":"Error (json_encode failed)"}';
    }
    $chunk =
        "--{$boundary}\r\n" .
        "Content-Type: application/json\r\n" .
        "Content-Length: " . strlen($json) . "\r\n" .
        "X-Chunk-Type: error\r\n" .
        "\r\n" .
        $json . "\r\n";
    try {
        $gz->write($chunk);
        $gz->sync();
    } catch (\Throwable $e) {
        // Gzip stream is broken — fall back to raw output.
        // The response is already partially gzipped so the client likely
        // can't parse this, but it's better than silent failure.
        echo $chunk;
        flush();
    }
}

// Streaming-aware error handler. Before streaming starts, errors produce
// a JSON response with HTTP 500. Mid-stream, errors become multipart
// error chunks so the client receives structured diagnostics.
//
// Respects the @ operator: suppressed errors are logged but never emitted
// into the stream or sent as responses, since the calling code already
// handles the failure (e.g. @readlink checks for false).
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    global $streaming_context;

    $error = [
        "error" => "PHP Error: $errstr",
        "file" => $errfile,
        "line" => $errline,
        "type" => $errno,
    ];

    if (!(error_reporting() & $errno)) {
        error_log("Export error (suppressed): " . json_encode($error));
        return true;
    }

    error_log("Export error: " . json_encode($error));

    if ($streaming_context !== null) {
        emit_error_chunk(
            $streaming_context['gz'],
            $streaming_context['boundary'],
            "PHP Error ({$errno}): {$errstr} in {$errfile}:{$errline}",
        );
        return true;
    }

    http_response_code(500);
    @header("Content-Type: application/json");
    echo json_encode($error);
    exit(1);
});

// Streaming-aware exception handler, mirrors the error handler above.
set_exception_handler(function ($e) {
    global $streaming_context;

    $error = [
        "error" => get_class($e) . ": " . $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine(),
        "trace" => $e->getTraceAsString(),
    ];
    error_log("Export exception: " . json_encode($error));

    if ($streaming_context !== null) {
        emit_error_chunk(
            $streaming_context['gz'],
            $streaming_context['boundary'],
            get_class($e) . ": " . $e->getMessage(),
        );
        return;
    }

    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode($error);
    exit(1);
});

// Catches E_ERROR/E_PARSE fatals that set_error_handler cannot intercept.
register_shutdown_function(function () {
    global $streaming_context;

    $error = error_get_last();
    if ($error === null) {
        return;
    }
    $fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
    if (!($error['type'] & $fatal_types)) {
        return;
    }

    $message = "Fatal: {$error['message']} in {$error['file']}:{$error['line']}";
    error_log("Export fatal: " . json_encode($error));

    if ($streaming_context !== null) {
        // Best-effort attempt to emit an error chunk into the stream.
        // The stream may already be in a broken state, but this gives
        // the client the best chance of receiving structured error info.
        try {
            emit_error_chunk(
                $streaming_context['gz'],
                $streaming_context['boundary'],
                $message,
            );
        } catch (Throwable $ignored) {
            // Stream is too broken to write to — nothing more we can do.
        }
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        @header("Content-Type: application/json");
        echo json_encode([
            "error" => $message,
            "file" => $error['file'],
            "line" => $error['line'],
            "type" => $error['type'],
        ]);
    }
});

// ============================================================================
// E2E Test Hook System (only active when SITE_EXPORT_TEST_MODE env var is set)
// We don't want anyone to interfere with the export process, which is why those
// hooks are not registered in production.
// ============================================================================
if (getenv('SITE_EXPORT_TEST_MODE')) {
    /**
     * Load test hooks from a well-known path relative to the site root.
     * The hook file can define callback functions that are called at key
     * points during export for testing error conditions and edge cases.
     *
     * Supported hook functions:
     *   test_hook_before_sql_batch(&$sql, $cursor)     - Before SQL batch emitted
     *   test_hook_before_file_chunk($path, $offset, &$data) - Before file chunk
     *   test_hook_after_gzip_init($gz, $boundary)       - After gzip stream init
     *   test_hook_before_completion($status, $gz, $boundary) - Before completion chunk
     *   test_hook_before_index_batch(&$batch_items, $stack)  - Before index batch emitted
     *   test_hook_during_dir_scan($dir, &$entries)       - During directory scanning
     */
    $__test_hook_file_loaded = false;
    function _e2e_load_test_hooks_if_needed(array $config): void {
        global $__test_hook_file_loaded;
        if ($__test_hook_file_loaded) {
            return;
        }
        $candidates = [];
        if (isset($config['directory'])) {
            $dirs = is_array($config['directory']) ? $config['directory'] : [$config['directory']];
            foreach ($dirs as $d) {
                $candidates[] = rtrim($d, '/') . '/wp-content/plugins/site-export/test-hooks.php';
            }
        }
        // Also check relative to this file's parent
        $candidates[] = dirname(__DIR__) . '/test-hooks.php';
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($candidate, true);
                }
                require $candidate;
                $__test_hook_file_loaded = true;
                return;
            }
        }
    }

    function _e2e_call_hook(string $name, array &$args = []): void {
        if (function_exists($name)) {
            call_user_func_array($name, $args);
        }
    }
}

require_once __DIR__ . "/class-mysql-dump-producer.php";
require_once __DIR__ . "/class-file-tree-producer.php";
require_once __DIR__ . "/class-gzip-output-stream.php";
require_once __DIR__ . "/class-resource-budget.php";

/**
 * Prepares the PHP environment for streaming by disabling output buffering,
 * compression layers, and proxy buffering.
 */
function prepare_streaming_response(): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (!headers_sent()) {
        @header("X-Accel-Buffering: no");
        @header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        @header("Pragma: no-cache");
        @header("Expires: 0");
    }

    /**
     * zlib.output_compression buffers the entire response before compressing. The
     * entire point of this plugin is to stream the response, therefore we use a custom
     * GzipOutputStream.
     */
    @ini_set("zlib.output_compression", "0");
    @ini_set("output_buffering", "0");
    @ini_set("implicit_flush", "1");

    @ob_implicit_flush(true);
}

/**
 * Deduplicates and resolves a list of paths, discarding empty entries.
 */
function normalize_path_list(array $paths): array
{
    $normalized = [];
    foreach ($paths as $path) {
        if (!is_string($path)) {
            continue;
        }
        $path = trim($path);
        if ($path === "") {
            continue;
        }
        $real = realpath($path);
        $final = $real !== false ? $real : $path;
        $final = rtrim($final, "/");
        if ($final === "") {
            continue;
        }
        $normalized[$final] = true;
    }
    return array_keys($normalized);
}

/**
 * Walks parent directories upward from each start path to find WordPress installations.
 */
function detect_wp_roots(array $start_paths): array
{
    $start_paths = normalize_path_list($start_paths);
    $seen = [];
    $roots = [];

    foreach ($start_paths as $start) {
        $current = $start;
        while ($current !== "" && !isset($seen[$current])) {
            $seen[$current] = true;
            $wp_load_path = $current . "/wp-load.php";
            $wp_config_path = $current . "/wp-config.php";
            $has_wp_load = file_exists($wp_load_path);
            $has_wp_config = file_exists($wp_config_path);
            $has_wp_content = is_dir($current . "/wp-content");
            if ($has_wp_load || $has_wp_config) {
                $roots[$current] = [
                    "path" => $current,
                    "wp_load" => $has_wp_load,
                    "wp_load_path" => $has_wp_load ? $wp_load_path : null,
                    "wp_config" => $has_wp_config,
                    "wp_config_path" => $has_wp_config ? $wp_config_path : null,
                    "wp_content" => $has_wp_content,
                ];
            }

            $parent = dirname($current);
            if ($parent === $current || $parent === "") {
                break;
            }
            $current = $parent;
        }
    }

    return [
        "searched" => array_keys($seen),
        "roots" => array_values($roots),
    ];
}

/**
 * Resolves directory paths from config.
 */
function resolve_directories(array $config): array
{
    $directories_input = $config["directory"] ?? null;
    if (!$directories_input) {
        throw new InvalidArgumentException(
            "directory is required for files operation",
        );
    }

    $directories = [];
    $dir_list = is_array($directories_input)
        ? $directories_input
        : [$directories_input];

    foreach ($dir_list as $directory) {
        if (!is_string($directory)) {
            throw new InvalidArgumentException(
                "directory entries must be non-empty strings",
            );
        }
        $directory = trim($directory);
        assert_valid_path($directory, "directory entry");

        $real_directory = realpath($directory);
        if ($real_directory === false) {
            throw new InvalidArgumentException(
                "directory does not exist or is not accessible: {$directory}\n" .
                    "Current working directory: " .
                    getcwd() .
                    "\n" .
                    "Script directory: " .
                    __DIR__
            );
        }

        $directories[] = $real_directory;
    }

    if (empty($directories)) {
        throw new InvalidArgumentException("No valid directories specified");
    }

    return $directories;
}

/**
 * Returns true when traversing $candidate would only duplicate or re-enter
 * one of the already-scheduled roots.
 *
 * Examples:
 * - candidate == root: duplicate root
 * - candidate is a parent of root: would expose outside-tree paths and then
 *   re-enter the scheduled root again
 */
function should_skip_index_root(string $candidate, array $roots): bool
{
    foreach ($roots as $root) {
        if ($candidate === $root) {
            return true;
        }
        if ($candidate === "/" || str_starts_with($root . "/", $candidate . "/")) {
            return true;
        }
    }

    return false;
}

/**
 * Streams file chunks from a producer as multipart/mixed.
 */
function stream_file_producer(
    $producer,
    ResourceBudget $budget,
    array $config = [],
    bool $gzip = false
): array {
    prepare_streaming_response();

    ['gz' => $gz, 'boundary' => $boundary] = begin_multipart_stream(false, $gzip);

    // E2E test hook: after gzip stream initialization (file producer)
    if (getenv('SITE_EXPORT_TEST_MODE')) {
        _e2e_load_test_hooks_if_needed($config);
        $hook_args = [$gz, $boundary];
        _e2e_call_hook('test_hook_after_gzip_init', $hook_args);
    }

    $chunks_processed = 0;
    $files_completed = 0;
    $bytes_processed = 0;
    $last_progress_output = microtime(true);
    $metadata_sent = false;
    $iterations = 0;
    $aborted = false;
    $abort_payload = null;
    $last_cursor = "";

    // -- Stream chunks from the producer --
    // The producer yields file data, directories, symlinks, index entries,
    // and progress updates. Each chunk type is wrapped in a multipart part
    // with metadata headers (path, cursor, size, ctime). The loop runs
    // until the producer is exhausted or the resource budget runs out.
    try {
        $initial_progress = $producer->get_progress();
        $initial_progress_json = json_encode_or_throw($initial_progress);
        $initial_cursor = $producer->get_reentrancy_cursor();
        $last_cursor = $initial_cursor;
        $gz->write(
            "--{$boundary}\r\n" .
            "Content-Type: application/json\r\n" .
            "Content-Length: " . strlen($initial_progress_json) . "\r\n" .
            "X-Chunk-Type: progress\r\n" .
            "X-Cursor: " . base64_encode($initial_cursor) . "\r\n" .
            "\r\n" .
            $initial_progress_json . "\r\n",
        );
        $gz->sync();
        while (true) {
            if (
                !$budget->has_remaining()
            ) {
                break;
            }

            if (!$producer->next_chunk()) {
                break;
            }

            $iterations++;
            $chunk = $producer->get_current_chunk();
            $progress = $producer->get_progress();

            if (!$metadata_sent && $progress["phase"] === "streaming") {
                $filesystem_root = $producer->get_filesystem_root();
                $metadata = [
                    "filesystem_root" => base64_encode($filesystem_root ?? ""),
                ];
                $metadata_json = json_encode_or_throw($metadata);

                $gz->write(
                    "--{$boundary}\r\n" .
                    "Content-Type: application/json\r\n" .
                    "Content-Length: " . strlen($metadata_json) . "\r\n" .
                    "X-Chunk-Type: metadata\r\n" .
                    "X-Filesystem-Root: " . base64_encode($filesystem_root ?? "") . "\r\n" .
                    "\r\n" .
                    $metadata_json . "\r\n",
                );
                $gz->sync();

                $metadata_sent = true;
            }

            if ($chunk === null) {
                $now = microtime(true);
                if ($iterations === 1 || $now - $last_progress_output >= 3.0) {
                    $progress_json = json_encode_or_throw($progress);
                    $cursor = $producer->get_reentrancy_cursor();
                    $last_cursor = $cursor;

                    $gz->write(
                        "--{$boundary}\r\n" .
                        "Content-Type: application/json\r\n" .
                        "Content-Length: " . strlen($progress_json) . "\r\n" .
                        "X-Chunk-Type: progress\r\n" .
                        "X-Cursor: " . base64_encode($cursor) . "\r\n" .
                        "\r\n" .
                        $progress_json . "\r\n",
                    );
                    $gz->sync();

                    $last_progress_output = $now;
                }

                continue;
            }

            $chunk_type = $chunk["type"] ?? "file";
            $cursor = $producer->get_reentrancy_cursor();
            $last_cursor = $cursor;

            if ($chunk_type === "directory") {
                $part =
                    "--{$boundary}\r\n" .
                    "Content-Type: application/octet-stream\r\n" .
                    "Content-Length: 0\r\n" .
                    "X-Chunk-Type: directory\r\n" .
                    "X-Cursor: " . base64_encode($cursor) . "\r\n" .
                    "X-Directory-Path: " . base64_encode($chunk["path"]) . "\r\n";
                if (isset($chunk["ctime"])) {
                    $part .= "X-Directory-Ctime: " . $chunk["ctime"] . "\r\n";
                }
                $gz->write($part . "\r\n\r\n");
                $gz->sync();
            } elseif ($chunk_type === "symlink") {
                $gz->write(
                    "--{$boundary}\r\n" .
                    "Content-Type: application/octet-stream\r\n" .
                    "Content-Length: 0\r\n" .
                    "X-Chunk-Type: symlink\r\n" .
                    "X-Cursor: " . base64_encode($cursor) . "\r\n" .
                    "X-Symlink-Path: " . base64_encode($chunk["path"]) . "\r\n" .
                    "X-Symlink-Target: " . base64_encode($chunk["target"]) . "\r\n" .
                    "X-Symlink-Ctime: " . $chunk["ctime"] . "\r\n" .
                    "\r\n\r\n",
                );
                $gz->sync();
            } elseif ($chunk_type === "index") {
                $gz->write(
                    "--{$boundary}\r\n" .
                    "Content-Type: application/octet-stream\r\n" .
                    "Content-Length: 0\r\n" .
                    "X-Chunk-Type: index\r\n" .
                    "X-Cursor: " . base64_encode($cursor) . "\r\n" .
                    "X-Index-Path: " . base64_encode($chunk["path"]) . "\r\n" .
                    "X-File-Ctime: " . $chunk["ctime"] . "\r\n" .
                    "X-File-Size: " . $chunk["size"] . "\r\n" .
                    "\r\n\r\n",
                );
                $gz->sync();
            } elseif ($chunk_type === "missing") {
                $gz->write(
                    "--{$boundary}\r\n" .
                    "Content-Type: application/octet-stream\r\n" .
                    "Content-Length: 0\r\n" .
                    "X-Chunk-Type: missing\r\n" .
                    "X-Cursor: " . base64_encode($cursor) . "\r\n" .
                    "X-File-Path: " . base64_encode($chunk["path"]) . "\r\n" .
                    "\r\n\r\n",
                );
                $gz->sync();
            } elseif ($chunk_type === "error") {
                $payload = [
                    "error_type" => $chunk["error_type"] ?? "unknown",
                    "path" => base64_encode($chunk["path"] ?? ""),
                    "message" => $chunk["message"] ?? "Error",
                ];
                if (isset($chunk["expected_ctime"])) {
                    $payload["expected_ctime"] = $chunk["expected_ctime"];
                }
                if (isset($chunk["actual_ctime"])) {
                    $payload["actual_ctime"] = $chunk["actual_ctime"];
                }
                $json = json_encode_or_throw($payload);
                $gz->write(
                    "--{$boundary}\r\n" .
                    "Content-Type: application/json\r\n" .
                    "Content-Length: " . strlen($json) . "\r\n" .
                    "X-Chunk-Type: error\r\n" .
                    "X-Cursor: " . base64_encode($cursor) . "\r\n" .
                    "\r\n" .
                    $json . "\r\n",
                );
                $gz->sync();
            } else {
                // E2E test hook: before file chunk is emitted
                if (getenv('SITE_EXPORT_TEST_MODE')) {
                    $hook_data = $chunk["data"];
                    $hook_args = [$chunk["path"], $chunk["offset"], &$hook_data];
                    _e2e_call_hook('test_hook_before_file_chunk', $hook_args);
                    $chunk["data"] = $hook_data;
                }

                $chunks_processed++;
                $bytes_processed += strlen($chunk["data"]);
                if ($chunk["is_first_chunk"]) {
                    $files_completed++;
                }

                $data = $chunk["data"];

                $headers =
                    "--{$boundary}\r\n" .
                    "Content-Type: application/octet-stream\r\n" .
                    "Content-Length: " . strlen($data) . "\r\n" .
                    "X-Chunk-Type: file\r\n" .
                    "X-Cursor: " . base64_encode($cursor) . "\r\n" .
                    "X-File-Path: " . base64_encode($chunk["path"]) . "\r\n" .
                    "X-File-Size: " . $chunk["size"] . "\r\n" .
                    "X-File-Ctime: " . $chunk["ctime"] . "\r\n" .
                    "X-Chunk-Offset: " . $chunk["offset"] . "\r\n" .
                    "X-Chunk-Size: " . strlen($data) . "\r\n" .
                    "X-First-Chunk: " . ($chunk["is_first_chunk"] ? "1" : "0") . "\r\n" .
                    "X-Last-Chunk: " . ($chunk["is_last_chunk"] ? "1" : "0") . "\r\n";
                if (!empty($chunk["file_changed"])) {
                    $headers .= "X-File-Changed: 1\r\n";
                    if ($chunk["change_ctime"] !== null) {
                        $headers .= "X-File-Change-Ctime: " . $chunk["change_ctime"] . "\r\n";
                    }
                    if ($chunk["change_size"] !== null) {
                        $headers .= "X-File-Change-Size: " . $chunk["change_size"] . "\r\n";
                    }
                }
                $gz->write($headers . "\r\n");
                $gz->write($data);
                $gz->write("\r\n");
                $gz->sync();
            }
        }
    } catch (Throwable $e) {
        $aborted = true;
        $abort_payload = [
            "error_type" => "exception",
            "path" => "",
            "message" => $e->getMessage(),
        ];
    }

    // Best-effort error and completion chunks — the client already has the
    // data chunks. If the stream is broken at this point, log and move on.
    try {
        // @TODO: If an exception is thrown right after the previous chunk header,
        //        it read the fixed Content-Length value and will consume this next
        //        chunk as data. We should try and backfill the output up to the 
        //        previous content-length value if possible.
        if ($abort_payload !== null) {
            $json = json_encode_or_throw($abort_payload);
            $gz->write(
                "--{$boundary}\r\n" .
                "Content-Type: application/json\r\n" .
                "Content-Length: " . strlen($json) . "\r\n" .
                "X-Chunk-Type: error\r\n" .
                "X-Cursor: " . base64_encode($last_cursor) . "\r\n" .
                "\r\n" .
                $json . "\r\n",
            );
            $gz->sync();
        }

        $progress = $producer->get_progress();
        $is_complete = $progress["phase"] === "finished" && !$aborted;
        $status = $is_complete ? "complete" : "partial";

        // E2E test hook: before completion chunk (file producer)
        if (getenv('SITE_EXPORT_TEST_MODE')) {
            $hook_args = [$status, $gz, $boundary];
            _e2e_call_hook('test_hook_before_completion', $hook_args);
        }

        error_log(
            "Export completion: status={$status}, phase={$progress["phase"]}, " .
                "chunks={$chunks_processed}, files={$files_completed}, bytes={$bytes_processed}",
        );

        $gz->write(
            "--{$boundary}\r\n" .
            "Content-Type: application/octet-stream\r\n" .
            "Content-Length: 0\r\n" .
            "X-Chunk-Type: completion\r\n" .
            "X-Status: {$status}\r\n" .
            "X-Chunks-Processed: {$chunks_processed}\r\n" .
            "X-Files-Completed: {$files_completed}\r\n" .
            "X-Bytes-Processed: {$bytes_processed}\r\n" .
            "X-Memory-Used: " . memory_get_peak_usage(true) . "\r\n" .
            "X-Memory-Limit: " . $budget->max_memory . "\r\n" .
            "X-Time-Elapsed: " . (microtime(true) - $budget->start_time) . "\r\n" .
            "\r\n" .
            "\r\n" .
            "--{$boundary}--\r\n",
        );
        $gz->finish();
    } catch (\Throwable $e) {
        error_log("Export: failed to write completion chunk: " . $e->getMessage());
    }

    $status = $aborted ? "partial" : ($status ?? "partial");

    return [
        "status" => $status,
        "stats" => [
            "chunks_processed" => $chunks_processed,
            "files_completed" => $files_completed,
            "bytes_processed" => $bytes_processed,
            "memory_used" => memory_get_peak_usage(true),
            "time_elapsed" => microtime(true) - $budget->start_time,
        ],
    ];
}

/**
 * Encodes a file_index stack for JSON serialization.
 *
 * Paths may contain non-UTF8 bytes, so dir and after are base64-encoded.
 */
function encode_index_stack(array $stack): array
{
    $encoded = [];
    foreach ($stack as $frame) {
        $encoded[] = [
            "dir" => base64_encode($frame["dir"]),
            "after" => $frame["after"] !== null ? base64_encode($frame["after"]) : null,
        ];
    }
    return $encoded;
}

/**
 * Resolve "." and ".." segments in a path without resolving symlinks.
 *
 * Unlike realpath(), this only performs textual normalization — it collapses
 * "." and ".." but leaves symlink components intact.  This is useful when
 * you need a clean absolute path to inspect which components are symlinks.
 *
 * @param string $path An absolute path that may contain "." or ".." segments.
 * @return string The normalized absolute path.
 */
function normalize_dot_segments(string $path): string
{
    $parts = explode("/", $path);
    $normalized = [];
    foreach ($parts as $p) {
        if ($p === "" || $p === ".") {
            if (empty($normalized)) {
                $normalized[] = "";
            }
            continue;
        }
        if ($p === "..") {
            if (count($normalized) > 1) {
                array_pop($normalized);
            }
            continue;
        }
        $normalized[] = $p;
    }
    return implode("/", $normalized);
}

/**
 * Given a path, such as `/srv/wordpress/wp-content/plugins/akismet/assets`, returns
 * a list of all the parent paths that are symlinks. It will check `/srv`,
 * `/srv/wordpress`, `/srv/wordpress/wp-content`, etc.
 * 
 * For example, given the following filesystem layout:
 * 
 *     /srv/wordpress/wp-content -> /htdocs/wp-content
 *     /srv/wordpress/wp-content/plugins/akismet -> /wordpress/plugins/akismet/latest
 *     /wordpress/plugins/akismet/latest -> /wordpress/plugins/akismet/5.0.5
 * 
 * Calling
 * 
 *     find_parents_symlinks("/srv/wordpress/wp-content/plugins/akismet/assets")
 * 
 * will return the following symlinks:
 * 
 * ['path' => '/srv/wordpress/wp-content', 'target' => '/htdocs/wp-content']
 * ['path' => '/htdocs/wp-content/plugins/akismet', 'target' => '/wordpress/plugins/akismet/latest']
 *
 * Note:
 * 
 * * Every found `path` is a resolved realpath(), which means that all the parents are
 *   regular directories, not symlinks.
 * * It is intentionally not recursive. That last `akismet/latest` -> `akismet/5.0.5`
 *   symlink was not returned. The client is free to recursively request the files from
 *   any additional directories outside of the initial content root based on the parent
 *   symlinks resolved by this function.
 * 
 * @param string $absolute_path An absolute path to a file or directory.
 * @return array An array of symlinks found in the path.
 * Each array element is an associative array with the following keys:
 * - "path": The path to the symlink.
 * - "ctime": The creation time of the symlink.
 * - "size": The size of the symlink.
 * - "type": The type of the symlink.
 * - "target": The target of the symlink.
 * - "intermediate": Whether the symlink is an intermediate symlink.
 */
function find_parents_symlinks(string $absolute_path): array
{
    $entries = [];
    $parts = explode('/', $absolute_path);
    $current = "";
    // Walk through /srv, /srv/wordpress, /srv/wordpress/wp-content, etc.
    foreach ($parts as $part) {
        if ($part === "") {
            $current = "/";
            continue;
        }
        $current = rtrim($current, "/") . "/" . $part;
        // If the path up to this point is not a symlink, we can just
        // expand to the next path segment.
        if (!@is_link($current)) {
            continue;
        }

        // If we're looking at a valid symlink, record it.
        $target = @readlink($current);
        if ($target !== false && $target !== "") {
            $stat = @lstat($current);
            $entries[] = [
                "path" => $current,
                "ctime" => (int) ($stat["ctime"] ?? 0),
                "size" => 0,
                "type" => "link",
                "target" => $target,
                "intermediate" => true,
            ];
        }
        // Swap the current path for the resolved realpath().
        // e.g. if $current is a symlink at /srv/wordpress/wp-content pointing
        // to /htdocs/wp-content, then from now on we'll use /htdocs/wp-content
        // as our $current and append the next path segments to it.
        $real = @realpath($current);
        if ($real !== false) {
            $current = $real;
        }
    }
    return $entries;
}

/**
 * Resolves a symlink's target to a canonical path for the file index.
 *
 * On many WordPress hosts (wp.com, SiteGround, etc.), the filesystem
 * contains chains of symlinks.  For example, /srv might point to /,
 * /srv/wordpress might point to /wordpress, and readlink() returns
 * relative paths like "../wordpress/core/latest" that still contain
 * intermediate symlinks.  realpath() cuts through all of this and
 * returns the final canonical path — e.g. /htdocs instead of /srv/htdocs.
 *
 * The client uses symlink targets to discover additional directories to
 * index, so only directory symlinks get a resolved target.  File symlink
 * targets are ignored because the client doesn't need to recurse into them.
 *
 * Also walks the raw readlink() path to find intermediate symlinks that
 * realpath() skips.  For example, if readlink() returns a relative path
 * like "../../../wordpress/plugins/akismet/latest", the absolute form
 * might be /srv/wordpress/plugins/akismet/latest — and /srv/wordpress is
 * itself a symlink to /wordpress.  realpath() jumps straight to
 * /wordpress/..., so we'd never record the /srv/wordpress intermediate.
 * find_parents_symlinks() catches those.
 *
 * @param string $path  Absolute path to the symlink.
 * @return array{target: string|null, intermediates: array} The resolved
 *               canonical target (null for file symlinks or unresolvable
 *               paths), and any intermediate symlink entries found.
 */
function resolve_symlink_target(string $path): array
{
    clearstatcache(true, $path);
    $resolved_target = @realpath($path);

    // Only directory symlinks matter — the client uses targets to discover
    // additional directories to index.  Also skip unresolvable symlinks
    // and self-referencing paths.
    if (
        $resolved_target === false ||
        $resolved_target === $path ||
        !is_dir($resolved_target)
    ) {
        return ['target' => null, 'intermediates' => []];
    }

    $intermediates = [];
    $raw_target = @readlink($path);
    if ($raw_target !== false && $raw_target !== "") {
        if ($raw_target[0] !== "/") {
            $raw_target = dirname($path) . "/" . $raw_target;
        }
        $abs_raw = normalize_dot_segments($raw_target);
        if ($abs_raw !== "" && $abs_raw[0] === "/" && $abs_raw !== $resolved_target) {
            $intermediates = find_parents_symlinks($abs_raw);
        }
    }

    return ['target' => $resolved_target, 'intermediates' => $intermediates];
}

/**
 * Encodes batch items for JSON serialization, base64-encoding paths
 * to handle non-UTF8 filesystem bytes.
 */
function encode_index_batch(array $batch_items): array
{
    $encoded = [];
    foreach ($batch_items as $item) {
        $entry = [
            "path" => base64_encode($item["path"]),
            "ctime" => $item["ctime"],
            "size" => $item["size"],
            "type" => $item["type"],
        ];
        if (isset($item["target"])) {
            $entry["target"] = base64_encode($item["target"]);
        }
        if (!empty($item["intermediate"])) {
            $entry["intermediate"] = true;
        }
        $encoded[] = $entry;
    }
    return $encoded;
}

/**
 * Decides whether to gzip a file_fetch multipart response based on the path
 * list it will carry.
 *
 * Encoding is set per response (Content-Encoding is a response-level header),
 * so we have to commit before any byte is sent. The trade-off:
 *   - Text-y bodies (PHP/JS/CSS/JSON/SQL/HTML/etc.) compress 5–60×. Gzip is
 *     a clear win on wire size and total wall time.
 *   - Image/video/audio/font/archive bodies are already compressed; passing
 *     them through gzip costs ~4 ms per 200 KB and produces ~0% size
 *     reduction (deflate falls back to literal stored blocks for incompressible
 *     input). Negligible per individual file, but unbounded if the batch is
 *     all-binary multiplied by request volume.
 *
 * Rule: gzip the response if **any** file in the batch is compressible.
 *
 * The previous all-or-nothing rule ("gzip only if every file is compressible")
 * was over-conservative — a single PNG in a 200-CSS batch flipped the whole
 * response to identity, losing ~50 % of wire size that would have compressed.
 * The wasted CPU on the small binary portion of mixed batches is bounded by
 * request size (capped server-side), so this trade-off favors smaller wire
 * bytes on the common WordPress mixed batch (theme dirs, wp-content/uploads
 * mixed with plugin assets) without harming the all-binary uploads case
 * (which has zero compressible files and stays identity).
 */
function file_fetch_paths_should_gzip(array $paths): bool
{
    if ($paths === []) {
        return false;
    }
    $any_compressible = false;
    foreach ($paths as $path) {
        if (!is_string($path)) {
            // Defensive: an unexpected non-string entry is a bad input we
            // shouldn't compress around. Treat as a hard reject.
            return false;
        }
        // Once true, we can skip checking the subsequent files.
        if ($any_compressible) {
            continue;
        }
        $ext = path_extension_compressibility($path);
        if ($ext === 'yes') {
            $any_compressible = true;
            continue;
        }
        if ($ext === 'unknown') {
            // Extension didn't match a known-text or known-binary list. Peek
            // at the first 64 bytes and let the bytes decide. Cheap (one
            // open/read/close per file) and means we don't have to grow the
            // whitelist every time a plugin invents a new template suffix.
            if (path_head_looks_like_text($path)) {
                $any_compressible = true;
            }
            continue;
        }
        // 'no' — known binary. Skip; doesn't disqualify the batch.
    }
    return $any_compressible;
}

/**
 * Returns true if a path's basename suggests text content gzip will shrink.
 *
 * Files with no extension (`.htaccess`, `LICENSE`, `README`, dotfiles) are
 * treated as text by convention — that's almost always how they're stored
 * in WordPress installs.
 */
function path_extension_is_compressible(string $path): bool
{
    return path_extension_compressibility($path) === 'yes';
}

/**
 * Three-state classifier for a path's extension.
 *
 *   - 'yes'     known text-y extension (or dotfile / extensionless name).
 *   - 'no'      known binary/already-compressed extension.
 *   - 'unknown' neither list matches; caller may probe the file bytes.
 */
function path_extension_compressibility(string $path): string
{
    $basename = basename($path);
    if ($basename === '') {
        return 'no';
    }
    // Dotfiles like .htaccess / .env / .gitignore have no "real" extension —
    // pathinfo() reports the part after the leading dot as the extension,
    // but they're text by convention. Treat the whole class as compressible.
    if ($basename[0] === '.' && strpos($basename, '.', 1) === false) {
        return 'yes';
    }
    $ext = strtolower((string) pathinfo($basename, PATHINFO_EXTENSION));
    // Files with truly no extension (LICENSE, README, Makefile) — treat as text.
    if ($ext === '') {
        return 'yes';
    }
    static $compressible = [
        // Source / markup
        'php', 'phtml', 'js', 'jsx', 'ts', 'tsx', 'mjs', 'cjs',
        'css', 'scss', 'sass', 'less',
        'html', 'htm', 'xml', 'xsl', 'xslt', 'svg',
        'vue', 'astro', 'twig', 'mustache', 'hbs', 'liquid',
        // Data / config
        'json', 'jsonl', 'yaml', 'yml', 'toml', 'csv', 'tsv',
        'sql', 'ini', 'conf', 'cfg', 'env', 'properties',
        // Docs / plain text
        'md', 'markdown', 'txt', 'log', 'rst', 'adoc',
        // Translations / feeds / captions
        'pot', 'po', 'rss', 'atom', 'srt', 'vtt', 'webvtt',
        // Misc text-y
        'sh', 'bash', 'patch', 'diff',
    ];
    if (in_array($ext, $compressible, true)) {
        return 'yes';
    }
    static $incompressible = [
        // Already-compressed / encrypted archives
        'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'tar',
        // Images
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'avif',
        'tiff', 'tif', 'bmp', 'ico',
        // Audio
        'mp3', 'm4a', 'aac', 'ogg', 'opus', 'flac', 'wav',
        // Video
        'mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi',
        // Fonts (already deflate-compressed in woff/woff2)
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        // Misc binary blobs
        'pdf', 'psd', 'sketch', 'fig', 'iso', 'dmg', 'mo', 'phar',
    ];
    if (in_array($ext, $incompressible, true)) {
        return 'no';
    }
    return 'unknown';
}

/**
 * Probes the first bytes of a file to decide if it looks like text.
 *
 * Used as a fallback when the extension didn't match either the text or the
 * binary list. The cost is one open + read + close per file in the
 * file_fetch batch, which is negligible relative to streaming the file
 * itself; the upside is we don't need to grow the extension lists every
 * time a plugin invents a new template suffix.
 *
 * The check is deliberately strict: any NUL or other ASCII control byte
 * (outside tab/newline/CR/form-feed) means binary, and the head must also
 * decode as valid UTF-8. UTF-8 happens to reject most random binary
 * sequences naturally because high-bit bytes only validate in well-formed
 * multi-byte runs — so PNG, JPEG, ZIP, etc. fail this within a handful of
 * bytes even when their headers look ASCII.
 */
function path_head_looks_like_text(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }
    $fp = @fopen($path, 'rb');
    if ($fp === false) {
        // Producer will surface a clearer error later; don't compress on
        // unreadable paths.
        return false;
    }
    $head = (string) fread($fp, 64);
    fclose($fp);
    if ($head === '') {
        // Empty file: nothing to compress, default to identity.
        return false;
    }
    // Any NUL byte → binary. Cheapest signal, catches PNG/ZIP/woff/etc.
    if (strpos($head, "\x00") !== false) {
        return false;
    }
    // Other ASCII control bytes (excluding TAB \x09, LF \x0A, FF \x0C, CR \x0D)
    // shouldn't appear in source/data files. Also reject DEL \x7F.
    if (preg_match('/[\x01-\x08\x0B\x0E-\x1F\x7F]/', $head)) {
        return false;
    }
    // Must decode cleanly as UTF-8. mb_check_encoding handles the case where
    // a multi-byte sequence is sliced by our 64-byte window: it returns false,
    // which we treat as "not obviously text" — biased toward identity, which
    // is the safe direction.
    if (function_exists('mb_check_encoding') && !mb_check_encoding($head, 'UTF-8')) {
        return false;
    }
    return true;
}

/**
 * Returns true if $path is a generated cache file, version-control or
 * dev-tooling artifact, or OS-level junk that is not worth shipping in
 * a typical site migration.
 *
 * Matching rules:
 *
 *   - Path-component-aware: a segment that *contains* a skipped name as a
 *     substring (e.g. "cache-control" or "node_modules-backup") does NOT
 *     trigger a skip. Only whole-segment matches do. This is done by
 *     wrapping `/` around both the haystack and needle and doing a
 *     substring check.
 *
 *   - Cache/upgrade dirs are matched only under `wp-content/` so a user
 *     directory literally called `cache` in some other tree doesn't
 *     silently disappear.
 *
 *   - Dotfiles that ship in real WordPress sites — `.htaccess`,
 *     `.user.ini`, `.well-known/` — are preserved. Editor/VCS dotfiles
 *     and macOS metadata are not.
 *
 * The default deny-list is conservative: false-negatives (something we
 * could have skipped but didn't) are mere wire-byte waste; false-positives
 * (something the user actually wanted) are silent data loss. Callers
 * opting in to a more aggressive filter can pass extra patterns; callers
 * who want everything can set include_caches=1 on the request.
 */
function path_is_default_skipped(string $path): bool
{
    // Sentinel slashes on each side make "starts-with" / "ends-with" /
    // "anywhere-in-middle" the same str_contains() check.
    $needle_haystack = '/' . trim($path, '/') . '/';

    // Generated content under wp-content/. WordPress regenerates these
    // on demand (cache via the page lifecycle, upgrade via wp-admin
    // updates), so transferring them is pure waste.
    //
    // Notable specific entries:
    //   - wp-content/wpcomsh-cache: wp.com Atomic's Memcached-backed
    //     filesystem cache shadow.
    //   - wp-content/wflogs: Wordfence's per-request scan logs; can
    //     reach gigabytes on long-running sites.
    static $cache_dirs = [
        '/wp-content/cache/',
        '/wp-content/upgrade/',
        '/wp-content/wpcomsh-cache/',
        '/wp-content/wflogs/',
    ];
    foreach ($cache_dirs as $needle) {
        if (strpos($needle_haystack, $needle) !== false) {
            return true;
        }
    }

    // VCS metadata + local dev tooling. Match any path component exactly.
    static $junk_components = [
        '.git', '.svn', '.hg', '.bzr',
        'node_modules',
        '.idea', '.vscode',
        '.cache', '.npm', '.yarn', '.pnpm-store',
    ];
    foreach ($junk_components as $needle) {
        if (strpos($needle_haystack, '/' . $needle . '/') !== false) {
            return true;
        }
    }

    // OS junk + filesystem metadata files (basename match).
    $basename = basename($path);
    static $junk_basenames = [
        '.DS_Store', '._.DS_Store',
        'Thumbs.db', 'desktop.ini', 'ehthumbs.db',
    ];
    if (in_array($basename, $junk_basenames, true)) {
        return true;
    }

    // Editor / merge scratch files (basename pattern):
    //   `.#name`      Emacs lock
    //   `#name#`      Emacs autosave
    //   `name~`       Editor backup
    //   `name.swp`    Vim swap (also .swo, .swn)
    //   `name.bak`    generic backup
    //   `name.orig`   merge conflict leftover
    //   `name.rej`    merge conflict leftover
    if ($basename !== '' && $basename[0] === '.' && isset($basename[1]) && $basename[1] === '#') {
        return true;
    }
    if (strlen($basename) >= 3 && $basename[0] === '#' && substr($basename, -1) === '#') {
        return true;
    }
    if (preg_match('/(?:~|\.(?:swp|swo|swn|bak|orig|rej))$/', $basename) === 1) {
        return true;
    }

    return false;
}

/**
 * Validates that an integer falls within the given range, or throws.
 */
function require_int_range(
    string $name,
    int $value,
    int $min,
    int $max
): int {
    if ($value < $min || $value > $max) {
        throw new InvalidArgumentException(
            "{$name} out of range. Expected {$min}-{$max}, got {$value}",
        );
    }
    return $value;
}

/**
 * Validates that a float falls within the given range, or throws.
 */
function require_float_range(
    string $name,
    float $value,
    float $min,
    float $max
): float {
    if ($value < $min || $value > $max) {
        throw new InvalidArgumentException(
            "{$name} out of range. Expected {$min}-{$max}, got {$value}",
        );
    }
    return $value;
}

/**
 * Returns the index of the first entry lexicographically after $after (binary search).
 */
function position_after_entry(array $entries, string $after): int
{
    $low = 0;
    $high = count($entries);
    while ($low < $high) {
        $mid = (int) (($low + $high) / 2);
        $entry = $entries[$mid];
        if (strcmp($entry, $after) <= 0) {
            $low = $mid + 1;
        } else {
            $high = $mid;
        }
    }
    return $low;
}

/**
 * Builds the config array from HTTP GET/POST parameters and optional JSON body.
 */
function parse_http_config(): array
{
    $body = file_get_contents('php://input');
    if ($body === false) {
        $body = '';
    }

    $server = new Site_Export_HTTP_Server();
    return $server->parse_http_config($_GET, $_POST, $_SERVER, $body);
}
