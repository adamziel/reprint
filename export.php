<?php
// Capture any accidental output before headers are set so we can discard it
// when switching to streaming mode later.
if (!ob_get_level()) {
    ob_start();
}
/**
 * Unified export API for SQL and file operations.
 *
 * Provides function-based interface for:
 * - SQL database exports with cursor-based resumption
 * - File synchronization with cursor-based resumption
 *
 * CURSOR ENCODING CONTRACT:
 * - Internal: Cursors are JSON strings (e.g., {"p":"streaming","n":123})
 * - HTTP transmission: Cursors are base64-encoded in X-Cursor header (outgoing) and X-Export-Cursor header (incoming)
 * - This file is responsible for encoding when sending and decoding when receiving
 * - Producers (FileTreeProducer, MySQLDumpProducer) work with JSON strings only, never base64
 */

// Global error handler to send errors back to client
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    $error = [
        "error" => "PHP Error: $errstr",
        "file" => $errfile,
        "line" => $errline,
        "type" => $errno,
    ];
    error_log("Export error: " . json_encode($error));
    http_response_code(500);
    @header("Content-Type: application/json");
    echo json_encode($error);
    exit(1);
});

set_exception_handler(function ($e) {
    $error = [
        "error" => get_class($e) . ": " . $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine(),
        "trace" => $e->getTraceAsString(),
    ];
    error_log("Export exception: " . json_encode($error));
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode($error);
    exit(1);
});

if (file_exists("./secrets.php")) {
    require_once "./secrets.php";
}

if (
    !defined("SECRET_KEY") ||
    !isset($_GET["SECRET_KEY"]) ||
    $_GET["SECRET_KEY"] !== SECRET_KEY
) {
    http_response_code(403);
    error_log("Invalid secret key");
    die("Invalid secret key");
}

// Uncomment if you want to declare the configuration here instead of
// passing it via env or $_GET:
if (false) {
    define("DB_HOST", "your-db-host");
    define("DB_USER", "your-db-user");
    define("DB_PASSWORD", "your-db-password");
    define("DB_NAME", "your-db-name");
}

// Export bounds (adjust here if needed)
if (!defined("EXPORT_MIN_EXECUTION_TIME")) {
    define("EXPORT_MIN_EXECUTION_TIME", 1);
}
if (!defined("EXPORT_MAX_EXECUTION_TIME")) {
    define("EXPORT_MAX_EXECUTION_TIME", 60);
}
if (!defined("EXPORT_MIN_MEMORY_THRESHOLD")) {
    define("EXPORT_MIN_MEMORY_THRESHOLD", 0.1);
}
if (!defined("EXPORT_MAX_MEMORY_THRESHOLD")) {
    define("EXPORT_MAX_MEMORY_THRESHOLD", 0.95);
}
if (!defined("EXPORT_MIN_CHUNK_SIZE")) {
    define("EXPORT_MIN_CHUNK_SIZE", 16 * 1024);
}
if (!defined("EXPORT_MAX_CHUNK_SIZE")) {
    define("EXPORT_MAX_CHUNK_SIZE", 32 * 1024 * 1024);
}
if (!defined("EXPORT_MIN_INDEX_BATCH")) {
    define("EXPORT_MIN_INDEX_BATCH", 100);
}
if (!defined("EXPORT_MAX_INDEX_BATCH")) {
    define("EXPORT_MAX_INDEX_BATCH", 100000);
}
if (!defined("EXPORT_MIN_SQL_FRAGMENTS")) {
    define("EXPORT_MIN_SQL_FRAGMENTS", 1);
}
if (!defined("EXPORT_MAX_SQL_FRAGMENTS")) {
    define("EXPORT_MAX_SQL_FRAGMENTS", 10000);
}
if (!defined("EXPORT_MIN_TABLES_BATCH")) {
    define("EXPORT_MIN_TABLES_BATCH", 10);
}
if (!defined("EXPORT_MAX_TABLES_BATCH")) {
    define("EXPORT_MAX_TABLES_BATCH", 10000);
}
if (!defined("EXPORT_MIN_DB_QUERY_TIME_MS")) {
    define("EXPORT_MIN_DB_QUERY_TIME_MS", 0);
}
if (!defined("EXPORT_MAX_DB_QUERY_TIME_MS")) {
    define("EXPORT_MAX_DB_QUERY_TIME_MS", 300000);
}

require_once __DIR__ . "/class-mysql-dump-producer.php";
require_once __DIR__ . "/file-sync.php";

/**
 * Best-effort streaming response setup.
 *
 * Disables output buffering, compression layers, and proxy buffering where possible.
 */
function prepare_streaming_response(): void
{
    // Discard any buffered output before we emit headers or stream data.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (!headers_sent()) {
        @header("X-Accel-Buffering: no");
        @header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        @header("Pragma: no-cache");
        @header("Expires: 0");
    }

    @ini_set("zlib.output_compression", "0");
    @ini_set("output_buffering", "0");
    @ini_set("implicit_flush", "1");

    @ob_implicit_flush(true);
    flush();
}

/**
 * Streaming gzip output wrapper.
 *
 * Compresses output incrementally without buffering the entire response.
 * Uses deflate_add() with ZLIB_SYNC_FLUSH to emit compressed data immediately.
 */
class GzipOutputStream
{
    private $deflate_ctx;
    private bool $header_sent = false;
    private bool $enabled = true;

    public function __construct(bool $enabled = true)
    {
        $this->enabled = $enabled;
        if ($this->enabled) {
            $this->deflate_ctx = deflate_init(ZLIB_ENCODING_GZIP, ["level" => 6]);
            if (!headers_sent()) {
                @header("Content-Encoding: gzip");
            }
        }
    }

    /**
     * Write data to the gzip stream.
     */
    public function write(string $data): void
    {
        if (!$this->enabled) {
            echo $data;
            return;
        }
        $compressed = deflate_add(
            $this->deflate_ctx,
            $data,
            ZLIB_SYNC_FLUSH,
        );
        if ($compressed !== false && $compressed !== "") {
            echo $compressed;
        }
    }

    /**
     * Flush the output buffer.
     */
    public function flush(): void
    {
        flush();
    }

    /**
     * Finalize the gzip stream.
     */
    public function finish(): void
    {
        if (!$this->enabled) {
            flush();
            return;
        }
        $final = deflate_add($this->deflate_ctx, "", ZLIB_FINISH);
        if ($final !== false && $final !== "") {
            echo $final;
        }
        flush();
    }
}

/**
 * Extract database credentials from wp-config.php using PHP tokenizer.
 *
 * @param array $directories Array of directory paths to search for wp-config.php
 * @return array|null Array with db_host, db_name, db_user, db_password, table_prefix, wp_config_path or null
 */
function extract_db_credentials_from_wp_config(array $directories): ?array
{
    // Search for wp-config.php in provided directories
    $wp_config_path = null;
    foreach ($directories as $dir) {
        $path = rtrim($dir, "/") . "/wp-config.php";
        if (file_exists($path)) {
            $wp_config_path = $path;
            break;
        }
    }

    if ($wp_config_path === null) {
        return null;
    }

    try {
        $content = file_get_contents($wp_config_path);
        if ($content === false) {
            return null;
        }

        $tokens = token_get_all($content);
        $credentials = [];

        // State machine to parse define('CONSTANT', 'value')
        $state = "search"; // search, found_define, found_open_paren, found_constant, found_comma
        $current_constant = null;

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            // Skip whitespace
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            if ($state === "search") {
                // Look for 'define' function call
                if (
                    is_array($token) &&
                    $token[0] === T_STRING &&
                    strtolower($token[1]) === "define"
                ) {
                    $state = "found_define";
                }
            } elseif ($state === "found_define") {
                // Expect opening parenthesis
                if ($token === "(") {
                    $state = "found_open_paren";
                } else {
                    $state = "search";
                }
            } elseif ($state === "found_open_paren") {
                // Expect constant name (string)
                if (
                    is_array($token) &&
                    $token[0] === T_CONSTANT_ENCAPSED_STRING
                ) {
                    $constant_name = trim($token[1], '\'"');
                    if (
                        in_array($constant_name, [
                            "DB_HOST",
                            "DB_NAME",
                            "DB_USER",
                            "DB_PASSWORD",
                        ])
                    ) {
                        $current_constant = $constant_name;
                        $state = "found_constant";
                    } else {
                        $state = "search";
                    }
                } else {
                    $state = "search";
                }
            } elseif ($state === "found_constant") {
                // Expect comma
                if ($token === ",") {
                    $state = "found_comma";
                } else {
                    $state = "search";
                }
            } elseif ($state === "found_comma") {
                // Expect value (string)
                if (
                    is_array($token) &&
                    $token[0] === T_CONSTANT_ENCAPSED_STRING
                ) {
                    $value = trim($token[1], '\'"');
                    $credentials[$current_constant] = $value;
                }
                $state = "search";
            }
        }

        // Check if we found all required credentials
        $required = ["DB_HOST", "DB_NAME", "DB_USER", "DB_PASSWORD"];
        foreach ($required as $key) {
            if (!isset($credentials[$key])) {
                return null;
            }
        }

        $table_prefix = null;
        $prefix_state = "search";
        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (
                is_array($token) &&
                ($token[0] === T_WHITESPACE ||
                    $token[0] === T_COMMENT ||
                    $token[0] === T_DOC_COMMENT)
            ) {
                continue;
            }

            if ($prefix_state === "search") {
                if (
                    is_array($token) &&
                    $token[0] === T_VARIABLE &&
                    $token[1] === "\$table_prefix"
                ) {
                    $prefix_state = "found_var";
                }
                continue;
            }

            if ($prefix_state === "found_var") {
                if ($token === "=") {
                    $prefix_state = "found_equals";
                } else {
                    $prefix_state = "search";
                }
                continue;
            }

            if ($prefix_state === "found_equals") {
                if (
                    is_array($token) &&
                    $token[0] === T_CONSTANT_ENCAPSED_STRING
                ) {
                    $table_prefix = trim($token[1], '\'"');
                    break;
                }
                $prefix_state = "search";
            }
        }

        return [
            "db_host" => $credentials["DB_HOST"],
            "db_name" => $credentials["DB_NAME"],
            "db_user" => $credentials["DB_USER"],
            "db_password" => $credentials["DB_PASSWORD"],
            "table_prefix" => $table_prefix,
            "wp_config_path" => $wp_config_path,
        ];
    } catch (Exception $e) {
        error_log(
            "Failed to extract credentials from wp-config.php: " .
                $e->getMessage(),
        );
        return null;
    }
}

