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

/**
 * Stream filesystem entries in deterministic, sorted DFS order.
 */
class FileTreeProducer
{
    const PHASE_STREAMING = "streaming";
    const PHASE_FINISHED = "finished";

    private array $directories;
    private int $chunk_size;
    private bool $follow_symlinks;
    private bool $index_only;
    private ?string $filesystem_root;

    private string $phase;
    private ?array $current_chunk = null;

    // Traversal state: stack of frames (dir, last child name emitted, entries cached)
    private array $traversal_stack = [];

    // Streaming file state
    private $streaming_file_handle = null;
    private int $streaming_file_offset = 0;
    private ?array $current_file_meta = null;
    private int $files_streamed = 0;

    /**
     * @param string|array $directories Root directories to scan
     * @param array $options Options:
     *   - chunk_size: bytes per file chunk
     *   - follow_symlinks: whether to follow symlinks (default true, but symlinks are emitted)
     *   - index_only: if true, emit index entries instead of file contents
     *   - cursor: JSON cursor string for resumption
     *   - start_after: last path processed (used only when cursor is absent)
     */
    public function __construct(string|array $directories, array $options = [])
    {
        $this->directories = $this->normalize_directories($directories);
        $this->chunk_size = $options["chunk_size"] ?? 5 * 1024 * 1024;
        $this->follow_symlinks = $options["follow_symlinks"] ?? true;
        $this->index_only = $options["index_only"] ?? false;

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
        $this->files_streamed = 0;

        if ($start_after_path) {
            $this->build_traversal_stack_from_last_path($start_after_path);
            return;
        }

        foreach (array_reverse($dirs) as $dir) {
            $this->traversal_stack[] = [
                "dir" => $dir,
                "last" => null,
                "entries" => null,
            ];
        }
    }

    /**
     * Initialize producer state from a JSON cursor string.
     */
    private function initialize_from_cursor(string $cursor_json): void
    {
        $cursor = json_decode($cursor_json, true);
        if ($cursor === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                "Invalid cursor format: " . json_last_error_msg(),
            );
        }

        $this->phase = $cursor["p"] ?? self::PHASE_STREAMING;
        $this->filesystem_root =
            $cursor["fsr"] ?? ($this->directories[0] ?? "/");
        $this->current_chunk = null;

