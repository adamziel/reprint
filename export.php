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
 * @return array|null Array with db_host, db_name, db_user, db_password or null if not found
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

        return [
            "db_host" => $credentials["DB_HOST"],
            "db_name" => $credentials["DB_NAME"],
            "db_user" => $credentials["DB_USER"],
            "db_password" => $credentials["DB_PASSWORD"],
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
    if ($can_send_headers) {
        @header("Content-Type: multipart/mixed; boundary=\"$boundary\"");
    }
    $gz = new GzipOutputStream($can_send_headers);

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
                    "Valid endpoints: 'file_stream', 'file_index', 'file_fetch', 'sql_chunk'",
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

            default:
                throw new InvalidArgumentException(
                    "Invalid endpoint: '{$endpoint}'. " .
                        "Valid endpoints: 'file_stream', 'file_index', 'file_fetch', 'sql_chunk'",
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