/**
 * Normalize a list of paths into unique, non-empty, absolute-ish entries.
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
 * Walk parent directories to detect WordPress roots.
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
 * Endpoint: Get next chunk of SQL data.
 *
 * @param array $config Configuration with optional cursor for resumption
 * @param float $script_start Script execution start time
 * @param int $max_execution_time Maximum execution time in seconds
 * @param int $max_memory Maximum memory in bytes
 * @param float $memory_threshold Memory usage threshold (0.0-1.0)
 * @return array Result with status and stats
 */
function endpoint_sql_chunk(
    array $config,
    float $script_start,
    int $max_execution_time,
    int $max_memory,
    float $memory_threshold,
): array {
    prepare_streaming_response();
    // Try to get credentials from config, constants, env vars, or wp-config.php
    $db_host =
        $config["db_host"] ??
        (defined("DB_HOST") ? DB_HOST : getenv("DB_HOST"));
    $db_name =
        $config["db_name"] ??
        (defined("DB_NAME") ? DB_NAME : getenv("DB_NAME"));
    $db_user =
        $config["db_user"] ??
        (defined("DB_USER") ? DB_USER : getenv("DB_USER"));
    $db_password =
        $config["db_password"] ??
        (defined("DB_PASSWORD") ? DB_PASSWORD : getenv("DB_PASSWORD"));

    // If any credentials are missing, try to extract from wp-config.php
    // Use directory parameter to locate wp-config.php
    if (!$db_host || !$db_name || !$db_user || $db_password === false) {
        $directories = [];
        if (isset($config["directory"])) {
            $directories = is_array($config["directory"])
                ? $config["directory"]
                : [$config["directory"]];
        }

        if (!empty($directories)) {
            $wp_credentials = extract_db_credentials_from_wp_config(
                $directories,
            );
            if ($wp_credentials !== null) {
                $db_host = $db_host ?: $wp_credentials["db_host"];
                $db_name = $db_name ?: $wp_credentials["db_name"];
                $db_user = $db_user ?: $wp_credentials["db_user"];
                $db_password =
                    $db_password !== false
                        ? $db_password
                        : $wp_credentials["db_password"];
            }
        }
    }

    // Validate that we have all required credentials
    if (!$db_host || !$db_name || !$db_user || $db_password === false) {
        throw new InvalidArgumentException(
            "Database credentials not found. Please provide via config, environment variables, " .
                "PHP constants, or ensure wp-config.php exists with valid credentials. " .
                "Missing: " .
                (!$db_host ? "db_host " : "") .
                (!$db_name ? "db_name " : "") .
                (!$db_user ? "db_user " : "") .
                ($db_password === false ? "db_password" : ""),
        );
    }

    $fragments_per_batch = $config["fragments_per_batch"] ?? 1000;
    $fragments_per_batch = require_int_range(
        "fragments_per_batch",
        (int) $fragments_per_batch,
        EXPORT_MIN_SQL_FRAGMENTS,
        EXPORT_MAX_SQL_FRAGMENTS,
    );

    // Initialize MySQL connection
    $pdo_options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ];
    if (!empty($config["db_unbuffered"])) {
        $pdo_options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;
    }
    $mysql = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_password,
        $pdo_options,
    );

    // Producer options
    $producer_options = [
        "create_table_query" => $config["create_table_query"] ?? true,
        "string_encoding" => $config["string_encoding"] ?? "base64",
    ];
    if (!empty($config["db_query_time_limit"])) {
        $query_time_limit = require_int_range(
            "db_query_time_limit",
            (int) $config["db_query_time_limit"],
            EXPORT_MIN_DB_QUERY_TIME_MS,
            EXPORT_MAX_DB_QUERY_TIME_MS,
        );
        if ($query_time_limit > 0) {
            $producer_options["query_time_limit_ms"] = $query_time_limit;
        }
    }

    if (isset($config["cursor"])) {
        $producer_options["cursor"] = $config["cursor"];
    }

    $reader = new WordPress\DataLiberation\MySQLDumpProducer(
        $mysql,
        $producer_options,
    );

    // Disable output buffering for immediate response
    if (ob_get_level()) {
        ob_end_flush();
    }

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
    if (!$can_send_headers) {
        throw new RuntimeException(
            "Cannot stream sql_preflight: headers already sent",
        );
    }
    @header("Content-Type: multipart/mixed; boundary=\"$boundary\"");
    $gz = new GzipOutputStream(true);

    $batches_processed = 0;
    $sql_bytes_processed = 0;

    // Process batches
    while (
        should_continue(
            $script_start,
            $max_execution_time,
            $max_memory,
            $memory_threshold,
        )
    ) {
        $batch_start = microtime(true);
        $sql = [];

        // Collect fragments for this batch
        $i = 0;
        while ($reader->next_sql_fragment()) {
            $sql[] = $reader->get_sql_fragment();
            $i++;

            if ($i >= $fragments_per_batch) {
                break;
            }

            if (
                !should_continue(
                    $script_start,
                    $max_execution_time,
                    $max_memory,
                    $memory_threshold,
                )
            ) {
                break;
            }
        }
        $sql = implode("", $sql);
        $sql_bytes_processed += strlen($sql);

        // Output SQL batch as multipart chunk
        $cursor = $reader->get_reentrancy_cursor();
        $gz->write("--{$boundary}\r\n");
        $gz->write("Content-Type: application/sql\r\n");
        $gz->write("Content-Length: " . strlen($sql) . "\r\n");
        $gz->write("X-Chunk-Type: sql\r\n");
        $gz->write("X-Cursor: " . base64_encode($cursor) . "\r\n");
        $gz->write("\r\n");
        $gz->write($sql);
        $gz->write("\r\n");
        $gz->flush();

        $batches_processed++;

        if ($reader->is_finished()) {
            break;
        }
    }

    // Output completion chunk with stats in headers
    $status = $reader->is_finished() ? "complete" : "partial";

    $gz->write("--{$boundary}\r\n");
    $gz->write("Content-Type: application/octet-stream\r\n");
    $gz->write("Content-Length: 0\r\n");
    $gz->write("X-Chunk-Type: completion\r\n");
    $gz->write("X-Status: {$status}\r\n");
    $gz->write("X-Batches-Processed: {$batches_processed}\r\n");
    $gz->write("X-SQL-Bytes: {$sql_bytes_processed}\r\n");
    $gz->write("X-Memory-Used: " . memory_get_peak_usage(true) . "\r\n");
    $gz->write("X-Memory-Limit: " . $max_memory . "\r\n");
    $gz->write("X-Time-Elapsed: " . (microtime(true) - $script_start) . "\r\n");
    $gz->write("\r\n");
    $gz->write("\r\n");

    // Close multipart
    $gz->write("--{$boundary}--\r\n");
    $gz->finish();

    return [
        "status" => $status,
        "stats" => [
            "batches_processed" => $batches_processed,
            "sql_bytes" => $sql_bytes_processed,
            "memory_used" => memory_get_peak_usage(true),
            "time_elapsed" => microtime(true) - $script_start,
        ],
    ];
}

/**
 * Endpoint: Stream table stats from INFORMATION_SCHEMA.
 *
 * Returns table name, estimated rows, and size information in chunks.
 *
 * @param array $config Configuration with optional cursor for resumption
 * @param float $script_start Script execution start time
 * @param int $max_execution_time Maximum execution time in seconds
 * @param int $max_memory Maximum memory in bytes
 * @param float $memory_threshold Memory usage threshold (0.0-1.0)
 * @return array Result with status and stats
 */