        if ($this->phase === self::PHASE_STREAMING) {
            $this->files_streamed = $cursor["n"] ?? 0;
            $this->streaming_file_offset = $cursor["b"] ?? 0;
            $this->current_file_meta = $cursor["cf"] ?? null;

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
                    "last" => null,
                    "entries" => null,
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
                "last" => $part,
                "entries" => null,
            ];
            $current_dir .= "/" . $part;
        }

        if (empty($frames)) {
            $frames[] = [
                "dir" => $matched_root,
                "last" => basename($last_path),
                "entries" => null,
            ];
        }

        $this->traversal_stack = array_reverse($frames);

        $root_index = array_search($matched_root, $roots, true);
        if ($root_index !== false) {
            for ($i = count($roots) - 1; $i > $root_index; $i--) {
                array_unshift($this->traversal_stack, [
                    "dir" => $roots[$i],
                    "last" => null,
                    "entries" => null,
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
        $this->files_streamed++;
        $this->current_file_meta = null;
    }

    /**
     * Fetch the next server file (or structural chunk) in lexicographic DFS order.
     */
    private function get_next_server_file(): ?array
    {
        while (!empty($this->traversal_stack)) {
            $idx = count($this->traversal_stack) - 1;
            $frame = &$this->traversal_stack[$idx];

            if ($frame["entries"] === null) {
                $entries = @scandir($frame["dir"]);
                if ($entries === false) {
                    array_pop($this->traversal_stack);
                    continue;
                }
                $entries = array_values(
                    array_filter($entries, fn($e) => $e !== "." && $e !== ".."),
                );
                sort($entries, SORT_STRING);
                $frame["entries"] = $entries;

                if (!$this->index_only && count($entries) === 0) {
                    array_pop($this->traversal_stack);
                    $this->current_chunk = [
                        "type" => "directory",
                        "path" => $frame["dir"],
                    ];
                    return null;
                }
            }

            $last = isset($frame["last"]) ? $frame["last"] : null;
            $start = isset($frame["pos"]) ? $frame["pos"] : 0;
            if ($last !== null) {
                $start = $this->binary_search_next($frame["entries"], $last);
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
                    "last" => null,
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
     * Stream the current file in fixed-size chunks.
     */
    private function stream_file_chunk(array $file): void
    {
        if ($this->streaming_file_handle === null) {
            $this->streaming_file_handle = @fopen($file["path"], "r");
            if (!$this->streaming_file_handle) {
                $this->current_file_meta = null;
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
        if ($data === false || $data === "") {
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

        $changed = false;
        $change_ctime = null;
        $change_size = null;
        if ($is_last) {
            clearstatcache(true, $file["path"]);
            $now_ctime = @filectime($file["path"]);
            $now_size = @filesize($file["path"]);
            if ($now_ctime !== false && $now_size !== false) {
                if (
                    $now_ctime !== $file["ctime"] ||
                    $now_size !== $file["size"]
                ) {
                    $changed = true;
                    $change_ctime = $now_ctime;
                    $change_size = $now_size;
                }
            }
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
            $this->files_streamed++;
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
            $cursor["cf"] = $this->current_file_meta;
            $cursor["ts"] = array_map(
                fn($f) => [
                    "d" => $f["dir"],
                    "l" => $f["last"],
                    "p" => $f["pos"] ?? 0,
                ],
                $this->traversal_stack,
            );
        }

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
            $progress["files_completed"] = $this->files_streamed;
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
}

/**
 * Stream an explicit list of file paths in order.
 */
class FileListProducer
{
    const PHASE_STREAMING = "streaming";
    const PHASE_FINISHED = "finished";

    private string $list_path;
    private int $chunk_size;
    private ?string $filesystem_root;

    private string $phase;
    private ?array $current_chunk = null;

    // List state
    private $list_handle = null;
    private int $list_offset = 0;

    // Streaming file state
    private $streaming_file_handle = null;
    private int $streaming_file_offset = 0;
    private ?array $current_file_meta = null;
    private int $files_streamed = 0;

    /**
     * @param string $list_path Path to newline-delimited list of absolute file paths
     * @param array $options Options:
     *   - chunk_size: bytes per file chunk
     *   - cursor: JSON cursor string for resumption
     */
    public function __construct(string $list_path, array $options = [])
    {
        if (!is_file($list_path)) {
            throw new InvalidArgumentException(
                "File list not found: {$list_path}",
            );
        }

        $this->list_path = $list_path;
        $this->chunk_size = $options["chunk_size"] ?? 5 * 1024 * 1024;
        $this->filesystem_root = $options["filesystem_root"] ?? "/";

        if (isset($options["cursor"])) {
            $this->initialize_from_cursor($options["cursor"]);
        } else {
            $this->initialize_new();
        }
    }

    /**
     * Initialize a fresh list stream.
     */
    private function initialize_new(): void
    {
        $this->phase = self::PHASE_STREAMING;
        $this->current_chunk = null;
        $this->list_handle = null;
        $this->list_offset = 0;
        $this->streaming_file_handle = null;
        $this->streaming_file_offset = 0;
        $this->current_file_meta = null;
        $this->files_streamed = 0;
    }

    /**
     * Initialize list stream from cursor state.
     */
    private function initialize_from_cursor(string $cursor_json): void
    {
        $cursor = json_decode($cursor_json, true);
        if ($cursor === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                "Invalid cursor format: " . json_last_error_msg(),
            );
        }

        $this->phase = $cursor["p"] ?? self::PHASE_STREAMING;
        $this->filesystem_root = $cursor["fsr"] ?? $this->filesystem_root;
        $this->current_chunk = null;

        if ($this->phase === self::PHASE_STREAMING) {
            $this->files_streamed = $cursor["n"] ?? 0;
            $this->list_offset = $cursor["lo"] ?? 0;
            $this->streaming_file_offset = $cursor["b"] ?? 0;
            $this->current_file_meta = $cursor["cf"] ?? null;
            $this->streaming_file_handle = null;
            $this->list_handle = null;
        } else {
            $this->phase = self::PHASE_FINISHED;
        }
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
     * Produce the next chunk from the list.
     */
    private function stream_step(): void
    {
        if ($this->current_file_meta !== null) {
            $this->stream_file_chunk($this->current_file_meta);
            return;
        }

        if ($this->list_handle === null) {
            $this->list_handle = fopen($this->list_path, "r");
            if (!$this->list_handle) {
                throw new RuntimeException("Failed to open file list");
            }
            if ($this->list_offset > 0) {
                fseek($this->list_handle, $this->list_offset);
            }
        }

        while (true) {
            $line = fgets($this->list_handle);
            if ($line === false) {
                fclose($this->list_handle);
                $this->list_handle = null;
                $this->phase = self::PHASE_FINISHED;
                $this->current_chunk = null;
                return;
            }
            $this->list_offset = ftell($this->list_handle);
            $path = trim($line);
            if ($path === "") {
                continue;
            }

            if (is_link($path)) {
                $target = readlink($path);
                $ctime = @filectime($path);
                $this->current_chunk = [
                    "type" => "symlink",
                    "path" => $path,
                    "target" => $target !== false ? $target : "",
                    "ctime" => $ctime !== false ? $ctime : 0,
                ];
                return;
            }

            if (is_dir($path)) {
                $this->current_chunk = [
                    "type" => "directory",
                    "path" => $path,
                ];
                return;
            }

            if (!is_file($path)) {
                $this->current_chunk = [
                    "type" => "missing",
                    "path" => $path,
                ];
                return;
            }

            $ctime = @filectime($path);
            $size = @filesize($path);
            if ($ctime === false || $size === false) {
                $this->current_chunk = [
                    "type" => "missing",
                    "path" => $path,
                ];
                return;
            }

            $this->current_file_meta = [
                "path" => $path,
                "ctime" => $ctime,
                "size" => $size,
            ];
            $this->streaming_file_offset = 0;
            $this->stream_file_chunk($this->current_file_meta);
            return;
        }
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
        if ($data === false || $data === "") {
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

        $changed = false;
        $change_ctime = null;
        $change_size = null;
        if ($is_last) {
            clearstatcache(true, $file["path"]);
            $now_ctime = @filectime($file["path"]);
            $now_size = @filesize($file["path"]);
            if ($now_ctime !== false && $now_size !== false) {
                if (
                    $now_ctime !== $file["ctime"] ||
                    $now_size !== $file["size"]
                ) {
                    $changed = true;
                    $change_ctime = $now_ctime;
                    $change_size = $now_size;
                }
            }
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
            $this->files_streamed++;
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
     */
    public function get_reentrancy_cursor(): string
    {
        $cursor = [
            "p" => $this->phase,
            "fsr" => $this->filesystem_root,
        ];

        if ($this->phase === self::PHASE_STREAMING) {
            $cursor["n"] = $this->files_streamed;
            $cursor["lo"] = $this->list_offset;
            $cursor["b"] = $this->streaming_file_offset;
            $cursor["cf"] = $this->current_file_meta;
        }

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
            $progress["files_completed"] = $this->files_streamed;
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
}

// Backward compatibility alias for tests
class_alias('FileTreeProducer', 'FileSyncProducer');
