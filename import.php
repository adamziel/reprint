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
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);

/**
 * Streaming multipart parser.
 * Parses multipart/mixed responses incrementally without buffering entire response.
 */
class MultipartStreamParser
{
    private $boundary;
    private $boundary_length;
    private $buffer = "";
    private $state = "boundary"; // boundary|headers|body
    private $current_headers = [];
    private $body_length = 0;
    private $body_target = null;
    private $chunk_handler;

    public function __construct(string $boundary, callable $chunk_handler)
    {
        $this->boundary = "--" . $boundary;
        $this->boundary_length = strlen($this->boundary);
        $this->chunk_handler = $chunk_handler;
    }

    /**
     * Feed data to parser. Called by curl write callback.
     */
    public function feed(string $data): void
    {
        $this->buffer .= $data;
        $this->parse();
    }

    /**
     * Parse buffered data.
     */
    private function parse(): void
    {
        while (true) {
            if ($this->state === "boundary") {
                if (!$this->parse_boundary()) {
                    break;
                }
            } elseif ($this->state === "headers") {
                if (!$this->parse_headers()) {
                    break;
                }
            } elseif ($this->state === "body") {
                if (!$this->parse_body()) {
                    break;
                }
            }
        }
    }

    /**
     * Parse boundary. Returns true if boundary found and consumed.
     */
    private function parse_boundary(): bool
    {
        // Look for boundary
        $pos = strpos($this->buffer, $this->boundary);
        if ($pos === false) {
            // Keep only last boundary_length bytes in case boundary is split
            if (strlen($this->buffer) > $this->boundary_length) {
                $this->buffer = substr($this->buffer, -$this->boundary_length);
            }
            return false;
        }

        // Check if this is the closing boundary (--boundary--)
        $after_boundary = $pos + $this->boundary_length;
        if ($after_boundary + 2 <= strlen($this->buffer)) {
            $next_chars = substr($this->buffer, $after_boundary, 2);
            if ($next_chars === "--") {
                // Closing boundary - done
                $this->buffer = "";
                return false;
            }
        }

        // Find end of line after boundary (\r\n or \n)
        $line_end = $this->find_line_end($after_boundary);
        if ($line_end === false) {
            return false; // Need more data
        }

        // Consume boundary line
        $this->buffer = substr($this->buffer, $line_end);
        $this->state = "headers";
        $this->current_headers = [];
        return true;
    }

    /**
     * Parse headers. Returns true if all headers parsed.
     */
    private function parse_headers(): bool
    {
        while (true) {
            // Check for blank line (end of headers)
            if (strlen($this->buffer) >= 2) {
                if ($this->buffer[0] === "\r" && $this->buffer[1] === "\n") {
                    // \r\n - blank line
                    $this->buffer = substr($this->buffer, 2);
                    $this->prepare_body();
                    return true;
                } elseif ($this->buffer[0] === "\n") {
                    // \n - blank line
                    $this->buffer = substr($this->buffer, 1);
                    $this->prepare_body();
                    return true;
                }
            }

            // Find end of line
            $line_end = $this->find_line_end(0);
            if ($line_end === false) {
                return false; // Need more data
            }

            // Extract header line
            $line = substr($this->buffer, 0, $line_end);
            $this->buffer = substr($this->buffer, $line_end);

            // Trim line endings
            $line = rtrim($line, "\r\n");

            if ($line === "") {
                // Blank line - end of headers
                $this->prepare_body();
                return true;
            }

            // Parse header (find first colon)
            $colon_pos = strpos($line, ":");
            if ($colon_pos !== false) {
                $name = substr($line, 0, $colon_pos);
                $value = substr($line, $colon_pos + 1);

                // Trim spaces
                $name = trim($name);
                $value = ltrim($value); // Only left trim value

                // Store header (lowercase key)
                $key = strtolower($name);
                $this->current_headers[$key] = $value;
            }
        }
    }

    /**
     * Prepare for body parsing.
     */
    private function prepare_body(): void
    {
        $this->state = "body";
        $this->body_length = 0;

        // Determine target length if Content-Length is specified
        $this->body_target = isset($this->current_headers["content-length"])
            ? (int) $this->current_headers["content-length"]
            : null;
    }