function endpoint_sql_preflight(
    array $config,
    float $script_start,
    int $max_execution_time,
    int $max_memory,
    float $memory_threshold,
): array {
    prepare_streaming_response();

    $db_host =
        $config["db_host"] ??
        (defined("DB_HOST") ? DB_HOST : getenv("DB_HOST"));
    $db_name =
        $config["db_name"] ??
        (defined("DB_NAME") ? DB_NAME : getenv("DB_NAME"));
    $db_user =
        $config["db_user"] ??
        (defined("DB_USER") ? DB_USER : getenv("DB_USER"));
    $db_password =
        $config["db_password"] ??
        (defined("DB_PASSWORD") ? DB_PASSWORD : getenv("DB_PASSWORD"));

    if (!$db_host || !$db_name || !$db_user || $db_password === false) {
        $directories = [];
        if (isset($config["directory"])) {
            $directories = is_array($config["directory"])
                ? $config["directory"]
                : [$config["directory"]];
        }

        if (!empty($directories)) {
            $wp_credentials = extract_db_credentials_from_wp_config(
                $directories,
            );
            if ($wp_credentials !== null) {
                $db_host = $db_host ?: $wp_credentials["db_host"];
                $db_name = $db_name ?: $wp_credentials["db_name"];
                $db_user = $db_user ?: $wp_credentials["db_user"];
                $db_password =
                    $db_password !== false
                        ? $db_password
                        : $wp_credentials["db_password"];
            }
        }
    }

    if (!$db_host || !$db_name || !$db_user || $db_password === false) {
        throw new InvalidArgumentException(
            "Database credentials not found for sql_preflight.",
        );
    }

    $tables_per_batch = $config["tables_per_batch"] ?? 1000;
    $tables_per_batch = require_int_range(
        "tables_per_batch",
        (int) $tables_per_batch,
        EXPORT_MIN_TABLES_BATCH,
        EXPORT_MAX_TABLES_BATCH,
    );

    $cursor = null;
    if (isset($config["cursor"])) {
        $cursor = json_decode($config["cursor"], true);
        if ($cursor === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                "Invalid cursor format: " . json_last_error_msg(),
            );
        }
    }
    $last_table = $cursor["last_table"] ?? "";

    $mysql = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $boundary = "boundary-" . bin2hex(random_bytes(16));
    $can_send_headers = !headers_sent();
    if ($can_send_headers) {
        @header("Content-Type: multipart/mixed; boundary=\"$boundary\"");
    }
    $gz = new GzipOutputStream($can_send_headers);

    $tables_processed = 0;
    $rows_estimated = 0;
    $status = "partial";

    while (
        should_continue(
            $script_start,
            $max_execution_time,
            $max_memory,
            $memory_threshold,
        )
    ) {
        $sql =
            "SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, ENGINE, " .
            "TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME > :last " .
            "ORDER BY TABLE_NAME ASC LIMIT {$tables_per_batch}";
        $stmt = $mysql->prepare($sql);
        $stmt->bindValue(":last", $last_table, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            $status = "complete";
            break;
        }

        $tables = [];
        foreach ($rows as $row) {
            $name = (string) ($row["TABLE_NAME"] ?? "");
            $tables[] = [
                "name" => $name,
                "rows" =>
                    isset($row["TABLE_ROWS"]) && is_numeric($row["TABLE_ROWS"])
                        ? (int) $row["TABLE_ROWS"]
                        : null,
                "data_bytes" =>
                    isset($row["DATA_LENGTH"]) && is_numeric($row["DATA_LENGTH"])
                        ? (int) $row["DATA_LENGTH"]
                        : null,
                "index_bytes" =>
                    isset($row["INDEX_LENGTH"]) && is_numeric($row["INDEX_LENGTH"])
                        ? (int) $row["INDEX_LENGTH"]
                        : null,
                "engine" => $row["ENGINE"] ?? null,
                "collation" => $row["TABLE_COLLATION"] ?? null,
            ];
            $last_table = $name;
            $tables_processed++;
            if (
                isset($row["TABLE_ROWS"]) &&
                is_numeric($row["TABLE_ROWS"])
            ) {
                $rows_estimated += (int) $row["TABLE_ROWS"];
            }
        }

        $payload = json_encode($tables);
        $cursor_json = json_encode([
            "phase" => "tables",
            "last_table" => $last_table,
        ]);

        $gz->write("--{$boundary}\r\n");
        $gz->write("Content-Type: application/json\r\n");
        $gz->write("Content-Length: " . strlen($payload) . "\r\n");
        $gz->write("X-Chunk-Type: table_stats\r\n");
        $gz->write("X-Tables: " . count($tables) . "\r\n");
        $gz->write("X-Cursor: " . base64_encode($cursor_json) . "\r\n");
        $gz->write("\r\n");
        $gz->write($payload);
        $gz->write("\r\n");
        $gz->flush();

        if (count($rows) < $tables_per_batch) {
            $status = "complete";
            break;
        }
    }

    $gz->write("--{$boundary}\r\n");
    $gz->write("Content-Type: application/octet-stream\r\n");
    $gz->write("Content-Length: 0\r\n");
    $gz->write("X-Chunk-Type: completion\r\n");
    $gz->write("X-Status: {$status}\r\n");
    $gz->write("X-Tables-Processed: {$tables_processed}\r\n");
    $gz->write("X-Rows-Estimated: {$rows_estimated}\r\n");
    $gz->write("X-Memory-Used: " . memory_get_peak_usage(true) . "\r\n");
    $gz->write("X-Memory-Limit: " . $max_memory . "\r\n");
    $gz->write("X-Time-Elapsed: " . (microtime(true) - $script_start) . "\r\n");
    $gz->write("\r\n");
    $gz->write("\r\n");

    $gz->write("--{$boundary}--\r\n");
    $gz->finish();

    return [
        "status" => $status,
        "stats" => [
            "tables_processed" => $tables_processed,
            "rows_estimated" => $rows_estimated,
            "memory_used" => memory_get_peak_usage(true),
            "time_elapsed" => microtime(true) - $script_start,
        ],
    ];
}

/**
 * Resolve and validate directories from config.
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
        if ($directory[0] === "~") {
            $home = getenv("HOME") ?: (getenv("USERPROFILE") ?: "/");
            $directory = $home . substr($directory, 1);
        }

        if ($directory[0] !== "/") {
            $directory = __DIR__ . "/" . $directory;
        }

        $real_directory = realpath($directory);
        if ($real_directory === false) {
            throw new InvalidArgumentException(
                "directory does not exist or is not accessible: {$directory}\n" .
                    "Current working directory: " .
                    getcwd() .
                    "\n" .
                    "Script directory: " .
                    __DIR__ .
                    "\n" .
                    "User: " .
                    (function_exists("posix_getpwuid")
                        ? posix_getpwuid(posix_geteuid())["name"]
                        : "unknown"),
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
 * Endpoint: Lightweight preflight checks and runtime info.
 *
 * Confirms filesystem accessibility and basic DB connectivity, and reports
 * environment details useful for diagnostics. This endpoint avoids heavy work.
 *
 * @param array $config Configuration with directory and optional DB overrides.
 * @return array Result with status and stats.
 */
