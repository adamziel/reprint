<?php
/**
 * File Synchronization with Streaming Two-Pointer Merge
 *
 * Architecture:
 * - Sessions store raw gzipped client index files
 * - Client index is streamed with gzopen/gzgets (never loaded into memory)
 * - Two-pointer merge runs incrementally across HTTP requests
 * - Cursor tracks positions in both server and client lists
 *
 * Memory usage:
 * - Server files: Array of metadata only (~100 bytes per file)
 * - Client files: Streamed line-by-line (0 bytes in memory)
 * - Can handle millions of files on shared hosting
 */

// =============================================================================
// SESSION MANAGEMENT
// =============================================================================

/**
 * Create a new file sync session from uploaded client index.
 * Stores raw gzipped data for streaming access via gzopen.
 *
 * @param array $upload $_FILES['client_index_gz'] entry
 * @return array ['session_id' => string, 'session_file' => string]
 */
function create_file_sync_session_from_upload(array $upload): array
{
    $error = $upload["error"] ?? UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(
            "client_index_gz upload failed with error code: {$error}",
        );
    }

    $tmp_name = $upload["tmp_name"] ?? "";
    if ($tmp_name === "" || !is_uploaded_file($tmp_name)) {
        throw new InvalidArgumentException(
            "client_index_gz upload missing or invalid",
        );
    }

    return create_file_sync_session_from_path($tmp_name, true);
}

/**
 * Create a new file sync session from a gzipped client index file on disk.
 * Stores raw gzipped data for streaming access via gzopen.
 *
 * @param string $client_index_path Path to gzipped client index (TSV format)
 * @param bool $is_uploaded_file Whether the source path is an uploaded file
 * @return array ['session_id' => string, 'session_file' => string]
 */
function create_file_sync_session_from_path(
    string $client_index_path,
    bool $is_uploaded_file = false,
): array {
    if (!is_file($client_index_path)) {
        throw new InvalidArgumentException(
            "client_index_gz file not found: {$client_index_path}",
        );
    }

    $sessions_dir = sys_get_temp_dir() . "/export-sessions";
    if (!is_dir($sessions_dir)) {
        mkdir($sessions_dir, 0755, true);
    }

    $session_id = bin2hex(random_bytes(16));
    $session_file = $sessions_dir . "/" . $session_id . ".gz";

    // Store raw gzipped data (for gzopen streaming)
    $stored = $is_uploaded_file
        ? move_uploaded_file($client_index_path, $session_file)
        : copy($client_index_path, $session_file);
    if ($stored === false) {
        throw new RuntimeException("Failed to create session file");
    }

    $size = filesize($session_file);
    $size_display = $size === false ? "unknown" : (string) $size;

    error_log(
        "Created file sync session {$session_id} | " .
            $size_display .
            " bytes (gzipped) | Streaming enabled",
    );

    return [
        "session_id" => $session_id,
        "session_file" => $session_file,
    ];
}

/**
 * Load an existing file sync session.
 * Returns session file path for streaming with gzopen.
 *
 * @param string $session_id Session ID
 * @return array ['session_id' => string, 'session_file' => string]
 * @throws InvalidArgumentException If session not found
 */
function load_file_sync_session(string $session_id): array
{
    // Validate session_id (alphanumeric only for security)
    if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $session_id)) {
        throw new InvalidArgumentException("Invalid session_id format");
    }

    $sessions_dir = sys_get_temp_dir() . "/export-sessions";
    $session_file = $sessions_dir . "/" . $session_id . ".gz";

    if (!file_exists($session_file)) {
        throw new InvalidArgumentException("Session not found: {$session_id}");
    }

    error_log(
        "Loaded file sync session {$session_id} | Streaming from {$session_file}",
    );

    return [
        "session_id" => $session_id,
        "session_file" => $session_file,
    ];
}

// =============================================================================
// FILE SYNC PRODUCER
// =============================================================================

