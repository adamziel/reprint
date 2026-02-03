<?php
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
 * - Producers (FileSyncProducer, MySQLDumpProducer) work with JSON strings only, never base64
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
    header("Content-Type: application/json");
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

require_once __DIR__ . "/class-mysql-dump-producer.php";
require_once __DIR__ . "/file-sync.php";

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

    // Initialize MySQL connection
    $mysql = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_password,
    );
    $mysql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Producer options
    $producer_options = [
        "create_table_query" => $config["create_table_query"] ?? true,
        "string_encoding" => $config["string_encoding"] ?? "base64",
    ];

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

    // Initialize multipart boundary
    $boundary = "boundary-" . bin2hex(random_bytes(16));
    header("Content-Type: multipart/mixed; boundary=\"$boundary\"");

    $batches_processed = 0;

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

        // Output SQL batch as multipart chunk
        $cursor = $reader->get_reentrancy_cursor();
        echo "--{$boundary}\r\n";
        echo "Content-Type: application/sql\r\n";
        echo "Content-Length: " . strlen($sql) . "\r\n";
        echo "X-Chunk-Type: sql\r\n";
        echo "X-Cursor: " . base64_encode($cursor) . "\r\n";
        echo "\r\n";
        echo $sql;
        echo "\r\n";

        $batches_processed++;

        if ($reader->is_finished()) {
            break;
        }
    }

    // Output completion chunk with stats in headers
    $status = $reader->is_finished() ? "complete" : "partial";

    echo "--{$boundary}\r\n";
    echo "Content-Type: application/octet-stream\r\n";
    echo "Content-Length: 0\r\n";
    echo "X-Chunk-Type: completion\r\n";
    echo "X-Status: {$status}\r\n";
    echo "X-Batches-Processed: {$batches_processed}\r\n";
    echo "X-Memory-Used: " . memory_get_peak_usage(true) . "\r\n";
    echo "X-Time-Elapsed: " . (microtime(true) - $script_start) . "\r\n";
    echo "\r\n";
    echo "\r\n";

    // Close multipart
    echo "--{$boundary}--\r\n";

    return [
        "status" => $status,
        "stats" => [
            "batches_processed" => $batches_processed,
            "memory_used" => memory_get_peak_usage(true),
            "time_elapsed" => microtime(true) - $script_start,
        ],
    ];
}

/**
 * Endpoint: Create a new file transfer session.
 *
 * @param array $config Configuration with optional session_id
 * @return array Result with session_id and snapshot_storage
 */
/**
 * Endpoint: Get next chunk of file data in a given file transfer session.
 *
 * @param array $config Configuration with optional cursor and session info
 * @param float $script_start Script execution start time
 * @param int $max_execution_time Maximum execution time in seconds
 * @param int $max_memory Maximum memory in bytes
 * @param float $memory_threshold Memory usage threshold (0.0-1.0)
 * @return array Result with status and stats
 */
