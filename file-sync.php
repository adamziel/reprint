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

    // Traversal state: stack of frames (dir, last child name emitted, entries cached)
    private $traversal_stack;

    // Streaming state (two-pointer merge)
    private $client_stream; // gzopen handle for client index file
    private $client_offset; // Byte offset in client stream (for gzseek)
    private $client_current_line; // Cached current line from client stream
    private $client_slice_start; // Start offset of current client slice
    private $client_slice_len; // Length of current client slice
    private $client_slice_total; // Total bytes in full client index (optional)
    private $waiting_for_client_slice; // Whether we need the next slice from client
    private $streaming_file_handle; // Handle for current file being streamed
    private $streaming_file_offset; // Byte offset in current file
    private $current_file_meta; // ['path','ctime','size']
    private $files_streamed;

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
        $this->client_slice_start = $options["client_slice_start"] ?? 0;
        $this->client_slice_len = $options["client_slice_len"] ?? null;
        $this->client_slice_total = $options["client_slice_total"] ?? null;
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
        $this->phase = self::PHASE_STREAMING;

        // Initialize traversal stack with root directories (sorted)
        $dirs = $this->directories;
        sort($dirs, SORT_STRING);
        $this->filesystem_root = $dirs[0] ?? "/";
        $this->traversal_stack = [];
        foreach (array_reverse($dirs) as $dir) {
            $this->traversal_stack[] = [
                "dir" => $dir,
                "last" => null, // last emitted child name
                "entries" => null,
            ];
        }

        // Initialize streaming state
        $this->client_stream = null;
        $this->client_offset = 0;
        $this->client_current_line = null;
        $this->waiting_for_client_slice = false;
        $this->streaming_file_handle = null;
        $this->streaming_file_offset = 0;
        $this->current_file_meta = null;
        $this->files_streamed = 0;

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

        if ($this->phase === self::PHASE_STREAMING) {
            $this->files_streamed = $cursor["n"] ?? 0;
            $this->streaming_file_offset = $cursor["b"] ?? 0;
            $this->current_file_meta = $cursor["cf"] ?? null;
            $this->deletions_count = $cursor["dc"] ?? 0;
            $this->client_offset = $cursor["co"] ?? 0;
            $this->client_slice_total = $cursor["ct"] ?? $this->client_slice_total;

            $this->client_current_line = null; // Will be read when streaming resumes
            $this->client_stream = null; // Will be opened when streaming starts

            // Rebuild traversal stack (entries will be reloaded)
            $this->traversal_stack = [];
            foreach ($cursor["ts"] ?? [] as $frame) {
                $this->traversal_stack[] = [
                    "dir" => $frame["d"],
                    "last" => $frame["l"] ?? null,
                    "entries" => null,
                ];
            }

            $this->streaming_file_handle = null;
        } else {
            $this->phase = self::PHASE_FINISHED;
        }
    }

    // Rescanning no longer needed; traversal stack encodes position.

    public function next_chunk(): bool
    {
        if ($this->phase === self::PHASE_FINISHED) {
            return false;
        }

        if ($this->phase === self::PHASE_STREAMING) {
            $this->stream_step();
            return $this->phase !== self::PHASE_FINISHED;
        }

        return false;
    }

    // Scanning removed; traversal happens during streaming.

    // Directory utilities removed with scanless traversal.

    /**
     * Incremental two-pointer merge streaming.
     * Compares server files vs client files on-the-fly.
     */
    private function stream_step(): void
    {
        // If waiting for next client slice, halt until new request provides it
        if ($this->waiting_for_client_slice) {
            $this->current_chunk = null;
            return;
        }

        // Open client stream if needed (delta mode)
        if ($this->client_index_file && $this->client_stream === null) {
            $this->client_stream = gzopen($this->client_index_file, "r");
            if (!$this->client_stream) {
                throw new RuntimeException(
                    "Failed to open client index file for streaming",
                );
            }

            // Validate slice start
            if ($this->client_slice_start !== null && $this->client_slice_start !== $this->client_offset) {
                throw new RuntimeException(
                    "Client slice start does not match expected offset: expected {$this->client_offset}, got {$this->client_slice_start}",
                );
            }

            if ($this->client_slice_start > 0) {
                gzseek($this->client_stream, 0);
            }

            error_log("Opened client index stream for two-pointer merge");
        }

        // If currently mid-file, continue streaming that file
        if ($this->current_file_meta !== null && $this->streaming_file_handle !== null) {
            $this->stream_file_chunk($this->current_file_meta);
            return;
        }

        // Two-pointer merge loop
        while (true) {
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
                $this->client_offset = $this->client_slice_start + gztell($this->client_stream);

                // Slice exhausted and more expected? request next slice
                if ($line === false || gzeof($this->client_stream)) {
                    $slice_end = $this->client_slice_start + ($this->client_slice_len ?? 0);
                    $has_more =
                        $this->client_slice_total !== null &&
                        $slice_end < $this->client_slice_total;
                    if ($has_more) {
                        $this->waiting_for_client_slice = true;
                        if ($this->client_stream) {
                            gzclose($this->client_stream);
                            $this->client_stream = null;
                        }
                        $this->current_chunk = null;
                        return;
                    }
                }
            }

            // Get next server file or structural chunk
            $server_file = $this->get_next_server_file();
            if ($this->current_chunk !== null && ($server_file === null && $this->current_file_meta === null)) {
                // A directory/symlink chunk was prepared
                return;
            }
            if ($server_file === null && $this->current_file_meta !== null) {
                $server_file = $this->current_file_meta;
            }

            // Both lists exhausted?
            $client_file = $this->client_current_line;
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
                    $this->client_current_line = null;
                    $this->current_file_meta = null;
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
     * Fetch next server file in lexicographic DFS order.
     * Populates $this->current_chunk for directory/symlink emissions.
     */
    /**
     * Fetch the next server file (or structural chunk) in lexicographic DFS order.
     *
     * - Traverses the filesystem without pre-scanning.
     * - Ensures paths are globally sorted by visiting per-directory entries sorted lexicographically.
     * - Emits directory/symlink as current_chunk (returns null) or returns file metadata for streaming.
     */
    private function get_next_server_file(): ?array
    {
        while (!empty($this->traversal_stack)) {
            $idx = count($this->traversal_stack) - 1;
            $frame =& $this->traversal_stack[$idx];

            // Load entries if first visit; compute start index based on last
            if ($frame["entries"] === null) {
                $entries = @scandir($frame["dir"]);
                if ($entries === false) {
                    array_pop($this->traversal_stack);
                    continue;
                }
                $entries = array_values(
                    array_filter(
                        $entries,
                        fn($e) => $e !== "." && $e !== "..",
                    ),
                );
                sort($entries, SORT_STRING);
                $frame["entries"] = $entries;

                if ($this->client_index_file === null && count($entries) === 0) {
                    // Empty directory emission (first-sync only)
                    array_pop($this->traversal_stack);
                    $this->current_chunk = [
                        "type" => "directory",
                        "path" => $frame["dir"],
                    ];
                    return null;
                }
            }

            // Find next entry strictly greater than last emitted in this dir
            $start = $frame["pos"] ?? 0;
            if ($frame["last"] !== null) {
                $start = $this->binary_search_next($frame["entries"], $frame["last"]);
            }

            if ($start >= count($frame["entries"])) {
                array_pop($this->traversal_stack);
                continue;
            }

            $entry = $frame["entries"][$start];
            $frame["last"] = $entry;
            $frame["pos"] = $start + 1;
            $path = $frame["dir"] . "/" . $entry;

            if (is_link($path)) {
                $target = readlink($path);
                $ctime = @filectime($path);
                $this->current_chunk = [
                    "type" => "symlink",
                    "path" => $path,
                    "target" => $target !== false ? $target : "",
                    "ctime" => $ctime !== false ? $ctime : 0,
                ];
                return null;
            }

            if (is_dir($path)) {
                $this->traversal_stack[] = [
                    "dir" => $path,
                    "pos" => 0,
                    "entries" => null,
                ];
                continue;
            }

            if (is_file($path)) {
                $ctime = @filectime($path);
                $size = @filesize($path);
                if ($ctime === false || $size === false) {
                    continue;
                }
                if ($this->min_ctime > 0 && $ctime <= $this->min_ctime) {
                    continue;
                }
                $this->current_file_meta = [
                    "path" => $path,
                    "ctime" => $ctime,
                    "size" => $size,
                ];
                $this->streaming_file_offset = 0;
                return $this->current_file_meta;
            }
        }

        return null;
    }

    /**
     * Stream a chunk from the current file.
     */
    /**
     * Stream the current file in fixed-size chunks, updating cursor state as we go.
     * Assumes $this->current_file_meta is set and traversal has positioned us on this file.
     */
    private function stream_file_chunk(array $file): void
    {
        // Open file handle if not open
        if ($this->streaming_file_handle === null) {
            $this->streaming_file_handle = @fopen($file["path"], "r");

            if (!$this->streaming_file_handle) {
                // Skip unreadable file
                $this->current_file_meta = null;
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
            $this->files_streamed++;
            $this->current_file_meta = null;
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
            $this->files_streamed++;
            $this->current_file_meta = null;
        }
    }

    public function get_current_chunk(): ?array
    {
        return $this->current_chunk;
    }

    /**
     * Serialize current streaming state for resumption.
     * Includes traversal stack, current file offset/meta, and client stream offset.
     */
    public function get_reentrancy_cursor(): string
    {
        $cursor = [
            "p" => $this->phase,
            "fsr" => $this->filesystem_root,
        ];

        if ($this->phase === self::PHASE_STREAMING) {
            $cursor["n"] = $this->files_streamed;
            $cursor["b"] = $this->streaming_file_offset;
            $cursor["co"] = $this->client_offset; // Client byte offset (absolute)
            $cursor["ct"] = $this->client_slice_total;
            $cursor["cf"] = $this->current_file_meta;
            $cursor["dc"] = $this->deletions_count;
            $cursor["ts"] = array_map(
                fn($f) => ["d" => $f["dir"], "l" => $f["last"], "p" => $f["pos"] ?? 0],
                $this->traversal_stack,
            );

        }

        return json_encode($cursor);
    }

    public function get_progress(): array
    {
        $progress = [
            "phase" => $this->phase,
        ];

        if ($this->phase === self::PHASE_STREAMING) {
            $progress["files_completed"] = $this->files_streamed;
            $progress["waiting_for_client_slice"] = $this->waiting_for_client_slice;
            $progress["client_offset"] = $this->client_offset;
            $progress["client_total"] = $this->client_slice_total;

            if ($this->current_file_meta) {
                $file = $this->current_file_meta;
                $progress["current_file"] = [
                    "path" => $file["path"],
                    "size" => $file["size"],
                    "bytes_read" => $this->streaming_file_offset,
                ];
            }
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

    public function is_waiting_for_client_slice(): bool
    {
        return $this->waiting_for_client_slice;
    }
}