/**
 * Streaming file sync producer with incremental two-pointer merge.
 *
 * Phases:
 * 1. SCANNING: Walk filesystem, collect file metadata (path, ctime, size)
 * 2. STREAMING: Stream files + do two-pointer merge with client index
 *
 * Two-pointer merge:
 * - Server list: Sorted array of file metadata in memory
 * - Client list: Streamed from gzipped file with gzopen/gzgets
 * - Compare on-the-fly, emit NEW/MODIFIED files, emit DELETED files
 * - Cursor saves positions in both lists for resumption
 */
class FileSyncProducer
{
    const PHASE_SCANNING = "scanning";
    const PHASE_STREAMING = "streaming";
    const PHASE_FINISHED = "finished";

    private $directories; // Array of directories to scan
    private $min_ctime;
    private $chunk_size;
    private $client_index_file; // Path to gzipped client index file for streaming
    private $deletions_count; // Count of deleted files emitted during merge
    private $follow_symlinks;
    private $filesystem_root;

    // State
    private $phase;
    private $current_chunk;

    // Scanning state
    private $scan_stack;
    private $scan_current_dir;
    private $scan_dir_handle;
    private $scanned_files; // Array of file metadata (path, ctime, size) - sorted by path
    private $scanned_directories;
    private $scanned_symlinks;
    private $visited_paths;
    private $scan_batch_size;
    private $scan_batch_count;

    // Streaming state (two-pointer merge)
    private $server_index; // Current index in scanned_files array
    private $client_stream; // gzopen handle for client index file
    private $client_offset; // Byte offset in client stream (for gzseek)
    private $client_current_line; // Cached current line from client stream
    private $streaming_file_handle; // Handle for current file being streamed
    private $streaming_file_offset; // Byte offset in current file
    private $files_streamed;
    private $empty_directories;
    private $empty_dir_index;
    private $symlink_index;

    public function __construct(string|array $directories, array $options = [])
    {
        // Accept both single directory (string) and multiple directories (array)
        if (is_string($directories)) {
            $this->directories = [rtrim($directories, "/")];
        } else {
            $this->directories = array_map(
                fn($d) => rtrim($d, "/"),
                $directories,
            );
        }

        $this->client_index_file = $options["client_index_file"] ?? null;
        $this->min_ctime = $options["min_ctime"] ?? 0;
        $this->chunk_size = $options["chunk_size"] ?? 5 * 1024 * 1024; // 5MB default
        $this->follow_symlinks = $options["follow_symlinks"] ?? true;

        if (isset($options["cursor"])) {
            $this->initialize_from_cursor($options["cursor"]);
        } else {
            $this->initialize_new();
        }
    }

    private function initialize_new(): void
    {
        $this->phase = self::PHASE_SCANNING;

        // Initialize scanning state
        $this->scan_stack = $this->directories;
        $this->scan_current_dir = null;
        $this->scan_dir_handle = null;
        $this->scanned_files = [];
        $this->scanned_directories = [];
        $this->scanned_symlinks = [];
        $this->visited_paths = [];
        $this->scan_batch_size = 10; // Scan 10 directories per chunk
        $this->scan_batch_count = 0;

        // Initialize streaming state
        $this->server_index = 0;
        $this->client_stream = null;
        $this->client_offset = 0;
        $this->client_current_line = null;
        $this->streaming_file_handle = null;
        $this->streaming_file_offset = 0;
        $this->files_streamed = 0;
        $this->empty_directories = [];
        $this->empty_dir_index = 0;
        $this->symlink_index = 0;

        $this->current_chunk = null;
        $this->deletions_count = 0;
    }

    private function initialize_from_cursor(string $cursor_json): void
    {
        $cursor = json_decode($cursor_json, true);
        if ($cursor === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                "Invalid cursor format: " . json_last_error_msg(),
            );
        }

        $this->phase = $cursor["p"];
        $this->filesystem_root = $cursor["fsr"] ?? null;
        $this->current_chunk = null;