    /**
     * Parse body. Returns true if body complete.
     */
    private function parse_body(): bool
    {
        // If we know the content length, read exactly that many bytes
        if ($this->body_target !== null) {
            $remaining = $this->body_target - $this->body_length;

            if (strlen($this->buffer) < $remaining) {
                // Need more data
                if (strlen($this->buffer) > 0) {
                    // Process what we have
                    $this->emit_body_chunk(substr($this->buffer, 0));
                    $this->body_length += strlen($this->buffer);
                    $this->buffer = "";
                }
                return false;
            }

            // We have enough data
            $body_data = substr($this->buffer, 0, $remaining);
            $this->buffer = substr($this->buffer, $remaining);

            $this->emit_body_chunk($body_data);
            $this->body_length += strlen($body_data);

            // Skip trailing \r\n after body
            $this->skip_crlf();

            // Complete - move to next boundary
            $this->state = "boundary";
            $this->emit_chunk_complete();
            return true;
        }

        // No content-length - read until next boundary
        // Look for boundary in buffer
        $boundary_pos = strpos($this->buffer, "\r\n" . $this->boundary);
        if ($boundary_pos === false) {
            $boundary_pos = strpos($this->buffer, "\n" . $this->boundary);
        }

        if ($boundary_pos === false) {
            // No boundary yet - process all but last boundary_length+2 bytes
            $safe_length = strlen($this->buffer) - $this->boundary_length - 2;
            if ($safe_length > 0) {
                $body_data = substr($this->buffer, 0, $safe_length);
                $this->buffer = substr($this->buffer, $safe_length);
                $this->emit_body_chunk($body_data);
                $this->body_length += strlen($body_data);
            }
            return false;
        }

        // Found boundary - emit remaining body
        $body_data = substr($this->buffer, 0, $boundary_pos);
        $this->buffer = substr($this->buffer, $boundary_pos);

        $this->emit_body_chunk($body_data);
        $this->body_length += strlen($body_data);

        // Skip \r\n before boundary
        $this->skip_crlf();

        // Complete - move to next boundary
        $this->state = "boundary";
        $this->emit_chunk_complete();
        return true;
    }

    /**
     * Skip \r\n or \n at start of buffer.
     */
    private function skip_crlf(): void
    {
        if (
            strlen($this->buffer) >= 2 &&
            $this->buffer[0] === "\r" &&
            $this->buffer[1] === "\n"
        ) {
            $this->buffer = substr($this->buffer, 2);
        } elseif (strlen($this->buffer) >= 1 && $this->buffer[0] === "\n") {
            $this->buffer = substr($this->buffer, 1);
        }
    }

    /**
     * Find line end position (\r\n or \n) starting from offset.
     * Returns position after line ending, or false if not found.
     */
    private function find_line_end(int $offset): int|false
    {
        $len = strlen($this->buffer);

        for ($i = $offset; $i < $len; $i++) {
            if ($this->buffer[$i] === "\n") {
                return $i + 1;
            }
            if (
                $this->buffer[$i] === "\r" &&
                $i + 1 < $len &&
                $this->buffer[$i + 1] === "\n"
            ) {
                return $i + 2;
            }
        }

        return false;
    }

    /**
     * Emit body chunk to handler.
     */
    private function emit_body_chunk(string $data): void
    {
        if ($data === "") {
            return;
        }

        ($this->chunk_handler)([
            "type" => "body",
            "headers" => $this->current_headers,
            "data" => $data,
        ]);
    }

    /**
     * Emit chunk complete to handler.
     */
    private function emit_chunk_complete(): void
    {
        ($this->chunk_handler)([
            "type" => "complete",
            "headers" => $this->current_headers,
        ]);
    }
}

class ImportClient
{
    private $remote_url;
    private $local_path;
    private $state_file;
    private $last_progress_output = 0;
    private $progress_throttle = 1.0; // seconds
    private $session_id;

    public function __construct(string $remote_url, string $local_path)
    {
        $this->remote_url = rtrim($remote_url, "?&");
        $this->local_path = rtrim($local_path, "/");
        $this->state_file = $this->local_path . "/.import-state.json";

        // Generate or load session ID for snapshot tracking on server
        $this->session_id = $this->get_or_create_session_id();

        // Create directories
        if (!is_dir($this->local_path)) {
            mkdir($this->local_path, 0755, true);
        }
        if (!is_dir($this->local_path . "/filesystem-root")) {
            mkdir($this->local_path . "/filesystem-root", 0755, true);
        }
    }