function endpoint_preflight(array $config): array
{
    $directories = [];
    $dir_error = null;
    $has_root_input = array_key_exists("directory", $config) && $config["directory"] !== null;
    if ($has_root_input) {
        try {
            $directories = resolve_directories($config);
        } catch (Exception $e) {
            $dir_error = $e->getMessage();
        }
    }

    $search_roots = [];
    if (!empty($directories)) {
        $search_roots = $directories;
    } else {
        $search_roots = normalize_path_list(
            array_filter(
                [
                    getcwd() ?: null,
                    __DIR__,
                    $_SERVER["DOCUMENT_ROOT"] ?? null,
                ],
                fn($value) => $value !== null && $value !== "",
            ),
        );
    }

    $wp_detect = detect_wp_roots($search_roots);
    $detected_root_paths = [];
    foreach ($wp_detect["roots"] as $root) {
        if (!empty($root["path"])) {
            $detected_root_paths[] = $root["path"];
        }
    }
    $detected_root_paths = normalize_path_list($detected_root_paths);

    $wp_load_path = null;
    foreach ($wp_detect["roots"] as $root) {
        if (!empty($root["wp_load_path"]) && is_readable($root["wp_load_path"])) {
            $wp_load_path = $root["wp_load_path"];
            break;
        }
    }
    $preflight_error = null;
    if (!$has_root_input && $wp_load_path === null) {
        $preflight_error =
            "wp-load.php not found and no root directories were provided";
    }

    $scan_roots = !empty($directories) ? $directories : $detected_root_paths;
    if (empty($scan_roots)) {
        $scan_roots = $search_roots;
    }
    $scan_roots = normalize_path_list($scan_roots);

    $wp_scan_roots = normalize_path_list(
        array_merge($scan_roots, $detected_root_paths),
    );

    $dir_checks = [];
    $htaccess_files = [];
    $wp_paths = [];
    if (!empty($scan_roots)) {
        foreach ($scan_roots as $dir) {
            $exists = is_dir($dir);
            $readable = $exists && is_readable($dir);
            $openable = false;
            $disk_free = null;
            $disk_total = null;
            if ($readable) {
                $dh = @opendir($dir);
                if ($dh !== false) {
                    $openable = true;
                    // Touch one entry to confirm traversal without scanning.
                    @readdir($dh);
                    closedir($dh);
                }
            }
            if ($openable) {
                $disk_free = @disk_free_space($dir);
                $disk_total = @disk_total_space($dir);
            }
            $dir_checks[] = [
                "path" => $dir,
                "exists" => $exists,
                "readable" => $readable,
                "openable" => $openable,
                "disk_free_bytes" => $disk_free !== false ? $disk_free : null,
                "disk_total_bytes" => $disk_total !== false ? $disk_total : null,
            ];

            $htaccess_path = rtrim($dir, "/") . "/.htaccess";
            if (file_exists($htaccess_path)) {
                $htaccess_readable = is_readable($htaccess_path);
                $htaccess_size = @filesize($htaccess_path);
                $htaccess_mtime = @filemtime($htaccess_path);
                $htaccess_content = null;
                $htaccess_truncated = false;
                if ($htaccess_readable) {
                    $limit = 8192;
                    $fh = @fopen($htaccess_path, "r");
                    if ($fh) {
                        $data = @fread($fh, $limit + 1);
                        fclose($fh);
                        if ($data !== false) {
                            if (strlen($data) > $limit) {
                                $htaccess_truncated = true;
                                $data = substr($data, 0, $limit);
                            }
                            $htaccess_content = $data;
                        }
                    }
                }
                $htaccess_files[] = [
                    "path" => $htaccess_path,
                    "readable" => $htaccess_readable,
                    "size_bytes" => $htaccess_size !== false ? $htaccess_size : null,
                    "mtime" => $htaccess_mtime !== false ? $htaccess_mtime : null,
                    "content" => $htaccess_content,
                    "truncated" => $htaccess_truncated,
                ];
            }

            $plugins_dir = rtrim($dir, "/") . "/wp-content/plugins";
            $mu_plugins_dir = rtrim($dir, "/") . "/wp-content/mu-plugins";
            $themes_dir = rtrim($dir, "/") . "/wp-content/themes";
            $wp_paths[] = [
                "root" => $dir,
                "plugins_dir" => $plugins_dir,
                "mu_plugins_dir" => $mu_plugins_dir,
                "themes_dir" => $themes_dir,
            ];
        }
    }

    if (!empty($wp_scan_roots)) {
        foreach ($wp_scan_roots as $dir) {
            $plugins_dir = rtrim($dir, "/") . "/wp-content/plugins";
            $mu_plugins_dir = rtrim($dir, "/") . "/wp-content/mu-plugins";
            $themes_dir = rtrim($dir, "/") . "/wp-content/themes";
            $wp_paths[] = [
                "root" => $dir,
                "plugins_dir" => $plugins_dir,
                "mu_plugins_dir" => $mu_plugins_dir,
                "themes_dir" => $themes_dir,
            ];
        }
    }

    $wp_paths = normalize_path_list(
        array_map(
            fn($entry) => $entry["root"] ?? null,
            $wp_paths,
        ),
    );
    $wp_paths = array_map(function ($root) {
        $root = rtrim($root, "/");
        return [
            "root" => $root,
            "plugins_dir" => $root . "/wp-content/plugins",
            "mu_plugins_dir" => $root . "/wp-content/mu-plugins",
            "themes_dir" => $root . "/wp-content/themes",
        ];
    }, $wp_paths);

    $filesystem_ok = true;
    if ($dir_error !== null) {
        $filesystem_ok = false;
    } elseif (!empty($dir_checks)) {
        foreach ($dir_checks as $check) {
            if (empty($check["openable"])) {
                $filesystem_ok = false;
                break;
            }
        }
    } elseif ($wp_load_path === null) {
        $filesystem_ok = false;
    }

    $memory_limit_raw = ini_get("memory_limit");
    $memory_limit_bytes = null;
    if ($memory_limit_raw !== false && $memory_limit_raw !== "") {
        if ($memory_limit_raw === "-1") {
            $memory_limit_bytes = PHP_INT_MAX;
        } else {
            $memory_limit_bytes = parse_memory_limit($memory_limit_raw);
        }
    }
    $memory_used = memory_get_usage(true);
    $memory_available =
        $memory_limit_bytes !== null && $memory_limit_bytes !== PHP_INT_MAX
            ? max(0, $memory_limit_bytes - $memory_used)
            : null;
    $post_max_size_raw = ini_get("post_max_size");
    $upload_max_filesize_raw = ini_get("upload_max_filesize");
    $post_max_bytes =
        $post_max_size_raw !== false && $post_max_size_raw !== ""
            ? parse_memory_limit($post_max_size_raw)
            : null;
    $upload_max_bytes =
        $upload_max_filesize_raw !== false && $upload_max_filesize_raw !== ""
            ? parse_memory_limit($upload_max_filesize_raw)
            : null;
    $max_request_bytes = null;
    if ($post_max_bytes !== null && $upload_max_bytes !== null) {
        $max_request_bytes = min($post_max_bytes, $upload_max_bytes);
    } elseif ($post_max_bytes !== null) {
        $max_request_bytes = $post_max_bytes;
    } elseif ($upload_max_bytes !== null) {
        $max_request_bytes = $upload_max_bytes;
    }

    $extensions = get_loaded_extensions();
    sort($extensions, SORT_STRING);
    $extension_versions = [];
    foreach ([
        "curl",
        "gd",
        "imagick",
        "pdo_mysql",
        "mysqli",
        "mbstring",
        "zlib",
        "openssl",
        "fileinfo",
        "exif",
    ] as $ext) {
        if (extension_loaded($ext)) {
            $ver = phpversion($ext);
            $extension_versions[$ext] = $ver !== false ? $ver : true;
        }
    }

    $gd_info = function_exists("gd_info") ? gd_info() : null;
    $gd_formats = null;
    $gd_version = null;
    if (is_array($gd_info)) {
        $gd_version = $gd_info["GD Version"] ?? null;
        $gd_formats = [
            "gif_create" => (bool) ($gd_info["GIF Create Support"] ?? false),
            "gif_read" => (bool) ($gd_info["GIF Read Support"] ?? false),
            "jpeg" => (bool) ($gd_info["JPEG Support"] ?? false),
            "png" => (bool) ($gd_info["PNG Support"] ?? false),
            "webp" => (bool) ($gd_info["WebP Support"] ?? false),
            "avif" => (bool) ($gd_info["AVIF Support"] ?? false),
            "bmp" => (bool) ($gd_info["BMP Support"] ?? false),
            "wbmp" => (bool) ($gd_info["WBMP Support"] ?? false),
            "xpm" => (bool) ($gd_info["XPM Support"] ?? false),
        ];
    }
    $imagick_version = extension_loaded("imagick")
        ? (phpversion("imagick") ?: null)
        : null;

    $db = [
        "credentials_found" => false,
        "connected" => false,
        "can_query" => false,
        "version" => null,
        "db_charset" => null,
        "db_collation" => null,
        "server_charset" => null,
        "server_collation" => null,
        "table_listable" => null,
        "table_list_error" => null,
        "wp" => [
            "wp_config_path" => null,
            "wp_load_path" => null,
            "wp_load_attempted" => false,
            "wp_load_loaded" => false,
            "wp_load_error" => null,
            "table_prefix" => null,
            "options_table" => null,
            "active_plugins" => null,
            "active_sitewide_plugins" => null,
            "theme_template" => null,
            "theme_stylesheet" => null,
            "siteurl" => null,
            "home" => null,
            "error" => null,
        ],
        "error" => null,
    ];

    $db_host =
        $config["db_host"] ??
        (defined("DB_HOST") ? DB_HOST : getenv("DB_HOST"));
    $db_name =
        $config["db_name"] ??
        (defined("DB_NAME") ? DB_NAME : getenv("DB_NAME"));
    $db_user =
        $config["db_user"] ??
        (defined("DB_USER") ? DB_USER : getenv("DB_USER"));
    $db_password =
        $config["db_password"] ??
        (defined("DB_PASSWORD") ? DB_PASSWORD : getenv("DB_PASSWORD"));

    $missing = [];
    if (!$db_host) {
        $missing[] = "db_host";
    }
    if (!$db_name) {
        $missing[] = "db_name";
    }
    if (!$db_user) {
        $missing[] = "db_user";
    }
    if ($db_password === false || $db_password === null || $db_password === "") {
        $missing[] = "db_password";
    }

    $wp_credentials = null;
    $credential_roots = [];
    if (!empty($directories)) {
        $credential_roots = $directories;
    } elseif (!empty($detected_root_paths)) {
        $credential_roots = $detected_root_paths;
    } elseif (!empty($search_roots)) {
        $credential_roots = $search_roots;
    }
    $credential_roots = normalize_path_list($credential_roots);

    if (!empty($missing) && !empty($credential_roots)) {
        if(defined("DB_HOST") && defined("DB_NAME") && defined("DB_USER") && defined("DB_PASSWORD")) {
            $wp_credentials = [
                "db_host" => DB_HOST,
                "db_name" => DB_NAME,
                "db_user" => DB_USER,
                "db_password" => DB_PASSWORD,
            ];
        } else {
            $wp_credentials = extract_db_credentials_from_wp_config($credential_roots);
        }
        if ($wp_credentials !== null) {
            $db_host = $db_host ?: $wp_credentials["db_host"];
            $db_name = $db_name ?: $wp_credentials["db_name"];
            $db_user = $db_user ?: $wp_credentials["db_user"];
            $db_password =
                ($db_password !== false && $db_password !== null && $db_password !== "")
                    ? $db_password
                    : $wp_credentials["db_password"];
            $missing = [];
            if (!$db_host) {
                $missing[] = "db_host";
            }
            if (!$db_name) {
                $missing[] = "db_name";
            }
            if (!$db_user) {
                $missing[] = "db_user";
            }
            if ($db_password === false || $db_password === null || $db_password === "") {
                $missing[] = "db_password";
            }
            $db["wp"]["wp_config_path"] = $wp_credentials["wp_config_path"] ?? null;
            $db["wp"]["table_prefix"] = $wp_credentials["table_prefix"] ?? null;
        }
    }

    $db["wp"]["wp_load_path"] = $wp_load_path;
    $db["wp"]["wp_load_loaded"] = function_exists("get_option");

    if (empty($missing)) {
        $db["credentials_found"] = true;
        if (!extension_loaded("pdo_mysql")) {
            $db["error"] = "pdo_mysql extension not loaded";
        } else {
            try {
                $mysql = new PDO(
                    "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
                    $db_user,
                    $db_password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                );
                $db["connected"] = true;

                $version = $mysql->query("SELECT VERSION()")->fetchColumn();
                $db["version"] = $version !== false ? (string) $version : null;
                $db["can_query"] = true;

                $table_prefix = $db["wp"]["table_prefix"];
                if ($table_prefix === null || $table_prefix === "") {
                    try {
                        $stmt = $mysql->query(
                            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES " .
                                "WHERE TABLE_SCHEMA = DATABASE() " .
                                "AND TABLE_NAME LIKE '%\\_options' ESCAPE '\\\\' " .
                                "LIMIT 5",
                        );
                        if ($stmt !== false) {
                            $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($names as $name) {
                                if (!is_string($name)) {
                                    continue;
                                }
                                $suffix = "options";
                                if (
                                    strlen($name) > strlen($suffix) &&
                                    substr($name, -strlen($suffix)) === $suffix
                                ) {
                                    $table_prefix = substr(
                                        $name,
                                        0,
                                        -strlen($suffix),
                                    );
                                    break;
                                }
                            }
                        }
                    } catch (Exception $e) {
                        if ($db["wp"]["error"] === null) {
                            $db["wp"]["error"] = $e->getMessage();
                        }
                    }
                }

                if ($table_prefix !== null && $table_prefix !== "") {
                    $db["wp"]["table_prefix"] = $table_prefix;
                    $db["wp"]["options_table"] = $table_prefix . "options";
                }

                $wp_load_attempted = false;
                $wp_load_error = null;
                $wp_loaded = $db["wp"]["wp_load_loaded"];
                if (!$wp_loaded && $wp_load_path !== null) {
                    $wp_load_attempted = true;
                    $errors = [];
                    $handler = function ($errno, $errstr) use (&$errors) {
                        $errors[] = $errstr;
                        return true;
                    };
                    set_error_handler($handler);
                    $include_result = @include_once $wp_load_path;
                    restore_error_handler();
                    if ($include_result === false) {
                        $wp_load_error = !empty($errors)
                            ? implode("; ", $errors)
                            : "Failed to include wp-load.php";
                    }
                    if (function_exists("get_option")) {
                        $wp_loaded = true;
                    } elseif ($wp_load_error === null) {
                        $wp_load_error = "wp-load.php did not load WordPress functions";
                    }
                }

                $db["wp"]["wp_load_attempted"] = $wp_load_attempted;
                $db["wp"]["wp_load_loaded"] = $wp_loaded;
                if ($wp_load_error !== null) {
                    $db["wp"]["wp_load_error"] = $wp_load_error;
                }

                if ($wp_loaded) {
                    try {
                        $db["wp"]["active_plugins"] = get_option("active_plugins");
                        $db["wp"]["theme_stylesheet"] = get_option("stylesheet");
                        $db["wp"]["theme_template"] = get_option("template");
                        $db["wp"]["siteurl"] = get_option("siteurl");
                        $db["wp"]["home"] = get_option("home");
                        if (
                            function_exists("is_multisite") &&
                            is_multisite() &&
                            function_exists("get_site_option")
                        ) {
                            $db["wp"]["active_sitewide_plugins"] = get_site_option(
                                "active_sitewide_plugins",
                            );
                        }
                    } catch (Throwable $e) {
                        if ($db["wp"]["error"] === null) {
                            $db["wp"]["error"] = $e->getMessage();
                        }
                    }
                } else {
                    if ($db["wp"]["error"] === null) {
                        if ($wp_load_error !== null) {
                            $db["wp"]["error"] = $wp_load_error;
                        } elseif ($wp_load_path === null) {
                            $db["wp"]["error"] = "wp-load.php not found";
                        } else {
                            $db["wp"]["error"] = "wp-load.php not loaded";
                        }
                    }
                }

                $vars = $mysql
                    ->query(
                        "SELECT @@character_set_database AS db_charset, " .
                            "@@collation_database AS db_collation, " .
                            "@@character_set_server AS server_charset, " .
                            "@@collation_server AS server_collation, " .
                            "@@character_set_connection AS connection_charset, " .
                            "@@collation_connection AS connection_collation, " .
                            "@@max_allowed_packet AS max_allowed_packet, " .
                            "@@sql_mode AS sql_mode, " .
                            "@@lower_case_table_names AS lower_case_table_names",
                    )
                    ->fetch(PDO::FETCH_ASSOC);
                if (is_array($vars)) {
                    $db["db_charset"] = $vars["db_charset"] ?? null;
                    $db["db_collation"] = $vars["db_collation"] ?? null;
                    $db["server_charset"] = $vars["server_charset"] ?? null;
                    $db["server_collation"] = $vars["server_collation"] ?? null;
                    $db["connection_charset"] = $vars["connection_charset"] ?? null;
                    $db["connection_collation"] = $vars["connection_collation"] ?? null;
                    $db["max_allowed_packet"] = isset($vars["max_allowed_packet"])
                        ? (int) $vars["max_allowed_packet"]
                        : null;
                    $db["sql_mode"] = $vars["sql_mode"] ?? null;
                    $db["lower_case_table_names"] = isset(
                        $vars["lower_case_table_names"],
                    )
                        ? (int) $vars["lower_case_table_names"]
                        : null;
                }

                try {
                    $stmt = $mysql->query(
                        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES " .
                            "WHERE TABLE_SCHEMA = DATABASE() LIMIT 1",
                    );
                    if ($stmt !== false) {
                        $stmt->fetchColumn();
                        $db["table_listable"] = true;
                        $db["table_list_error"] = null;
                    } else {
                        $db["table_listable"] = false;
                        $db["table_list_error"] = "SHOW TABLES failed";
                    }
                } catch (Exception $e) {
                    $db["table_listable"] = false;
                    $db["table_list_error"] = $e->getMessage();
                }
            } catch (Exception $e) {
                $db["error"] = $e->getMessage();
            }
        }
    } else {
        $db["error"] = "Database credentials not found";
        $db["missing"] = $missing;
    }

    $wp_runtime_paths = null;
    if ($db["wp"]["wp_load_loaded"]) {
        $runtime_root = defined("ABSPATH") ? rtrim(ABSPATH, "/") : null;
        $content_dir = defined("WP_CONTENT_DIR")
            ? rtrim(WP_CONTENT_DIR, "/")
            : null;
        $plugins_dir = defined("WP_PLUGIN_DIR")
            ? rtrim(WP_PLUGIN_DIR, "/")
            : null;
        $mu_plugins_dir = defined("WPMU_PLUGIN_DIR")
            ? rtrim(WPMU_PLUGIN_DIR, "/")
            : null;
        $themes_dir = null;
        if (function_exists("get_theme_root")) {
            $themes_dir = get_theme_root();
            if (is_string($themes_dir)) {
                $themes_dir = rtrim($themes_dir, "/");
            } else {
                $themes_dir = null;
            }
        }

        if ($content_dir !== null) {
            if ($plugins_dir === null) {
                $plugins_dir = $content_dir . "/plugins";
            }
            if ($mu_plugins_dir === null) {
                $mu_plugins_dir = $content_dir . "/mu-plugins";
            }
            if ($themes_dir === null) {
                $themes_dir = $content_dir . "/themes";
            }
        }

        $wp_runtime_paths = [
            "root" => $runtime_root ?? $content_dir,
            "content_dir" => $content_dir,
            "plugins_dir" => $plugins_dir,
            "mu_plugins_dir" => $mu_plugins_dir,
            "themes_dir" => $themes_dir,
        ];
    }

    $wp_content = [
        "roots" => [],
    ];
    $wp_paths_to_scan = $wp_runtime_paths !== null ? [$wp_runtime_paths] : $wp_paths;
    foreach ($wp_paths_to_scan as $paths) {
        $root_entry = [
            "root" => $paths["root"],
            "content_dir" => $paths["content_dir"] ?? null,
            "plugins" => [],
            "mu_plugins" => [],
            "themes" => [],
        ];
        $plugins_dir = $paths["plugins_dir"] ?? null;
        if ($plugins_dir !== null && is_dir($plugins_dir) && is_readable($plugins_dir)) {
            $entries = @scandir($plugins_dir) ?: [];
            foreach ($entries as $entry) {
                if ($entry === "." || $entry === "..") {
                    continue;
                }
                $path = $plugins_dir . "/" . $entry;
                $root_entry["plugins"][] = [
                    "name" => $entry,
                    "type" => is_dir($path) ? "dir" : "file",
                ];
            }
            usort(
                $root_entry["plugins"],
                fn($a, $b) => strcmp($a["name"], $b["name"]),
            );
        }

        $mu_plugins_dir = $paths["mu_plugins_dir"] ?? null;
        if ($mu_plugins_dir !== null && is_dir($mu_plugins_dir) && is_readable($mu_plugins_dir)) {
            $entries = @scandir($mu_plugins_dir) ?: [];
            foreach ($entries as $entry) {
                if ($entry === "." || $entry === "..") {
                    continue;
                }
                $path = $mu_plugins_dir . "/" . $entry;
                $root_entry["mu_plugins"][] = [
                    "name" => $entry,
                    "type" => is_dir($path) ? "dir" : "file",
                ];
            }
            usort(
                $root_entry["mu_plugins"],
                fn($a, $b) => strcmp($a["name"], $b["name"]),
            );
        }

        $themes_dir = $paths["themes_dir"] ?? null;
        if ($themes_dir !== null && is_dir($themes_dir) && is_readable($themes_dir)) {
            $entries = @scandir($themes_dir) ?: [];
            foreach ($entries as $entry) {
                if ($entry === "." || $entry === "..") {
                    continue;
                }
                $path = $themes_dir . "/" . $entry;
                if (is_dir($path)) {
                    $root_entry["themes"][] = $entry;
                }
            }
            sort($root_entry["themes"]);
        }

        $wp_content["roots"][] = $root_entry;
    }

    $ok =
        $preflight_error === null &&
        $filesystem_ok &&
        (!empty($db["credentials_found"]) ? !empty($db["connected"]) : false);
    $response = [
        "ok" => $ok,
        "error" => $preflight_error,
        "timestamp" => time(),
        "wp_detect" => [
            "found" => !empty($wp_detect["roots"]),
            "searched" => $wp_detect["searched"],
            "roots" => $wp_detect["roots"],
            "error" =>
                !empty($wp_detect["roots"])
                    ? null
                    : "wp-load.php or wp-config.php not found in parent directories",
        ],
        "php" => [
            "version" => PHP_VERSION,
            "sapi" => php_sapi_name(),
            "timezone" => date_default_timezone_get(),
            "extensions" => $extensions,
            "extension_versions" => $extension_versions,
        ],
        "limits" => [
            "ini_max_execution_time" => (int) ini_get("max_execution_time"),
            "ini_max_input_time" => (int) ini_get("max_input_time"),
            "ini_default_socket_timeout" => (int) ini_get("default_socket_timeout"),
            "max_input_vars" => (int) ini_get("max_input_vars"),
            "max_file_uploads" => (int) ini_get("max_file_uploads"),
            "post_max_size" => $post_max_size_raw !== false ? $post_max_size_raw : null,
            "post_max_bytes" => $post_max_bytes,
            "upload_max_filesize" =>
                $upload_max_filesize_raw !== false ? $upload_max_filesize_raw : null,
            "upload_max_bytes" => $upload_max_bytes,
            "max_request_bytes" => $max_request_bytes,
            "output_buffering" => ini_get("output_buffering") ?: null,
            "zlib_output_compression" =>
                ini_get("zlib.output_compression") ?: null,
            "disable_functions" => ini_get("disable_functions") ?: null,
            "allow_url_fopen" => ini_get("allow_url_fopen") ?: null,
            "open_basedir" => ini_get("open_basedir") ?: null,
        ],
        "memory" => [
            "limit_raw" => $memory_limit_raw !== false ? $memory_limit_raw : null,
            "limit_bytes" => $memory_limit_bytes,
            "used_bytes" => $memory_used,
            "available_bytes" => $memory_available,
        ],
        "images" => [
            "gd" => [
                "available" => is_array($gd_info),
                "version" => $gd_version,
                "formats" => $gd_formats,
            ],
            "imagick" => [
                "available" => $imagick_version !== null,
                "version" => $imagick_version,
            ],
        ],
        "runtime" => [
            "server_software" => $_SERVER["SERVER_SOFTWARE"] ?? null,
            "php_ini" => function_exists("php_ini_loaded_file")
                ? (php_ini_loaded_file() ?: null)
                : null,
            "temp_dir" => sys_get_temp_dir(),
        ],
        "filesystem" => [
            "directories" => $dir_checks,
            "error" => $dir_error,
            "ok" => $filesystem_ok,
        ],
        "htaccess" => [
            "files" => $htaccess_files,
        ],
        "wp_content" => $wp_content,
        "database" => $db,
    ];

    header("Content-Type: application/json");
    echo json_encode($response);

    return [
        "status" => $response["ok"] ? "ok" : "error",
        "stats" => $response,
    ];
}