function endpoint_file_chunk(
    array $config,
    float $script_start,
    int $max_execution_time,
    int $max_memory,
    float $memory_threshold,
): array {
    $directories_input = $config["directory"] ?? null;
    if (!$directories_input) {
        throw new InvalidArgumentException(
            "directory is required for files operation",
        );
    }

    // Handle multiple directories (array or single string)
    $directories = [];
    $dir_list = is_array($directories_input)
        ? $directories_input
        : [$directories_input];

    foreach ($dir_list as $directory) {
        // Expand ~ to home directory
        // @TODO: Expand this as a path segment as well
        if ($directory[0] === "~") {
            $home = getenv("HOME") ?: (getenv("USERPROFILE") ?: "/");
            $directory = $home . substr($directory, 1);
        }

        // Convert to absolute path if relative
        if ($directory[0] !== "/") {
            $directory = __DIR__ . "/" . $directory;
        }

        // Resolve realpath
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

    // Session handling for client state across multiple requests
    $session_id = $config["session_id"] ?? null;
    $session_file = null;

    // Create or load session
    if ($session_id) {
        // Load existing session
        error_log("Loading existing session: {$session_id}");
        $session = load_file_sync_session($session_id);
        $session_file = $session["session_file"];
    } elseif (isset($_FILES["client_index_gz"])) {
        // Create new session with uploaded client index
        $upload_size = $_FILES["client_index_gz"]["size"] ?? "unknown";
        error_log(
            sprintf(
                "Creating new session | client_index_gz upload=%s bytes",
                $upload_size,
            ),
        );
        $session = create_file_sync_session_from_upload(
            $_FILES["client_index_gz"],
        );
        $session_id = $session["session_id"];
        $session_file = $session["session_file"];
    } elseif (isset($config["client_index_file"])) {
        // Create new session from CLI-provided file path
        error_log(
            sprintf(
                "Creating new session | client_index_file=%s",
                $config["client_index_file"],
            ),
        );
        $session = create_file_sync_session_from_path(
            $config["client_index_file"],
        );
        $session_id = $session["session_id"];
        $session_file = $session["session_file"];
    } else {
        // No session, no client index = full sync
        error_log("FULL SYNC | No session or client index provided");
        $session_file = null;
        $session_id = null;
    }

    // File sync options
    $sync_options = [
        "min_ctime" => $config["min_ctime"] ?? 0,
        "chunk_size" => $config["chunk_size"] ?? 5 * 1024 * 1024, // 5MB
        "client_index_file" => $session_file, // Path to gzipped session file for streaming
    ];

    if (isset($config["cursor"])) {
        $sync_options["cursor"] = $config["cursor"];
    }

    $sync = new FileSyncProducer($directories, $sync_options);

    // Disable output buffering for immediate response
    if (ob_get_level()) {
        ob_end_flush();
    }

    // Initialize multipart boundary
    $boundary = "boundary-" . bin2hex(random_bytes(16));
    header("Content-Type: multipart/mixed; boundary=\"$boundary\"");

    // Send session_id in header so client can use it for subsequent requests
    if ($session_id) {
        header("X-Session-Id: {$session_id}");
    }

    // Output initial progress chunk immediately
    $initial_progress = $sync->get_progress();
    $initial_progress_json = json_encode($initial_progress);
    $initial_cursor = $sync->get_reentrancy_cursor();
    echo "--{$boundary}\r\n";
    echo "Content-Type: application/json\r\n";
    echo "Content-Length: " . strlen($initial_progress_json) . "\r\n";
    echo "X-Chunk-Type: progress\r\n";
    echo "X-Cursor: " . base64_encode($initial_cursor) . "\r\n";
    echo "\r\n";
    echo $initial_progress_json;
    echo "\r\n";
    flush();

    $chunks_processed = 0;
    $files_completed = 0;
    $bytes_processed = 0;
    $last_progress_output = microtime(true);
    $deletions_output = false;
    $metadata_sent = false;
    $iterations = 0;

    // Process chunks
    while ($sync->next_chunk()) {
        $iterations++;
        $chunk = $sync->get_current_chunk();
        $progress = $sync->get_progress();

        // Output metadata once when we first enter streaming phase
        if (!$metadata_sent && $progress["phase"] === "streaming") {
            $filesystem_root = $sync->get_filesystem_root();
            $metadata = [
                "filesystem_root" => $filesystem_root,
            ];
            $metadata_json = json_encode($metadata);

            echo "--{$boundary}\r\n";
            echo "Content-Type: application/json\r\n";
            echo "Content-Length: " . strlen($metadata_json) . "\r\n";
            echo "X-Chunk-Type: metadata\r\n";
            echo "X-Filesystem-Root: " .
                base64_encode($filesystem_root) .
                "\r\n";
            echo "\r\n";
            echo $metadata_json;
            echo "\r\n";
            flush(); // Force output immediately

            $metadata_sent = true;
        }

        // Output deletions once when we first enter streaming phase
        // This must be AFTER metadata so filesystem root is known
        if (!$deletions_output && $progress["phase"] === "streaming") {
            $deletions = $sync->get_deletions();
            if ($deletions && count($deletions) > 0) {
                foreach ($deletions as $deletion) {
                    $deletion_json = json_encode($deletion);
                    $cursor = $sync->get_reentrancy_cursor();

                    echo "--{$boundary}\r\n";
                    echo "Content-Type: application/json\r\n";
                    echo "Content-Length: " . strlen($deletion_json) . "\r\n";
                    echo "X-Chunk-Type: deletion\r\n";
                    echo "X-Cursor: " . base64_encode($cursor) . "\r\n";
                    echo "\r\n";
                    echo $deletion_json;
                    echo "\r\n";
                    flush(); // Force output immediately
                }
            }
            $deletions_output = true;
        }

        // During scanning/sorting phases, chunk will be null - output progress
        if ($chunk === null) {
            // Output progress chunk (throttled to once every 3 seconds, except first one)
            $now = microtime(true);
            if ($iterations === 1 || $now - $last_progress_output >= 3.0) {
                $progress_json = json_encode($progress);
                $cursor = $sync->get_reentrancy_cursor();

                echo "--{$boundary}\r\n";
                echo "Content-Type: application/json\r\n";
                echo "Content-Length: " . strlen($progress_json) . "\r\n";
                echo "X-Chunk-Type: progress\r\n";
                echo "X-Cursor: " . base64_encode($cursor) . "\r\n";
                echo "\r\n";
                echo $progress_json;
                echo "\r\n";
                flush(); // Force output immediately

                $last_progress_output = $now;
            }

            // Check limits
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
            continue;
        }

        // Handle different chunk types
        $chunk_type = $chunk["type"] ?? "file";
        $cursor = $sync->get_reentrancy_cursor();

        if ($chunk_type === "directory") {
            // Output directory chunk
            echo "--{$boundary}\r\n";
            echo "Content-Type: application/octet-stream\r\n";
            echo "Content-Length: 0\r\n";
            echo "X-Chunk-Type: directory\r\n";
            echo "X-Cursor: " . base64_encode($cursor) . "\r\n";
            echo "X-Directory-Path: " . base64_encode($chunk["path"]) . "\r\n";
            echo "\r\n";
            echo "\r\n";
            flush(); // Force output immediately
        } elseif ($chunk_type === "symlink") {
            // Output symlink chunk
            echo "--{$boundary}\r\n";
            echo "Content-Type: application/octet-stream\r\n";
            echo "Content-Length: 0\r\n";
            echo "X-Chunk-Type: symlink\r\n";
            echo "X-Cursor: " . base64_encode($cursor) . "\r\n";
            echo "X-Symlink-Path: " . base64_encode($chunk["path"]) . "\r\n";
            echo "X-Symlink-Target: " .
                base64_encode($chunk["target"]) .
                "\r\n";
            echo "X-Symlink-Ctime: " . $chunk["ctime"] . "\r\n";
            echo "\r\n";
            echo "\r\n";
            flush(); // Force output immediately
        } else {
            // Track stats
            $chunks_processed++;
            $bytes_processed += strlen($chunk["data"]);
            if ($chunk["is_first_chunk"]) {
                $files_completed++;
            }

            // Output file chunk as multipart
            $data = $chunk["data"];

            echo "--{$boundary}\r\n";
            echo "Content-Type: application/octet-stream\r\n";
            echo "Content-Length: " . strlen($data) . "\r\n";
            echo "X-Chunk-Type: file\r\n";
            echo "X-Cursor: " . base64_encode($cursor) . "\r\n";
            echo "X-File-Path: " . base64_encode($chunk["path"]) . "\r\n";
            echo "X-File-Size: " . $chunk["size"] . "\r\n";
            echo "X-File-Ctime: " . $chunk["ctime"] . "\r\n";
            echo "X-Chunk-Offset: " . $chunk["offset"] . "\r\n";
            echo "X-Chunk-Size: " . strlen($data) . "\r\n";
            echo "X-First-Chunk: " .
                ($chunk["is_first_chunk"] ? "1" : "0") .
                "\r\n";
            echo "X-Last-Chunk: " .
                ($chunk["is_last_chunk"] ? "1" : "0") .
                "\r\n";
            echo "\r\n";
            echo $data;
            echo "\r\n";
            flush(); // Force output immediately
        }

        // Check limits
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

    // Output completion chunk with stats in headers
    $progress = $sync->get_progress();
    $is_complete = $progress["phase"] === "finished";
    $status = $is_complete ? "complete" : "partial";

    // Log completion
    error_log(
        "Export completion: status={$status}, phase={$progress["phase"]}, " .
            "chunks={$chunks_processed}, files={$files_completed}, bytes={$bytes_processed}",
    );

    echo "--{$boundary}\r\n";
    echo "Content-Type: application/octet-stream\r\n";
    echo "Content-Length: 0\r\n";
    echo "X-Chunk-Type: completion\r\n";
    echo "X-Status: {$status}\r\n";
    echo "X-Chunks-Processed: {$chunks_processed}\r\n";
    echo "X-Files-Completed: {$files_completed}\r\n";
    echo "X-Bytes-Processed: {$bytes_processed}\r\n";
    echo "X-Memory-Used: " . memory_get_peak_usage(true) . "\r\n";
    echo "X-Time-Elapsed: " . (microtime(true) - $script_start) . "\r\n";
    echo "\r\n";
    echo "\r\n";

    // Close multipart
    echo "--{$boundary}--\r\n";

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
// CLI/HTTP Runtime
// ============================================================================

// Detect runtime environment
$is_cli = PHP_SAPI === "cli" || PHP_SAPI === "phpdbg";

// Only execute if called directly (not included as a library)
if (basename(__FILE__) === basename($_SERVER["SCRIPT_FILENAME"] ?? "")) {
    error_reporting(E_ALL);
    ini_set("display_errors", 1);

    try {
        // Parse configuration from CLI or HTTP
        $config = $is_cli ? parse_cli_config() : parse_http_config();

        // Decode cursor from base64 to JSON
        // Cursor is ALWAYS base64-encoded in transit (GET param, header, or env var)
        // Cursor is ALWAYS JSON when decoded

        // First, check if cursor was already set from GET/POST params
        if (!isset($config["cursor"])) {
            // Try header or environment variable
            $config["cursor"] = $is_cli
                ? getenv("EXPORT_CURSOR")
                : $_SERVER["HTTP_X_EXPORT_CURSOR"] ?? null;
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
                    "Valid endpoints: 'create_file_session', 'file_chunk', 'sql_chunk'",
            );
        }

        $max_execution_time = $config["max_execution_time"] ?? 5;
        $memory_threshold = $config["memory_threshold"] ?? 0.8;

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
            case "create_file_session":
                $result = endpoint_create_file_session($config);
                // Return session info as JSON for this endpoint
                if (!$is_cli) {
                    header("Content-Type: application/json");
                    echo json_encode([
                        "session_id" => $result["session_id"],
                    ]);
                } else {
                    echo json_encode(
                        ["session_id" => $result["session_id"]],
                        JSON_PRETTY_PRINT,
                    ) . "\n";
                }
                break;

            case "file_chunk":
                $result = endpoint_file_chunk(
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
                        "Valid endpoints: 'create_file_session', 'file_chunk', 'sql_chunk'",
                );
        }
    } catch (Exception $e) {
        if ($is_cli) {
            fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
            exit(1);
        } else {
            http_response_code(400);
            header("Content-Type: application/json");
            echo json_encode([
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
        }
    }
}

/**
 * Parse configuration from CLI arguments.
 */
function parse_cli_config(): array
{
    global $argv;
    $config = [];

    // Parse --key=value style arguments
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];

        if (strpos($arg, "--") === 0) {
            $parts = explode("=", substr($arg, 2), 2);
            $key = $parts[0];
            $value = $parts[1] ?? "";

            // Convert kebab-case to snake_case
            $key = str_replace("-", "_", $key);

            // Type casting for known numeric/boolean fields
            if (
                in_array($key, [
                    "max_execution_time",
                    "min_ctime",
                    "chunk_size",
                    "fragments_per_batch",
                ])
            ) {
                $value = (int) $value;
            } elseif (in_array($key, ["memory_threshold"])) {
                $value = (float) $value;
            } elseif (in_array($key, ["create_table_query"])) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }

            $config[$key] = $value;
        }
    }

    return $config;
}

/**
 * Parse configuration from HTTP GET/POST parameters.
 */
function parse_http_config(): array
{
    $config = [];
    $params = array_merge($_GET, $_POST);

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
            ])
        ) {
            $value = (int) $value;
        } elseif (in_array($key, ["memory_threshold"])) {
            $value = (float) $value;
        } elseif (in_array($key, ["create_table_query"])) {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        $config[$key] = $value;
    }

    return $config;
}