    /**
     * Run the import process.
     *
     * @param array $options Options for controlling import behavior:
     *   - only_files: Import only files phase
     *   - only_sql: Import only SQL phase
     *   - only_deltas: Import only file deltas phase
     *   - reset: Reset state and start fresh
     */
    public function run(array $options = []): void
    {
        $state = $this->load_state();

        // Handle reset option
        if ($options["reset"] ?? false) {
            $state = [
                "phase" => "init",
                "files_cursor" => null,
                "sql_cursor" => null,
                "deltas_cursor" => null,
                "last_sync_time" => null,
            ];
            $this->save_state($state);
            $this->output_progress([
                "status" => "reset",
                "message" => "State reset",
            ]);
        }

        try {
            // Determine which phases to run
            $run_files =
                !($options["only_sql"] ?? false) &&
                !($options["only_deltas"] ?? false);
            $run_sql =
                !($options["only_files"] ?? false) &&
                !($options["only_deltas"] ?? false);
            $run_deltas =
                !($options["only_files"] ?? false) &&
                !($options["only_sql"] ?? false);

            // If only_* option specified, force that phase and reset its cursor
            if ($options["only_files"] ?? false) {
                $run_files = true;
                $run_sql = false;
                $run_deltas = false;
                $state["phase"] = "files";
                $state["files_cursor"] = null; // Force re-download
            }
            if ($options["only_sql"] ?? false) {
                $run_files = false;
                $run_sql = true;
                $run_deltas = false;
                $state["phase"] = "sql";
                $state["sql_cursor"] = null; // Force re-download
            }
            if ($options["only_deltas"] ?? false) {
                $run_files = false;
                $run_sql = false;
                $run_deltas = true;
                $state["phase"] = "deltas";
                $state["deltas_cursor"] = null; // Force re-download
            }

            // Phase 1: Download files
            if (
                $run_files &&
                ($state["phase"] === "files" || $state["phase"] === "init")
            ) {
                $this->output_progress([
                    "status" => "starting",
                    "phase" => "files",
                ]);
                $state["phase"] = "files";
                $this->save_state($state);

                $this->download_files($state);
                $state["files_cursor"] = null;
                $state["phase"] = "sql";
                $this->save_state($state);
            }

            // Phase 2: Download SQL
            if ($run_sql && $state["phase"] === "sql") {
                $this->output_progress([
                    "status" => "starting",
                    "phase" => "sql",
                ]);
                $this->download_sql($state);
                $state["sql_cursor"] = null;
                $state["phase"] = "deltas";
                $state["last_sync_time"] = time();
                $this->save_state($state);
            }

            // Phase 3: Download file deltas (changes since last sync)
            if ($run_deltas && $state["phase"] === "deltas") {
                // Skip deltas if we just did files (no point getting deltas right after full sync)
                if ($options["only_files"] ?? false) {
                    $this->output_progress([
                        "status" => "skipped",
                        "phase" => "deltas",
                        "message" => "Skipping deltas after full file download",
                    ]);
                } else {
                    $this->output_progress([
                        "status" => "starting",
                        "phase" => "deltas",
                    ]);
                    $this->download_file_deltas($state);
                    $state["deltas_cursor"] = null;
                }
                $state["phase"] = "complete";
                $this->save_state($state);
            }

            $this->output_progress(["status" => "complete"]);
        } catch (Exception $e) {
            $this->output_progress([
                "status" => "error",
                "error" => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Download files from remote.
     */
    private function download_files(array &$state): void
    {
        $cursor = $state["files_cursor"] ?? null;
        $complete = false;

        while (!$complete) {
            $url = $this->build_url("files", $cursor);

            $context = new StreamingContext();
            $context->file_handle = null;
            $context->file_path = null;
            $context->file_ctime = null;

            $context->on_chunk = function ($chunk) use (
                &$cursor,
                &$complete,
                $context,
            ) {
                $cursor = $chunk["headers"]["x-cursor"] ?? $cursor;

                $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";
                error_log("ImportClient: Received chunk type: $chunk_type, cursor: " . substr($cursor ?? 'null', 0, 50));

                // Debug: Log what chunk types we're receiving
                static $chunk_counts = [];
                $chunk_counts[$chunk_type] =
                    ($chunk_counts[$chunk_type] ?? 0) + 1;
                if (
                    $chunk_counts[$chunk_type] === 1 ||
                    $chunk_counts[$chunk_type] % 10 === 0
                ) {
                    error_log(
                        "Chunk type received: {$chunk_type} (count: {$chunk_counts[$chunk_type]})",
                    );
                }

                if ($chunk_type === "metadata") {
                    $this->handle_metadata_chunk($chunk, $context);
                } elseif ($chunk_type === "file") {
                    error_log(
                        "Processing file chunk: " .
                            base64_decode(
                                $chunk["headers"]["x-file-path"] ?? "",
                            ),
                    );
                    $this->handle_file_chunk($chunk, $context);
                } elseif ($chunk_type === "directory") {
                    $this->handle_directory_chunk($chunk);
                } elseif ($chunk_type === "symlink") {
                    $this->handle_symlink_chunk($chunk);
                } elseif ($chunk_type === "deletion") {
                    $this->handle_deletion($chunk);
                } elseif ($chunk_type === "progress") {
                    $this->handle_progress($chunk, "files");
                } elseif ($chunk_type === "completion") {
                    // Close any open file handle
                    if ($context->file_handle) {
                        fclose($context->file_handle);
                        if ($context->file_ctime && $context->file_path) {
                            touch($context->file_path, $context->file_ctime);
                        }
                        $context->file_handle = null;
                    }

                    $complete =
                        ($chunk["headers"]["x-status"] ?? "") === "complete";
                    error_log("ImportClient: Completion chunk received, status=" . ($chunk["headers"]["x-status"] ?? "missing") . ", complete=" . ($complete ? 'true' : 'false'));

                    $progress_data = [
                        "phase" => "files",
                        "status" => $chunk["headers"]["x-status"] ?? "unknown",
                        "chunks_processed" =>
                            (int) ($chunk["headers"]["x-chunks-processed"] ??
                                0),
                        "files_completed" =>
                            (int) ($chunk["headers"]["x-files-completed"] ?? 0),
                        "bytes_processed" =>
                            (int) ($chunk["headers"]["x-bytes-processed"] ?? 0),
                    ];

                    // Add max_files info if available
                    if (isset($chunk["headers"]["x-max-files-limit"])) {
                        $progress_data["max_files_limit"] =
                            (int) $chunk["headers"]["x-max-files-limit"];
                    }

                    $this->output_progress($progress_data, true);
                }
            };

            $this->fetch_streaming($url, $cursor, $context);

            // Save cursor for resumption
            if (!$complete) {
                $state["files_cursor"] = $cursor;
                $this->save_state($state);
            }
        }
    }

    /**
     * Download SQL from remote.
     */
    private function download_sql(array &$state): void
    {
        $cursor = $state["sql_cursor"] ?? null;
        $complete = false;
        $sql_file = $this->local_path . "/db.sql";

        // Open in write mode if no cursor (starting fresh), append mode if resuming
        $sql_handle = fopen($sql_file, $cursor ? "a" : "w");

        if (!$sql_handle) {
            throw new RuntimeException("Cannot open SQL file: {$sql_file}");
        }

        try {
            while (!$complete) {
                $url = $this->build_url("sql", $cursor);

                $context = new StreamingContext();
                $context->sql_handle = $sql_handle;
                $context->chunk_fingerprints = [];
                $context->on_chunk = function ($chunk) use (
                    &$cursor,
                    &$complete,
                    $sql_handle,
                    $context,
                ) {
                    $cursor = $chunk["headers"]["x-cursor"] ?? $cursor;

                    $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

                    if ($chunk_type === "sql") {
                        fwrite($sql_handle, $chunk["body"]);
                    } elseif ($chunk_type === "progress") {
                        $this->handle_progress($chunk, "sql");
                    } elseif ($chunk_type === "completion") {
                        $complete =
                            ($chunk["headers"]["x-status"] ?? "") ===
                            "complete";
                        $this->output_progress(
                            [
                                "phase" => "sql",
                                "status" =>
                                    $chunk["headers"]["x-status"] ?? "unknown",
                                "batches_processed" =>
                                    (int) ($chunk["headers"][
                                        "x-batches-processed"
                                    ] ?? 0),
                            ],
                            true,
                        );
                    }
                };

                $this->fetch_streaming($url, $cursor, $context);

                // Save cursor for resumption
                if (!$complete) {
                    $state["sql_cursor"] = $cursor;
                    $this->save_state($state);
                }
            }
        } finally {
            fclose($sql_handle);
        }
    }

    /**
     * Download file deltas (changes since last sync).
     */
    private function download_file_deltas(array &$state): void
    {
        $cursor = $state["deltas_cursor"] ?? null;
        $min_ctime = $state["last_sync_time"] ?? 0;

        // If no last_sync_time, this is the first run - skip deltas
        if ($min_ctime === 0 || $min_ctime === null) {
            $this->output_progress([
                "status" => "skipped",
                "phase" => "deltas",
                "message" => "No previous sync time - skipping deltas",
            ]);
            return;
        }

        $complete = false;

        while (!$complete) {
            $url = $this->build_url("files", $cursor, [
                "min_ctime" => $min_ctime,
            ]);

            $context = new StreamingContext();
            $context->file_handle = null;
            $context->file_path = null;
            $context->file_ctime = null;

            $context->on_chunk = function ($chunk) use (
                &$cursor,
                &$complete,
                $context,
            ) {
                $cursor = $chunk["headers"]["x-cursor"] ?? $cursor;

                $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

                if ($chunk_type === "metadata") {
                    $this->handle_metadata_chunk($chunk, $context);
                } elseif ($chunk_type === "file") {
                    $this->handle_file_chunk($chunk, $context);
                } elseif ($chunk_type === "symlink") {
                    $this->handle_symlink_chunk($chunk);
                } elseif ($chunk_type === "deletion") {
                    $this->handle_deletion($chunk);
                } elseif ($chunk_type === "progress") {
                    $this->handle_progress($chunk, "deltas");
                } elseif ($chunk_type === "completion") {
                    // Close any open file handle
                    if ($context->file_handle) {
                        fclose($context->file_handle);
                        if ($context->file_ctime && $context->file_path) {
                            touch($context->file_path, $context->file_ctime);
                        }
                        $context->file_handle = null;
                    }

                    $complete =
                        ($chunk["headers"]["x-status"] ?? "") === "complete";
                    $this->output_progress(
                        [
                            "phase" => "deltas",
                            "status" =>
                                $chunk["headers"]["x-status"] ?? "unknown",
                            "chunks_processed" =>
                                (int) ($chunk["headers"][
                                    "x-chunks-processed"
                                ] ?? 0),
                            "files_completed" =>
                                (int) ($chunk["headers"]["x-files-completed"] ??
                                    0),
                            "bytes_processed" =>
                                (int) ($chunk["headers"]["x-bytes-processed"] ??
                                    0),
                        ],
                        true,
                    );
                }
            };

            $this->fetch_streaming($url, $cursor, $context);

            // Save cursor for resumption
            if (!$complete) {
                $state["deltas_cursor"] = $cursor;
                $this->save_state($state);
            }
        }
    }

    /**
     * Handle a metadata chunk from multipart response.
     */
    private function handle_metadata_chunk(
        array $chunk,
        StreamingContext $context,
    ): void {
        $headers = $chunk["headers"];
        $filesystem_root = base64_decode($headers["x-filesystem-root"] ?? "");

        if ($filesystem_root) {
            $context->filesystem_root = $filesystem_root;
            error_log("Filesystem root: {$filesystem_root}");
            $this->output_progress([
                "type" => "metadata",
                "filesystem_root" => $filesystem_root,
            ]);
        }
    }

    /**
     * Handle a file chunk from multipart response.
     */
    private function handle_file_chunk(
        array $chunk,
        StreamingContext $context,
    ): void {
        $headers = $chunk["headers"];
        $path = base64_decode($headers["x-file-path"] ?? "");
        $is_first = ($headers["x-first-chunk"] ?? "0") === "1";
        $is_last = ($headers["x-last-chunk"] ?? "0") === "1";

        if (!$path) {
            return;
        }

        // Use full path under filesystem-root
        $local_path = $this->local_path . "/filesystem-root" . $path;

        // Log path mapping for debugging
        if ($is_first) {
            error_log("File mapping: {$path} -> {$local_path}");
        }

        // Open file on first chunk
        if ($is_first) {
            // Close previous file if any
            if ($context->file_handle) {
                fclose($context->file_handle);
                if ($context->file_ctime && $context->file_path) {
                    touch($context->file_path, $context->file_ctime);
                }
            }

            // Create parent directory if needed
            $dir = dirname($local_path);
            if (!is_dir($dir)) {
                // Suppress warning if directory exists (race condition with symlinks/parallel operations)
                $result = @mkdir($dir, 0755, true);
                if (!$result && !is_dir($dir)) {
                    throw new RuntimeException(
                        "Failed to create directory: {$dir}\n" .
                            "Error: " .
                            (error_get_last()["message"] ?? "unknown"),
                    );
                }
            }

            // Open new file
            $context->file_handle = fopen($local_path, "wb");
            if (!$context->file_handle) {
                $error = error_get_last();
                throw new RuntimeException(
                    "Failed to open file for writing: {$local_path}\n" .
                        "Parent directory: {$dir}\n" .
                        "Directory exists: " .
                        (is_dir($dir) ? "yes" : "no") .
                        "\n" .
                        "Error: " .
                        ($error["message"] ?? "unknown"),
                );
            }
            $context->file_path = $local_path;
            $context->file_ctime = (int) ($headers["x-file-ctime"] ?? 0);
        }

        // Write body data if present
        if (isset($chunk["body"]) && $chunk["body"] !== "") {
            if ($context->file_handle) {
                fwrite($context->file_handle, $chunk["body"]);
            }
        }

        // Close on last chunk
        if ($is_last && $context->file_handle) {
            fclose($context->file_handle);

            // Set file modification time
            if ($context->file_ctime && $context->file_path) {
                touch($context->file_path, $context->file_ctime);
            }

            $context->file_handle = null;
            $context->file_path = null;
            $context->file_ctime = null;
        }
    }

    /**
     * Handle a directory chunk (create empty directory).
     */
    private function handle_directory_chunk(array $chunk): void
    {
        $headers = $chunk["headers"];
        $path = base64_decode($headers["x-directory-path"] ?? "");

        if (!$path) {
            return;
        }

        // Use full path under filesystem-root
        $local_path = $this->local_path . "/filesystem-root" . $path;

        // Create directory if it doesn't exist
        if (!is_dir($local_path)) {
            if (!mkdir($local_path, 0755, true) && !is_dir($local_path)) {
                throw new RuntimeException(
                    "Failed to create directory: {$local_path}",
                );
            }
        }
    }

    /**
     * Handle a symlink chunk (recreate symlink in filesystem).
     *
     * Symlinks are safely recreated because we download the complete directory
     * tree including all symlink targets. The paths are relative to the
     * filesystem root which prevents directory traversal outside the import directory.
     */
    private function handle_symlink_chunk(array $chunk): void
    {
        $headers = $chunk["headers"];
        $path = base64_decode($headers["x-symlink-path"] ?? "");
        $target = base64_decode($headers["x-symlink-target"] ?? "");
        $ctime = (int) ($headers["x-symlink-ctime"] ?? 0);

        // Skip if path or target is missing/empty
        if (!$path || $target === false || $target === "") {
            // return;
        }

        // Use full path under filesystem-root
        $local_path = realpath($this->local_path . "/filesystem-root") . $path;

        // Remove existing file/symlink if present
        if (file_exists($local_path) || is_link($local_path)) {
            error_log("Deleting existing file/symlink: {$local_path}");
            unlink($local_path);
        }

        // Log symlink creation for debugging
        error_log("Creating symlink: {$local_path} -> {$target}");

        // Create parent directory
        $dir = dirname($local_path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                // Log error and skip this symlink
                error_log("Failed to create directory for symlink: {$dir}");
                $this->output_progress([
                    "type" => "symlink_error",
                    "path" => $path,
                    "target" => $target,
                    "error" => "Failed to create parent directory",
                ]);
                return;
            }
        }

        // Create symlink
        $symlink_result = symlink($target, $local_path);
        if (true !== $symlink_result || !is_link($local_path)) {
            // Log error and skip this symlink
            error_log("Failed to create symlink: {$local_path} -> {$target}");
            $this->output_progress([
                "type" => "symlink_error",
                "path" => $path,
                "target" => $target,
                "error" => "Failed to create symlink",
            ]);
            return;
        }

        // Try to set the ctime (may not work on all systems)
        if ($ctime > 0) {
            @touch($local_path, $ctime);
        }

        $this->output_progress([
            "type" => "symlink",
            "path" => $path,
            "target" => $target,
        ]);
    }

    /**
     * Handle a deletion notification.
     */
    private function handle_deletion(array $chunk): void
    {
        $body = $chunk["body"] ?? "";
        $data = json_decode($body, true);
        if (!$data || !isset($data["path"])) {
            return;
        }

        $local_path = $this->local_path . "/filesystem-root" . $data["path"];
        if (file_exists($local_path)) {
            if (true !== @unlink($local_path)) {
                error_log("Failed to delete file: {$local_path}");
            } else {
                error_log("Deleted file: {$local_path}");
            }
        }

        $this->output_progress([
            "type" => "deletion",
            "path" => $data["path"],
        ]);
    }

    /**
     * Handle progress chunk.
     */
    private function handle_progress(array $chunk, string $phase): void
    {
        $body = $chunk["body"] ?? "";
        $data = json_decode($body, true);
        if (!$data) {
            return;
        }

        $this->output_progress(array_merge(["phase" => $phase], $data));
    }

    /**
     * Build request URL with operation and cursor.
     */
    private function build_url(
        string $operation,
        ?string $cursor,
        array $params = [],
    ): string {
        $url = $this->remote_url;
        $separator = strpos($url, "?") === false ? "?" : "&";

        $params["operation"] = $operation;
        if ($cursor) {
            $params["cursor"] = $cursor;
        }

        // Add session_id for snapshot tracking (enables deletion detection)
        if ($this->session_id) {
            $params["session_id"] = $this->session_id;
        }

        return $url . $separator . http_build_query($params);
    }

    /**
     * Fetch URL with streaming multipart parsing.
     */
    private function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
    ): void {
        error_log("ImportClient: Fetching URL: $url");
        $this->output_progress(["debug" => "Requesting: $url"]);
        $ch = curl_init($url);

        $parser = null;
        $current_chunk = null;
        $bytes_received = 0;
        $last_heartbeat = microtime(true);
        $last_progress_check = microtime(true);
        $last_bytes_received = 0;
        $error_body = "";

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => $cursor ? ["X-Export-Cursor: {$cursor}"] : [],
            CURLOPT_HEADERFUNCTION => function ($ch, $header_line) use (
                &$parser,
                $context,
                &$current_chunk,
            ) {
                $len = strlen($header_line);

                // Parse Content-Type to extract boundary
                if (stripos($header_line, "Content-Type:") === 0) {
                    // Find boundary parameter
                    $pos = stripos($header_line, "boundary=");
                    if ($pos !== false) {
                        $boundary_start = $pos + 9; // length of 'boundary='
                        $boundary_value = substr($header_line, $boundary_start);
                        $boundary_value = trim($boundary_value);

                        // Remove quotes if present
                        if ($boundary_value[0] === '"') {
                            $quote_end = strpos($boundary_value, '"', 1);
                            if ($quote_end !== false) {
                                $boundary_value = substr(
                                    $boundary_value,
                                    1,
                                    $quote_end - 1,
                                );
                            }
                        } else {
                            // Find end (semicolon, comma, or whitespace)
                            $end_pos = strcspn($boundary_value, ";,\r\n \t");
                            $boundary_value = substr(
                                $boundary_value,
                                0,
                                $end_pos,
                            );
                        }

                        if ($boundary_value !== "") {
                            error_log("ImportClient: Creating multipart parser with boundary: $boundary_value");
                            $parser = new MultipartStreamParser(
                                $boundary_value,
                                function ($event) use (
                                    $context,
                                    &$current_chunk,
                                ) {
                                    if ($event["type"] === "body") {
                                        // Accumulate body data in current chunk
                                        if (!$current_chunk) {
                                            $current_chunk = [
                                                "headers" => $event["headers"],
                                                "body" => $event["data"],
                                            ];
                                        } else {
                                            $current_chunk["body"] =
                                                ($current_chunk["body"] ?? "") .
                                                $event["data"];
                                        }
                                    } elseif ($event["type"] === "complete") {
                                        // Chunk complete - emit to handler
                                        if ($current_chunk) {
                                            if ($context->on_chunk) {
                                                ($context->on_chunk)(
                                                    $current_chunk,
                                                );
                                            }
                                        } elseif ($event["headers"]) {
                                            // No body data - emit just headers
                                            if ($context->on_chunk) {
                                                ($context->on_chunk)([
                                                    "headers" =>
                                                        $event["headers"],
                                                    "body" => "",
                                                ]);
                                            }
                                        }
                                        $current_chunk = null;
                                    }
                                },
                            );
                        }
                    }
                }

                return $len;
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (
                &$parser,
                &$bytes_received,
                &$last_heartbeat,
                &$last_progress_check,
                &$last_bytes_received,
                &$error_body,
            ) {
                // If no parser yet, we might be receiving an error response
                if (!$parser) {
                    $error_body .= $data;
                    static $logged_no_parser = false;
                    if (!$logged_no_parser && strlen($error_body) > 0) {
                        error_log("ImportClient: No parser, accumulating error body (first 500 chars): " . substr($error_body, 0, 500));
                        $logged_no_parser = true;
                    }
                }

                if ($parser) {
                    $parser->feed($data);
                }

                $bytes_received += strlen($data);

                // Check for stuck/slow transfer every 5 seconds
                $now = microtime(true);
                if ($now - $last_progress_check >= 5.0) {
                    $bytes_since_check = $bytes_received - $last_bytes_received;
                    $rate = $bytes_since_check / 5.0; // bytes per second

                    echo json_encode([
                        "progress_check" => true,
                        "bytes_received" => $bytes_received,
                        "bytes_last_5s" => $bytes_since_check,
                        "rate_bps" => round($rate),
                    ]) . "\n";
                    flush();

                    // If we're receiving less than 1KB/s for 5 seconds, something is wrong
                    if ($bytes_since_check < 1024 && $bytes_received > 0) {
                        error_log(
                            "Warning: Slow transfer detected - {$bytes_since_check} bytes in 5 seconds",
                        );
                    }

                    $last_progress_check = $now;
                    $last_bytes_received = $bytes_received;
                }

                // Output heartbeat every second
                if ($now - $last_heartbeat >= 1.0) {
                    echo json_encode([
                        "heartbeat" => true,
                        "bytes_received" => $bytes_received,
                    ]) . "\n";
                    flush();
                    $last_heartbeat = $now;
                }

                return strlen($data);
            },
        ]);

        error_log("ImportClient: Executing curl request...");
        $this->output_progress(["debug" => "Waiting for server response..."]);
        $result = curl_exec($ch);
        error_log("ImportClient: curl_exec completed, result=" . ($result === false ? 'false' : 'true'));

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL error: {$error}");
        }

        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            $error_msg = "HTTP error {$http_code}";

            // Try to parse error response as JSON
            if ($error_body) {
                $error_data = json_decode($error_body, true);
                if ($error_data && isset($error_data["error"])) {
                    $error_msg .= ": " . $error_data["error"];
                    if (isset($error_data["trace"])) {
                        $error_msg .=
                            "\n\nStack trace:\n" . $error_data["trace"];
                    }
                } else {
                    // Not JSON, show raw body
                    $error_msg .=
                        "\n\nResponse: " . substr($error_body, 0, 500);
                }
            }

            throw new RuntimeException($error_msg);
        }
    }

    /**
     * Get or create a session ID for snapshot tracking.
     * Session ID is persistent across import runs.
     */
    private function get_or_create_session_id(): string
    {
        $session_file = $this->local_path . "/.import-session-id";

        if (file_exists($session_file)) {
            $session_id = trim(file_get_contents($session_file));
            if ($session_id) {
                return $session_id;
            }
        }

        // Generate new session ID
        $session_id = "import-" . bin2hex(random_bytes(16));
        file_put_contents($session_file, $session_id);

        return $session_id;
    }

    /**
     * Load import state from disk.
     */
    private function load_state(): array
    {
        if (!file_exists($this->state_file)) {
            return [
                "phase" => "init",
                "files_cursor" => null,
                "sql_cursor" => null,
                "deltas_cursor" => null,
                "last_sync_time" => null,
            ];
        }

        $state = json_decode(file_get_contents($this->state_file), true);
        return $state ?: [
                "phase" => "init",
                "files_cursor" => null,
                "sql_cursor" => null,
                "deltas_cursor" => null,
                "last_sync_time" => null,
            ];
    }

    /**
     * Save import state to disk.
     */
    private function save_state(array $state): void
    {
        file_put_contents(
            $this->state_file,
            json_encode($state, JSON_PRETTY_PRINT),
        );
    }

    /**
     * Output progress as JSON line.
     *
     * @param array $data Progress data to output
     * @param bool $force Force output regardless of throttle
     */
    private function output_progress(array $data, bool $force = false): void
    {
        $now = microtime(true);

        // Always output status changes
        $is_status_change =
            isset($data["status"]) &&
            in_array($data["status"], ["starting", "complete", "error"]);

        // Output if forced, status change, or throttle time passed
        if (
            $force ||
            $is_status_change ||
            $now - $this->last_progress_output >= $this->progress_throttle
        ) {
            echo json_encode($data) . "\n";
            flush();
            $this->last_progress_output = $now;
        }
    }
}

