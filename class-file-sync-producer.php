<?php
/**
 * Re-entrant file synchronization producer - Simplified version.
 *
 * Provides two-phase file scanning and streaming:
 * 1. Scanning - Walk directory tree, collect matching files in memory
 * 2. Streaming - Stream file contents in chunks
 *
 * The scanning phase collects all files and sorts them by ctime in memory.
 * This is simpler than external sorting and works well for typical use cases.
 */

/**
 * Interface for storing file snapshots to detect deletions.
 * Implementations can use files, SQLite, or any other storage mechanism.
 */
interface SnapshotStorage
{
    /**
     * Update snapshot with current scan results and detect deletions.
     *
     * @param array $current_files Array of current files: [["path" => string, "ctime" => int, "size" => int], ...]
     * @return array Array of detected deletions: [["path" => string, "ctime" => int, "size" => int, "deleted_at" => int], ...]
     */
    public function update_from_scan(array $current_files): array;

    /**
     * Check if a file exists in the snapshot.
     *
     * @param string $path File path
     * @return array|null File info if exists, null otherwise
     */
    public function get_file(string $path): ?array;

    /**
     * Get all files from snapshot (for iteration).
     * Should return generator for memory efficiency.
     *
     * @return \Generator Yields ["path" => string, "ctime" => int, "size" => int, "status" => string]
     */
    public function get_all_files(): \Generator;

    /**
     * Get the timestamp of the last sync.
     *
     * @return int|null Last sync timestamp, or null if never synced
     */
    public function get_last_sync_time(): ?int;

    /**
     * Set the timestamp of the last sync.
     *
     * @param int $timestamp Sync timestamp
     */
    public function set_last_sync_time(int $timestamp): void;
}

/**
 * File-based snapshot storage using TSV format.
 * Memory-efficient for large file lists.
 * Format: path\tctime\tsize\tstatus\tlast_seen\tdeleted_at
 */
class FileSnapshotStorage implements SnapshotStorage
{
    private $index_file;
    private $last_sync_time = null;

    public function __construct(string $index_file)
    {
        $this->index_file = $index_file;
        $this->load_last_sync_time();
    }

    public function update_from_scan(array $current_files): array
    {
        $current_time = time();
        $deletions = [];

        // Convert current files array to lookup map
        $current_map = [];
        foreach ($current_files as $file) {
            $current_map[$file["path"]] = $file;
        }

        // Create new index file
        $new_index_file = $this->index_file . ".new";
        $new_handle = fopen($new_index_file, "w");
        if (!$new_handle) {
            throw new RuntimeException("Could not create new index file");
        }

        // Merge with existing index
        if (file_exists($this->index_file)) {
            foreach ($this->get_all_files() as $old_file) {
                $path = $old_file["path"];

                if (isset($current_map[$path])) {
                    // File still exists - update
                    fprintf(
                        $new_handle,
                        "%s\t%d\t%d\tactive\t%d\t0\n",
                        $this->escape_path($path),
                        $current_map[$path]["ctime"],
                        $current_map[$path]["size"],
                        $current_time,
                    );
                    unset($current_map[$path]);
                } elseif ($old_file["status"] === "active") {
                    // File was deleted
                    fprintf(
                        $new_handle,
                        "%s\t%d\t%d\tdeleted\t%d\t%d\n",
                        $this->escape_path($path),
                        $old_file["ctime"],
                        $old_file["size"],
                        $old_file["last_seen"],
                        $current_time,
                    );

                    $deletions[] = [
                        "path" => $path,
                        "ctime" => $old_file["ctime"],
                        "size" => $old_file["size"],
                        "deleted_at" => $current_time,
                    ];
                } else {
                    // Keep deleted files in index
                    fprintf(
                        $new_handle,
                        "%s\t%d\t%d\t%s\t%d\t%d\n",
                        $this->escape_path($path),
                        $old_file["ctime"],
                        $old_file["size"],
                        $old_file["status"],
                        $old_file["last_seen"],
                        $old_file["deleted_at"],
                    );
                }
            }
        }

        // Add new files
        foreach ($current_map as $path => $file) {
            fprintf(
                $new_handle,
                "%s\t%d\t%d\tactive\t%d\t0\n",
                $this->escape_path($path),
                $file["ctime"],
                $file["size"],
                $current_time,
            );
        }

        fclose($new_handle);

        // Atomic rename
        rename($new_index_file, $this->index_file);

        return $deletions;
    }