/**
 * Stream chunks from a file producer as multipart/mixed with gzip compression.
 */
function stream_file_producer(
    $producer,
    float $script_start,
    int $max_execution_time,
    int $max_memory,
    float $memory_threshold,
): array {
    prepare_streaming_response();

    $boundary = "boundary-" . bin2hex(random_bytes(16));
    $can_send_headers = !headers_sent();
    if ($can_send_headers) {
        @header("Content-Type: multipart/mixed; boundary=\"$boundary\"");
    }

    $gz = new GzipOutputStream($can_send_headers);

    $initial_progress = $producer->get_progress();
    $initial_progress_json = json_encode($initial_progress);
    $initial_cursor = $producer->get_reentrancy_cursor();
    $gz->write("--{$boundary}\r\n");
    $gz->write("Content-Type: application/json\r\n");
    $gz->write("Content-Length: " . strlen($initial_progress_json) . "\r\n");
    $gz->write("X-Chunk-Type: progress\r\n");
    $gz->write("X-Cursor: " . base64_encode($initial_cursor) . "\r\n");
    $gz->write("\r\n");
    $gz->write($initial_progress_json);
    $gz->write("\r\n");
    $gz->flush();

    $chunks_processed = 0;
    $files_completed = 0;
    $bytes_processed = 0;
    $last_progress_output = microtime(true);
    $metadata_sent = false;
    $iterations = 0;

    while (true) {
        if (
            !should_continue(
                $script_start,
                $max_execution_time,
                $max_memory,
                $memory_threshold,
            )
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
                "filesystem_root" => $filesystem_root,
            ];
            $metadata_json = json_encode($metadata);

            $gz->write("--{$boundary}\r\n");
            $gz->write("Content-Type: application/json\r\n");
            $gz->write("Content-Length: " . strlen($metadata_json) . "\r\n");
            $gz->write("X-Chunk-Type: metadata\r\n");
            $gz->write(
                "X-Filesystem-Root: " .
                    base64_encode($filesystem_root ?? "") .
                    "\r\n",
            );
            $gz->write("\r\n");
            $gz->write($metadata_json);
            $gz->write("\r\n");
            $gz->flush();

            $metadata_sent = true;
        }

        if ($chunk === null) {
            $now = microtime(true);
            if ($iterations === 1 || $now - $last_progress_output >= 3.0) {
                $progress_json = json_encode($progress);
                $cursor = $producer->get_reentrancy_cursor();

                $gz->write("--{$boundary}\r\n");
                $gz->write("Content-Type: application/json\r\n");
                $gz->write(
                    "Content-Length: " . strlen($progress_json) . "\r\n",
                );
                $gz->write("X-Chunk-Type: progress\r\n");
                $gz->write("X-Cursor: " . base64_encode($cursor) . "\r\n");
                $gz->write("\r\n");
                $gz->write($progress_json);
                $gz->write("\r\n");
                $gz->flush();

                $last_progress_output = $now;
            }

            continue;
        }

        $chunk_type = $chunk["type"] ?? "file";
        $cursor = $producer->get_reentrancy_cursor();

        if ($chunk_type === "directory") {
            $gz->write("--{$boundary}\r\n");
            $gz->write("Content-Type: application/octet-stream\r\n");
            $gz->write("Content-Length: 0\r\n");
            $gz->write("X-Chunk-Type: directory\r\n");
            $gz->write("X-Cursor: " . base64_encode($cursor) . "\r\n");
            $gz->write(
                "X-Directory-Path: " . base64_encode($chunk["path"]) . "\r\n",
            );
            $gz->write("\r\n");
            $gz->write("\r\n");
            $gz->flush();
        } elseif ($chunk_type === "symlink") {
            $gz->write("--{$boundary}\r\n");
            $gz->write("Content-Type: application/octet-stream\r\n");
            $gz->write("Content-Length: 0\r\n");
            $gz->write("X-Chunk-Type: symlink\r\n");
            $gz->write("X-Cursor: " . base64_encode($cursor) . "\r\n");
            $gz->write(
                "X-Symlink-Path: " . base64_encode($chunk["path"]) . "\r\n",
            );
            $gz->write(
                "X-Symlink-Target: " .
                    base64_encode($chunk["target"]) .
                    "\r\n",
            );
            $gz->write("X-Symlink-Ctime: " . $chunk["ctime"] . "\r\n");
            $gz->write("\r\n");
            $gz->write("\r\n");
            $gz->flush();
        } elseif ($chunk_type === "index") {
            $gz->write("--{$boundary}\r\n");
            $gz->write("Content-Type: application/octet-stream\r\n");
            $gz->write("Content-Length: 0\r\n");
            $gz->write("X-Chunk-Type: index\r\n");
            $gz->write("X-Cursor: " . base64_encode($cursor) . "\r\n");
            $gz->write(
                "X-Index-Path: " . base64_encode($chunk["path"]) . "\r\n",
            );
            $gz->write("X-File-Ctime: " . $chunk["ctime"] . "\r\n");
            $gz->write("X-File-Size: " . $chunk["size"] . "\r\n");
            $gz->write("\r\n");
            $gz->write("\r\n");
            $gz->flush();
        } elseif ($chunk_type === "missing") {
            $gz->write("--{$boundary}\r\n");
            $gz->write("Content-Type: application/octet-stream\r\n");
            $gz->write("Content-Length: 0\r\n");
            $gz->write("X-Chunk-Type: missing\r\n");
            $gz->write("X-Cursor: " . base64_encode($cursor) . "\r\n");
            $gz->write(
                "X-File-Path: " . base64_encode($chunk["path"]) . "\r\n",
            );
            $gz->write("\r\n");
            $gz->write("\r\n");
            $gz->flush();
        } elseif ($chunk_type === "error") {
            $payload = [
                "error_type" => $chunk["error_type"] ?? "unknown",
                "path" => $chunk["path"] ?? "",
                "message" => $chunk["message"] ?? "Error",
            ];
            if (isset($chunk["expected_ctime"])) {
                $payload["expected_ctime"] = $chunk["expected_ctime"];
            }
            if (isset($chunk["actual_ctime"])) {
                $payload["actual_ctime"] = $chunk["actual_ctime"];
            }
            $json = json_encode($payload);
            $gz->write("--{$boundary}\r\n");
            $gz->write("Content-Type: application/json\r\n");
            $gz->write("Content-Length: " . strlen($json) . "\r\n");
            $gz->write("X-Chunk-Type: error\r\n");
            $gz->write("X-Cursor: " . base64_encode($cursor) . "\r\n");
            $gz->write("\r\n");
            $gz->write($json);
            $gz->write("\r\n");
            $gz->flush();
        } else {
            $chunks_processed++;
            $bytes_processed += strlen($chunk["data"]);
            if ($chunk["is_first_chunk"]) {
                $files_completed++;
            }

            $data = $chunk["data"];

            $gz->write("--{$boundary}\r\n");
            $gz->write("Content-Type: application/octet-stream\r\n");
            $gz->write("Content-Length: " . strlen($data) . "\r\n");
            $gz->write("X-Chunk-Type: file\r\n");
            $gz->write("X-Cursor: " . base64_encode($cursor) . "\r\n");
            $gz->write(
                "X-File-Path: " . base64_encode($chunk["path"]) . "\r\n",
            );
            $gz->write("X-File-Size: " . $chunk["size"] . "\r\n");
            $gz->write("X-File-Ctime: " . $chunk["ctime"] . "\r\n");
            $gz->write("X-Chunk-Offset: " . $chunk["offset"] . "\r\n");
            $gz->write("X-Chunk-Size: " . strlen($data) . "\r\n");
            $gz->write(
                "X-First-Chunk: " .
                    ($chunk["is_first_chunk"] ? "1" : "0") .
                    "\r\n",
            );
            $gz->write(
                "X-Last-Chunk: " .
                    ($chunk["is_last_chunk"] ? "1" : "0") .
                    "\r\n",
            );
            if (!empty($chunk["file_changed"])) {
                $gz->write("X-File-Changed: 1\r\n");
                if ($chunk["change_ctime"] !== null) {
                    $gz->write(
                        "X-File-Change-Ctime: " .
                            $chunk["change_ctime"] .
                            "\r\n",
                    );
                }
                if ($chunk["change_size"] !== null) {
                    $gz->write(
                        "X-File-Change-Size: " .
                            $chunk["change_size"] .
                            "\r\n",
                    );
                }
            }
            $gz->write("\r\n");
            $gz->write($data);
            $gz->write("\r\n");
            $gz->flush();
        }

    }

    $progress = $producer->get_progress();
    $is_complete = $progress["phase"] === "finished";
    $status = $is_complete ? "complete" : "partial";

    error_log(
        "Export completion: status={$status}, phase={$progress["phase"]}, " .
            "chunks={$chunks_processed}, files={$files_completed}, bytes={$bytes_processed}",
    );

    $gz->write("--{$boundary}\r\n");
    $gz->write("Content-Type: application/octet-stream\r\n");
    $gz->write("Content-Length: 0\r\n");
    $gz->write("X-Chunk-Type: completion\r\n");
    $gz->write("X-Status: {$status}\r\n");
    $gz->write("X-Chunks-Processed: {$chunks_processed}\r\n");
    $gz->write("X-Files-Completed: {$files_completed}\r\n");
    $gz->write("X-Bytes-Processed: {$bytes_processed}\r\n");
    $gz->write("X-Memory-Used: " . memory_get_peak_usage(true) . "\r\n");
    $gz->write("X-Memory-Limit: " . $max_memory . "\r\n");
    $gz->write("X-Time-Elapsed: " . (microtime(true) - $script_start) . "\r\n");
    $gz->write("\r\n");
    $gz->write("\r\n");
    $gz->write("--{$boundary}--\r\n");
    $gz->finish();

    return [
        "status" => $status,
        "stats" => [
            "chunks_processed" => $chunks_processed,
            "files_completed" => $files_completed,
            "bytes_processed" => $bytes_processed,
            "memory_used" => memory_get_peak_usage(true),
            "time_elapsed" => microtime(true) - $script_start,
        ],
    ];
}

