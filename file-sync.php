<?php
/**
 * File synchronization producers.
 *
 * - FileTreeProducer: Stream filesystem entries in sorted DFS order.
 * - FileListProducer: Stream a provided list of paths (in order).
 *
 * Cursors are JSON strings (not base64). Callers are responsible for encoding
 * cursors for transport.
 */

require_once __DIR__ . '/class-directory-listing.php';

/**
 * Stream filesystem entries in deterministic, sorted DFS order.
 *
 * Supports two modes of operation:
 * 1. Tree traversal mode (default): DFS traversal of all directories
 * 2. Paths mode: Stream a specific list of paths passed in memory
 *
 * When `paths` option is provided, the producer iterates through those specific
 * paths instead of doing full directory traversal. This allows filtering without
 * any filesystem writes.
 */
class FileTreeProducer
{
    const PHASE_STREAMING = "streaming";
    const PHASE_FINISHED = "finished";

    private array $directories;
    private int $chunk_size;
    private bool $index_only;
    private ?string $filesystem_root;

    private string $phase;
    private ?array $current_chunk = null;

    // Traversal state: stack of frames (dir, last child name emitted, entries cached)
    // Rebuilt on each request from cursor path - never stored in cursor
    private array $traversal_stack = [];

    // Paths mode state: explicit list of paths to stream (sorted on first use)
    private ?array $paths = null;
    private bool $paths_sorted = false;
    private bool $paths_positioned = false;
    private int $paths_position = 0;  // Ephemeral index, NOT stored in cursor

    // Streaming file state
    private $streaming_file_handle = null;
    private int $streaming_file_offset = 0;
    private ?array $current_file_meta = null;

    // Last emitted path tracking (for cursor generation)
    private ?string $last_emitted_path = null;
    private ?int $last_emitted_ctime = null;

    /**
     * @param string|array $directories Root directories to scan
     * @param array $options Options:
     *   - chunk_size: bytes per file chunk
     *   - index_only: if true, emit index entries instead of file contents
     *   - cursor: JSON cursor string for resumption
     *   - start_after: last path processed (used only when cursor is absent)
     *   - paths: optional array of specific paths to stream (skips tree traversal)
     */
    public function __construct(string|array $directories, array $options = [])
    {
        $this->directories = $this->normalize_directories($directories);
        $this->chunk_size = $options["chunk_size"] ?? 5 * 1024 * 1024;
        $this->index_only = $options["index_only"] ?? false;

        // Paths mode: if a list of specific paths is provided, iterate through
        // those instead of doing full tree traversal. The caller must pass the
        // same paths array on each request when resuming from a cursor.
        if (isset($options["paths"]) && is_array($options["paths"])) {
            $this->paths = $options["paths"];
        }

        if (isset($options["cursor"])) {
            $this->initialize_from_cursor($options["cursor"]);
        } else {
            $this->initialize_new($options["start_after"] ?? null);
        }
    }

    /**
     * Initialize a fresh traversal, optionally resuming after a known path.
     */
    private function initialize_new(?string $start_after_path): void
    {
        $this->phase = self::PHASE_STREAMING;
        $dirs = $this->directories;
        sort($dirs, SORT_STRING);
        $this->filesystem_root = $dirs[0] ?? "/";

        $this->traversal_stack = [];
        $this->current_chunk = null;
        $this->streaming_file_handle = null;
        $this->streaming_file_offset = 0;
        $this->current_file_meta = null;
        $this->last_emitted_path = null;
        $this->last_emitted_ctime = null;
        $this->paths_sorted = false;
        $this->paths_positioned = false;
        $this->paths_position = 0;

        // In paths mode, we don't need to build a traversal stack - we just
        // iterate through the paths array sequentially
        if ($this->paths !== null) {
            return;
        }

        if ($start_after_path) {
            $this->build_traversal_stack_from_last_path($start_after_path);
            return;
        }

        foreach (array_reverse($dirs) as $dir) {
            $this->traversal_stack[] = [
                "dir" => $dir,
                "last_visited" => null,
                "listing" => null,
            ];
        }
    }