        if ($this->phase === self::PHASE_SCANNING) {
            // Restart scanning (simpler than saving full scan state)
            $this->scan_stack = $this->directories;
            $this->scan_current_dir = null;
            $this->scan_dir_handle = null;
            $this->scanned_files = [];
            $this->scanned_directories = [];
            $this->scanned_symlinks = [];
            $this->visited_paths = [];
            $this->scan_batch_size = 10;
            $this->scan_batch_count = 0;
            $this->server_index = 0;
            $this->client_stream = null;
            $this->client_offset = 0;
            $this->client_current_line = null;
            $this->streaming_file_handle = null;
            $this->streaming_file_offset = 0;
            $this->files_streamed = 0;
            $this->empty_directories = [];
            $this->empty_dir_index = 0;
            $this->symlink_index = 0;
            $this->deletions_count = 0;
        } elseif ($this->phase === self::PHASE_STREAMING) {
            $this->files_streamed = $cursor["n"] ?? 0;
            $this->streaming_file_offset = $cursor["b"] ?? 0;
            $this->deletions_count = 0;

            // Two-pointer merge state
            $this->server_index = $cursor["si"] ?? 0;
            $this->client_offset = $cursor["co"] ?? 0;
            $this->client_current_line = null; // Will be read when streaming resumes
            $this->client_stream = null; // Will be opened when streaming starts

            // Re-scan to rebuild scanned_files array
            $this->rescan_for_resume();

            $this->streaming_file_handle = null;
        } else {
            $this->phase = self::PHASE_FINISHED;
        }
    }

    private function rescan_for_resume(): void
    {
        $this->scanned_files = [];
        $this->scanned_directories = [];
        $this->scanned_symlinks = [];
        $this->visited_paths = [];
        $this->empty_directories = [];
        $this->empty_dir_index = 0;
        $this->symlink_index = 0;

        $stack = $this->directories;

        foreach ($this->directories as $dir) {
            $root_real = realpath($dir);
            if ($root_real !== false) {
                $this->visited_paths[$root_real] = true;
            }
        }

        while (!empty($stack)) {
            $current_dir = array_pop($stack);
            $handle = @opendir($current_dir);

            if (!$handle) {
                continue;
            }

            $this->scanned_directories[$current_dir] = true;

            while (($entry = readdir($handle)) !== false) {
                if ($entry === "." || $entry === "..") {
                    continue;
                }

                $full_path = $current_dir . "/" . $entry;

                if (is_link($full_path)) {
                    $target = readlink($full_path);
                    if ($target !== false) {
                        $ctime = @filectime($full_path);
                        if ($ctime !== false) {
                            $this->scanned_symlinks[] = [
                                "path" => $full_path,
                                "target" => $target,
                                "ctime" => $ctime,
                            ];
                        }
                    }
                    continue;
                } elseif (is_dir($full_path)) {
                    $real_path = realpath($full_path);
                    if ($real_path !== false) {
                        if (isset($this->visited_paths[$real_path])) {
                            continue;
                        }
                        $this->visited_paths[$real_path] = true;
                    }
                    $stack[] = $full_path;
                } elseif (is_file($full_path)) {
                    $ctime = @filectime($full_path);
                    $size = @filesize($full_path);

                    if ($ctime === false || $size === false) {
                        continue;
                    }

                    // Apply min_ctime filter during scan
                    if ($this->min_ctime > 0 && $ctime <= $this->min_ctime) {
                        continue;
                    }

                    $this->scanned_files[] = [
                        "path" => $full_path,
                        "ctime" => $ctime,
                        "size" => $size,
                    ];
                }
            }

            closedir($handle);
        }

        // Sort by path for two-pointer merge
        usort($this->scanned_files, fn($a, $b) => strcmp($a["path"], $b["path"]));

        error_log(
            "Rescanned for resume: " .
                count($this->scanned_files) .
                " files (sorted by path)",
        );
    }

    public function next_chunk(): bool
    {
        if ($this->phase === self::PHASE_FINISHED) {
            return false;
        }

        if ($this->phase === self::PHASE_SCANNING) {
            $scan_complete = $this->scan_step();

            if ($scan_complete) {
                $this->finalize_scanning();
                // Fall through to streaming
            } else {
                // More scanning to do
                $this->current_chunk = null;
                return true;
            }
        }

        if ($this->phase === self::PHASE_STREAMING) {
            $this->stream_step();
            return $this->phase !== self::PHASE_FINISHED;
        }

        return false;
    }

    private function scan_step(): bool
    {
        // Initialize visited paths for root directories
        static $initialized_roots = false;
        if (!$initialized_roots) {
            foreach ($this->directories as $dir) {
                $real = realpath($dir);
                if ($real !== false) {
                    $this->visited_paths[$real] = true;
                }
            }
            $initialized_roots = true;
        }

        $this->scan_batch_count = 0;

        while (
            !empty($this->scan_stack) &&
            $this->scan_batch_count < $this->scan_batch_size
        ) {
            $current_dir = array_pop($this->scan_stack);
            $handle = @opendir($current_dir);

            if (!$handle) {
                continue;
            }

            $this->scanned_directories[$current_dir] = true;
            $this->scan_batch_count++;

            while (($entry = readdir($handle)) !== false) {
                if ($entry === "." || $entry === "..") {
                    continue;
                }

                $full_path = $current_dir . "/" . $entry;

                if (is_link($full_path)) {
                    $target = readlink($full_path);
                    if ($target !== false) {
                        $ctime = @filectime($full_path);
                        if ($ctime !== false) {
                            $this->scanned_symlinks[] = [
                                "path" => $full_path,
                                "target" => $target,
                                "ctime" => $ctime,
                            ];
                        }
                    }
                    continue;
                } elseif (is_dir($full_path)) {
                    $real_path = realpath($full_path);
                    if ($real_path !== false) {
                        if (isset($this->visited_paths[$real_path])) {
                            continue;
                        }
                        $this->visited_paths[$real_path] = true;
                    }
                    $this->scan_stack[] = $full_path;
                } elseif (is_file($full_path)) {
                    $ctime = @filectime($full_path);
                    $size = @filesize($full_path);

                    if ($ctime === false || $size === false) {
                        continue;
                    }

                    // Apply min_ctime filter during scan
                    if ($this->min_ctime > 0 && $ctime <= $this->min_ctime) {
                        continue;
                    }

                    $this->scanned_files[] = [
                        "path" => $full_path,
                        "ctime" => $ctime,
                        "size" => $size,
                    ];
                }
            }

            closedir($handle);
        }

        return empty($this->scan_stack);
    }

    private function finalize_scanning(): void
    {
        error_log(
            "FileSyncProducer: Scanning complete, found " .
                count($this->scanned_files) .
                " files",
        );

        // Sort scanned files by path for two-pointer merge
        usort($this->scanned_files, fn($a, $b) => strcmp($a["path"], $b["path"]));

        error_log(
            "FileSyncProducer: Sorted " .
                count($this->scanned_files) .
                " server files by path",
        );

        // Identify empty directories (needed for first-sync)
        $this->identify_empty_directories();

        // Calculate filesystem root
        $this->filesystem_root = $this->calculate_filesystem_root();

        $this->phase = self::PHASE_STREAMING;
        $this->initialize_streaming();

        error_log(
            "FileSyncProducer: Transitioning to streaming phase | " .
                "Two-pointer merge will run incrementally",
        );
    }

    private function identify_empty_directories(): void
    {
        // Build set of directories that contain files
        $dirs_with_files = [];
        foreach ($this->scanned_files as $file) {
            $dir = dirname($file["path"]);
            while ($dir !== "/" && $dir !== ".") {
                $dirs_with_files[$dir] = true;
                $dir = dirname($dir);
            }
        }

        // Empty directories = scanned directories - directories with files
        // Only for first-sync (no client index)
        if ($this->client_index_file === null) {
            foreach (array_keys($this->scanned_directories) as $dir) {
                // Skip root scan directories themselves
                if (in_array($dir, $this->directories)) {
                    continue;
                }

                if (!isset($dirs_with_files[$dir])) {
                    $this->empty_directories[] = $dir;
                }
            }
        }

        error_log(
            "FileSyncProducer: Found " .
                count($this->empty_directories) .
                " empty directories",
        );
    }

    private function calculate_filesystem_root(): string
    {
        $all_paths = array_merge(
            array_column($this->scanned_files, "path"),
            array_column($this->scanned_symlinks, "path"),
        );

        if (empty($all_paths)) {
            return $this->directories[0] ?? "/";
        }

        $common = $all_paths[0];
        foreach ($all_paths as $path) {
            while (strpos($path, $common) !== 0) {
                $common = dirname($common);
                if ($common === "/" || $common === ".") {
                    break;
                }
            }
        }

        return $common;
    }

    private function initialize_streaming(): void
    {
        $this->server_index = 0;
        $this->client_stream = null;
        $this->client_offset = 0;
        $this->client_current_line = null;
        $this->streaming_file_handle = null;
        $this->streaming_file_offset = 0;
        $this->files_streamed = 0;
    }

    /**
     * Incremental two-pointer merge streaming.
     * Compares server files vs client files on-the-fly.
     */
    private function stream_step(): void
    {
        // 1. Output empty directories first (only for first-sync)
        if (
            $this->client_index_file === null &&
            $this->empty_dir_index < count($this->empty_directories)
        ) {
            $dir = $this->empty_directories[$this->empty_dir_index];
            $this->empty_dir_index++;
            $this->current_chunk = ["type" => "directory", "path" => $dir];
            return;
        }

        // 2. Output symlinks second
        if ($this->symlink_index < count($this->scanned_symlinks)) {
            $symlink = $this->scanned_symlinks[$this->symlink_index];
            $this->symlink_index++;
            $this->current_chunk = [
                "type" => "symlink",
                "path" => $symlink["path"],
                "target" => $symlink["target"],
                "ctime" => $symlink["ctime"],
            ];
            return;
        }

        // 3. Open client stream if needed (delta mode)
        if ($this->client_index_file && $this->client_stream === null) {
            $this->client_stream = gzopen($this->client_index_file, "r");
            if (!$this->client_stream) {
                throw new RuntimeException(
                    "Failed to open client index file for streaming",
                );
            }

            // Seek to saved position if resuming
            if ($this->client_offset > 0) {
                gzseek($this->client_stream, $this->client_offset);
                error_log(
                    "Resuming client stream at offset " . $this->client_offset,
                );
            }

            error_log("Opened client index stream for two-pointer merge");
        }

        // 4. Two-pointer merge loop
        while (true) {
            // Get next server file
            $server_file =
                $this->server_index < count($this->scanned_files)
                    ? $this->scanned_files[$this->server_index]
                    : null;

            // Get next client line if not cached
            if ($this->client_stream && $this->client_current_line === null) {
                $line = gzgets($this->client_stream);
                if ($line !== false) {
                    $line = trim($line);
                    if ($line !== "") {
                        $parts = explode("\t", $line);
                        if (count($parts) >= 2) {
                            $this->client_current_line = [
                                "path" => $parts[0],
                                "ctime" => (int) $parts[1],
                                "size" => (int) ($parts[2] ?? 0),
                            ];
                        }
                    }
                }
                // Update offset after reading
                $this->client_offset = gztell($this->client_stream);
            }

            $client_file = $this->client_current_line;

            // Both lists exhausted?
            if ($server_file === null && $client_file === null) {
                if ($this->client_stream) {
                    gzclose($this->client_stream);
                    $this->client_stream = null;
                }
                if ($this->streaming_file_handle) {
                    fclose($this->streaming_file_handle);
                    $this->streaming_file_handle = null;
                }
                $this->phase = self::PHASE_FINISHED;
                $this->current_chunk = null;
                error_log(
                    "Two-pointer merge complete | Total deletions: " .
                        $this->deletions_count,
                );
                return;
            }

            // Server only (NEW file or no client index)
            if ($client_file === null) {
                $this->stream_file_chunk($server_file);
                return;
            }

            // Client only (DELETED file)
            if ($server_file === null) {
                $this->deletions_count++;
                $this->current_chunk = [
                    "type" => "deletion",
                    "path" => $client_file["path"],
                    "ctime" => $client_file["ctime"],
                    "size" => $client_file["size"],
                    "deleted_at" => time(),
                ];
                $this->client_current_line = null; // Consume
                return; // Yield one deletion at a time
            }

            // Both exist - compare paths
            $cmp = strcmp($server_file["path"], $client_file["path"]);

            if ($cmp === 0) {
                // Same path - check if modified
                if ($server_file["ctime"] !== $client_file["ctime"]) {
                    // MODIFIED → stream it
                    $this->stream_file_chunk($server_file);
                    $this->client_current_line = null; // Consume client line too
                    return;
                } else {
                    // UNCHANGED → skip both
                    $this->server_index++;
                    $this->client_current_line = null;
                    continue;
                }
            } elseif ($cmp < 0) {
                // Server comes first → NEW file
                $this->stream_file_chunk($server_file);
                return;
            } else {
                // Client comes first → DELETED file
                $this->deletions_count++;
                $this->current_chunk = [
                    "type" => "deletion",
                    "path" => $client_file["path"],
                    "ctime" => $client_file["ctime"],
                    "size" => $client_file["size"],
                    "deleted_at" => time(),
                ];
                $this->client_current_line = null;
                return;
            }
        }
    }

    /**
     * Stream a chunk from the current file.
     */
    private function stream_file_chunk(array $file): void
    {
        // Open file handle if not open
        if ($this->streaming_file_handle === null) {
            $this->streaming_file_handle = @fopen($file["path"], "r");

            if (!$this->streaming_file_handle) {
                // Skip unreadable file
                $this->server_index++;
                $this->files_streamed++;
                return;
            }

            if ($this->streaming_file_offset > 0) {
                fseek($this->streaming_file_handle, $this->streaming_file_offset);
            }
        }

        // Read chunk
        $data = fread($this->streaming_file_handle, $this->chunk_size);

        if ($data === false || $data === "") {
            // End of file
            fclose($this->streaming_file_handle);
            $this->streaming_file_handle = null;
            $this->streaming_file_offset = 0;
            $this->server_index++;
            $this->files_streamed++;
            return;
        }

        $offset = $this->streaming_file_offset;
        $this->streaming_file_offset += strlen($data);

        $is_first = $offset === 0;
        $is_last = feof($this->streaming_file_handle);

        $this->current_chunk = [
            "type" => "file",
            "path" => $file["path"],
            "data" => $data,
            "size" => $file["size"],
            "ctime" => $file["ctime"],
            "offset" => $offset,
            "is_first_chunk" => $is_first,
            "is_last_chunk" => $is_last,
        ];

        // If done with this file, advance server pointer
        if ($is_last) {
            fclose($this->streaming_file_handle);
            $this->streaming_file_handle = null;
            $this->streaming_file_offset = 0;
            $this->server_index++;
            $this->files_streamed++;
        }
    }

    public function get_current_chunk(): ?array
    {
        return $this->current_chunk;
    }

    public function get_reentrancy_cursor(): string
    {
        $cursor = [
            "p" => $this->phase,
            "fsr" => $this->filesystem_root,
        ];

        if ($this->phase === self::PHASE_STREAMING) {
            $cursor["n"] = $this->files_streamed;
            $cursor["b"] = $this->streaming_file_offset;
            $cursor["si"] = $this->server_index; // Server pointer
            $cursor["co"] = $this->client_offset; // Client byte offset

        }

        return json_encode($cursor);
    }

    public function get_progress(): array
    {
        $progress = [
            "phase" => $this->phase,
        ];

        if ($this->phase === self::PHASE_SCANNING) {
            $progress["files_found"] = count($this->scanned_files);
            $progress["directories_pending"] = count($this->scan_stack);
        } elseif ($this->phase === self::PHASE_STREAMING) {
            $progress["files_total"] = count($this->scanned_files);
            $progress["files_completed"] = $this->files_streamed;

            if ($this->server_index < count($this->scanned_files)) {
                $file = $this->scanned_files[$this->server_index];
                $progress["current_file"] = [
                    "path" => $file["path"],
                    "size" => $file["size"],
                    "bytes_read" => $this->streaming_file_offset,
                    "percent" =>
                        $file["size"] > 0
                            ? $this->streaming_file_offset / $file["size"]
                            : 1,
                ];
            }

            $progress["percent_complete"] =
                count($this->scanned_files) > 0
                    ? $this->files_streamed / count($this->scanned_files)
                    : 1;
        }

        return $progress;
    }

    public function get_deletions(): array
    {
        return [];
    }

    public function get_filesystem_root(): ?string
    {
        return $this->filesystem_root;
    }
}