/**
 * Endpoint: Stream files.
 *
 * If `paths` is provided (array of specific file paths), streams only those files.
 * Otherwise, streams all files via directory traversal (initial sync).
 *
 * The paths array is passed in memory - no filesystem writes required.
 */
function endpoint_file_stream(
    array $config,
    float $script_start,
    int $max_execution_time,
    int $max_memory,
    float $memory_threshold,
): array {
    $directories = resolve_directories($config);
    $chunk_size = $config["chunk_size"] ?? 5 * 1024 * 1024;
    $chunk_size = require_int_range(
        "chunk_size",
        (int) $chunk_size,
        EXPORT_MIN_CHUNK_SIZE,
        EXPORT_MAX_CHUNK_SIZE,
    );

    $sync_options = [
        "chunk_size" => $chunk_size,
    ];
    if (isset($config["cursor"])) {
        $sync_options["cursor"] = $config["cursor"];
    }

    // Optional paths filter: when provided, only stream these specific paths
    // instead of doing full directory traversal
    if (isset($config["paths"]) && is_array($config["paths"])) {
        $sync_options["paths"] = $config["paths"];
    }

    $producer = new FileTreeProducer($directories, $sync_options);
    return stream_file_producer(
        $producer,
        $script_start,
        $max_execution_time,
        $max_memory,
        $memory_threshold,
    );
}

