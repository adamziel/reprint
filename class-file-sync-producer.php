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
            $current_map[$file['path']] = $file;
        }

        // Create new index file
        $new_index_file = $this->index_file . '.new';
        $new_handle = fopen($new_index_file, 'w');
        if (!$new_handle) {
            throw new RuntimeException("Could not create new index file");
        }

        // Merge with existing index
        if (file_exists($this->index_file)) {
            foreach ($this->get_all_files() as $old_file) {
                $path = $old_file['path'];

                if (isset($current_map[$path])) {
                    // File still exists - update
                    fprintf(
                        $new_handle,
                        "%s\t%d\t%d\tactive\t%d\t0\n",
                        $this->escape_path($path),
                        $current_map[$path]['ctime'],
                        $current_map[$path]['size'],
                        $current_time
                    );
                    unset($current_map[$path]);
                } elseif ($old_file['status'] === 'active') {
                    // File was deleted
                    fprintf(
                        $new_handle,
                        "%s\t%d\t%d\tdeleted\t%d\t%d\n",
                        $this->escape_path($path),
                        $old_file['ctime'],
                        $old_file['size'],
                        $old_file['last_seen'],
                        $current_time
                    );

                    $deletions[] = [
                        'path' => $path,
                        'ctime' => $old_file['ctime'],
                        'size' => $old_file['size'],
                        'deleted_at' => $current_time,
                    ];
                } else {
                    // Keep deleted files in index
                    fprintf(
                        $new_handle,
                        "%s\t%d\t%d\t%s\t%d\t%d\n",
                        $this->escape_path($path),
                        $old_file['ctime'],
                        $old_file['size'],
                        $old_file['status'],
                        $old_file['last_seen'],
                        $old_file['deleted_at']
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
                $file['ctime'],
                $file['size'],
                $current_time
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

        $handle = fopen($this->index_file, 'r');
        if (!$handle) {
            return null;
        }

        while (($line = fgets($handle)) !== false) {
            $parts = explode("\t", rtrim($line, "\n"));
            if (count($parts) >= 6 && $parts[0] === $escaped_path) {
                fclose($handle);
                return [
                    'path' => $this->unescape_path($parts[0]),
                    'ctime' => (int) $parts[1],
                    'size' => (int) $parts[2],
                    'status' => $parts[3],
                    'last_seen' => (int) $parts[4],
                    'deleted_at' => (int) $parts[5],
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

        $handle = fopen($this->index_file, 'r');
        if (!$handle) {
            return;
        }

        while (($line = fgets($handle)) !== false) {
            $parts = explode("\t", rtrim($line, "\n"));
            if (count($parts) >= 6) {
                yield [
                    'path' => $this->unescape_path($parts[0]),
                    'ctime' => (int) $parts[1],
                    'size' => (int) $parts[2],
                    'status' => $parts[3],
                    'last_seen' => (int) $parts[4],
                    'deleted_at' => (int) $parts[5],
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
        $meta_file = $this->index_file . '.meta';
        if (file_exists($meta_file)) {
            $data = json_decode(file_get_contents($meta_file), true);
            $this->last_sync_time = $data['last_sync_time'] ?? null;
        }
    }

    private function save_last_sync_time(): void
    {
        $meta_file = $this->index_file . '.meta';
        file_put_contents($meta_file, json_encode(['last_sync_time' => $this->last_sync_time]));
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
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_status ON files(status)');
    }

    public function update_from_scan(array $current_files): array
    {
        $current_time = time();
        $deletions = [];

        // Convert current files to lookup map
        $current_map = [];
        foreach ($current_files as $file) {
            $current_map[$file['path']] = $file;
        }

        $this->db->exec('BEGIN TRANSACTION');

        try {
            // Mark existing active files as deleted if not in current scan
            $stmt = $this->db->prepare('SELECT path, ctime, size FROM files WHERE status = ?');
            $stmt->bindValue(1, 'active', SQLITE3_TEXT);
            $result = $stmt->execute();

            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if (!isset($current_map[$row['path']])) {
                    // File deleted
                    $update = $this->db->prepare(
                        'UPDATE files SET status = ?, deleted_at = ? WHERE path = ?'
                    );
                    $update->bindValue(1, 'deleted', SQLITE3_TEXT);
                    $update->bindValue(2, $current_time, SQLITE3_INTEGER);
                    $update->bindValue(3, $row['path'], SQLITE3_TEXT);
                    $update->execute();

                    $deletions[] = [
                        'path' => $row['path'],
                        'ctime' => $row['ctime'],
                        'size' => $row['size'],
                        'deleted_at' => $current_time,
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
                $upsert->bindValue(2, $info['ctime'], SQLITE3_INTEGER);
                $upsert->bindValue(3, $info['size'], SQLITE3_INTEGER);
                $upsert->bindValue(4, 'active', SQLITE3_TEXT);
                $upsert->bindValue(5, $current_time, SQLITE3_INTEGER);
                $upsert->bindValue(6, 'active', SQLITE3_TEXT);
                $upsert->execute();
            }

            $this->db->exec('COMMIT');
        } catch (Exception $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }

        return $deletions;
    }

    public function get_file(string $path): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM files WHERE path = ?');
        $stmt->bindValue(1, $path, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return $row ?: null;
    }

    public function get_all_files(): \Generator
    {
        $result = $this->db->query('SELECT * FROM files');

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            yield $row;
        }
    }

    public function get_last_sync_time(): ?int
    {
        $stmt = $this->db->prepare('SELECT value FROM metadata WHERE key = ?');
        $stmt->bindValue(1, 'last_sync_time', SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return $row ? (int) $row['value'] : null;
    }

    public function set_last_sync_time(int $timestamp): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO metadata (key, value) VALUES (?, ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value
        ');
        $stmt->bindValue(1, 'last_sync_time', SQLITE3_TEXT);
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
    const PHASE_SCANNING = 'scanning';
    const PHASE_STREAMING = 'streaming';
    const PHASE_FINISHED = 'finished';

    private $directory;
    private $min_ctime;
    private $max_files;
    private $chunk_size;
    private $snapshot_storage;
    private $deletions;

    // State
    private $phase;
    private $current_chunk;

    // Scanning state
    private $scan_stack; // Directory stack for DFS
    private $scan_current_dir; // Current directory being scanned
    private $scan_dir_handle; // Directory handle
    private $scanned_files; // Array of found files (sorted by ctime after scanning)

    // Streaming state
    private $streaming_index; // Current index in scanned_files array
    private $streaming_file_handle;
    private $streaming_file_offset;
    private $files_streamed;

    public function __construct(string $directory, array $options = [])
    {
        $this->directory = rtrim($directory, '/');
        $this->snapshot_storage = $options['snapshot_storage'] ?? null;
        $this->min_ctime = $options['min_ctime'] ?? 0;
        $this->max_files = $options['max_files'] ?? 1000;
        $this->chunk_size = $options['chunk_size'] ?? (5 * 1024 * 1024); // 5MB default

        if (isset($options['cursor'])) {
            $this->initialize_from_cursor($options['cursor']);
        } else {
            $this->initialize_new();
        }
    }

    private function initialize_new(): void
    {
        $this->phase = self::PHASE_SCANNING;

        // Initialize scanning state
        $this->scan_stack = [$this->directory];
        $this->scan_current_dir = null;
        $this->scan_dir_handle = null;
        $this->scanned_files = [];

        // Initialize streaming state
        $this->streaming_index = 0;
        $this->streaming_file_handle = null;
        $this->streaming_file_offset = 0;
        $this->files_streamed = 0;

        $this->current_chunk = null;
        $this->deletions = null;
    }

    private function initialize_from_cursor(string $cursor_json): void
    {
        $cursor = json_decode($cursor_json, true);
        if (!$cursor) {
            throw new InvalidArgumentException('Invalid cursor format');
        }

        $this->phase = $cursor['p'];
        $this->current_chunk = null;

        if ($this->phase === self::PHASE_SCANNING) {
            // Resume scanning - restart (simpler than saving full state)
            $this->scan_stack = [$this->directory];
            $this->scan_current_dir = null;
            $this->scan_dir_handle = null;
            $this->scanned_files = [];
            $this->streaming_index = 0;
            $this->streaming_file_handle = null;
            $this->streaming_file_offset = 0;
            $this->files_streamed = 0;
            $this->deletions = null;
        } elseif ($this->phase === self::PHASE_STREAMING) {
            $this->files_streamed = $cursor['n'] ?? 0;
            $this->streaming_file_offset = $cursor['b'] ?? 0;
            $this->deletions = $cursor['d'] ?? null;

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
        $stack = [$this->directory];

        while (!empty($stack)) {
            $current_dir = array_pop($stack);
            $handle = @opendir($current_dir);

            if (!$handle) {
                continue;
            }

            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $full_path = $current_dir . '/' . $entry;

                if (is_dir($full_path)) {
                    $stack[] = $full_path;
                } elseif (is_file($full_path)) {
                    $ctime = filectime($full_path);
                    if ($ctime > $this->min_ctime) {
                        $this->scanned_files[] = [
                            'path' => $full_path,
                            'ctime' => $ctime,
                            'size' => filesize($full_path),
                        ];
                    }
                }
            }

            closedir($handle);
        }

        // Sort by ctime
        usort($this->scanned_files, fn($a, $b) => $a['ctime'] <=> $b['ctime']);

        // Limit to max_files
        if (count($this->scanned_files) > $this->max_files) {
            $this->scanned_files = array_slice($this->scanned_files, 0, $this->max_files);
        }
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
            // Complete scanning all at once (it's fast without temp file I/O)
            $this->complete_scanning();

            // After scanning completes, transition to streaming
            // Continue to streaming phase below
        }

        if ($this->phase === self::PHASE_STREAMING) {
            $this->stream_step();
            return $this->phase !== self::PHASE_FINISHED;
        }

        return false;
    }

    /**
     * Complete scanning all at once (fast since no temp file I/O)
     */
    private function complete_scanning(): void
    {
        $all_files = [];

        while (!empty($this->scan_stack)) {
            $current_dir = array_pop($this->scan_stack);
            $handle = @opendir($current_dir);

            if (!$handle) {
                continue;
            }

            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $full_path = $current_dir . '/' . $entry;

                if (is_dir($full_path)) {
                    $this->scan_stack[] = $full_path;
                } elseif (is_file($full_path)) {
                    $ctime = filectime($full_path);

                    if ($ctime > $this->min_ctime) {
                        $all_files[] = [
                            'path' => $full_path,
                            'ctime' => $ctime,
                            'size' => filesize($full_path),
                        ];
                    }
                }
            }

            closedir($handle);
        }

        // If we have snapshot storage, load previous snapshot and filter to changed files
        if ($this->snapshot_storage) {
            // Load previous snapshot into map
            $previous_snapshot = [];
            foreach ($this->snapshot_storage->get_all_files() as $file) {
                if ($file['status'] === 'active') {
                    $previous_snapshot[$file['path']] = [
                        'ctime' => $file['ctime'],
                        'size' => $file['size'],
                    ];
                }
            }

            // Filter to only changed files
            foreach ($all_files as $file) {
                $prev = $previous_snapshot[$file['path']] ?? null;

                // Include file if:
                // - It's new (not in previous snapshot)
                // - Or its ctime or size changed
                if (!$prev || $prev['ctime'] != $file['ctime'] || $prev['size'] != $file['size']) {
                    $this->scanned_files[] = $file;
                }
            }

            // Update snapshot and get deletions (after filtering)
            $this->deletions = $this->snapshot_storage->update_from_scan($all_files);
        } else {
            // No snapshot storage - include all files
            $this->scanned_files = $all_files;
        }

        // Sort by ctime
        usort($this->scanned_files, fn($a, $b) => $a['ctime'] <=> $b['ctime']);

        // Limit to max_files
        if (count($this->scanned_files) > $this->max_files) {
            $this->scanned_files = array_slice($this->scanned_files, 0, $this->max_files);
        }

        $this->phase = self::PHASE_STREAMING;
        $this->initialize_streaming();
    }

    private function initialize_streaming(): void
    {
        $this->streaming_index = 0;
        $this->streaming_file_handle = null;
        $this->streaming_file_offset = 0;
        $this->files_streamed = 0;
    }

    /**
     * Stream file chunks incrementally
     */
    private function stream_step(): void
    {
        // Check if we're done streaming
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
            $this->streaming_file_handle = @fopen($file['path'], 'r');

            if (!$this->streaming_file_handle) {
                // Skip unreadable files
                $this->streaming_index++;
                $this->files_streamed++;
                return;
            }

            // Seek to offset if resuming mid-file
            if ($this->streaming_file_offset > 0) {
                fseek($this->streaming_file_handle, $this->streaming_file_offset);
            }
        }

        // Read next chunk
        $file = $this->scanned_files[$this->streaming_index];
        $data = fread($this->streaming_file_handle, $this->chunk_size);

        if ($data === false || $data === '') {
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

        $is_first = ($offset === 0);
        $is_last = (feof($this->streaming_file_handle));

        if ($is_last) {
            fclose($this->streaming_file_handle);
            $this->streaming_file_handle = null;
            $this->streaming_file_offset = 0;
            $this->streaming_index++;
            $this->files_streamed++;
        }

        $this->current_chunk = [
            'path' => $file['path'],
            'size' => $file['size'],
            'ctime' => $file['ctime'],
            'data' => $data,
            'offset' => $offset,
            'is_first_chunk' => $is_first,
            'is_last_chunk' => $is_last,
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

    public function get_reentrancy_cursor(): string
    {
        $cursor = ['p' => $this->phase];

        if ($this->phase === self::PHASE_STREAMING) {
            $cursor['n'] = $this->files_streamed;
            $cursor['b'] = $this->streaming_file_offset;

            // Only include deletions if not yet output
            if ($this->files_streamed === 0 && $this->deletions !== null) {
                $cursor['d'] = $this->deletions;
            }
        }

        return json_encode($cursor);
    }

    public function get_progress(): array
    {
        $progress = [
            'phase' => $this->phase,
        ];

        if ($this->phase === self::PHASE_SCANNING) {
            $progress['files_found'] = count($this->scanned_files);
            $progress['directories_pending'] = count($this->scan_stack);
        } elseif ($this->phase === self::PHASE_STREAMING) {
            $progress['files_total'] = count($this->scanned_files);
            $progress['files_completed'] = $this->files_streamed;

            if ($this->streaming_index < count($this->scanned_files)) {
                $file = $this->scanned_files[$this->streaming_index];
                $progress['current_file'] = [
                    'path' => $file['path'],
                    'size' => $file['size'],
                    'bytes_read' => $this->streaming_file_offset,
                    'percent' => $file['size'] > 0 ? $this->streaming_file_offset / $file['size'] : 1,
                ];
            }

            $progress['percent_complete'] = count($this->scanned_files) > 0
                ? $this->files_streamed / count($this->scanned_files)
                : 1;
        }

        return $progress;
    }
}