    /**
     * Initialize producer state from a JSON cursor string.
     *
     * Cursor format is minimal: (path, ctime, byte_offset)
     * - path: the file/dir/symlink we were processing or just finished
     * - ctime: the ctime of the file when we started (for change detection)
     * - b: byte offset within the file (0 if finished or non-file)
     *
     * The traversal stack is rebuilt from the path on each request - never
     * stored in the cursor. This ensures correctness even when the filesystem
     * changes between requests.
     */
    private function initialize_from_cursor(string $cursor_json): void
    {
        $cursor = json_decode($cursor_json, true);
        if ($cursor === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                "Invalid cursor format: " . json_last_error_msg(),
            );
        }

        $this->phase = $cursor["phase"] ?? self::PHASE_STREAMING;
        $this->filesystem_root =
            $cursor["root"] ?? ($this->directories[0] ?? "/");
        $this->current_chunk = null;
        $this->streaming_file_handle = null;
        $this->traversal_stack = [];
        $this->paths_sorted = false;
        $this->paths_positioned = false;
        $this->paths_position = 0;

        if ($this->phase !== self::PHASE_STREAMING) {
            $this->phase = self::PHASE_FINISHED;
            return;
        }

        $path = $cursor["path"] ?? null;
        $ctime = $cursor["ctime"] ?? null;
        $byte_offset = $cursor["bytes"] ?? 0;

        $this->last_emitted_path = null;
        $this->last_emitted_ctime = null;

        if ($path !== null && $byte_offset > 0) {
            // Resuming mid-file: set up current file state
            $size = @filesize($path);
            if ($size === false) {
                // File no longer exists - treat as if we finished it
                $this->current_file_meta = null;
                $this->streaming_file_offset = 0;
                $this->last_emitted_path = $path;
            } else {
                $this->current_file_meta = [
                    "path" => $path,
                    "ctime" => $ctime,
                    "size" => $size,
                ];
                $this->streaming_file_offset = $byte_offset;
                // Set last_emitted_path so we build the traversal stack
                // to continue from AFTER this file finishes streaming
                $this->last_emitted_path = $path;
            }
        } else {
            // Starting fresh or resuming after a completed item
            $this->current_file_meta = null;
            $this->streaming_file_offset = 0;
            $this->last_emitted_path = $path;  // might be null
        }