/**
 * Endpoint: Stream index in batches with gzip compression.
 *
 * Instead of emitting one multipart chunk per file, collects up to
 * `batch_size` (default 5000) index entries and emits them as a single
 * TSV chunk. The entire response is gzip-compressed.
 *
 * Output format per batch:
 *   X-Chunk-Type: index_batch
 *   X-Cursor: <base64 cursor>
 *   Body: TSV (path\tctime\tsize per line)
 */
function endpoint_file_index(
    array $config,
    float $script_start,
    int $max_execution_time,
    int $max_memory,
    float $memory_threshold,
): array {
    $directories = resolve_directories($config);
    $batch_size = $config["batch_size"] ?? 5000;
    $batch_size = require_int_range(
        "batch_size",
        (int) $batch_size,
        EXPORT_MIN_INDEX_BATCH,
        EXPORT_MAX_INDEX_BATCH,
    );

    $chunk_size = $config["chunk_size"] ?? 5 * 1024 * 1024;
    $chunk_size = require_int_range(
        "chunk_size",
        (int) $chunk_size,
        EXPORT_MIN_CHUNK_SIZE,
        EXPORT_MAX_CHUNK_SIZE,
    );

    $sync_options = [
        "chunk_size" => $chunk_size,
        "index_only" => true,
    ];
    if (isset($config["cursor"])) {
        $sync_options["cursor"] = $config["cursor"];
    } elseif (isset($config["index_after"])) {
        $sync_options["start_after"] = $config["index_after"];
    }

    $producer = new FileTreeProducer($directories, $sync_options);

    prepare_streaming_response();

    $boundary = "boundary-" . bin2hex(random_bytes(16));
    $can_send_headers = !headers_sent();
    if ($can_send_headers) {
        @header("Content-Type: multipart/mixed; boundary=\"$boundary\"");
    }

    $gz = new GzipOutputStream($can_send_headers);

    // Emit initial metadata
    $filesystem_root = $producer->get_filesystem_root();
    $metadata = ["filesystem_root" => $filesystem_root];
    $metadata_json = json_encode($metadata);

    $gz->write("--{$boundary}\r\n");
    $gz->write("Content-Type: application/json\r\n");
    $gz->write("Content-Length: " . strlen($metadata_json) . "\r\n");
    $gz->write("X-Chunk-Type: metadata\r\n");
    $gz->write(
        "X-Filesystem-Root: " . base64_encode($filesystem_root ?? "") . "\r\n",
    );
    $gz->write("\r\n");
    $gz->write($metadata_json);
    $gz->write("\r\n");
    $gz->flush();

    $batches_emitted = 0;
    $total_entries = 0;
    $batch_lines = [];

    while (true) {
        if (
            !should_continue(
                $script_start,
                $max_execution_time,
                $max_memory,
                $memory_threshold,
            )
        ) {
            break;
        }

        if (!$producer->next_chunk()) {
            break;
        }

        $chunk = $producer->get_current_chunk();

        if ($chunk === null) {
            continue;
        }

        $chunk_type = $chunk["type"] ?? "file";

        // Collect index entries as TSV lines
        if ($chunk_type === "index") {
            $batch_lines[] =
                $chunk["path"] .
                "\t" .
                $chunk["ctime"] .
                "\t" .
                $chunk["size"];
        } elseif ($chunk_type === "symlink") {
            $batch_lines[] =
                $chunk["path"] . "\t" . ($chunk["ctime"] ?? 0) . "\t0";
        } elseif ($chunk_type === "directory") {
            // Skip directories in index - they're implicit from file paths
            continue;
        } elseif ($chunk_type === "error") {
            $payload = [
                "error_type" => $chunk["error_type"] ?? "unknown",
                "path" => $chunk["path"] ?? "",
                "message" => $chunk["message"] ?? "Error",
            ];
            if (isset($chunk["expected_ctime"])) {
                $payload["expected_ctime"] = $chunk["expected_ctime"];
            }
            if (isset($chunk["actual_ctime"])) {
                $payload["actual_ctime"] = $chunk["actual_ctime"];
            }
            $json = json_encode($payload);
            $cursor = $producer->get_reentrancy_cursor();
            $gz->write("--{$boundary}\r\n");
            $gz->write("Content-Type: application/json\r\n");
            $gz->write("Content-Length: " . strlen($json) . "\r\n");
            $gz->write("X-Chunk-Type: error\r\n");
            $gz->write("X-Cursor: " . base64_encode($cursor) . "\r\n");
            $gz->write("\r\n");
            $gz->write($json);
            $gz->write("\r\n");
            $gz->flush();
            continue;
        }

        // Emit batch when full or when we need to stop
        if (count($batch_lines) >= $batch_size) {
            $cursor = $producer->get_reentrancy_cursor();
            $tsv = implode("\n", $batch_lines) . "\n";

            $gz->write("--{$boundary}\r\n");
            $gz->write("Content-Type: text/tab-separated-values\r\n");
            $gz->write("Content-Length: " . strlen($tsv) . "\r\n");
            $gz->write("X-Chunk-Type: index_batch\r\n");
            $gz->write("X-Cursor: " . base64_encode($cursor) . "\r\n");
            $gz->write("X-Batch-Size: " . count($batch_lines) . "\r\n");
            $gz->write("\r\n");
            $gz->write($tsv);
            $gz->write("\r\n");
            $gz->flush();

            $batches_emitted++;
            $total_entries += count($batch_lines);
            $batch_lines = [];

        }
    }

    // Emit any remaining entries
    if (!empty($batch_lines)) {
        $cursor = $producer->get_reentrancy_cursor();
        $tsv = implode("\n", $batch_lines) . "\n";

        $gz->write("--{$boundary}\r\n");
        $gz->write("Content-Type: text/tab-separated-values\r\n");
        $gz->write("Content-Length: " . strlen($tsv) . "\r\n");
        $gz->write("X-Chunk-Type: index_batch\r\n");
        $gz->write("X-Cursor: " . base64_encode($cursor) . "\r\n");
        $gz->write("X-Batch-Size: " . count($batch_lines) . "\r\n");
        $gz->write("\r\n");
        $gz->write($tsv);
        $gz->write("\r\n");
        $gz->flush();

        $batches_emitted++;
        $total_entries += count($batch_lines);
    }

    $progress = $producer->get_progress();
    $is_complete = $progress["phase"] === "finished";
    $status = $is_complete ? "complete" : "partial";

    $gz->write("--{$boundary}\r\n");
    $gz->write("Content-Type: application/octet-stream\r\n");
    $gz->write("Content-Length: 0\r\n");
    $gz->write("X-Chunk-Type: completion\r\n");
    $gz->write("X-Status: {$status}\r\n");
    $gz->write("X-Batches-Emitted: {$batches_emitted}\r\n");
    $gz->write("X-Total-Entries: {$total_entries}\r\n");
    $gz->write("X-Memory-Used: " . memory_get_peak_usage(true) . "\r\n");
    $gz->write("X-Memory-Limit: " . $max_memory . "\r\n");
    $gz->write("X-Time-Elapsed: " . (microtime(true) - $script_start) . "\r\n");
    $gz->write("\r\n");
    $gz->write("\r\n");
    $gz->write("--{$boundary}--\r\n");
    $gz->finish();

    return [
        "status" => $status,
        "stats" => [
            "batches_emitted" => $batches_emitted,
            "total_entries" => $total_entries,
            "memory_used" => memory_get_peak_usage(true),
            "time_elapsed" => microtime(true) - $script_start,
        ],
    ];
}