    public function get_file(string $path): ?array
    {
        if (!file_exists($this->index_file)) {
            return null;
        }

        $escaped_path = $this->escape_path($path);

        $handle = fopen($this->index_file, "r");
        if (!$handle) {
            return null;
        }

        while (($line = fgets($handle)) !== false) {
            $parts = explode("\t", rtrim($line, "\n"));
            if (count($parts) >= 6 && $parts[0] === $escaped_path) {
                fclose($handle);
                return [
                    "path" => $this->unescape_path($parts[0]),
                    "ctime" => (int) $parts[1],
                    "size" => (int) $parts[2],
                    "status" => $parts[3],
                    "last_seen" => (int) $parts[4],
                    "deleted_at" => (int) $parts[5],
                ];
            }
        }

        fclose($handle);
        return null;
    }

    public function get_all_files(): \Generator
    {
        if (!file_exists($this->index_file)) {
            return;
        }

        $handle = fopen($this->index_file, "r");
        if (!$handle) {
            return;
        }

        while (($line = fgets($handle)) !== false) {
            $parts = explode("\t", rtrim($line, "\n"));
            if (count($parts) >= 6) {
                yield [
                    "path" => $this->unescape_path($parts[0]),
                    "ctime" => (int) $parts[1],
                    "size" => (int) $parts[2],
                    "status" => $parts[3],
                    "last_seen" => (int) $parts[4],
                    "deleted_at" => (int) $parts[5],
                ];
            }
        }

        fclose($handle);
    }

    /**
     * Encode path for TSV storage (handles tabs, newlines, etc).
     */
    private function escape_path(string $path): string
    {
        return base64_encode($path);
    }

    /**
     * Decode path from TSV storage.
     */
    private function unescape_path(string $path): string
    {
        return base64_decode($path, true) ?: $path;
    }

    /**
     * Normalize a file path by resolving . and .. components.
     * Does NOT follow symlinks - only resolves path syntax.
     */
    private function normalize_path(string $path): string
    {
        $parts = explode("/", $path);
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === "" || $part === ".") {
                // Skip empty parts and current directory references
                if ($part === "" && count($normalized) === 0) {
                    // Preserve leading slash for absolute paths
                    $normalized[] = "";
                }
                continue;
            }

            if ($part === "..") {
                // Go up one directory
                if (count($normalized) > 0 && end($normalized) !== "..") {
                    array_pop($normalized);
                } else {
                    // Can't go up (relative path starting with ..)
                    $normalized[] = $part;
                }
            } else {
                $normalized[] = $part;
            }
        }

        return implode("/", $normalized);
    }

    public function get_last_sync_time(): ?int
    {
        return $this->last_sync_time;
    }

    public function set_last_sync_time(int $timestamp): void
    {
        $this->last_sync_time = $timestamp;
        $this->save_last_sync_time();
    }

    private function load_last_sync_time(): void
    {
        $meta_file = $this->index_file . ".meta";
        if (file_exists($meta_file)) {
            $data = json_decode(file_get_contents($meta_file), true);
            $this->last_sync_time = $data["last_sync_time"] ?? null;
        }
    }

    private function save_last_sync_time(): void
    {
        $meta_file = $this->index_file . ".meta";
        file_put_contents(
            $meta_file,
            json_encode(["last_sync_time" => $this->last_sync_time]),
        );
    }
}

/**
 * SQLite-based snapshot storage.
 * Efficient for very large file lists (millions of files).
 */
class SqliteSnapshotStorage implements SnapshotStorage
{
    private $db;

    public function __construct(string $db_path)
    {
        $this->db = new SQLite3($db_path);

        // Create table if not exists
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS files (
                path TEXT PRIMARY KEY,
                ctime INTEGER NOT NULL,
                size INTEGER NOT NULL,
                status TEXT NOT NULL,
                last_seen INTEGER NOT NULL,
                deleted_at INTEGER NOT NULL DEFAULT 0
            )
        ');

        // Create metadata table for tracking sync times
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS metadata (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )
        ');

        // Create index for faster queries
        $this->db->exec(
            "CREATE INDEX IF NOT EXISTS idx_status ON files(status)",
        );
    }