/**
 * Context object passed to streaming callbacks.
 */
class StreamingContext
{
    public $on_chunk = null;
    public $sql_handle = null;
    public $file_handle = null;
    public $file_path = null;
    public $file_ctime = null;
    public $filesystem_root = null;
}

// ============================================================================
// CLI Entry Point
// ============================================================================

// Only run CLI logic if this file is executed directly (not included/required)
if (
    PHP_SAPI === "cli" &&
    isset($argv) &&
    realpath($argv[0] ?? "") === __FILE__
) {
    if ($argc < 3) {
        echo "Usage: php import.php <remote-url> <local-path> [options]\n";
        echo "\n";
        echo "Arguments:\n";
        echo "  remote-url   URL to export.php script with required parameters:\n";
        echo "               - directory: Directory to export (use directory[] for multiple)\n";
        echo "               - SECRET_KEY: Authentication key (required)\n";
        echo "               Example: http://example.com/export.php?directory=/var/www/html&SECRET_KEY=xxx\n";
        echo "               Multiple: http://example.com/export.php?directory[]=/srv&directory[]=/wordpress&SECRET_KEY=xxx\n";
        echo "  local-path   Local directory to store imported data\n";
        echo "\n";
        echo "Options:\n";
        echo "  --only-files     Import only files (initial download)\n";
        echo "  --only-sql       Import only database dump\n";
        echo "  --only-deltas    Import only file changes since last sync\n";
        echo "  --reset          Reset state and start fresh import\n";
        echo "\n";
        echo "Notes:\n";
        echo "  - Snapshot tracking is automatic (enables deletion detection)\n";
        echo "  - Session ID is stored in <local-path>/.import-session-id\n";
        echo "  - Snapshots are stored on the server in /tmp/export-snapshots/\n";
        echo "\n";
        echo "Examples:\n";
        echo "  # Full import (files, then SQL, then deltas)\n";
        echo "  php import.php 'http://example.com/export.php?directory=/var/www/html&SECRET_KEY=xxx' ./backup\n";
        echo "\n";
        echo "  # Re-download just the database\n";
        echo "  php import.php 'http://example.com/export.php?directory=/var/www&SECRET_KEY=xxx' ./backup --only-sql\n";
        echo "\n";
        echo "  # Get file updates only (detects deletions via snapshot)\n";
        echo "  php import.php 'http://example.com/export.php?directory=/var/www&SECRET_KEY=xxx' ./backup --only-deltas\n";
        echo "\n";
        echo "  # Start fresh (ignores previous state)\n";
        echo "  php import.php 'http://example.com/export.php?directory=/var/www&SECRET_KEY=xxx' ./backup --reset\n";
        echo "\n";
        echo "Output:\n";
        echo "  - Progress is reported as JSON lines to stdout\n";
        echo "  - SQL data is written to <local-path>/db.sql\n";
        echo "  - Files are written to <local-path>/filesystem-root/\n";
        echo "  - Script can be interrupted and will resume on next run\n";
        exit(1);
    }

    $remote_url = $argv[1];
    $local_path = $argv[2];

    // Parse options
    $options = [
        "only_files" => false,
        "only_sql" => false,
        "only_deltas" => false,
        "reset" => false,
    ];

    for ($i = 3; $i < $argc; $i++) {
        switch ($argv[$i]) {
            case "--only-files":
                $options["only_files"] = true;
                break;
            case "--only-sql":
                $options["only_sql"] = true;
                break;
            case "--only-deltas":
                $options["only_deltas"] = true;
                break;
            case "--reset":
                $options["reset"] = true;
                break;
            default:
                fwrite(STDERR, "Unknown option: {$argv[$i]}\n");
                exit(1);
        }
    }

    try {
        $client = new ImportClient($remote_url, $local_path);
        $client->run($options);
        exit(0);
    } catch (Exception $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
        fwrite(STDERR, $e->getTraceAsString() . "\n");
        exit(1);
    }
}

