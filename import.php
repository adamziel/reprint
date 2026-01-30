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

    public function __construct(string $remote_url, string $local_path)
    {
        $this->remote_url = rtrim($remote_url, "?&");
        $this->local_path = rtrim($local_path, "/");
        $this->state_file = $this->local_path . "/.import-state.json";

        // Create directories
        if (!is_dir($this->local_path)) {
            mkdir($this->local_path, 0755, true);
        }
        if (!is_dir($this->local_path . "/document-root")) {
            mkdir($this->local_path . "/document-root", 0755, true);
        }
    }

    /**
     * Run the import process.
     */
    public function run(): void
    {
        $state = $this->load_state();

        try {
            // Phase 1: Download files
            if ($state["phase"] === "files" || $state["phase"] === "init") {
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
            if ($state["phase"] === "sql") {
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
            if ($state["phase"] === "deltas") {
                $this->output_progress([
                    "status" => "starting",
                    "phase" => "deltas",
                ]);
                $this->download_file_deltas($state);
                $state["deltas_cursor"] = null;
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
        $previous_fingerprint = null;

        while (!$complete) {
            $url = $this->build_url("files", $cursor);

            $context = new StreamingContext();
            $context->file_handle = null;
            $context->file_path = null;
            $context->file_ctime = null;
            $context->chunk_fingerprints = [];

            $context->on_chunk = function ($chunk) use (
                &$cursor,
                &$complete,
                $context,
            ) {
                $cursor = $chunk["headers"]["x-cursor"] ?? $cursor;

                $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

                // Build fingerprint for this chunk
                $fingerprint_data = ["type" => $chunk_type];
                if ($chunk_type === "file") {
                    $fingerprint_data["path"] =
                        $chunk["headers"]["x-file-path"] ?? "";
                    $fingerprint_data["offset"] =
                        $chunk["headers"]["x-chunk-offset"] ?? "";
                    $fingerprint_data["size"] =
                        $chunk["headers"]["x-chunk-size"] ?? "";
                } elseif ($chunk_type === "progress") {
                    $fingerprint_data["body"] = $chunk["body"] ?? "";
                } elseif ($chunk_type === "completion") {
                    $fingerprint_data["status"] =
                        $chunk["headers"]["x-status"] ?? "";
                    $fingerprint_data["chunks"] =
                        $chunk["headers"]["x-chunks-processed"] ?? "";
                    $fingerprint_data["files"] =
                        $chunk["headers"]["x-files-completed"] ?? "";
                } elseif ($chunk_type === "deletion") {
                    $fingerprint_data["body"] = $chunk["body"] ?? "";
                }
                $context->chunk_fingerprints[] = json_encode($fingerprint_data);

                if ($chunk_type === "file") {
                    $this->handle_file_chunk($chunk, $context);
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
                    $this->output_progress(
                        [
                            "phase" => "files",
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

            // Detect stuck state (same chunks as previous operation)
            $current_fingerprint = implode("|", $context->chunk_fingerprints);
            if (
                $previous_fingerprint !== null &&
                $current_fingerprint === $previous_fingerprint
            ) {
                throw new RuntimeException(
                    "Import stuck: received identical chunks in two consecutive operations. We may be stuck? " .
                        "Phase: files",
                );
            }
            $previous_fingerprint = $current_fingerprint;

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
        $previous_fingerprint = null;
        $sql_file = $this->local_path . "/db.sql";
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

                    // Build fingerprint for this chunk
                    $fingerprint_data = ["type" => $chunk_type];
                    if ($chunk_type === "sql") {
                        // Hash the body content for SQL chunks
                        $fingerprint_data["hash"] = md5($chunk["body"] ?? "");
                        $fingerprint_data["length"] = strlen(
                            $chunk["body"] ?? "",
                        );
                    } elseif ($chunk_type === "progress") {
                        $fingerprint_data["body"] = $chunk["body"] ?? "";
                    } elseif ($chunk_type === "completion") {
                        $fingerprint_data["status"] =
                            $chunk["headers"]["x-status"] ?? "";
                        $fingerprint_data["batches"] =
                            $chunk["headers"]["x-batches-processed"] ?? "";
                    }
                    $context->chunk_fingerprints[] = json_encode(
                        $fingerprint_data,
                    );

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

                // Detect stuck state (same chunks as previous operation)
                $current_fingerprint = implode(
                    "|",
                    $context->chunk_fingerprints,
                );
                if (
                    $previous_fingerprint !== null &&
                    $current_fingerprint === $previous_fingerprint
                ) {
                    throw new RuntimeException(
                        "Import stuck: received identical chunks in two consecutive operations. " .
                            "Phase: sql",
                    );
                }
                $previous_fingerprint = $current_fingerprint;

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
        $complete = false;
        $previous_fingerprint = null;

        while (!$complete) {
            $url = $this->build_url("files", $cursor, [
                "min_ctime" => $min_ctime,
            ]);

            $context = new StreamingContext();
            $context->file_handle = null;
            $context->file_path = null;
            $context->file_ctime = null;
            $context->chunk_fingerprints = [];

            $context->on_chunk = function ($chunk) use (
                &$cursor,
                &$complete,
                $context,
            ) {
                $cursor = $chunk["headers"]["x-cursor"] ?? $cursor;

                $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

                // Build fingerprint for this chunk
                $fingerprint_data = ["type" => $chunk_type];
                if ($chunk_type === "file") {
                    $fingerprint_data["path"] =
                        $chunk["headers"]["x-file-path"] ?? "";
                    $fingerprint_data["offset"] =
                        $chunk["headers"]["x-chunk-offset"] ?? "";
                    $fingerprint_data["size"] =
                        $chunk["headers"]["x-chunk-size"] ?? "";
                } elseif ($chunk_type === "progress") {
                    $fingerprint_data["body"] = $chunk["body"] ?? "";
                } elseif ($chunk_type === "completion") {
                    $fingerprint_data["status"] =
                        $chunk["headers"]["x-status"] ?? "";
                    $fingerprint_data["chunks"] =
                        $chunk["headers"]["x-chunks-processed"] ?? "";
                    $fingerprint_data["files"] =
                        $chunk["headers"]["x-files-completed"] ?? "";
                } elseif ($chunk_type === "deletion") {
                    $fingerprint_data["body"] = $chunk["body"] ?? "";
                }
                $context->chunk_fingerprints[] = json_encode($fingerprint_data);

                if ($chunk_type === "file") {
                    $this->handle_file_chunk($chunk, $context);
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

            // Detect stuck state (same chunks as previous operation)
            $current_fingerprint = implode("|", $context->chunk_fingerprints);
            if (
                $previous_fingerprint !== null &&
                $current_fingerprint === $previous_fingerprint
            ) {
                throw new RuntimeException(
                    "Import stuck: received identical chunks in two consecutive operations. " .
                        "Phase: deltas",
                );
            }
            $previous_fingerprint = $current_fingerprint;

            // Save cursor for resumption
            if (!$complete) {
                $state["deltas_cursor"] = $cursor;
                $this->save_state($state);
            }
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

        // Make path relative to document-root
        $local_path = $this->local_path . "/document-root" . $path;
        $dir = dirname($local_path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
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

            // Open new file
            $context->file_handle = fopen($local_path, "wb");
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
     * Handle a deletion notification.
     */
    private function handle_deletion(array $chunk): void
    {
        $body = $chunk["body"] ?? "";
        $data = json_decode($body, true);
        if (!$data || !isset($data["path"])) {
            return;
        }

        $local_path = $this->local_path . "/document-root" . $data["path"];
        if (file_exists($local_path)) {
            @unlink($local_path);
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
        $ch = curl_init($url);

        $parser = null;
        $current_chunk = null;
        $bytes_received = 0;
        $last_heartbeat = microtime(true);

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
            ) {
                if ($parser) {
                    $parser->feed($data);
                }

                $bytes_received += strlen($data);

                // Output heartbeat every second
                $now = microtime(true);
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

        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL error: {$error}");
        }

        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            throw new RuntimeException("HTTP error {$http_code}");
        }
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
    public $chunk_fingerprints = [];
}

// ============================================================================
// CLI Entry Point
// ============================================================================

if (PHP_SAPI !== "cli") {
    die("This script must be run from the command line\n");
}

if ($argc < 3) {
    echo "Usage: php import.php <remote-url> <local-path>\n";
    echo "\n";
    echo "Arguments:\n";
    echo "  remote-url   URL to export.php script (e.g., http://example.com/export.php)\n";
    echo "  local-path   Local directory to store imported data\n";
    echo "\n";
    echo "Example:\n";
    echo "  php import.php http://example.com/export.php?directory=/var/www/html ./backup\n";
    echo "\n";
    echo "Output:\n";
    echo "  - Progress is reported as JSON lines to stdout\n";
    echo "  - SQL data is written to <local-path>/db.sql\n";
    echo "  - Files are written to <local-path>/document-root/\n";
    echo "  - Script can be interrupted and will resume on next run\n";
    exit(1);
}

$remote_url = $argv[1];
$local_path = $argv[2];

try {
    $client = new ImportClient($remote_url, $local_path);
    $client->run();
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}