    public function update_from_scan(array $current_files): array
    {
        $current_time = time();
        $deletions = [];

        // Convert current files to lookup map
        $current_map = [];
        foreach ($current_files as $file) {
            $current_map[$file["path"]] = $file;
        }

        $this->db->exec("BEGIN TRANSACTION");

        try {
            // Mark existing active files as deleted if not in current scan
            $stmt = $this->db->prepare(
                "SELECT path, ctime, size FROM files WHERE status = ?",
            );
            $stmt->bindValue(1, "active", SQLITE3_TEXT);
            $result = $stmt->execute();

            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (!isset($current_map[$row["path"]])) {
                    // File deleted
                    $update = $this->db->prepare(
                        "UPDATE files SET status = ?, deleted_at = ? WHERE path = ?",
                    );
                    $update->bindValue(1, "deleted", SQLITE3_TEXT);
                    $update->bindValue(2, $current_time, SQLITE3_INTEGER);
                    $update->bindValue(3, $row["path"], SQLITE3_TEXT);
                    $update->execute();

                    $deletions[] = [
                        "path" => $row["path"],
                        "ctime" => $row["ctime"],
                        "size" => $row["size"],
                        "deleted_at" => $current_time,
                    ];
                }
            }

            // Upsert current files
            $upsert = $this->db->prepare('
                INSERT INTO files (path, ctime, size, status, last_seen, deleted_at)
                VALUES (?, ?, ?, ?, ?, 0)
                ON CONFLICT(path) DO UPDATE SET
                    ctime = excluded.ctime,
                    size = excluded.size,
                    status = ?,
                    last_seen = excluded.last_seen
            ');

            foreach ($current_map as $path => $info) {
                $upsert->bindValue(1, $path, SQLITE3_TEXT);
                $upsert->bindValue(2, $info["ctime"], SQLITE3_INTEGER);
                $upsert->bindValue(3, $info["size"], SQLITE3_INTEGER);
                $upsert->bindValue(4, "active", SQLITE3_TEXT);
                $upsert->bindValue(5, $current_time, SQLITE3_INTEGER);
                $upsert->bindValue(6, "active", SQLITE3_TEXT);
                $upsert->execute();
            }

            $this->db->exec("COMMIT");
        } catch (Exception $e) {
            $this->db->exec("ROLLBACK");
            throw $e;
        }

        return $deletions;
    }

    public function get_file(string $path): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM files WHERE path = ?");
        $stmt->bindValue(1, $path, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return $row ?: null;
    }

    public function get_all_files(): \Generator
    {
        $result = $this->db->query("SELECT * FROM files");

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            yield $row;
        }
    }

    public function get_last_sync_time(): ?int
    {
        $stmt = $this->db->prepare("SELECT value FROM metadata WHERE key = ?");
        $stmt->bindValue(1, "last_sync_time", SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return $row ? (int) $row["value"] : null;
    }

    public function set_last_sync_time(int $timestamp): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO metadata (key, value) VALUES (?, ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value
        ');
        $stmt->bindValue(1, "last_sync_time", SQLITE3_TEXT);
        $stmt->bindValue(2, (string) $timestamp, SQLITE3_TEXT);
        $stmt->execute();
    }

    public function __destruct()
    {
        $this->db->close();
    }
}

/**
 * Simplified re-entrant file sync producer with chunked file streaming.
 *
 * Two phases:
 * 1. Scanning - Walk directory, collect files in memory
 * 2. Streaming - Stream file contents in chunks
 */
class FileSyncProducer
{
    const PHASE_SCANNING = "scanning";
    const PHASE_STREAMING = "streaming";
    const PHASE_FINISHED = "finished";

    private $directories; // Array of directories to scan
    private $min_ctime;
    private $chunk_size;
    private $snapshot_storage;
    private $deletions;
    private $follow_symlinks;
    private $filesystem_root; // Common ancestor of scan dirs and all symlink targets

    // State
    private $phase;
    private $current_chunk;

