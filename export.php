<?php
/**
 * Unified export API for SQL and file operations.
 *
 * Provides function-based interface for:
 * - SQL database exports with cursor-based resumption
 * - File synchronization with cursor-based resumption
 *
 * Cursors are output to stdout as part of the stream (never written to disk).
 */

require_once __DIR__ . "/class-mysql-dump-producer.php";
require_once __DIR__ . "/class-file-sync-producer.php";

/**
 * Handle SQL export operation.
 */
function export_sql(
    array $config,
    float $script_start,
    int $max_execution_time,
    int $max_memory,
    float $memory_threshold,
): array {
    // Database configuration
    $db_host =
        $config["db_host"] ??
        (defined("DB_HOST") ? DB_HOST : (getenv("DB_HOST") ?: "127.0.0.1"));
    $db_name =
        $config["db_name"] ??
        (defined("DB_NAME") ? DB_NAME : (getenv("DB_NAME") ?: "sakila"));
    $db_user =
        $config["db_user"] ??
        (defined("DB_USER") ? DB_USER : (getenv("DB_USER") ?: "root"));
    $db_password =
        $config["db_password"] ??
        (defined("DB_PASSWORD")
            ? DB_PASSWORD
            : (getenv("DB_PASSWORD") ?:
            "my-secret-pw"));

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
 * Handle file export operation.
 */
function export_files(
    array $config,
    float $script_start,
    int $max_execution_time,
    int $max_memory,
    float $memory_threshold,
): array {
    $directory = $config["directory"] ?? null;
    if (!$directory) {
        throw new InvalidArgumentException(
            "directory is required for files operation",
        );
    }

    if (!is_dir($directory)) {
        throw new InvalidArgumentException(
            "directory does not exist: {$directory}",
        );
    }

    // File sync options
    $sync_options = [
        "min_ctime" => $config["min_ctime"] ?? 0,
        "max_files" => $config["max_files"] ?? 1000,
        "chunk_size" => $config["chunk_size"] ?? 5 * 1024 * 1024, // 5MB
    ];

    if (isset($config["cursor"])) {
        $sync_options["cursor"] = $config["cursor"];
    }

    if (isset($config["snapshot_path"])) {
        $sync_options["snapshot_storage"] = new FileSnapshotStorage(
            $config["snapshot_path"],
        );
    }

    $sync = new FileSyncProducer($directory, $sync_options);

    // Initialize multipart boundary
    $boundary = "boundary-" . bin2hex(random_bytes(16));
    header("Content-Type: multipart/mixed; boundary=\"$boundary\"");

    $chunks_processed = 0;
    $files_completed = 0;
    $bytes_processed = 0;
    $last_progress_output = 0;
    $deletions_output = false;

    // Process chunks
    while ($sync->next_chunk()) {
        $chunk = $sync->get_current_chunk();
        $progress = $sync->get_progress();

        // Output deletions once when we first enter streaming phase
        // This must be BEFORE the chunk null check so deletions are output even when no files change
        if (!$deletions_output && $progress["phase"] === "streaming") {
            $deletions = $sync->get_deletions();
            if (count($deletions) > 0) {
                foreach ($deletions as $deletion) {
                    $cursor = $sync->get_reentrancy_cursor();

                    echo "--{$boundary}\r\n";
                    echo "Content-Type: application/octet-stream\r\n";
                    echo "Content-Length: 0\r\n";
                    echo "X-Chunk-Type: deletion\r\n";
                    echo "X-Cursor: " . base64_encode($cursor) . "\r\n";
                    echo "X-Deleted-Path: " .
                        base64_encode($deletion["path"]) .
                        "\r\n";
                    echo "X-Deleted-Ctime: " . $deletion["ctime"] . "\r\n";
                    echo "X-Deleted-Size: " . $deletion["size"] . "\r\n";
                    echo "X-Deleted-At: " . $deletion["deleted_at"] . "\r\n";
                    echo "\r\n";
                    echo "\r\n";
                }
            }
            $deletions_output = true;
        }

        // During scanning/sorting phases, chunk will be null - output progress
        if ($chunk === null) {
            // Output progress chunk (throttled to once every 3 seconds)
            $now = microtime(true);
            if ($now - $last_progress_output >= 3.0) {
                $cursor = $sync->get_reentrancy_cursor();

                echo "--{$boundary}\r\n";
                echo "Content-Type: application/octet-stream\r\n";
                echo "Content-Length: 0\r\n";
                echo "X-Chunk-Type: progress\r\n";
                echo "X-Cursor: " . base64_encode($cursor) . "\r\n";
                echo "X-Progress-Phase: " . $progress["phase"] . "\r\n";

                // Phase-specific progress details
                if (isset($progress["files_total"])) {
                    echo "X-Progress-Files-Total: " .
                        $progress["files_total"] .
                        "\r\n";
                }
                if (isset($progress["files_completed"])) {
                    echo "X-Progress-Files-Completed: " .
                        $progress["files_completed"] .
                        "\r\n";
                }
                if (isset($progress["percent_complete"])) {
                    echo "X-Progress-Percent: " .
                        round($progress["percent_complete"] * 100, 2) .
                        "\r\n";
                }
                if (isset($progress["directories_pending"])) {
                    echo "X-Progress-Directories-Pending: " .
                        $progress["directories_pending"] .
                        "\r\n";
                }
                if (isset($progress["chunks_sorted"])) {
                    echo "X-Progress-Chunks-Sorted: " .
                        $progress["chunks_sorted"] .
                        "\r\n";
                }
                if (isset($progress["current_file"])) {
                    $file = $progress["current_file"];
                    echo "X-Progress-Current-File: " .
                        base64_encode($file["path"]) .
                        "\r\n";
                    echo "X-Progress-Current-File-Size: " .
                        $file["size"] .
                        "\r\n";
                    echo "X-Progress-Current-File-Bytes: " .
                        $file["bytes_read"] .
                        "\r\n";
                    echo "X-Progress-Current-File-Percent: " .
                        round($file["percent"] * 100, 2) .
                        "\r\n";
                }

                echo "\r\n";
                echo "\r\n";

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

        // Track stats
        $chunks_processed++;
        $bytes_processed += $chunk["chunk_size"];
        if ($chunk["is_first_chunk"]) {
            $files_completed++;
        }

        // Output file chunk as multipart
        $data = $chunk["data"];
        $cursor = $sync->get_reentrancy_cursor();

        echo "--{$boundary}\r\n";
        echo "Content-Type: application/octet-stream\r\n";
        echo "Content-Length: " . strlen($data) . "\r\n";
        echo "X-Chunk-Type: file\r\n";
        echo "X-Cursor: " . base64_encode($cursor) . "\r\n";
        echo "X-File-Path: " . base64_encode($chunk["path"]) . "\r\n";
        echo "X-File-Size: " . $chunk["size"] . "\r\n";
        echo "X-File-Ctime: " . $chunk["ctime"] . "\r\n";
        echo "X-Chunk-Offset: " . $chunk["offset"] . "\r\n";
        echo "X-Chunk-Size: " . $chunk["chunk_size"] . "\r\n";
        echo "X-First-Chunk: " .
            ($chunk["is_first_chunk"] ? "1" : "0") .
            "\r\n";
        echo "X-Last-Chunk: " . ($chunk["is_last_chunk"] ? "1" : "0") . "\r\n";
        echo "\r\n";
        echo $data;
        echo "\r\n";

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

        // Get cursor from environment/headers
        if (!isset($config["cursor"])) {
            $cursor = $is_cli
                ? getenv("EXPORT_CURSOR")
                : $_SERVER["HTTP_X_EXPORT_CURSOR"] ?? null;
            if ($cursor && $cursor !== "") {
                $config["cursor"] = $cursor;
            }
        }

        /**
         * Export operation configuration and execution.
         *
         * Output is always multipart/mixed format.
         *
         * @param array $config Configuration array with:
         *   - operation: 'sql' or 'files' (required)
         *   - cursor: Optional cursor string for resumption
         *   - max_execution_time: Maximum seconds to run (default: 30)
         *   - memory_threshold: Stop at this fraction of memory_limit (default: 0.8)
         *
         *   For SQL operations:
         *   - db_host: Database host (default: from DB_HOST constant or env or '127.0.0.1')
         *   - db_name: Database name (default: from DB_NAME constant or env or 'sakila')
         *   - db_user: Database user (default: from DB_USER constant or env or 'root')
         *   - db_password: Database password (default: from DB_PASSWORD constant or env or 'my-secret-pw')
         *   - fragments_per_batch: SQL fragments per batch (default: 1000)
         *   - string_encoding: 'base64' or 'escape' (default: 'base64')
         *   - create_table_query: Include CREATE TABLE statements (default: true)
         *
         *   For file operations:
         *   - directory: Directory to scan (required for files operation)
         *   - snapshot_path: Optional snapshot file path for change detection
         *   - min_ctime: Minimum creation time filter (default: 0)
         *   - max_files: Maximum files to process (default: 1000)
         *   - chunk_size: File chunk size in bytes (default: 5MB)
         *
         * @return array Result with:
         *   - status: 'complete' or 'partial'
         *   - stats: Array of statistics (memory_used, time_elapsed, etc.)
         */
        $operation = $config["operation"] ?? null;
        if (!$operation || !in_array($operation, ["sql", "files"])) {
            throw new InvalidArgumentException(
                "operation must be 'sql' or 'files' (got '{$operation}')",
            );
        }

        $max_execution_time = $config["max_execution_time"] ?? 30;
        $memory_threshold = $config["memory_threshold"] ?? 0.8;

        // Parse memory limit
        $memory_limit = ini_get("memory_limit");
        if ($memory_limit === "-1") {
            $max_memory = PHP_INT_MAX;
        } else {
            $max_memory = parse_memory_limit($memory_limit);
        }

        $script_start = microtime(true);

        // Route to appropriate handler
        if ($operation === "sql") {
            $result = export_sql(
                $config,
                $script_start,
                $max_execution_time,
                $max_memory,
                $memory_threshold,
            );
        } else {
            $result = export_files(
                $config,
                $script_start,
                $max_execution_time,
                $max_memory,
                $memory_threshold,
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
                    "max_files",
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
                "max_files",
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
