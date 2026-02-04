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
    private $index_file; // Local index of imported files for delta detection (sorted TSV)
    private $index_updates_file; // Temp file collecting sorted index updates this run
    private $index_updates_handle;
    private $index_updates_count = 0;
    private $last_update_path = null;
    private $last_update_delete = null;
    private $last_update_ctime = null;
    private $last_update_size = null;
    private $remote_index_file; // Path to latest remote index TSV
    private $download_list_file; // Path to file list for downloads
    private $audit_log; // Audit log file for all operations
    private $verbose_mode = false; // Whether to show verbose output
    private $is_tty; // Whether stdout is a TTY
    private $files_imported = 0; // Counter for imported files
    private $state; // Current import state
    private $chunks_since_save = 0; // Track chunks for periodic saves
    private $shutdown_requested = false; // Flag for graceful shutdown
    private $current_curl_handle = null; // Active curl handle for abort

    public function __construct(string $remote_url, string $local_path)
    {
        $this->remote_url = rtrim($remote_url, "?&");
        $this->local_path = rtrim($local_path, "/");
        $this->state_file = $this->local_path . "/.import-state.json";
        $this->index_file = $this->local_path . "/.import-index.tsv";
        $this->index_updates_file =
            $this->local_path . "/.import-index-updates.tsv";
        $this->remote_index_file =
            $this->local_path . "/.import-remote-index.tsv";
        $this->download_list_file =
            $this->local_path . "/.import-download-list.txt";
        $this->audit_log = $this->local_path . "/.import-audit.log";

        // Detect TTY for progress display
        $this->is_tty = function_exists("posix_isatty") && posix_isatty(STDOUT);

        // Register signal handlers for graceful shutdown
        if (function_exists("pcntl_signal")) {
            // Enable async signals (PHP 7.1+) so signals work during blocking operations
            if (function_exists("pcntl_async_signals")) {
                pcntl_async_signals(true);
            }
            pcntl_signal(SIGINT, [$this, "handle_shutdown"]);
            pcntl_signal(SIGTERM, [$this, "handle_shutdown"]);
        }

        // Create directories
        if (!is_dir($this->local_path)) {
            mkdir($this->local_path, 0755, true);
        }
        if (!is_dir($this->local_path . "/filesystem-root")) {
            mkdir($this->local_path . "/filesystem-root", 0755, true);
        }
    }

    /**
     * Return current index size.
     */
    private function index_count(): int
    {
        if (!file_exists($this->index_file)) {
            return 0;
        }
        $h = fopen($this->index_file, "r");
        if (!$h) {
            return 0;
        }
        $c = 0;
        while (fgets($h) !== false) {
            $c++;
        }
        fclose($h);
        return $c;
    }

    /**
     * Upsert a file entry in the index.
     */
    private function upsert_index_entry(
        string $path,
        int $ctime,
        int $size,
    ): void {
        $this->record_index_update_file($path, $ctime, $size);
    }

    /**
     * Delete a file entry from the index.
     */
    private function delete_index_entry(string $path): void
    {
        $this->record_index_update_deletion($path);
    }

    /**
     * Recover and merge any pending index updates from a previous run.
     */
    private function recover_index_updates(): void
    {
        if (
            $this->index_updates_file &&
            file_exists($this->index_updates_file)
        ) {
            $this->finalize_index_updates();
        }
    }

    /**
     * Log to audit file (always) and optionally to console.
     *
     * @param string $message Message to log
     * @param bool $to_console Whether to also output to console (respects verbose mode)
     */
    private function audit_log(string $message, bool $to_console = true): void
    {
        $timestamp = date("Y-m-d H:i:s");
        $log_line = "[{$timestamp}] {$message}\n";

        // Always write to audit log
        file_put_contents($this->audit_log, $log_line, FILE_APPEND);

        // Output to console if verbose mode or if explicitly requested
        if ($to_console && $this->verbose_mode) {
            echo $log_line;
        }
    }

    /**
     * Show progress in a single refreshing line (TTY mode only).
     * Truncates long messages to fit terminal width.
     *
     * @param string $message Progress message
     */
    private function show_progress_line(string $message): void
    {
        if ($this->is_tty && !$this->verbose_mode) {
            // Get terminal width (default 80 if can't detect)
            $width = 80;
            if (function_exists("exec")) {
                $tput_cols = @exec("tput cols 2>/dev/null");
                if ($tput_cols && is_numeric($tput_cols)) {
                    $width = (int) $tput_cols;
                }
            }

            // Truncate message if too long, leaving room for "..."
            if (strlen($message) > $width - 3) {
                $message = substr($message, 0, $width - 3) . "...";
            }

            // Clear line and write progress
            echo "\r\033[K" . $message;
            flush();
        }
    }

    /**
     * Clear progress line and move to next line (TTY mode only).
     */
    private function clear_progress_line(): void
    {
        if ($this->is_tty && !$this->verbose_mode) {
            echo "\r\033[K";
        }
    }

    /**
     * Run the import process with explicit command validation.
     *
     * @param array $options Options:
     *   - command: Required. One of: files-sync-initial, files-sync-delta, sql-sync
     *   - restart: Optional. Force restart of completed command
     *   - verbose: Optional. Enable verbose output
     */
    public function run(array $options = []): void
    {
        $this->verbose_mode = $options["verbose"] ?? false;
        $command = $options["command"] ?? null;
        $restart = $options["restart"] ?? false;

        if (!$command) {
            throw new InvalidArgumentException(
                "Command is required. Valid commands: files-sync-initial, files-sync-delta, sql-sync",
            );
        }

        if (
            !in_array($command, [
                "files-sync-initial",
                "files-sync-delta",
                "sql-sync",
            ])
        ) {
            throw new InvalidArgumentException(
                "Invalid command: {$command}. Valid commands: files-sync-initial, files-sync-delta, sql-sync",
            );
        }

        $this->state = $this->load_state();

        // Dispatch to appropriate command handler
        try {
            switch ($command) {
                case "files-sync-initial":
                    $this->run_files_sync_initial($restart);
                    break;

                case "files-sync-delta":
                    $this->run_files_sync_delta($restart);
                    break;

                case "sql-sync":
                    $this->run_sql_sync($restart);
                    break;
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
     * Command: files-sync-initial
     *
     * Rules:
     * - If target directory is empty: request new session id, start sync
     * - If not empty and last state is files-sync-initial: resume using cursor
     * - If restart flag: clear state and start fresh
     * - Otherwise: error
     */
    private function run_files_sync_initial(bool $restart): void
    {
        $state_command = $this->state["command"] ?? null;
        $has_cursor =
            $state_command === "files-sync-initial" &&
            !empty($this->state["cursor"] ?? null);
        $current_status =
            $state_command === "files-sync-initial"
                ? $this->state["status"] ?? null
                : null;
        $filesystem_root = $this->local_path . "/filesystem-root";
        $is_empty =
            !is_dir($filesystem_root) || count(scandir($filesystem_root)) <= 2; // only . and ..

        // Handle restart flag
        if ($restart) {
            $this->audit_log(
                "RESTART | Clearing files-sync-initial state and starting fresh",
                true,
            );
            $this->state = $this->default_state();

            if (file_exists($this->index_file)) {
                @unlink($this->index_file);
                $this->audit_log("FILE DELETE | {$this->index_file}");
            }
            if (
                $this->index_updates_file &&
                file_exists($this->index_updates_file)
            ) {
                @unlink($this->index_updates_file);
                $this->audit_log("FILE DELETE | {$this->index_updates_file}");
            }
            $this->index_updates_file = null;
            $this->index_updates_handle = null;
            $this->index_updates_count = 0;

            if (file_exists($this->remote_index_file)) {
                @unlink($this->remote_index_file);
                $this->audit_log("FILE DELETE | {$this->remote_index_file}");
            }
            if (file_exists($this->download_list_file)) {
                @unlink($this->download_list_file);
                $this->audit_log("FILE DELETE | {$this->download_list_file}");
            }
            $this->save_state($this->state);
            $has_cursor = false;
            $current_status = null;
        }

        $this->recover_index_updates();

        // Check if already completed
        if ($current_status === "complete" && !$restart) {
            throw new RuntimeException(
                "files-sync-initial already completed. Use --restart flag to start over.",
            );
        }

        // Validate state: if no cursor and target not empty, refuse to proceed
        if (!$has_cursor && !$is_empty) {
            throw new RuntimeException(
                "Target directory is not empty and no cursor found. " .
                    "Either clear the target directory or use --restart flag.",
            );
        }

        // Start new run only when no cursor is available
        if ($has_cursor) {
            // Resuming - reset counter to 0 for this session (we'll count new completions)
            $this->files_imported = 0;
            $index_size = $this->index_count();

            $this->audit_log(
                sprintf(
                    "RESUME files-sync-initial | cursor=%s | indexed_files=%d",
                    substr($this->state["cursor"], 0, 20) . "...",
                    $index_size,
                ),
                true,
            );

            if (!$this->verbose_mode) {
                echo "Resuming files-sync-initial\n";
                echo "  Already indexed: {$index_size} files\n";
            }
        } else {
            $this->state["command"] = "files-sync-initial";
            $this->state["status"] = "in_progress";
            $this->state["cursor"] = null;
            $this->state["stage"] = null;
            $this->state["diff"] = $this->default_state()["diff"];
            $this->save_state($this->state);

            $this->audit_log("START files-sync-initial", true);

            if (!$this->verbose_mode) {
                echo "Starting files-sync-initial\n";
            }
        }

        $this->state["command"] = "files-sync-initial";
        $this->save_state($this->state);

        // Execute sync (no client state for initial sync)
        do {
            $completed = $this->download_file_stream("file_stream", null);

            // Mark status based on completion
            $this->state["status"] = $completed ? "complete" : "partial";
            $this->save_state($this->state);
        } while (!$completed);

        $this->clear_progress_line();
        $index_size = $this->index_count();
        $this->audit_log(
            sprintf(
                "files-sync-initial %s: %d files indexed",
                $completed ? "complete" : "partial",
                $index_size,
            ),
            true,
        );

        if (!$this->verbose_mode) {
            echo "files-sync-initial " .
                ($completed ? "complete" : "partial") .
                ": {$index_size} files indexed\n";
            echo "Audit log: {$this->audit_log}\n";
        }
    }

    /**
     * Command: files-sync-delta
     *
     * Rules:
     * - If has index and just finished files-sync-initial: download remote index
     * - Diff locally and build a download list
     * - Fetch only changed/new files
     * - If already completed: require --restart flag
     * - Otherwise: error
     */
    private function run_files_sync_delta(bool $restart): void
    {
        $state_command = $this->state["command"] ?? null;
        $current_status =
            $state_command === "files-sync-delta"
                ? $this->state["status"] ?? null
                : null;
        $stage =
            $state_command === "files-sync-delta"
                ? $this->state["stage"] ?? null
                : null;

        if ($restart) {
            $this->audit_log(
                "RESTART | Clearing files-sync-delta state and starting fresh",
                true,
            );
            $this->state = $this->default_state();
            if (file_exists($this->remote_index_file)) {
                @unlink($this->remote_index_file);
                $this->audit_log("FILE DELETE | {$this->remote_index_file}");
            }
            if (file_exists($this->download_list_file)) {
                @unlink($this->download_list_file);
                $this->audit_log("FILE DELETE | {$this->download_list_file}");
            }
            $this->save_state($this->state);
            $current_status = null;
            $stage = null;
        }

        $this->recover_index_updates();

        if ($current_status === "complete" && !$restart) {
            throw new RuntimeException(
                "files-sync-delta already completed. Use --restart flag to start a new delta sync.",
            );
        }

        if ($this->index_count() === 0) {
            throw new RuntimeException(
                "No import index found. You must run files-sync-initial first.",
            );
        }

        if (
            !file_exists($this->index_file) ||
            filesize($this->index_file) === 0
        ) {
            throw new RuntimeException(
                "files-sync-initial has not completed. Run files-sync-initial first.",
            );
        }

        // When starting the index stage fresh (not resuming a previous delta run),
        // clear the cursor so we don't reuse a stale cursor from files-sync-initial
        $starting_index_fresh = $stage === null;
        $stage = $stage ?? "index";

        $this->state["status"] = "in_progress";
        $this->state["command"] = "files-sync-delta";
        $this->state["stage"] = $stage;
        if ($starting_index_fresh) {
            $this->audit_log(
                "DELTA INDEX FRESH | clearing cursor and remote index for new delta sync",
            );
            $this->state["cursor"] = null;
            if (file_exists($this->remote_index_file)) {
                @unlink($this->remote_index_file);
                $this->audit_log("FILE DELETE | {$this->remote_index_file}");
            }
        }
        $this->save_state($this->state);

        $this->files_imported = 0;
        $index_size = $this->index_count();
        $this->audit_log(
            "START files-sync-delta | index_files={$index_size} | stage={$stage}",
            true,
        );

        if (!$this->verbose_mode) {
            echo "Starting files-sync-delta\n";
            echo "  Index contains: {$index_size} files\n";
            echo "  Stage: {$stage}\n";
        }

        if ($stage === "index") {
            $complete = $this->download_remote_index();
            if (!$complete) {
                $this->state["status"] = "partial";
                $this->save_state($this->state);
                return;
            }

            $this->state["stage"] = "diff";
            $this->state["cursor"] = null;
            $this->state["diff"] = $this->default_state()["diff"];
            if (file_exists($this->download_list_file)) {
                @unlink($this->download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->download_list_file} | clearing before diff stage",
                );
            }
            $this->save_state($this->state);
            $stage = "diff";
        }

        if ($stage === "diff") {
            $complete = $this->diff_indexes_and_build_fetch_list();
            if (!$complete) {
                $this->state["status"] = "partial";
                $this->save_state($this->state);
                return;
            }

            $has_downloads =
                file_exists($this->download_list_file) &&
                filesize($this->download_list_file) > 0;
            $this->state["stage"] = $has_downloads ? "fetch" : null;
            $this->save_state($this->state);
            $stage = $has_downloads ? "fetch" : null;

            // Clean up empty download list when no files need fetching
            if (!$has_downloads && file_exists($this->download_list_file)) {
                @unlink($this->download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->download_list_file} | no files to fetch",
                );
            }
        }

        if ($stage === "fetch") {
            $complete = $this->download_files_from_list();
            if (!$complete) {
                $this->state["status"] = "partial";
                $this->save_state($this->state);
                return;
            }
            $this->state["stage"] = null;
            $this->state["cursor"] = null;
            $this->save_state($this->state);

            // Clean up download list after successful fetch
            if (file_exists($this->download_list_file)) {
                @unlink($this->download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->download_list_file} | fetch complete",
                );
            }
        }

        $this->state["status"] = "complete";
        $this->save_state($this->state);

        $this->clear_progress_line();
        $index_size = $this->index_count();
        $this->audit_log(
            sprintf("files-sync-delta complete: %d files indexed", $index_size),
            true,
        );

        if (!$this->verbose_mode) {
            echo "files-sync-delta complete: {$index_size} files indexed\n";
            echo "Audit log: {$this->audit_log}\n";
        }
    }

    /**
     * Command: sql-sync
     *
     * Rules:
     * - Stream next portion of SQL from last saved cursor
     * - If already completed and db.sql exists: require --restart flag
     * - If db.sql missing but state says complete: warn and require --restart flag
     * - Otherwise: error
     */
    private function run_sql_sync(bool $restart): void
    {
        $state_command = $this->state["command"] ?? null;
        $sql_file = $this->local_path . "/db.sql";

        $has_cursor =
            $state_command === "sql-sync" &&
            !empty($this->state["cursor"] ?? null);
        $current_status =
            $state_command === "sql-sync"
                ? $this->state["status"] ?? null
                : null;
        $sql_exists = file_exists($sql_file);

        // Handle restart flag
        if ($restart) {
            $this->audit_log(
                "RESTART | Clearing sql-sync state and starting fresh",
                true,
            );
            $this->state = $this->default_state();
            $this->save_state($this->state);
            $has_cursor = false;
            $current_status = null;

            // Remove existing SQL file on restart
            if ($sql_exists) {
                unlink($sql_file);
                $this->audit_log(
                    "FILE DELETE | {$sql_file} | restart sql-sync",
                );
                $sql_exists = false;
            }
        }

        // Check if already completed
        if ($current_status === "complete") {
            if ($sql_exists && !$restart) {
                throw new RuntimeException(
                    "sql-sync already completed and db.sql exists. Use --restart flag to start over.",
                );
            } elseif (!$sql_exists && !$restart) {
                throw new RuntimeException(
                    "sql-sync marked complete but db.sql is missing. Use --restart flag to re-sync.",
                );
            }
        }

        // Starting fresh SQL sync
        if (!$has_cursor) {
            $this->state["command"] = "sql-sync";
            $this->state["status"] = "in_progress";
            $this->state["cursor"] = null;
            $this->state["stage"] = null;
            $this->state["diff"] = $this->default_state()["diff"];
            $this->save_state($this->state);

            $this->audit_log("START sql-sync", true);

            if (!$this->verbose_mode) {
                echo "Starting sql-sync\n";
            }
        } else {
            // Resuming SQL sync
            $this->audit_log(
                sprintf(
                    "RESUME sql-sync | cursor=%s",
                    substr($this->state["cursor"], 0, 20) . "...",
                ),
                true,
            );

            if (!$this->verbose_mode) {
                echo "Resuming sql-sync\n";
            }
        }

        $this->state["command"] = "sql-sync";
        $this->save_state($this->state);

        $this->output_progress([
            "status" => "starting",
            "phase" => "sql",
        ]);

        // Execute SQL sync
        $this->download_sql();

        // Mark as complete
        $this->state["status"] = "complete";
        $this->save_state($this->state);

        $this->audit_log("sql-sync complete", true);

        if (!$this->verbose_mode) {
            echo "sql-sync complete\n";
            echo "SQL file: {$sql_file}\n";
            echo "Audit log: {$this->audit_log}\n";
        }
    }

    /**
     * Download file content stream from a server endpoint.
     *
     * @param string $endpoint Endpoint name (file_stream or file_fetch)
     * @param array|null $post_data Optional POST data
     */
    private function download_file_stream(
        string $endpoint,
        ?array $post_data,
    ): bool {
        $cursor = $this->state["cursor"] ?? null;
        $complete = false;
        $this->chunks_since_save = 0;

        // Crash recovery: if we have a tracked file that's larger than expected,
        // truncate it. This happens if we crashed after writing but before saving
        // the new cursor, so we'll re-fetch the same data.
        $tracked_file = $this->state["current_file"] ?? null;
        $tracked_bytes = $this->state["current_file_bytes"] ?? null;
        if ($tracked_file !== null && $tracked_bytes !== null && file_exists($tracked_file)) {
            $actual_size = filesize($tracked_file);
            if ($actual_size > $tracked_bytes) {
                $this->audit_log(
                    sprintf(
                        "CRASH RECOVERY | Truncating %s from %d to %d bytes",
                        $tracked_file,
                        $actual_size,
                        $tracked_bytes,
                    ),
                    true,
                );
                $handle = fopen($tracked_file, "r+");
                if ($handle) {
                    ftruncate($handle, $tracked_bytes);
                    fclose($handle);
                }
            }
        }

        $url = $this->build_url($endpoint, $cursor, [], null);
        $this->audit_log("Downloading file stream from {$url}");
        $this->audit_log("POST data: " . json_encode($post_data));

        $context = new StreamingContext();
        $context->file_handle = null;
        $context->file_path = null;
        $context->file_ctime = null;

        $context->on_chunk = function ($chunk) use (
            &$cursor,
            &$complete,
            $context,
        ) {
            if ($this->shutdown_requested) {
                throw new RuntimeException("Shutdown requested");
            }

            if (function_exists("pcntl_signal_dispatch")) {
                pcntl_signal_dispatch();
            }

            $this->chunks_since_save++;
            if ($this->chunks_since_save >= 50) {
                $this->state["cursor"] = $cursor;
                // Track current file for crash recovery
                if ($context->file_handle && $context->file_path) {
                    // Flush to ensure bytes are on disk before saving state
                    fflush($context->file_handle);
                    $this->state["current_file"] = $context->file_path;
                    $this->state["current_file_bytes"] = $context->file_bytes_written;
                } else {
                    $this->state["current_file"] = null;
                    $this->state["current_file_bytes"] = null;
                }
                $this->save_state($this->state);
                $this->chunks_since_save = 0;
            }

            if (isset($chunk["headers"]["x-cursor"])) {
                $cursor = $chunk["headers"]["x-cursor"];
            }

            $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

            if ($chunk_type === "metadata") {
                $this->handle_metadata_chunk($chunk, $context);
            } elseif ($chunk_type === "file") {
                $this->handle_file_chunk($chunk, $context);
            } elseif ($chunk_type === "directory") {
                $this->handle_directory_chunk($chunk);
            } elseif ($chunk_type === "symlink") {
                $this->handle_symlink_chunk($chunk);
            } elseif ($chunk_type === "missing") {
                $path = base64_decode($chunk["headers"]["x-file-path"] ?? "");
                if ($path) {
                    $this->audit_log("Missing on server: {$path}", true);
                }
            } elseif ($chunk_type === "progress") {
                $this->handle_progress($chunk, "files");
            } elseif ($chunk_type === "completion") {
                $complete =
                    ($chunk["headers"]["x-status"] ?? "") === "complete";
                $this->output_progress(
                    [
                        "phase" => "files",
                        "status" => $chunk["headers"]["x-status"] ?? "unknown",
                        "files_completed" =>
                            (int) ($chunk["headers"]["x-files-completed"] ?? 0),
                        "bytes_processed" =>
                            (int) ($chunk["headers"]["x-bytes-processed"] ?? 0),
                    ],
                    true,
                );
            }
        };

        $this->fetch_streaming($url, $cursor, $context, $post_data);
        $this->finalize_index_updates();
        $this->state["cursor"] = $cursor;
        // Update file tracking: track in-progress file, or clear if complete/no active file
        if ($context->file_handle && $context->file_path) {
            fflush($context->file_handle);
            $this->state["current_file"] = $context->file_path;
            $this->state["current_file_bytes"] = $context->file_bytes_written;
        } else {
            $this->state["current_file"] = null;
            $this->state["current_file_bytes"] = null;
        }
        $this->save_state($this->state);

        return $complete;
    }

    /**
     * Download the remote index stream and write to disk.
     */
    private function download_remote_index(): bool
    {
        $cursor = $this->state["cursor"] ?? null;
        $mode = $cursor ? "a" : "w";
        if ($mode === "w") {
            $this->audit_log(
                "FILE CREATE | {$this->remote_index_file} | downloading fresh remote index",
            );
        } else {
            $this->audit_log(
                "FILE APPEND | {$this->remote_index_file} | resuming remote index download",
            );
        }
        $handle = fopen($this->remote_index_file, $mode);
        if (!$handle) {
            throw new RuntimeException("Failed to open remote index file");
        }

        $complete = false;
        $this->chunks_since_save = 0;
        $url = $this->build_url("file_index", $cursor, [], null);
        $context = new StreamingContext();

        $context->on_chunk = function ($chunk) use (
            &$cursor,
            &$complete,
            $handle,
            $context,
        ) {
            if ($this->shutdown_requested) {
                throw new RuntimeException("Shutdown requested");
            }

            if (function_exists("pcntl_signal_dispatch")) {
                pcntl_signal_dispatch();
            }

            $this->chunks_since_save++;
            if ($this->chunks_since_save >= 50) {
                $this->state["cursor"] = $cursor;
                $this->save_state($this->state);
                $this->chunks_since_save = 0;
            }

            if (isset($chunk["headers"]["x-cursor"])) {
                $cursor = $chunk["headers"]["x-cursor"];
            }

            $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

            if ($chunk_type === "index_batch") {
                // Batched format: gzipped TSV (path\tctime\tsize per line)
                $body = $chunk["body"] ?? "";
                $encoding = $chunk["headers"]["content-encoding"] ?? "";

                // Decompress if gzipped
                if ($encoding === "gzip") {
                    $body = gzdecode($body);
                }

                // Write TSV lines directly to the index file
                if ($body !== false && $body !== "") {
                    fwrite($handle, $body);
                    // Ensure newline at end if not present
                    if (substr($body, -1) !== "\n") {
                        fwrite($handle, "\n");
                    }
                }
            } elseif ($chunk_type === "index") {
                // Legacy single-entry format (backwards compatibility)
                $path = base64_decode($chunk["headers"]["x-index-path"] ?? "");
                $ctime = (int) ($chunk["headers"]["x-file-ctime"] ?? 0);
                $size = (int) ($chunk["headers"]["x-file-size"] ?? 0);
                if ($path !== "") {
                    fwrite($handle, "{$path}\t{$ctime}\t{$size}\n");
                }
            } elseif ($chunk_type === "symlink") {
                // Legacy symlink format (backwards compatibility)
                $path = base64_decode(
                    $chunk["headers"]["x-symlink-path"] ?? "",
                );
                $ctime = (int) ($chunk["headers"]["x-symlink-ctime"] ?? 0);
                if ($path !== "") {
                    fwrite($handle, "{$path}\t{$ctime}\t0\n");
                }
            } elseif ($chunk_type === "progress") {
                $this->handle_progress($chunk, "index");
            } elseif ($chunk_type === "metadata") {
                $this->handle_metadata_chunk($chunk, $context);
            } elseif ($chunk_type === "completion") {
                $complete =
                    ($chunk["headers"]["x-status"] ?? "") === "complete";
            }
        };

        $this->fetch_streaming($url, $cursor, $context, null);
        fclose($handle);

        $this->state["cursor"] = $cursor;
        $this->save_state($this->state);

        return $complete;
    }

    /**
     * Diff local index against remote index and build download list.
     */
    private function diff_indexes_and_build_fetch_list(): bool
    {
        if (!file_exists($this->remote_index_file)) {
            throw new RuntimeException("Remote index file not found");
        }

        $diff = $this->state["diff"] ?? [];
        $remote_offset = (int) ($diff["remote_offset"] ?? 0);
        $local_after = $diff["local_after"] ?? null;
        $download_mode = $remote_offset > 0 ? "a" : "w";
        if ($download_mode === "w") {
            $this->audit_log(
                "FILE CREATE | {$this->download_list_file} | building download list",
            );
        } else {
            $this->audit_log(
                "FILE APPEND | {$this->download_list_file} | resuming download list build",
            );
        }
        $download_handle = fopen($this->download_list_file, $download_mode);
        if (!$download_handle) {
            throw new RuntimeException("Failed to open download list file");
        }

        $remote_handle = fopen($this->remote_index_file, "r");
        if (!$remote_handle) {
            fclose($download_handle);
            throw new RuntimeException("Failed to open remote index file");
        }
        if ($remote_offset > 0) {
            fseek($remote_handle, $remote_offset);
        }

        $local_handle = file_exists($this->index_file)
            ? fopen($this->index_file, "r")
            : null;
        $local = $this->read_index_line($local_handle);
        if ($local_after) {
            while (
                $local !== null &&
                strcmp($local["path"], $local_after) <= 0
            ) {
                $local = $this->read_index_line($local_handle);
            }
        }
        $this->begin_index_updates();
        $processed = 0;

        while (($line = fgets($remote_handle)) !== false) {
            if ($this->shutdown_requested) {
                break;
            }

            if (function_exists("pcntl_signal_dispatch")) {
                pcntl_signal_dispatch();
            }

            $remote_offset = ftell($remote_handle);
            $remote = $this->parse_index_line($line);
            if (!$remote) {
                continue;
            }

            while (
                $local !== null &&
                strcmp($local["path"], $remote["path"]) < 0
            ) {
                $this->delete_local_file_path($local["path"]);
                $this->delete_index_entry($local["path"]);
                $local_after = $local["path"];
                $local = $this->read_index_line($local_handle);
            }

            if ($local !== null && $local["path"] === $remote["path"]) {
                if (
                    $local["ctime"] !== $remote["ctime"] ||
                    $local["size"] !== $remote["size"]
                ) {
                    $this->append_download_list(
                        $remote["path"],
                        $download_handle,
                    );
                }
                $local_after = $local["path"];
                $local = $this->read_index_line($local_handle);
            } elseif (
                $local === null ||
                strcmp($local["path"], $remote["path"]) > 0
            ) {
                $this->append_download_list($remote["path"], $download_handle);
            }

            $processed++;
            if ($processed % 200 === 0) {
                $this->state["diff"] = [
                    "remote_offset" => $remote_offset,
                    "local_after" => $local_after,
                ];
                $this->save_state($this->state);
            }
        }

        while ($local !== null) {
            $this->delete_local_file_path($local["path"]);
            $this->delete_index_entry($local["path"]);
            $local_after = $local["path"];
            $local = $this->read_index_line($local_handle);
        }

        if ($local_handle) {
            fclose($local_handle);
        }
        fclose($remote_handle);
        fclose($download_handle);

        $this->state["diff"] = [
            "remote_offset" => $remote_offset,
            "local_after" => $local_after,
        ];
        $this->save_state($this->state);

        $this->finalize_index_updates();

        return !$this->shutdown_requested;
    }

    /**
     * Download files from a prepared list.
     */
    private function download_files_from_list(): bool
    {
        if (!file_exists($this->download_list_file)) {
            return true;
        }

        if (filesize($this->download_list_file) === 0) {
            return true;
        }

        $post_data = [
            "file_list" => new CURLFile(
                $this->download_list_file,
                "text/plain",
                "file-list.txt",
            ),
        ];

        return $this->download_file_stream("file_fetch", $post_data);
    }

    /**
     * Append a path to the download list file.
     */
    private function append_download_list(string $path, $handle): void
    {
        fwrite($handle, $path . "\n");
        $this->audit_log("Download: {$path}", false);
    }

    /**
     * Delete a local file path safely under filesystem-root.
     */
    private function delete_local_file_path(string $path): void
    {
        if ($path === "" || $path[0] !== "/") {
            return;
        }
        $local_path = $this->local_path . "/filesystem-root" . $path;
        if (file_exists($local_path)) {
            if (true !== @unlink($local_path)) {
                $this->audit_log("Failed to delete: {$path}", true);
            } else {
                $this->audit_log("Deleted: {$path}", false);
            }
        }
    }

    /**
     * Parse one TSV index line into an array.
     */
    private function parse_index_line(string $line): ?array
    {
        $line = trim($line);
        if ($line === "") {
            return null;
        }
        $parts = explode("\t", $line);
        if (count($parts) < 3) {
            return null;
        }
        return [
            "path" => $parts[0],
            "ctime" => (int) $parts[1],
            "size" => (int) $parts[2],
        ];
    }

    /**
     * Start collecting index updates into a temp file for streaming merge.
     */
    private function begin_index_updates(): void
    {
        if ($this->index_updates_handle) {
            return;
        }
        $is_new = false;
        if ($this->index_updates_file === null) {
            $tmp = tempnam(sys_get_temp_dir(), "index-updates-");
            if ($tmp === false) {
                throw new RuntimeException(
                    "Failed to create temp index updates file",
                );
            }
            $this->index_updates_file = $tmp;
            $is_new = true;
        } elseif (!file_exists($this->index_updates_file)) {
            $dir = dirname($this->index_updates_file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $is_new = true;
        }
        $this->index_updates_handle = fopen($this->index_updates_file, "a");
        if (!$this->index_updates_handle) {
            throw new RuntimeException(
                "Failed to open temp index updates file",
            );
        }
        if ($is_new) {
            $this->audit_log(
                "FILE CREATE | {$this->index_updates_file} | index updates buffer",
            );
        }
        $this->index_updates_count = 0;
        $this->last_update_path = null;
        $this->last_update_delete = null;
        $this->last_update_ctime = null;
        $this->last_update_size = null;
    }

    /**
     * Record a file upsert into the index updates stream.
     */
    private function record_index_update_file(
        string $path,
        int $ctime,
        int $size,
    ): void {
        if (!$this->index_updates_handle) {
            $this->begin_index_updates();
        }
        if (
            $this->last_update_path === $path &&
            $this->last_update_delete === false &&
            $this->last_update_ctime === $ctime &&
            $this->last_update_size === $size
        ) {
            return;
        }
        $line = sprintf("F\t%s\t%d\t%d\n", $path, $ctime, $size);
        fwrite($this->index_updates_handle, $line);
        $this->index_updates_count++;
        $this->last_update_path = $path;
        $this->last_update_delete = false;
        $this->last_update_ctime = $ctime;
        $this->last_update_size = $size;
    }

    /**
     * Record a deletion into the index updates stream.
     */
    private function record_index_update_deletion(string $path): void
    {
        if (!$this->index_updates_handle) {
            $this->begin_index_updates();
        }
        if (
            $this->last_update_path === $path &&
            $this->last_update_delete === true
        ) {
            return;
        }
        $line = sprintf("D\t%s\n", $path);
        fwrite($this->index_updates_handle, $line);
        $this->index_updates_count++;
        $this->last_update_path = $path;
        $this->last_update_delete = true;
        $this->last_update_ctime = null;
        $this->last_update_size = null;
    }

    /**
     * Merge the collected updates with the existing sorted index without loading it into memory.
     */
    private function finalize_index_updates(): void
    {
        if ($this->index_updates_handle) {
            fclose($this->index_updates_handle);
            $this->index_updates_handle = null;
        }
        $this->last_update_path = null;
        $this->last_update_delete = null;
        $this->last_update_ctime = null;
        $this->last_update_size = null;

        $has_updates =
            $this->index_updates_count > 0 ||
            ($this->index_updates_file &&
                file_exists($this->index_updates_file) &&
                filesize($this->index_updates_file) > 0);

        if (!$has_updates) {
            if (
                $this->index_updates_file &&
                file_exists($this->index_updates_file)
            ) {
                @unlink($this->index_updates_file);
                $this->audit_log(
                    "FILE DELETE | {$this->index_updates_file} | no updates to merge",
                );
            }
            return;
        }

        $updates_path = $this->index_updates_file;
        $new_index = $this->index_file . ".new";

        $this->audit_log(
            "INDEX MERGE START | merging updates into {$this->index_file}",
        );

        $old_handle = file_exists($this->index_file)
            ? fopen($this->index_file, "r")
            : null;
        $upd_handle = fopen($updates_path, "r");
        $new_handle = fopen($new_index, "w");

        if (!$upd_handle || !$new_handle) {
            throw new RuntimeException("Failed to merge index updates");
        }

        $old = $this->read_index_line($old_handle);
        $carry = null;
        $upd = $this->read_update_line($upd_handle, $carry);
        $last_written_path = null;

        while ($old !== null || $upd !== null) {
            if ($upd === null) {
                if ($last_written_path !== $old["path"]) {
                    fwrite(
                        $new_handle,
                        sprintf(
                            "%s\t%d\t%d\n",
                            $old["path"],
                            $old["ctime"],
                            $old["size"],
                        ),
                    );
                    $last_written_path = $old["path"];
                }
                $old = $this->read_index_line($old_handle);
                continue;
            }

            if ($old === null) {
                if (!$upd["delete"] && $last_written_path !== $upd["path"]) {
                    fwrite(
                        $new_handle,
                        sprintf(
                            "%s\t%d\t%d\n",
                            $upd["path"],
                            $upd["ctime"],
                            $upd["size"],
                        ),
                    );
                    $last_written_path = $upd["path"];
                }
                $upd = $this->read_update_line($upd_handle, $carry);
                continue;
            }

            $cmp = strcmp($old["path"], $upd["path"]);
            if ($cmp === 0) {
                if (!$upd["delete"] && $last_written_path !== $upd["path"]) {
                    fwrite(
                        $new_handle,
                        sprintf(
                            "%s\t%d\t%d\n",
                            $upd["path"],
                            $upd["ctime"],
                            $upd["size"],
                        ),
                    );
                    $last_written_path = $upd["path"];
                }
                $old = $this->read_index_line($old_handle);
                $upd = $this->read_update_line($upd_handle, $carry);
            } elseif ($cmp < 0) {
                if ($last_written_path !== $old["path"]) {
                    fwrite(
                        $new_handle,
                        sprintf(
                            "%s\t%d\t%d\n",
                            $old["path"],
                            $old["ctime"],
                            $old["size"],
                        ),
                    );
                    $last_written_path = $old["path"];
                }
                $old = $this->read_index_line($old_handle);
            } else {
                if (!$upd["delete"] && $last_written_path !== $upd["path"]) {
                    fwrite(
                        $new_handle,
                        sprintf(
                            "%s\t%d\t%d\n",
                            $upd["path"],
                            $upd["ctime"],
                            $upd["size"],
                        ),
                    );
                    $last_written_path = $upd["path"];
                }
                $upd = $this->read_update_line($upd_handle, $carry);
            }
        }

        if ($old_handle) {
            fclose($old_handle);
        }
        fclose($upd_handle);
        fclose($new_handle);

        if (!rename($new_index, $this->index_file)) {
            throw new RuntimeException("Failed to replace index file");
        }
        $this->audit_log("INDEX MERGE COMPLETE | {$this->index_file} updated");

        @unlink($updates_path);
        $this->audit_log("FILE DELETE | {$updates_path} | updates merged");
    }

    /**
     * Read one TSV record from the on-disk index.
     */
    private function read_index_line($handle): ?array
    {
        if (!$handle) {
            return null;
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) >= 3) {
                if ($parts[0] === "F" && count($parts) >= 4) {
                    // Recover from accidental update lines in the index file.
                    return [
                        "path" => $parts[1],
                        "ctime" => (int) $parts[2],
                        "size" => (int) $parts[3],
                    ];
                }
                if ($parts[0] === "D") {
                    // Skip deletion markers accidentally written to the index.
                    continue;
                }
                return [
                    "path" => $parts[0],
                    "ctime" => (int) $parts[1],
                    "size" => (int) $parts[2],
                ];
            }
        }
        return null;
    }

    /**
     * Read one raw update record (F/D) from the updates file.
     */
    private function read_update_line_raw($handle): ?array
    {
        if (!$handle) {
            return null;
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) < 2) {
                continue;
            }
            if ($parts[0] === "D") {
                return [
                    "path" => $parts[1],
                    "delete" => true,
                    "ctime" => 0,
                    "size" => 0,
                ];
            } elseif ($parts[0] === "F" && count($parts) >= 4) {
                return [
                    "path" => $parts[1],
                    "delete" => false,
                    "ctime" => (int) $parts[2],
                    "size" => (int) $parts[3],
                ];
            }
        }
        return null;
    }

    /**
     * Read one update record, coalescing consecutive updates to the same path.
     *
     * @param mixed $handle Update file handle
     * @param array|null $carry Read-ahead buffer for the next record
     */
    private function read_update_line($handle, ?array &$carry = null): ?array
    {
        if (!$handle) {
            return null;
        }
        $current = $carry ?? $this->read_update_line_raw($handle);
        $carry = null;
        if ($current === null) {
            return null;
        }

        while (true) {
            $next = $this->read_update_line_raw($handle);
            if ($next === null) {
                return $current;
            }
            if ($next["path"] !== $current["path"]) {
                $carry = $next;
                return $current;
            }
            // Same path: keep the latest update.
            $current = $next;
        }
    }
    /**
     * Download SQL from remote.
     */
    private function download_sql(): void
    {
        $cursor = $this->state["cursor"] ?? null;
        $complete = false;
        $sql_file = $this->local_path . "/db.sql";

        // Crash recovery: if SQL file is larger than expected, truncate it.
        // This happens if we crashed after writing but before saving the new cursor.
        $tracked_bytes = $this->state["sql_bytes"] ?? null;
        if ($tracked_bytes !== null && file_exists($sql_file)) {
            $actual_size = filesize($sql_file);
            if ($actual_size > $tracked_bytes) {
                $this->audit_log(
                    sprintf(
                        "CRASH RECOVERY | Truncating db.sql from %d to %d bytes",
                        $actual_size,
                        $tracked_bytes,
                    ),
                    true,
                );
                $handle = fopen($sql_file, "r+");
                if ($handle) {
                    ftruncate($handle, $tracked_bytes);
                    fclose($handle);
                }
            }
        }

        // Log current progress at start of request
        $sql_size = file_exists($sql_file)
            ? filesize($sql_file)
            : 0;
        $has_cursor = $cursor !== null;
        $this->audit_log(
            sprintf(
                "START SQL REQUEST | cursor=%s | sql_size=%s",
                $has_cursor ? "YES" : "NO",
                number_format($sql_size) . " bytes",
            ),
            false,
        );

        // Track bytes written for crash recovery
        $sql_bytes_written = $sql_size;

        // Open in write mode if no cursor (starting fresh), append mode if resuming
        $sql_handle = fopen($sql_file, $cursor ? "a" : "w");

        if (!$sql_handle) {
            throw new RuntimeException("Cannot open SQL file: {$sql_file}");
        }

        try {
            while (!$complete) {
                $url = $this->build_url("sql_chunk", $cursor, [], null);

                $context = new StreamingContext();
                $context->sql_handle = $sql_handle;
                $context->chunk_fingerprints = [];
                $context->on_chunk = function ($chunk) use (
                    &$cursor,
                    &$complete,
                    &$sql_bytes_written,
                    $sql_handle,
                    $context,
                ) {
                    // Check if shutdown was requested
                    if ($this->shutdown_requested) {
                        throw new RuntimeException("Shutdown requested");
                    }

                    // Allow signal handlers to run
                    if (function_exists("pcntl_signal_dispatch")) {
                        pcntl_signal_dispatch();
                    }

                    $cursor = $chunk["headers"]["x-cursor"] ?? $cursor;

                    // Save cursor periodically (every 50 chunks)
                    $this->chunks_since_save++;
                    if ($this->chunks_since_save >= 50) {
                        // Flush to ensure bytes are on disk before saving state
                        fflush($sql_handle);
                        $this->state["cursor"] = $cursor;
                        $this->state["sql_bytes"] = $sql_bytes_written;
                        $this->save_state($this->state);
                        $this->chunks_since_save = 0;
                    }

                    $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

                    if ($chunk_type === "sql") {
                        $bytes = fwrite($sql_handle, $chunk["body"]);
                        if ($bytes !== false) {
                            $sql_bytes_written += $bytes;
                        }
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

                $this->fetch_streaming($url, $cursor, $context, null);

                // Save cursor for resumption (keep it even when complete for reference)
                fflush($sql_handle);
                $this->state["cursor"] = $cursor;
                // Clear sql_bytes when complete, otherwise save current position
                $this->state["sql_bytes"] = $complete ? null : $sql_bytes_written;
                $this->save_state($this->state);
            }
        } finally {
            fclose($sql_handle);
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
            $this->audit_log("Filesystem root: {$filesystem_root}", false);
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

        // Security: path must be absolute (start with /)
        if ($path[0] !== "/") {
            throw new RuntimeException(
                "Security: File path must be absolute: {$path}",
            );
        }

        // Use full path under filesystem-root
        $local_path = $this->local_path . "/filesystem-root" . $path;

        // Open file on first chunk
        if ($is_first) {
            // Check if file exists locally
            $exists_locally = file_exists($local_path);
            $local_size = $exists_locally ? filesize($local_path) : 0;
            $file_size = (int) ($headers["x-file-size"] ?? 0);

            // Log file import with useful context
            $this->audit_log(
                sprintf(
                    "File: %s (remote_size=%d, ctime=%d, local_exists=%s, local_size=%d)",
                    $path,
                    $file_size,
                    (int) ($headers["x-file-ctime"] ?? 0),
                    $exists_locally ? "yes" : "no",
                    $local_size,
                ),
                false,
            );

            // Show relative path (remove leading /)
            $relative_path = ltrim($path, "/");

            // Truncate from the left if too long (keep the end which is more distinctive)
            $max_path_len = 60;
            if (strlen($relative_path) > $max_path_len) {
                $relative_path =
                    "..." . substr($relative_path, -($max_path_len - 3));
            }

            $this->show_progress_line(
                sprintf("[%d files] %s", $this->files_imported, $relative_path),
            );
        }

        // Open file handle on first chunk
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
                // Check if any component of the path exists as a file and remove it
                $this->ensure_directory_path($dir);
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
            $context->file_bytes_written = 0;  // Reset byte counter for new file
        }

        // Write body data if present
        if (isset($chunk["body"]) && $chunk["body"] !== "") {
            if ($context->file_handle) {
                $bytes = fwrite($context->file_handle, $chunk["body"]);
                if ($bytes !== false) {
                    $context->file_bytes_written += $bytes;
                }
            }
        }

        // Close on last chunk
        if ($is_last && $context->file_handle) {
            fclose($context->file_handle);

            // Set file modification time
            if ($context->file_ctime && $context->file_path) {
                touch($context->file_path, $context->file_ctime);
            }

            // Index update (TSV)
            $file_size = (int) ($headers["x-file-size"] ?? 0);
            $final_size = file_exists($context->file_path)
                ? filesize($context->file_path)
                : 0;

            $file_changed = ($headers["x-file-changed"] ?? "0") === "1";

            if ($context->file_ctime && !$file_changed) {
                $this->upsert_index_entry(
                    $path,
                    $context->file_ctime,
                    $file_size,
                );
                $this->files_imported++; // Count completed files only
                $this->audit_log(
                    sprintf("  Indexed (wrote %d bytes)", $final_size),
                    false,
                );
            } elseif ($file_changed) {
                $this->audit_log(
                    "  File changed during stream; index not updated",
                    true,
                );
            }

            $context->file_handle = null;
            $context->file_path = null;
            $context->file_ctime = null;
            $context->file_bytes_written = 0;
            // Clear crash recovery tracking - file is complete
            $this->state["current_file"] = null;
            $this->state["current_file_bytes"] = null;
        }
    }

    /**
     * Ensure a directory path exists, removing any files that block it.
     *
     * @param string $dir Directory path to ensure
     * @throws RuntimeException if directory cannot be created or is outside allowed path
     */
    private function ensure_directory_path(string $dir): void
    {
        // Security: Ensure path is under filesystem-root
        $filesystem_root_base = $this->local_path . "/filesystem-root";
        $real_filesystem_root = realpath($filesystem_root_base);
        if ($real_filesystem_root === false) {
            // filesystem-root doesn't exist yet, create it first
            if (!is_dir($filesystem_root_base)) {
                mkdir($filesystem_root_base, 0755, true);
            }
            $real_filesystem_root = realpath($filesystem_root_base);
        }

        // Resolve the target path (or what it would be)
        // For non-existent paths, resolve the parent and append the final component
        $check_path = $dir;
        while (
            !file_exists($check_path) &&
            $check_path !== dirname($check_path)
        ) {
            $check_path = dirname($check_path);
        }

        if (file_exists($check_path)) {
            $real_check = realpath($check_path);
            if (
                $real_check === false ||
                strpos($real_check, $real_filesystem_root) !== 0
            ) {
                throw new RuntimeException(
                    "Security: Refusing to create directory outside filesystem-root: {$dir}",
                );
            }
        }

        if (is_dir($dir)) {
            return;
        }

        // For absolute paths starting with /, build from root
        // For relative paths, build incrementally
        $is_absolute = $dir[0] === "/";
        $parts = explode("/", $dir);
        $current = "";

        foreach ($parts as $i => $part) {
            // Skip empty parts except for the first one in absolute paths
            if ($part === "") {
                if ($i === 0 && $is_absolute) {
                    $current = "/";
                }
                continue;
            }

            // Build path incrementally
            if ($current === "") {
                $current = $part;
            } elseif ($current === "/") {
                $current = "/" . $part;
            } else {
                $current .= "/" . $part;
            }

            // Remove file if blocking directory creation
            if (is_file($current)) {
                $this->audit_log(
                    "Removing file blocking directory: {$current}",
                    true,
                );
                if (!unlink($current)) {
                    throw new RuntimeException(
                        "Failed to remove file blocking directory: {$current}",
                    );
                }
            }

            // Create directory if it doesn't exist
            if (!is_dir($current)) {
                if (!mkdir($current, 0755) && !is_dir($current)) {
                    throw new RuntimeException(
                        "Failed to create directory: {$current}\n" .
                            "Error: " .
                            (error_get_last()["message"] ?? "unknown"),
                    );
                }
            }
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

        // Security: path must be absolute (start with /)
        if ($path[0] !== "/") {
            throw new RuntimeException(
                "Security: Directory path must be absolute: {$path}",
            );
        }

        // Use full path under filesystem-root
        $local_path = $this->local_path . "/filesystem-root" . $path;

        // Create directory, removing any files that block the path
        $this->ensure_directory_path($local_path);

        $this->audit_log("Directory: {$path}", false);
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

        // Security: path must be absolute (start with /)
        if ($path[0] !== "/") {
            throw new RuntimeException(
                "Security: Symlink path must be absolute: {$path}",
            );
        }

        // Use full path under filesystem-root
        $local_path = realpath($this->local_path . "/filesystem-root") . $path;

        // Remove existing file/symlink if present
        if (file_exists($local_path) || is_link($local_path)) {
            unlink($local_path);
        }

        // Create parent directory
        $dir = dirname($local_path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                // Log error and skip this symlink
                $this->audit_log(
                    "Failed to create directory for symlink: {$dir}",
                    true,
                );
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
            $this->audit_log(
                "Failed to create symlink: {$local_path} -> {$target}",
                true,
            );
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

        $this->audit_log("Symlink: {$path} -> {$target}", false);

        if ($ctime > 0) {
            $this->upsert_index_entry($path, $ctime, 0);
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

        // Security: path must be absolute (start with /)
        if (!isset($data["path"][0]) || $data["path"][0] !== "/") {
            throw new RuntimeException(
                "Security: Deletion path must be absolute: " .
                    ($data["path"] ?? "empty"),
            );
        }

        $local_path = $this->local_path . "/filesystem-root" . $data["path"];
        if (file_exists($local_path)) {
            if (true !== @unlink($local_path)) {
                $this->audit_log("Failed to delete: {$data["path"]}", true);
            } else {
                $this->audit_log("Deleted: {$data["path"]}", false);

                // Show relative path for deletion
                $relative_path = ltrim($data["path"], "/");
                $max_path_len = 60;
                if (strlen($relative_path) > $max_path_len) {
                    $relative_path =
                        "..." . substr($relative_path, -($max_path_len - 3));
                }
                $this->show_progress_line("Deleted: " . $relative_path);

                // Remove from index after successful deletion
                $this->delete_index_entry($data["path"]);
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
     * Build request URL with endpoint and cursor.
     */
    private function build_url(
        string $endpoint,
        ?string $cursor,
        array $params = [],
        ?string $session_id = null,
    ): string {
        $url = $this->remote_url;
        $separator = strpos($url, "?") === false ? "?" : "&";

        $params["endpoint"] = $endpoint;
        if ($cursor) {
            // Also include cursor in query params as a fallback when headers are stripped.
            $params["cursor"] = $cursor;
        }

        // Add session_id for server to load client state
        if ($session_id) {
            $params["session_id"] = $session_id;
        }

        $params["_cache_bust"] = time() . "-" . rand(0, 999999);

        return $url . $separator . http_build_query($params);
    }

    /**
     * Fetch URL with streaming multipart parsing.
     */
    private function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data = null,
    ): void {
        // Log HTTP request details
        $log_parts = ["HTTP_REQUEST", $post_data ? "POST" : "GET", $url];

        if ($post_data && isset($post_data["file_list"])) {
            $file_list_part = $post_data["file_list"];
            if ($file_list_part instanceof CURLFile) {
                $upload_path = $file_list_part->getFilename();
                $upload_size = is_string($upload_path)
                    ? filesize($upload_path)
                    : false;
                $upload_size = $upload_size === false ? 0 : $upload_size;
                $log_parts[] = "file_list_file=" . $upload_size . "b";
            } else {
                $log_parts[] =
                    "file_list=" . strlen((string) $file_list_part) . "b";
            }
        }

        $this->audit_log(implode(" | ", $log_parts), false);

        $ch = curl_init($url);

        // Store curl handle so signal handler can abort it
        $this->current_curl_handle = $ch;

        $parser = null;
        $current_chunk = null;
        $bytes_received = 0;
        $last_heartbeat = microtime(true);
        $last_progress_check = microtime(true);
        $last_bytes_received = 0;
        $error_body = "";

        // Build headers to look like a real browser
        $headers = [
            "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8",
            "Accept-Language: en-US,en;q=0.9",
            "Accept-Encoding: gzip, deflate, br",
            "Cache-Control: no-cache",
            "Pragma: no-cache",
            "Connection: keep-alive",
            "Upgrade-Insecure-Requests: 1",
            "Sec-Fetch-Dest: document",
            "Sec-Fetch-Mode: navigate",
            "Sec-Fetch-Site: none",
            "Sec-Fetch-User: ?1",
        ];

        if ($cursor) {
            $headers[] = "X-Export-Cursor: {$cursor}";
        }

        // Configure POST data if provided
        if ($post_data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            $has_file = false;
            foreach ($post_data as $value) {
                if ($value instanceof CURLFile) {
                    $has_file = true;
                    break;
                }
            }
            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                $has_file ? $post_data : http_build_query($post_data),
            );
        }

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_ENCODING => "", // Auto-decompress gzip/deflate/br responses
            CURLOPT_HTTPHEADER => $headers,
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
                            $this->audit_log(
                                "Creating multipart parser with boundary: $boundary_value",
                                false,
                            );
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
                        $this->audit_log(
                            "No parser, accumulating error body (first 500 chars): " .
                                substr($error_body, 0, 500),
                            false,
                        );
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

                    // Only output progress_check in verbose mode or non-TTY
                    if ($this->verbose_mode || !$this->is_tty) {
                        echo json_encode([
                            "progress_check" => true,
                            "bytes_received" => $bytes_received,
                            "bytes_last_5s" => $bytes_since_check,
                            "rate_bps" => round($rate),
                        ]) . "\n";
                        flush();
                    }

                    // If we're receiving less than 1KB/s for 5 seconds, something is wrong
                    if ($bytes_since_check < 1024 && $bytes_received > 0) {
                        $this->audit_log(
                            "Warning: Slow transfer detected - {$bytes_since_check} bytes in 5 seconds",
                            false,
                        );
                    }

                    $last_progress_check = $now;
                    $last_bytes_received = $bytes_received;
                }

                // Output heartbeat every second (only in verbose/non-TTY mode)
                if ($now - $last_heartbeat >= 1.0) {
                    if ($this->verbose_mode || !$this->is_tty) {
                        echo json_encode([
                            "heartbeat" => true,
                            "bytes_received" => $bytes_received,
                        ]) . "\n";
                        flush();
                    }
                    $last_heartbeat = $now;
                }

                return strlen($data);
            },
        ]);

        $this->audit_log("Executing curl request...", false);
        $this->output_progress(["debug" => "Waiting for server response..."]);
        $result = curl_exec($ch);
        $this->audit_log(
            "curl_exec completed, result=" .
                ($result === false ? "false" : "true"),
            false,
        );

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL error: {$error}");
        }

        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Clear curl handle reference
        $this->current_curl_handle = null;

        if ($http_code !== 200) {
            $error_msg = "HTTP error {$http_code}";

            // Log what we received
            $this->audit_log(
                "HTTP error {$http_code} | error_body length: " .
                    strlen($error_body),
                true,
            );

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
                        "\n\nResponse: " . substr($error_body, 0, 1000);
                }
            } else {
                // No error body captured - server might have sent multipart response
                // Check server error log for details
                $error_msg .=
                    "\n\nNo error body received. Check server error log at:\n";
                $error_msg .=
                    "  " .
                    dirname(parse_url($url, PHP_URL_PATH)) .
                    "/error_log\n";
                $error_msg .= "  or enable display_errors on the server";
            }

            throw new RuntimeException($error_msg);
        }
    }

    /**
     * Return the default compact state structure.
     */
    private function default_state(): array
    {
        return [
            "command" => null,
            "status" => null,
            "cursor" => null,
            "stage" => null,
            "diff" => [
                "remote_offset" => 0,
                "local_after" => null,
            ],
            // Crash recovery: track in-progress file downloads
            // If we crash mid-write, we can truncate to the expected size on resume
            "current_file" => null,        // Path to file being written
            "current_file_bytes" => null,  // Expected bytes written so far
            // Crash recovery: track SQL file size
            "sql_bytes" => null,           // Expected SQL file size
        ];
    }

    /**
     * Normalize state array to the compact schema.
     */
    private function normalize_state(array $state): array
    {
        $defaults = $this->default_state();
        $state = array_intersect_key($state, $defaults);
        $state = array_merge($defaults, $state);
        $diff = $state["diff"];
        if (!is_array($diff)) {
            $diff = [];
        }
        $diff = array_intersect_key($diff, $defaults["diff"]);
        $state["diff"] = array_merge($defaults["diff"], $diff);
        return $state;
    }

    /**
     * Load import state from disk.
     */
    private function load_state(): array
    {
        if (!file_exists($this->state_file)) {
            return $this->default_state();
        }

        $state = json_decode(file_get_contents($this->state_file), true);
        if (!is_array($state)) {
            return $this->default_state();
        }

        return $this->normalize_state($state);
    }

    /**
     * Save import state to disk.
     *
     * Uses atomic write (temp file + rename) to prevent corruption if
     * the process is killed mid-write.
     */
    private function save_state(array $state): void
    {
        $state = $this->normalize_state($state);

        // Write to temp file first, then atomic rename
        $tmp_file = $this->state_file . '.tmp';
        file_put_contents($tmp_file, json_encode($state, JSON_PRETTY_PRINT));
        rename($tmp_file, $this->state_file);

        $indexed = $this->index_count();
        $files_imported = $this->files_imported; // Completed in this run
        $cursor_info = $state["cursor"] ? "cursor=saved" : "cursor=none";

        $this->audit_log(
            sprintf(
                "SAVE CURSOR | total_indexed=%d | completed_this_run=%d | %s",
                $indexed,
                $files_imported,
                $cursor_info,
            ),
            false,
        );
    }

    /**
     * Handle shutdown signals (SIGINT, SIGTERM).
     * Saves state before exiting.
     */
    public function handle_shutdown(int $signal): void
    {
        // Prevent multiple signal handling
        static $already_shutting_down = false;
        if ($already_shutting_down) {
            // Force kill on second signal
            if (
                function_exists("posix_kill") &&
                function_exists("posix_getpid")
            ) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            die("\nForced exit.\n");
        }
        $already_shutting_down = true;

        $this->shutdown_requested = true;
        $this->clear_progress_line();

        // Flush index updates so progress is not lost on interrupt
        try {
            $this->finalize_index_updates();
        } catch (Exception $e) {
            $this->audit_log(
                "Failed to finalize index updates on shutdown: " .
                    $e->getMessage(),
                true,
            );
        }

        // Log final progress before exit
        $indexed = $this->index_count();
        $files_imported = $this->files_imported; // Files completed in this run
        $current_command = $this->state["command"] ?? "unknown";

        $this->audit_log(
            sprintf(
                "SHUTDOWN REQUESTED | command=%s | total_indexed=%d files | completed_this_run=%d files",
                $current_command,
                $indexed,
                $files_imported,
            ),
            true,
        );

        if (!$this->verbose_mode) {
            echo "\nInterrupted - saving state...\n";
            echo "  Command: {$current_command}\n";
            echo "  Total files indexed: {$indexed}\n";
            echo "  Files completed in this run: {$files_imported}\n";
            flush();
        }

        // Save current state (with timeout protection)
        try {
            $this->save_state($this->state);
            if (!$this->verbose_mode) {
                echo "✓ State saved successfully\n";
            }
        } catch (Exception $e) {
            echo "Warning: Failed to save state: " . $e->getMessage() . "\n";
            flush();
        }

        if (!$this->verbose_mode) {
            echo "Exiting...\n";
            flush();
        }

        // CRITICAL: Use SIGKILL for immediate termination
        // Regular exit() hangs because PHP's shutdown sequence tries to
        // close the curl handle gracefully, which blocks waiting for server.
        // curl_close() also hangs when called during an active curl_exec().
        // SIGKILL bypasses all cleanup and terminates at OS level immediately.
        if (function_exists("posix_kill") && function_exists("posix_getpid")) {
            posix_kill(posix_getpid(), SIGKILL);
        }

        // Fallback if posix functions not available
        die();
    }

    /**
     * Output progress as JSON line.
     * Only outputs in verbose mode or non-TTY mode (for programmatic consumption).
     *
     * @param array $data Progress data to output
     * @param bool $force Force output regardless of throttle
     */
    private function output_progress(array $data, bool $force = false): void
    {
        // In TTY non-verbose mode, suppress JSON output (use show_progress_line instead)
        if ($this->is_tty && !$this->verbose_mode) {
            return;
        }

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
    public $chunk_fingerprints = [];
    public $need_client_slice = false;
    public $next_client_offset = 0;
    // Crash recovery: track bytes written for current file
    public $file_bytes_written = 0;
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
        echo "Usage: php import.php <remote-url> <local-path> <command> [options]\n";
        echo "\n";
        echo "Arguments:\n";
        echo "  remote-url   URL to export.php script with required parameters:\n";
        echo "               - directory: Directory to export (use directory[] for multiple)\n";
        echo "               - SECRET_KEY: Authentication key (required)\n";
        echo "               Example: http://example.com/export.php?directory=/var/www/html&SECRET_KEY=xxx\n";
        echo "  local-path   Local directory to store imported data\n";
        echo "  command      Command to execute (required)\n";
        echo "\n";
        echo "Commands:\n";
        echo "  files-sync-initial   Initial full file sync\n";
        echo "                       - If target empty: starts new sync\n";
        echo "                       - If in progress: resumes from cursor\n";
        echo "                       - If complete: requires --restart flag\n";
        echo "\n";
        echo "  files-sync-delta     Delta file sync (only changed/new/deleted files)\n";
        echo "                       - Requires completed files-sync-initial\n";
        echo "                       - Downloads remote index and diffs locally\n";
        echo "                       - If in progress: resumes from cursor\n";
        echo "                       - If complete: requires --restart flag\n";
        echo "\n";
        echo "  sql-sync             Download database dump\n";
        echo "                       - Streams SQL to db.sql file\n";
        echo "                       - If in progress: resumes from cursor\n";
        echo "                       - If complete: requires --restart flag\n";
        echo "\n";
        echo "Options:\n";
        echo "  --restart        Force restart of completed command (clears state)\n";
        echo "  --verbose, -v    Show detailed logs (default: show progress only)\n";
        echo "\n";
        echo "State Management:\n";
        echo "  - Each command tracks its own state (cursor, status, session)\n";
        echo "  - Interrupted commands automatically resume from last cursor\n";
        echo "  - Completed commands require --restart to run again\n";
        echo "  - State is stored in .import-state.json\n";
        echo "\n";
        echo "Examples:\n";
        echo "  # Initial full sync (downloads everything)\n";
        echo "  php import.php 'http://example.com/export.php?directory=/var/www&SECRET_KEY=xxx' ./backup files-sync-initial\n";
        echo "\n";
        echo "  # Resume interrupted initial sync (continues from cursor)\n";
        echo "  php import.php 'http://example.com/export.php?directory=/var/www&SECRET_KEY=xxx' ./backup files-sync-initial\n";
        echo "\n";
        echo "  # Delta sync (only download changes since last sync)\n";
        echo "  php import.php 'http://example.com/export.php?directory=/var/www&SECRET_KEY=xxx' ./backup files-sync-delta\n";
        echo "\n";
        echo "  # Restart completed delta sync\n";
        echo "  php import.php 'http://example.com/export.php?directory=/var/www&SECRET_KEY=xxx' ./backup files-sync-delta --restart\n";
        echo "\n";
        echo "  # Download database\n";
        echo "  php import.php 'http://example.com/export.php?directory=/var/www&SECRET_KEY=xxx' ./backup sql-sync\n";
        echo "\n";
        echo "Output:\n";
        echo "  - Progress reported as JSON lines to stdout (in verbose mode)\n";
        echo "  - SQL written to <local-path>/db.sql\n";
        echo "  - Files written to <local-path>/filesystem-root/\n";
        echo "  - State written to <local-path>/.import-state.json\n";
        echo "  - Index written to <local-path>/.import-index.tsv\n";
        echo "  - Audit log written to <local-path>/.import-audit.log\n";
        exit(1);
    }

    $remote_url = $argv[1];
    $local_path = $argv[2];
    $command = $argv[3] ?? null;

    if (!$command) {
        fwrite(STDERR, "Error: Command is required\n");
        fwrite(
            STDERR,
            "Valid commands: files-sync-initial, files-sync-delta, sql-sync\n",
        );
        exit(1);
    }

    // Parse options
    $options = [
        "command" => $command,
        "restart" => false,
        "verbose" => false,
    ];

    for ($i = 4; $i < $argc; $i++) {
        if ($argv[$i] === "--restart") {
            $options["restart"] = true;
        } elseif ($argv[$i] === "--verbose" || $argv[$i] === "-v") {
            $options["verbose"] = true;
        } else {
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