    // Scanning state
    private $scan_stack; // Directory stack for DFS
    private $scan_current_dir; // Current directory being scanned
    private $scan_dir_handle; // Directory handle
    private $scanned_files; // Array of found files (sorted by ctime after scanning)
    private $scanned_directories; // Array of directories encountered
    private $scanned_symlinks; // Array of symlinks to preserve
    private $visited_paths; // Set of visited real paths (for cycle detection)
    private $scan_batch_size; // Number of directories to scan per chunk
    private $scan_batch_count; // Counter for current batch

    // Streaming state
    private $streaming_index; // Current index in scanned_files array
    private $streaming_file_handle;
    private $streaming_file_offset;
    private $files_streamed;
    private $empty_directories; // Empty directories to output
    private $empty_dir_index; // Current index in empty_directories
    private $symlink_index; // Current index in scanned_symlinks

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

        $this->snapshot_storage = $options["snapshot_storage"] ?? null;
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

        // Initialize scanning state - start with all directories
        $this->scan_stack = $this->directories;
        $this->scan_current_dir = null;
        $this->scan_dir_handle = null;
        $this->scanned_files = [];
        $this->scanned_directories = [];
        $this->scanned_symlinks = [];
        $this->visited_paths = [];
        $this->scan_batch_size = 10; // Scan 10 directories per chunk for faster response
        $this->scan_batch_count = 0;

        // Initialize streaming state
        $this->streaming_index = 0;
        $this->streaming_file_handle = null;
        $this->streaming_file_offset = 0;
        $this->files_streamed = 0;
        $this->empty_directories = [];
        $this->empty_dir_index = 0;
        $this->symlink_index = 0;