        // Build traversal state from path (not from stored indices)
        // This must happen even when resuming mid-file, so we know where
        // to continue after the current file finishes streaming
        if ($this->paths === null) {
            // Tree mode: rebuild traversal stack from last emitted path
            if ($this->last_emitted_path !== null) {
                $this->build_traversal_stack_from_last_path(
                    $this->last_emitted_path,
                );
            } else {
                // Starting fresh - initialize root directories
                $dirs = $this->directories;
                sort($dirs, SORT_STRING);
                foreach (array_reverse($dirs) as $dir) {
                    $this->traversal_stack[] = [
                        "dir" => $dir,
                        "last_visited" => null,
                        "listing" => null,
                    ];
                }
            }
        }
        // For paths mode, we'll sort and position when get_next_path_entry is called
    }

    /**
     * Build traversal stack so the next emitted entry is after $last_path.
     */
    private function build_traversal_stack_from_last_path(
        string $last_path,
    ): void {
        $roots = $this->directories;
        sort($roots, SORT_STRING);

        $matched_root = null;
        foreach ($roots as $root) {
            if (
                str_starts_with($last_path, $root . "/") ||
                $last_path === $root
            ) {
                $matched_root = $root;
                break;
            }
        }

        if ($matched_root === null) {
            foreach (array_reverse($roots) as $dir) {
                $this->traversal_stack[] = [
                    "dir" => $dir,
                    "last_visited" => null,
                    "listing" => null,
                ];
            }
            return;
        }

        $suffix =
            $last_path === $matched_root
                ? ""
                : ltrim(substr($last_path, strlen($matched_root)), "/");
        $parts = $suffix === "" ? [] : explode("/", $suffix);

        $frames = [];
        $current_dir = $matched_root;
        foreach ($parts as $part) {
            $frames[] = [
                "dir" => $current_dir,
                "last_visited" => $part,
                "listing" => null,
            ];
            $current_dir .= "/" . $part;
        }

        if (empty($frames)) {
            $frames[] = [
                "dir" => $matched_root,
                "last_visited" => basename($last_path),
                "listing" => null,
            ];
        }

        // Keep frames in root -> leaf order so the stack top is the deepest dir.
        $this->traversal_stack = $frames;

        $root_index = array_search($matched_root, $roots, true);
        if ($root_index !== false) {
            // Prepend roots that come after the matched root so they are processed later.
            for ($i = $root_index + 1; $i < count($roots); $i++) {
                array_unshift($this->traversal_stack, [
                    "dir" => $roots[$i],
                    "last_visited" => null,
                    "listing" => null,
                ]);
            }
        }
    }

    /**
     * Normalize directories input into an array of trimmed paths.
     */
    private function normalize_directories(string|array $directories): array
    {
        if (is_string($directories)) {
            return [rtrim($directories, "/")];
        }
        return array_map(fn($d) => rtrim($d, "/"), $directories);
    }

    /**
     * Advance to the next chunk. Returns false when finished.
     */
    public function next_chunk(): bool
    {
        if ($this->phase === self::PHASE_FINISHED) {
            return false;
        }

        $this->stream_step();
        return $this->phase !== self::PHASE_FINISHED;
    }

    /**
     * Produce the next chunk (file data, index entry, directory, or symlink).
     */
    private function stream_step(): void
    {
        if ($this->current_file_meta !== null) {
            $this->stream_file_chunk($this->current_file_meta);
            return;
        }

        while (true) {
            // Clear stale chunk before looking for the next item.
            // get_next_server_file() may set current_chunk for symlinks/directories.
            $this->current_chunk = null;

            $server_file = $this->get_next_server_file();

            // If get_next_server_file() set a chunk (symlink/directory), return it
            if ($this->current_chunk !== null) {
                return;
            }

            if ($server_file === null) {
                $this->phase = self::PHASE_FINISHED;
                $this->current_chunk = null;
                return;
            }

            if ($this->index_only) {
                $this->emit_index_chunk($server_file);
                return;
            }

            $this->stream_file_chunk($server_file);
            return;
        }
    }

    /**
     * Emit index entry chunk for a file without streaming file data.
     */
    private function emit_index_chunk(array $file): void
    {
        $this->current_chunk = [
            "type" => "index",
            "path" => $file["path"],
            "ctime" => $file["ctime"],
            "size" => $file["size"],
        ];
        $this->last_emitted_path = $file["path"];
        $this->last_emitted_ctime = $file["ctime"];
        $this->current_file_meta = null;
    }

    /**
     * Fetch the next server file (or structural chunk) in lexicographic DFS order.
     * In paths mode, iterates through the provided paths array instead.
     *
     * Uses binary search based on entry names to find position after resumption,
     * not stored indices. This ensures correctness when directory contents change.
     */
    private function get_next_server_file(): ?array
    {
        // Paths mode: iterate through the explicit paths array
        if ($this->paths !== null) {
            return $this->get_next_path_entry();
        }

        // Depth-first traversal of the filesystem
		// @TODO: Why do we need traversal stack? Can't we just string paths?
        while (!empty($this->traversal_stack)) {
            $idx = count($this->traversal_stack) - 1;
            $frame = &$this->traversal_stack[$idx];

            if ($frame["listing"] === null) {
				/**
				 * Use DirectoryListing which handles large directories efficiently.
				 *
				 * DirectoryListing uses php://temp which stores entries in memory up to a threshold
				 * (default 2MB), then automatically spills to a temporary file. This allows handling
				 * directories with millions of files without exhausting memory.
				 *
				 * We use readdir() internally (not scandir()) to avoid loading all entries into
				 * memory at once during the scan phase.
				 */
                $listing = DirectoryListing::scan($frame["dir"]);
                if ($listing === null) {
                    $path = $frame["dir"];
                    array_pop($this->traversal_stack);
                    $this->last_emitted_path = $path;
                    $this->last_emitted_ctime = null;
                    $this->current_chunk = [
                        "type" => "error",
                        "error_type" => "dir_open",
                        "path" => $path,
                        "message" => "Failed to open directory",
                    ];
                    return null;
                }
                $listing->sort();
                $frame["listing"] = $listing;

				// Empty directory? Emit that as a chunk:
                if ($listing->isEmpty()) {
                    array_pop($this->traversal_stack);
                    $this->last_emitted_path = $frame["dir"];
                    $this->last_emitted_ctime = null;
                    $this->current_chunk = [
                        "type" => "directory",
                        "path" => $frame["dir"],
                    ];
                    return null;
                }
            }

            $listing = $frame["listing"];

            // Use DirectoryListing's binary search to find position after last_visited
            $last = $frame["last_visited"] ?? null;
            if ($last !== null) {
                $listing->seekAfter($last);
            } else {
                $listing->rewind();
            }

            $entry = $listing->next();
            if ($entry === null) {
                array_pop($this->traversal_stack);
                continue;
            }

            $frame["last_visited"] = $entry;
            $path = $frame["dir"] . "/" . $entry;

            $info = $this->lstat_path($path);
            if ($info === null) {
                continue;
            }

            if ($info["type"] === "link") {
                $target = readlink($path);
                $this->last_emitted_path = $path;
                $this->last_emitted_ctime = $info["ctime"];
                $this->current_chunk = [
                    "type" => "symlink",
                    "path" => $path,
                    "target" => $target !== false ? $target : "",
                    "ctime" => $info["ctime"] ?? 0,
                ];
                return null;
            }

            if ($info["type"] === "dir") {
                $this->traversal_stack[] = [
                    "dir" => $path,
                    "last_visited" => null,
                    "listing" => null,
                ];
                continue;
            }

            if ($info["type"] === "file") {
                $ctime = $info["ctime"];
                $size = $info["size"];
                if ($ctime === null || $size === null) {
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
     * Fetch the next entry from the explicit paths array (paths mode).
     *
     * Paths are sorted on first access. Position is determined by binary
     * search based on last_emitted_path, not by a stored index. This ensures
     * correctness even when the paths array changes between requests.
     */
    private function get_next_path_entry(): ?array
    {
        // Sort paths on first access
        if (!$this->paths_sorted) {
            sort($this->paths, SORT_STRING);
            $this->paths_sorted = true;
        }

        // Position to start after last_emitted_path using binary search
        if (!$this->paths_positioned) {
            if ($this->last_emitted_path !== null) {
                $this->paths_position = $this->binary_search_next(
                    $this->paths,
                    $this->last_emitted_path,
                );
            } else {
                $this->paths_position = 0;
            }
            $this->paths_positioned = true;
        }

        while ($this->paths_position < count($this->paths)) {
            $path = $this->paths[$this->paths_position];
            $this->paths_position++;

            // Normalize path - it could be relative to a root directory
            $resolved_path = $this->resolve_path($path);
            if ($resolved_path === null) {
                // Path doesn't exist or isn't accessible, emit as missing
                $this->last_emitted_path = $path;
                $this->last_emitted_ctime = null;
                $this->current_chunk = [
                    "type" => "missing",
                    "path" => $path,
                ];
                return null;
            }

            $info = $this->lstat_path($resolved_path);
            if ($info === null) {
                continue;
            }

            if ($info["type"] === "link") {
                $target = readlink($resolved_path);
                $this->last_emitted_path = $resolved_path;
                $this->last_emitted_ctime = $info["ctime"];
                $this->current_chunk = [
                    "type" => "symlink",
                    "path" => $resolved_path,
                    "target" => $target !== false ? $target : "",
                    "ctime" => $info["ctime"] ?? 0,
                ];
                return null;
            }

            if ($info["type"] === "dir") {
                $this->last_emitted_path = $resolved_path;
                $this->last_emitted_ctime = null;
                $this->current_chunk = [
                    "type" => "directory",
                    "path" => $resolved_path,
                ];
                return null;
            }

            if ($info["type"] === "file") {
                $ctime = $info["ctime"];
                $size = $info["size"];
                if ($ctime === null || $size === null) {
                    continue;
                }
                $this->current_file_meta = [
                    "path" => $resolved_path,
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
     * Resolve a path that might be relative to one of the root directories.
     * Returns the absolute path if it exists, null otherwise.
     */
    private function resolve_path(string $path): ?string
    {
        // If it's already an absolute path and exists, use it
        if ($path[0] === "/" && file_exists($path)) {
            return $path;
        }

        // Try resolving relative to each root directory
        foreach ($this->directories as $dir) {
            $candidate = $dir . "/" . ltrim($path, "/");
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        // If absolute path doesn't exist, return null to signal missing
        if ($path[0] === "/") {
            return null;
        }

        return null;
    }

    /**
     * Stream the current file in fixed-size chunks.
     */
    private function stream_file_chunk(array $file): void
    {
        if ($this->streaming_file_handle === null) {
            $this->streaming_file_handle = @fopen($file["path"], "r");
            if (!$this->streaming_file_handle) {
                $this->current_file_meta = null;
                $this->current_chunk = [
                    "type" => "error",
                    "error_type" => "file_open",
                    "path" => $file["path"],
                    "message" => "Failed to open file",
                ];
                $this->last_emitted_path = $file["path"];
                $this->last_emitted_ctime = $file["ctime"];
                return;
            }
            if ($this->streaming_file_offset > 0) {
                fseek(
                    $this->streaming_file_handle,
                    $this->streaming_file_offset,
                );
            }
        }

        $data = fread($this->streaming_file_handle, $this->chunk_size);
        if (false === $data || ("" === $data && $file["size"] !== 0)) {
            fclose($this->streaming_file_handle);
            $this->streaming_file_handle = null;
            $this->streaming_file_offset = 0;
            $this->last_emitted_path = $file["path"];
            $this->last_emitted_ctime = $file["ctime"];
            $this->current_file_meta = null;
            $this->current_chunk = [
                "type" => "error",
                "error_type" => "file_read",
                "path" => $file["path"],
                "message" => "Failed to read file",
            ];
            return;
        }

        $offset = $this->streaming_file_offset;
        $this->streaming_file_offset += strlen($data);

        $is_first = $offset === 0;
        $is_last = feof($this->streaming_file_handle);

        $changed = false;
        $change_ctime = null;
        $change_size = null;
        $error_type = "file_changed";

        // Post-read change detection: only compare ctime.
        clearstatcache(true, $file["path"]);
        $stat = @stat($file["path"]);
        if ($stat === false) {
            $changed = true;
            $error_type = "file_missing";
        } else {
            $now_ctime = $stat["ctime"];
            if ($now_ctime !== $file["ctime"]) {
                $changed = true;
                $change_ctime = $now_ctime;
            }
        }

        if ($changed) {
            fclose($this->streaming_file_handle);
            $this->streaming_file_handle = null;
            $this->streaming_file_offset = 0;
            $this->last_emitted_path = $file["path"];
            $this->last_emitted_ctime = $file["ctime"];
            $this->current_file_meta = null;
            $this->current_chunk = [
                "type" => "error",
                "error_type" => $error_type,
                "path" => $file["path"],
                "message" =>
                    $error_type === "file_missing"
                        ? "File disappeared during stream"
                        : "File changed during stream",
                "expected_ctime" => $file["ctime"],
                "actual_ctime" => $change_ctime,
            ];
            return;
        }

        $this->current_chunk = [
            "type" => "file",
            "path" => $file["path"],
            "data" => $data,
            "size" => $file["size"],
            "ctime" => $file["ctime"],
            "offset" => $offset,
            "is_first_chunk" => $is_first,
            "is_last_chunk" => $is_last,
            "file_changed" => $changed,
            "change_ctime" => $change_ctime,
            "change_size" => $change_size,
        ];

        if ($is_last) {
            fclose($this->streaming_file_handle);
            $this->streaming_file_handle = null;
            $this->streaming_file_offset = 0;
            $this->last_emitted_path = $file["path"];
            $this->last_emitted_ctime = $file["ctime"];
            $this->current_file_meta = null;
        }
    }

    /**
     * Return the current chunk for the last step.
     */
    public function get_current_chunk(): ?array
    {
        return $this->current_chunk;
    }

    /**
     * Serialize state into a JSON cursor string.
     *
     * Cursor format is minimal: (path, ctime, byte_offset)
     * - path: last emitted path, or current file being streamed
     * - ctime: ctime of file when we started reading (for change detection)
     * - b: byte offset within the current file (0 if not mid-file)
     *
     * No traversal stack or list indices are stored. On resume, position is
     * determined by binary search based on the path. This ensures correctness
     * even when the filesystem changes between requests.
     */
    public function get_reentrancy_cursor(): string
    {
        if ($this->phase === self::PHASE_FINISHED) {
            return json_encode([
                "phase" => self::PHASE_FINISHED,
                "root" => $this->filesystem_root,
            ]);
        }

        $cursor = [
            "phase" => $this->phase,
            "root" => $this->filesystem_root,
        ];

        if ($this->current_file_meta !== null) {
            // In the middle of streaming a file
            $cursor["path"] = $this->current_file_meta["path"];
            $cursor["ctime"] = $this->current_file_meta["ctime"];
            $cursor["bytes"] = $this->streaming_file_offset;
        } else if ($this->last_emitted_path !== null) {
            // Just finished emitting something, continue after this path
            $cursor["path"] = $this->last_emitted_path;
            $cursor["ctime"] = $this->last_emitted_ctime;
            $cursor["bytes"] = 0;
        }
        // If neither, we haven't emitted anything yet - no path needed

        return json_encode($cursor);
    }

    /**
     * Return progress for logging and UI updates.
     */
    public function get_progress(): array
    {
        $progress = [
            "phase" => $this->phase,
        ];

        if ($this->phase === self::PHASE_STREAMING) {
            if ($this->last_emitted_path !== null) {
                $progress["last_path"] = $this->last_emitted_path;
            }
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

    /**
     * Return the filesystem root for metadata.
     */
    public function get_filesystem_root(): ?string
    {
        return $this->filesystem_root;
    }

    /**
     * Find the next index after $last in a sorted array.
     */
    private function binary_search_next(array $entries, string $last): int
    {
        $low = 0;
        $high = count($entries);
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if (strcmp($entries[$mid], $last) <= 0) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }
        return $low;
    }

    /**
     * Single lstat() call to classify a path.
     *
     * @return array|null ['type' => 'file'|'dir'|'link'|'other', 'ctime' => int|null, 'size' => int|null]
     */
    private function lstat_path(string $path): ?array
    {
        $stat = @lstat($path);
        if ($stat === false) {
            return null;
        }

        $mode = $stat["mode"] & 0170000;
        $type = "other";
        if ($mode === 0120000) {
            $type = "link";
        } elseif ($mode === 0040000) {
            $type = "dir";
        } elseif ($mode === 0100000) {
            $type = "file";
        }

        return [
            "type" => $type,
            "ctime" => isset($stat["ctime"]) ? (int) $stat["ctime"] : null,
            "size" => isset($stat["size"]) ? (int) $stat["size"] : null,
        ];
    }
}

// Backward compatibility alias for tests
class_alias("FileTreeProducer", "FileSyncProducer");