/**
 * Endpoint: Stream files from a provided list.
 *
 * Reads paths from an uploaded file (one path per line) and streams
 * those files using FileTreeProducer with paths mode.
 */
function endpoint_file_fetch(
    array $config,
    float $script_start,
    int $max_execution_time,
    int $max_memory,
    float $memory_threshold,
): array {
    $directories = resolve_directories($config);

    // Get paths from uploaded file
    $list_path = $config["file_list_path"] ?? null;
    if ($list_path === null && isset($_FILES["file_list"])) {
        $tmp_name = $_FILES["file_list"]["tmp_name"] ?? "";
        if ($tmp_name === "" || !is_uploaded_file($tmp_name)) {
            throw new InvalidArgumentException(
                "file_list upload missing or invalid",
            );
        }
        $list_path = $tmp_name;
    }

    if ($list_path === null) {
        throw new InvalidArgumentException(
            "file_list is required for file_fetch endpoint",
        );
    }

    // Read paths from the file
    $paths = [];
    $handle = fopen($list_path, "r");
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            $path = trim($line);
            if ($path !== "") {
                $paths[] = $path;
            }
        }
        fclose($handle);
    }

    $chunk_size = $config["chunk_size"] ?? 5 * 1024 * 1024;
    $chunk_size = require_int_range(
        "chunk_size",
        (int) $chunk_size,
        EXPORT_MIN_CHUNK_SIZE,
        EXPORT_MAX_CHUNK_SIZE,
    );

    $sync_options = [
        "chunk_size" => $chunk_size,
        "paths" => $paths,
    ];
    if (isset($config["cursor"])) {
        $sync_options["cursor"] = $config["cursor"];
    }

    $producer = new FileTreeProducer($directories, $sync_options);
    return stream_file_producer(
        $producer,
        $script_start,
        $max_execution_time,
        $max_memory,
        $memory_threshold,
    );
}

/**
 * Parse memory limit string into bytes.
 */
function parse_memory_limit(string $limit): int
{
    $limit = trim($limit);
    $unit = strtoupper(substr($limit, -1));
    $value = (int) substr($limit, 0, -1);

    switch ($unit) {
        case "G":
            return $value * 1024 * 1024 * 1024;
        case "M":
            return $value * 1024 * 1024;
        case "K":
            return $value * 1024;
        default:
            return (int) $limit;
    }
}

/**
 * Require an integer within range, else throw.
 */
function require_int_range(
    string $name,
    int $value,
    int $min,
    int $max,
): int {
    if ($value < $min || $value > $max) {
        throw new InvalidArgumentException(
            "{$name} out of range. Expected {$min}-{$max}, got {$value}",
        );
    }
    return $value;
}

/**
 * Require a float within range, else throw.
 */
function require_float_range(
    string $name,
    float $value,
    float $min,
    float $max,
): float {
    if ($value < $min || $value > $max) {
        throw new InvalidArgumentException(
            "{$name} out of range. Expected {$min}-{$max}, got {$value}",
        );
    }
    return $value;
}

/**
 * Check if execution should continue based on time and memory constraints.
 */
function should_continue(
    float $start_time,
    int $max_time,
    int $max_mem,
    float $threshold,
): bool {
    // Check execution time
    if (microtime(true) - $start_time >= $max_time) {
        return false;
    }

    // Check memory usage
    $memory_used = memory_get_usage(true);
    if ($memory_used >= $max_mem * $threshold) {
        return false;
    }

    return true;
}

// ============================================================================
// HTTP Runtime
// ============================================================================

// Only execute if called directly (not included as a library)
if (basename(__FILE__) === basename($_SERVER["SCRIPT_FILENAME"] ?? "")) {
    error_reporting(E_ALL);
    ini_set("display_errors", 1);

    try {
        $config = parse_http_config();

        // Decode cursor from base64 to JSON
        // Cursor is ALWAYS base64-encoded in transit (GET param or header)
        // Cursor is ALWAYS JSON when decoded

        // First, check if cursor was already set from GET/POST params
        if (!isset($config["cursor"])) {
            // Try X-Export-Cursor header
            $config["cursor"] = $_SERVER["HTTP_X_EXPORT_CURSOR"] ?? null;
        }

        // If cursor exists (from any source), decode it
        if (
            isset($config["cursor"]) &&
            $config["cursor"] !== "" &&
            $config["cursor"] !== null
        ) {
            $cursor_b64 = $config["cursor"];

            // Cursor MUST be base64-encoded
            $cursor_json = base64_decode($cursor_b64, true);
            if ($cursor_json === false) {
                throw new InvalidArgumentException(
                    "Cursor must be base64-encoded. Received invalid base64: " .
                        substr($cursor_b64, 0, 50),
                );
            }

            // Decoded cursor MUST be valid JSON
            $cursor_data = json_decode($cursor_json, true);
            if (
                $cursor_data === null &&
                json_last_error() !== JSON_ERROR_NONE
            ) {
                throw new InvalidArgumentException(
                    "Cursor must be valid JSON after base64 decoding. " .
                        "JSON error: " .
                        json_last_error_msg() .
                        ". " .
                        "Base64: " .
                        substr($cursor_b64, 0, 50),
                );
            }

            // Store the JSON string (not the decoded array)
            $config["cursor"] = $cursor_json;
        }

        // Route to endpoint handlers based on explicit endpoint parameter
        $endpoint = $config["endpoint"] ?? null;
        if (!$endpoint) {
            throw new InvalidArgumentException(
                "endpoint parameter is required. " .
                    "Valid endpoints: 'file_stream', 'file_index', 'file_fetch', 'sql_chunk', 'sql_preflight', 'preflight'",
            );
        }

        $max_execution_time = $config["max_execution_time"] ?? 5;
        $memory_threshold = $config["memory_threshold"] ?? 0.8;

        $max_execution_time = require_int_range(
            "max_execution_time",
            (int) $max_execution_time,
            EXPORT_MIN_EXECUTION_TIME,
            EXPORT_MAX_EXECUTION_TIME,
        );

        $memory_threshold = require_float_range(
            "memory_threshold",
            (float) $memory_threshold,
            EXPORT_MIN_MEMORY_THRESHOLD,
            EXPORT_MAX_MEMORY_THRESHOLD,
        );

        // Parse memory limit
        $memory_limit = ini_get("memory_limit");
        if ($memory_limit === "-1") {
            $max_memory = PHP_INT_MAX;
        } else {
            $max_memory = parse_memory_limit($memory_limit);
        }

        $script_start = microtime(true);

        // Dispatch to appropriate endpoint
        switch ($endpoint) {
            case "file_stream":
                $result = endpoint_file_stream(
                    $config,
                    $script_start,
                    $max_execution_time,
                    $max_memory,
                    $memory_threshold,
                );
                break;

            case "file_index":
                $result = endpoint_file_index(
                    $config,
                    $script_start,
                    $max_execution_time,
                    $max_memory,
                    $memory_threshold,
                );
                break;

            case "file_fetch":
                $result = endpoint_file_fetch(
                    $config,
                    $script_start,
                    $max_execution_time,
                    $max_memory,
                    $memory_threshold,
                );
                break;

            case "sql_chunk":
                $result = endpoint_sql_chunk(
                    $config,
                    $script_start,
                    $max_execution_time,
                    $max_memory,
                    $memory_threshold,
                );
                break;
            case "sql_preflight":
                $result = endpoint_sql_preflight(
                    $config,
                    $script_start,
                    $max_execution_time,
                    $max_memory,
                    $memory_threshold,
                );
                break;
            case "preflight":
                $result = endpoint_preflight($config);
                break;

            default:
                throw new InvalidArgumentException(
                    "Invalid endpoint: '{$endpoint}'. " .
                        "Valid endpoints: 'file_stream', 'file_index', 'file_fetch', 'sql_chunk', 'sql_preflight', 'preflight'",
                );
        }
    } catch (Exception $e) {
        http_response_code(400);
        header("Content-Type: application/json");
        echo json_encode([
            "error" => $e->getMessage(),
            "trace" => $e->getTraceAsString(),
        ]);
    }
}

/**
 * Parse configuration from HTTP GET/POST parameters.
 *
 * Paths can be passed as:
 * - JSON array in 'paths' parameter (GET or POST)
 * - JSON body with Content-Type: application/json containing {"paths": [...]}
 */
function parse_http_config(): array
{
    $config = [];
    $params = array_merge($_GET, $_POST);

    // Check for JSON body (application/json) - useful for passing large paths arrays
    $content_type = $_SERVER["CONTENT_TYPE"] ?? "";
    if (strpos($content_type, "application/json") !== false) {
        $json_body = file_get_contents("php://input");
        if ($json_body !== false && $json_body !== "") {
            $json_data = json_decode($json_body, true);
            if (is_array($json_data)) {
                // Merge JSON body params, with GET params taking precedence
                $params = array_merge($json_data, $params);
            }
        }
    }

    foreach ($params as $key => $value) {
        // Convert kebab-case to snake_case
        $key = str_replace("-", "_", $key);

        // Type casting for known numeric/boolean fields
        if (
            in_array($key, [
                "max_execution_time",
                "min_ctime",
                "chunk_size",
                "fragments_per_batch",
                "batch_size",
                "db_query_time_limit",
                "tables_per_batch",
            ])
        ) {
            $value = (int) $value;
        } elseif (in_array($key, ["memory_threshold"])) {
            $value = (float) $value;
        } elseif (in_array($key, ["create_table_query", "db_unbuffered"])) {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        } elseif ($key === "paths" && is_string($value)) {
            // Paths passed as JSON-encoded string in parameter
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        $config[$key] = $value;
    }

    return $config;
}