        $this->current_chunk = null;
        $this->deletions = null;
    }

    /**
     * Initialize from a reentrancy cursor.
     *
     * @param string $cursor_json JSON string (NOT base64-encoded). Must be valid JSON.
     * @throws InvalidArgumentException if cursor is not valid JSON
     */
    private function initialize_from_cursor(string $cursor_json): void
    {
        $cursor = json_decode($cursor_json, true);
        if ($cursor === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                "Invalid cursor format: cursor must be valid JSON. " .
                    "JSON error: " .
                    json_last_error_msg() .
                    ". " .
                    "Received: " .
                    substr($cursor_json, 0, 100),
            );
        }
        if (!is_array($cursor)) {
            throw new InvalidArgumentException(
                "Invalid cursor format: cursor must be a JSON object. " .
                    "Received: " .
                    substr($cursor_json, 0, 100),
            );
        }

        $this->phase = $cursor["p"];
        $this->filesystem_root = $cursor["fsr"] ?? null;
        $this->current_chunk = null;

        if ($this->phase === self::PHASE_SCANNING) {
            // Resume scanning - restart (simpler than saving full state)
            $this->scan_stack = $this->directories;
            $this->scan_current_dir = null;
            $this->scan_dir_handle = null;
            $this->scanned_files = [];
            $this->scanned_directories = [];
            $this->scanned_symlinks = [];
            $this->visited_paths = [];
            $this->scan_batch_size = 10;
            $this->scan_batch_count = 0;
            $this->streaming_index = 0;
            $this->streaming_file_handle = null;
            $this->streaming_file_offset = 0;
            $this->files_streamed = 0;
            $this->empty_directories = [];
            $this->empty_dir_index = 0;
            $this->symlink_index = 0;
            $this->deletions = null;
        } elseif ($this->phase === self::PHASE_STREAMING) {
            $this->files_streamed = $cursor["n"] ?? 0;
            $this->streaming_file_offset = $cursor["b"] ?? 0;
            $this->deletions = $cursor["d"] ?? null;

            // We need to re-scan to rebuild the scanned_files array
            // This is acceptable because scanning is fast (no I/O to temp files)
            $this->rescan_for_resume();

            $this->streaming_index = $this->files_streamed;
            $this->streaming_file_handle = null;
        } else {
            $this->phase = self::PHASE_FINISHED;
        }
    }

    /**
     * Re-scan directory to rebuild scanned_files array when resuming.
     */
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

        // Track root directories
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

                // Handle symlinks - just record them, don't follow
                if (is_link($full_path)) {
                    $target = readlink($full_path);
                    if ($target !== false) {
                        // Suppress warning for broken symlinks
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
                    // Always scan all files
                    // Suppress warnings for inaccessible files
                    $ctime = @filectime($full_path);
                    $size = @filesize($full_path);

                    // Skip files we can't access
                    if ($ctime === false || $size === false) {
                        continue;
                    }

                    // Apply min_ctime filter when adding to scanned_files
                    if ($ctime > $this->min_ctime) {
                        $this->scanned_files[] = [
                            "path" => $full_path,
                            "ctime" => $ctime,
                            "size" => $size,
                        ];
                    }
                }
            }

            closedir($handle);
        }

        // Sort by ctime, then by path for stable ordering (handles same ctime)
        usort(
            $this->scanned_files,
            fn($a, $b) => $a["ctime"] <=> $b["ctime"] ?:
            strcmp($a["path"], $b["path"]),
        );

        // Identify empty directories
        $this->identify_empty_directories($this->scanned_files);

        // Calculate filesystem root (common ancestor of scan dir and all symlink targets)
        $this->filesystem_root = $this->calculate_filesystem_root();
    }

    /**
     * Process next chunk. Returns true if more work to do, false if done.
     */
    public function next_chunk(): bool
    {
        if ($this->phase === self::PHASE_FINISHED) {
            return false;
        }

        if ($this->phase === self::PHASE_SCANNING) {
            // Scan incrementally
            $scan_complete = $this->scan_step();

            if ($scan_complete) {
                // Scanning finished, finalize and transition to streaming
                $this->finalize_scanning();
                // Fall through to streaming
            } else {
                // More scanning to do, output progress chunk
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

    /**
     * Scan a batch of directories. Returns true if scanning is complete.
     */
    private function scan_step(): bool
    {
        // Initialize visited paths for root directories on first scan
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
                        // Suppress warning for broken symlinks
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
                    // Suppress warnings for inaccessible files
                    $ctime = @filectime($full_path);
                    $size = @filesize($full_path);

                    // Skip files we can't access
                    if ($ctime === false || $size === false) {
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

        // Return true if scanning is complete
        return empty($this->scan_stack);
    }

    /**
     * Finalize scanning and transition to streaming.
     */
    private function finalize_scanning(): void
    {
        error_log(
            "FileSyncProducer: Scanning complete, found " .
                count($this->scanned_files) .
                " files",
        );

        $all_files = $this->scanned_files;

        // Handle snapshot storage
        if ($this->snapshot_storage) {
            error_log("FileSyncProducer: Loading previous snapshot");
            $previous_snapshot = [];
            foreach ($this->snapshot_storage->get_all_files() as $file) {
                if ($file["status"] === "active") {
                    $previous_snapshot[$file["path"]] = [
                        "ctime" => $file["ctime"],
                        "size" => $file["size"],
                    ];
                }
            }
            error_log(
                "FileSyncProducer: Loaded " .
                    count($previous_snapshot) .
                    " files from snapshot",
            );

            // Filter files to send
            $filtered_files = [];
            foreach ($all_files as $file) {
                if ($file["ctime"] <= $this->min_ctime) {
                    continue;
                }

                $prev = $previous_snapshot[$file["path"]] ?? null;
                if (
                    !$prev ||
                    $prev["ctime"] != $file["ctime"] ||
                    $prev["size"] != $file["size"]
                ) {
                    $filtered_files[] = $file;
                }
            }
            $this->scanned_files = $filtered_files;
            error_log(
                "FileSyncProducer: Filtered to " .
                    count($this->scanned_files) .
                    " files to send",
            );

            error_log(
                "FileSyncProducer: Updating snapshot with " .
                    count($all_files) .
                    " files",
            );
            $this->deletions = $this->snapshot_storage->update_from_scan(
                $all_files,
            );
            error_log(
                "FileSyncProducer: Snapshot updated, found " .
                    count($this->deletions ?? []) .
                    " deletions",
            );
        } else {
            $filtered_files = [];
            foreach ($all_files as $file) {
                if ($file["ctime"] > $this->min_ctime) {
                    $filtered_files[] = $file;
                }
            }
            $this->scanned_files = $filtered_files;
        }

        // Sort by ctime, then by path
        usort(
            $this->scanned_files,
            fn($a, $b) => $a["ctime"] <=> $b["ctime"] ?:
            strcmp($a["path"], $b["path"]),
        );

        // Identify empty directories
        $this->identify_empty_directories($all_files);

        // Calculate filesystem root
        $this->filesystem_root = $this->calculate_filesystem_root();

        $this->phase = self::PHASE_STREAMING;
        $this->initialize_streaming();
        error_log(
            "FileSyncProducer: Scanning complete, transitioning to streaming phase",
        );
    }

    /**
     * Identify directories that contain no files
     */
    private function identify_empty_directories(array $all_files): void
    {
        // Build set of directories that contain files
        $dirs_with_files = [];
        foreach ($all_files as $file) {
            $dir = dirname($file["path"]);
            // Mark this directory and all parent directories as having content
            while (
                !in_array($dir, $this->directories) &&
                $dir !== "/" &&
                $dir !== "."
            ) {
                $dirs_with_files[$dir] = true;
                $dir = dirname($dir);
            }
        }

        // Find directories with no files (excluding root directories)
        $this->empty_directories = [];
        foreach (array_keys($this->scanned_directories) as $dir) {
            // Skip the root scan directories themselves
            if (in_array($dir, $this->directories)) {
                continue;
            }

            if (!isset($dirs_with_files[$dir])) {
                $this->empty_directories[] = $dir;
            }
        }

        // Sort empty directories by path for consistent output
        sort($this->empty_directories);
    }

    /**
     * Calculate the filesystem root - the common ancestor of all scan directories
     * and all symlink targets. This allows us to export the full directory tree
     * needed to preserve symlinks.
     */
    private function calculate_filesystem_root(): string
    {
        // Start with all scan directories
        $paths = array_map("realpath", $this->directories);
        $paths = array_filter($paths); // Remove any false values

        // Add all symlink target real paths
        foreach ($this->scanned_symlinks as $symlink) {
            // Resolve symlink to absolute path
            $symlink_path = $symlink["path"];
            $target = $symlink["target"];

            // Resolve target relative to symlink's directory
            $symlink_dir = dirname($symlink_path);
            if ($target[0] !== "/") {
                // Relative path - resolve it
                $target_path = $symlink_dir . "/" . $target;
            } else {
                // Absolute path
                $target_path = $target;
            }

            $real_target = realpath($target_path);
            if ($real_target !== false) {
                $paths[] = $real_target;
            } else {
                // Symlink target doesn't exist or can't be resolved
                error_log(
                    "Warning: Cannot resolve symlink target: {$symlink_path} -> {$target} " .
                        "(resolved to {$target_path})",
                );
            }
        }

        // Find common ancestor of all paths
        if (count($paths) === 0) {
            return "/";
        }

        if (count($paths) === 1) {
            return $paths[0];
        }

        $common = $this->find_common_ancestor($paths);

        // If filesystem root is /, that's expected when exporting multiple directories
        if ($common === "/" || $common === "") {
            if (count($this->directories) > 1) {
                error_log(
                    "FileSyncProducer: Using / as filesystem root for multiple directories: " .
                        implode(", ", $this->directories),
                );
            }
            return "/";
        }

        return $common;
    }

    /**
     * Find the common ancestor directory of multiple paths.
     */
    private function find_common_ancestor(array $paths): string
    {
        if (empty($paths)) {
            return "/";
        }

        // Split each path into components
        $path_parts = array_map(fn($p) => explode("/", trim($p, "/")), $paths);

        // Find shortest path length
        $min_depth = min(array_map("count", $path_parts));

        // Find common prefix
        $common_parts = [];
        for ($i = 0; $i < $min_depth; $i++) {
            $part = $path_parts[0][$i];
            $all_match = true;

            foreach ($path_parts as $parts) {
                if ($parts[$i] !== $part) {
                    $all_match = false;
                    break;
                }
            }

            if ($all_match) {
                $common_parts[] = $part;
            } else {
                break;
            }
        }

        if (empty($common_parts)) {
            return "/";
        }

        return "/" . implode("/", $common_parts);
    }

    private function initialize_streaming(): void
    {
        $this->streaming_index = 0;
        $this->streaming_file_handle = null;
        $this->streaming_file_offset = 0;
        $this->files_streamed = 0;
    }

    /**
     * Convert an absolute path to be relative to the filesystem root.
     * This allows us to recreate the directory structure under filesystem-root/
     */
    private function make_relative_to_root(string $path): string
    {
        $root = rtrim($this->filesystem_root, "/");
        $path = rtrim($path, "/");

        if (strpos($path, $root) === 0) {
            $relative = substr($path, strlen($root));
            return $relative ?: "/";
        }

        // Path not under root - return as-is (shouldn't happen after our safety checks)
        return $path;
    }

    /**
     * Stream file chunks incrementally
     */
    private function stream_step(): void
    {
        // Output empty directory chunks first
        if ($this->empty_dir_index < count($this->empty_directories)) {
            $dir = $this->empty_directories[$this->empty_dir_index];
            $this->empty_dir_index++;

            $this->current_chunk = [
                "type" => "directory",
                "path" => $dir, // Keep full absolute path
            ];
            return;
        }

        // Output symlink chunks second
        if ($this->symlink_index < count($this->scanned_symlinks)) {
            $symlink = $this->scanned_symlinks[$this->symlink_index];
            $this->symlink_index++;

            $this->current_chunk = [
                "type" => "symlink",
                "path" => $symlink["path"], // Keep full absolute path
                "target" => $symlink["target"], // Keep target as-is (relative link)
                "ctime" => $symlink["ctime"],
            ];
            return;
        }

        // Check if we're done streaming files
        if ($this->streaming_index >= count($this->scanned_files)) {
            // Close any open file handle
            if ($this->streaming_file_handle) {
                fclose($this->streaming_file_handle);
                $this->streaming_file_handle = null;
            }

            $this->phase = self::PHASE_FINISHED;
            $this->current_chunk = null;
            return;
        }

        // Open next file if needed
        if ($this->streaming_file_handle === null) {
            $file = $this->scanned_files[$this->streaming_index];
            $this->streaming_file_handle = @fopen($file["path"], "r");

            if (!$this->streaming_file_handle) {
                // Skip unreadable files
                $this->streaming_index++;
                $this->files_streamed++;
                return;
            }

            // Seek to offset if resuming mid-file
            if ($this->streaming_file_offset > 0) {
                fseek(
                    $this->streaming_file_handle,
                    $this->streaming_file_offset,
                );
            }
        }

        // Read next chunk
        $file = $this->scanned_files[$this->streaming_index];

        $data = fread($this->streaming_file_handle, $this->chunk_size);

        if ($data === false || $data === "") {
            // End of file or error
            fclose($this->streaming_file_handle);
            $this->streaming_file_handle = null;
            $this->streaming_file_offset = 0;
            $this->streaming_index++;
            $this->files_streamed++;
            return;
        }

        $offset = $this->streaming_file_offset;
        $this->streaming_file_offset += strlen($data);

        $is_first = $offset === 0;
        $is_last = feof($this->streaming_file_handle);

        if ($is_last) {
            fclose($this->streaming_file_handle);
            $this->streaming_file_handle = null;
            $this->streaming_file_offset = 0;
            $this->streaming_index++;
            $this->files_streamed++;
        }

        $this->current_chunk = [
            "type" => "file",
            "path" => $file["path"], // Keep full absolute path
            "size" => $file["size"],
            "ctime" => $file["ctime"],
            "data" => $data,
            "offset" => $offset,
            "is_first_chunk" => $is_first,
            "is_last_chunk" => $is_last,
        ];
    }

    public function get_current_chunk(): ?array
    {
        return $this->current_chunk;
    }

    public function get_deletions(): ?array
    {
        return $this->deletions;
    }

    public function get_filesystem_root(): ?string
    {
        return $this->filesystem_root;
    }

    /**
     * Get reentrancy cursor for resuming.
     *
     * @return string JSON string (NOT base64-encoded). Caller is responsible for base64 encoding if needed for HTTP transmission.
     */
    public function get_reentrancy_cursor(): string
    {
        $cursor = [
            "p" => $this->phase,
            "fsr" => $this->filesystem_root ?? null, // filesystem root
        ];

        if ($this->phase === self::PHASE_STREAMING) {
            $cursor["n"] = $this->files_streamed;
            $cursor["b"] = $this->streaming_file_offset;

            // Only include deletions if not yet output
            if ($this->files_streamed === 0 && $this->deletions !== null) {
                $cursor["d"] = $this->deletions;
            }
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

            if ($this->streaming_index < count($this->scanned_files)) {
                $file = $this->scanned_files[$this->streaming_index];
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
}
