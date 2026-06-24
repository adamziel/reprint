<?php

namespace Reprint\Exporter;

use RuntimeException;
use Throwable;

/**
 * Active multipart stream state shared with error/shutdown handlers.
 */
final class StreamingContext
{
    /** @var array{gz: GzipOutputStream, boundary: string}|null */
    private static $context = null;

    /**
     * @param array{gz: GzipOutputStream, boundary: string} $context
     */
    public static function set(array $context): void
    {
        self::$context = $context;
    }

    /**
     * @return array{gz: GzipOutputStream, boundary: string}|null
     */
    public static function get(): ?array
    {
        return self::$context;
    }
}

/**
 * Initializes a multipart/mixed streaming response.
 *
 * @return array{gz: GzipOutputStream, boundary: string}
 */
function begin_multipart_stream(bool $require_headers = false, bool $gzip = true): array
{
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
    $context = ['gz' => $gz, 'boundary' => $boundary];
    StreamingContext::set($context);

    return $context;
}

/**
 * Emits an error chunk into a gzip multipart stream.
 *
 * @param mixed $gz
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
    } catch (Throwable $e) {
        echo $chunk;
        flush();
    }
}

/**
 * Installs streaming-aware error, exception, and fatal handlers.
 */
function install_export_error_handlers(): void
{
    static $installed = false;
    if ($installed) {
        return;
    }
    $installed = true;

    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
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

        $context = StreamingContext::get();
        if ($context !== null) {
            emit_error_chunk(
                $context['gz'],
                $context['boundary'],
                "PHP Error ({$errno}): {$errstr} in {$errfile}:{$errline}",
            );
            return true;
        }

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

        $context = StreamingContext::get();
        if ($context !== null) {
            emit_error_chunk(
                $context['gz'],
                $context['boundary'],
                get_class($e) . ": " . $e->getMessage(),
            );
            return;
        }

        http_response_code(500);
        header("Content-Type: application/json");
        echo json_encode($error);
        exit(1);
    });

    register_shutdown_function(function () {
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

        $context = StreamingContext::get();
        if ($context !== null) {
            try {
                emit_error_chunk(
                    $context['gz'],
                    $context['boundary'],
                    $message,
                );
            } catch (Throwable $ignored) {
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
}

/**
 * Prepares the PHP environment for streaming.
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

    @ini_set("zlib.output_compression", "0");
    @ini_set("output_buffering", "0");
    @ini_set("implicit_flush", "1");

    @ob_implicit_flush(1);
}

/**
 * Streams file chunks from a producer as multipart/mixed.
 *
 * @param mixed $producer
 * @param array<string, mixed> $config
 * @return array{status: string, stats: array<string, int|float>}
 */
function stream_file_producer(
    $producer,
    ResourceBudget $budget,
    array $config = [],
    bool $gzip = false
): array {
    prepare_streaming_response();

    ['gz' => $gz, 'boundary' => $boundary] = begin_multipart_stream(false, $gzip);

    if (getenv('SITE_EXPORT_TEST_MODE')) {
        E2E\load_test_hooks_if_needed($config);
        $hook_args = [$gz, $boundary];
        E2E\call_hook('test_hook_after_gzip_init', $hook_args);
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
            if (!$budget->has_remaining()) {
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
                if (getenv('SITE_EXPORT_TEST_MODE')) {
                    $hook_data = $chunk["data"];
                    $hook_args = [$chunk["path"], $chunk["offset"], &$hook_data];
                    E2E\call_hook('test_hook_before_file_chunk', $hook_args);
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

    try {
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

        if (getenv('SITE_EXPORT_TEST_MODE')) {
            $hook_args = [$status, $gz, $boundary];
            E2E\call_hook('test_hook_before_completion', $hook_args);
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
    } catch (Throwable $e) {
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
