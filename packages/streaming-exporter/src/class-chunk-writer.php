<?php

/**
 * Writes file chunks, directories, and symlinks into a target directory.
 *
 * Extracted from the importer's chunk handlers so both the pull-side importer
 * and the push-side receiver can share the same filesystem write logic.
 * The writer performs path security validation (no traversal outside root)
 * and handles the first-chunk/last-chunk protocol for multi-part file transfers.
 */
class ChunkWriter
{
    /** @var string Absolute path to the root directory for writes. */
    private $root;

    /** @var resource|null Currently open file handle for streaming writes. */
    private $file_handle = null;

    /** @var string|null Path of the currently open file. */
    private $file_path = null;

    /** @var int Ctime of the currently open file (for touch after close). */
    private $file_ctime = 0;

    /** @var callable|null Optional audit log callback: function(string $message): void */
    private $audit_log;

    /**
     * @param string        $root      Absolute path to the target directory.
     * @param callable|null $audit_log Optional logging callback.
     */
    public function __construct(string $root, ?callable $audit_log = null)
    {
        $this->root = rtrim($root, '/');
        $this->audit_log = $audit_log;
    }

    /**
     * Write a file data chunk.
     *
     * Handles the first/last chunk protocol: opens the file on first chunk,
     * writes data, closes and sets mtime on last chunk.
     *
     * @param string $path       Remote absolute path (e.g. "/wp-content/uploads/photo.jpg").
     * @param string $data       File content bytes for this chunk.
     * @param bool   $is_first   True if this is the first chunk of a new file.
     * @param bool   $is_last    True if this is the final chunk.
     * @param int    $ctime      File ctime (used for touch on last chunk).
     */
    public function write_file_chunk(
        string $path,
        string $data,
        bool $is_first,
        bool $is_last,
        int $ctime = 0
    ): void {
        $local_path = $this->resolve_path($path);

        if ($is_first) {
            // Close any previously open file (safety net)
            $this->close_current_file();

            // Remove non-regular-files at the target path
            if (
                (file_exists($local_path) || is_link($local_path)) &&
                (!is_file($local_path) || is_link($local_path))
            ) {
                $this->remove_path($local_path);
            }

            // Create parent directory
            $this->ensure_directory(dirname($local_path));

            // Open for writing
            $this->file_handle = fopen($local_path, 'wb');
            if (!$this->file_handle) {
                throw new RuntimeException("Failed to open file for writing: {$local_path}");
            }
            $this->file_path = $local_path;
            $this->file_ctime = $ctime;
        }

        // Resume recovery: if a file was partially written in a previous
        // request, re-open it in append mode so continuation chunks (where
        // is_first=false) can still be written.  Without this, the writer
        // starts with file_handle=null and non-first chunks are silently dropped.
        if (!$is_first && $this->file_handle === null && file_exists($local_path)) {
            $this->file_handle = fopen($local_path, 'ab');
            if ($this->file_handle) {
                $this->file_path = $local_path;
                $this->file_ctime = $ctime;
            }
        }

        // Write data
        if ($this->file_handle && $data !== '') {
            $bytes = fwrite($this->file_handle, $data);
            if ($bytes === false || $bytes !== strlen($data)) {
                throw new RuntimeException(
                    "Write failed for {$this->file_path}: wrote " .
                    ($bytes === false ? '0' : $bytes) . '/' . strlen($data) .
                    ' bytes (disk full?)'
                );
            }
        }

        // Close on last chunk
        if ($is_last) {
            $this->close_current_file();
        }
    }

    /**
     * Create a directory.
     */
    public function write_directory(string $path, int $ctime = 0): void
    {
        $local_path = $this->resolve_path($path);

        // Remove non-directory at the target path
        if (
            (file_exists($local_path) || is_link($local_path)) &&
            (!is_dir($local_path) || is_link($local_path))
        ) {
            $this->remove_path($local_path);
        }

        $this->ensure_directory($local_path);

        if ($ctime > 0) {
            @touch($local_path, $ctime);
        }
    }

    /**
     * Create a symlink.
     *
     * @param string $path   Remote absolute path for the symlink.
     * @param string $target Symlink target (relative or absolute).
     * @param int    $ctime  Ctime (informational, symlinks can't be touched on all OSes).
     */
    public function write_symlink(string $path, string $target, int $ctime = 0): void
    {
        $local_path = $this->resolve_path($path);

        // Validate target stays within root
        $this->assert_symlink_target_within_root(
            dirname($local_path),
            $target
        );

        // Create parent directory
        $this->ensure_directory(dirname($local_path));

        // Remove anything at the target path
        if (file_exists($local_path) || is_link($local_path)) {
            $this->remove_path($local_path);
        }

        if (!symlink($target, $local_path)) {
            $this->log("Warning: failed to create symlink: {$path} -> {$target}");
        }
    }

    /**
     * Flush and close the currently open file, if any.
     * Called automatically between files and on destruction.
     */
    public function close_current_file(): void
    {
        if ($this->file_handle) {
            fclose($this->file_handle);
            if ($this->file_ctime > 0 && $this->file_path) {
                @touch($this->file_path, $this->file_ctime);
            }
            $this->file_handle = null;
            $this->file_path = null;
            $this->file_ctime = 0;
        }
    }

    public function __destruct()
    {
        $this->close_current_file();
    }

    /**
     * Resolve a remote absolute path to a local path under the root.
     * Validates the path is safe (no NUL bytes, no dot-segments, absolute).
     */
    private function resolve_path(string $path): string
    {
        assert_valid_path($path, 'remote path');
        return $this->root . $path;
    }

    /**
     * Ensure a directory exists, creating intermediate directories as needed.
     */
    private function ensure_directory(string $dir): void
    {
        if (is_dir($dir) && !is_link($dir)) {
            return;
        }

        // Walk from root to target, creating each component
        if (
            $dir !== $this->root &&
            !str_starts_with($dir, $this->root . '/')
        ) {
            throw new RuntimeException(
                "Security: Refusing to create directory outside root: {$dir}"
            );
        }

        $relative = ltrim(substr($dir, strlen($this->root)), '/');
        if ($relative === '') {
            return;
        }

        $current = $this->root;
        foreach (explode('/', $relative) as $part) {
            if ($part === '') {
                continue;
            }
            $current .= '/' . $part;

            // Remove symlinks or files that block directory creation
            if (is_link($current)) {
                if (!unlink($current)) {
                    throw new RuntimeException("Failed to remove symlink blocking directory: {$current}");
                }
                clearstatcache(true, $current);
            }

            if (is_file($current)) {
                if (!unlink($current)) {
                    throw new RuntimeException("Failed to remove file blocking directory: {$current}");
                }
            }

            if (!is_dir($current) && !mkdir($current, 0755) && !is_dir($current)) {
                throw new RuntimeException(
                    "Failed to create directory: {$current}\n" .
                    'Error: ' . (error_get_last()['message'] ?? 'unknown')
                );
            }
        }
    }

    /**
     * Remove a path (file, symlink, or empty directory) without following symlinks.
     */
    private function remove_path(string $path): void
    {
        if (is_link($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            @rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Assert that a symlink target resolves to a path within the root.
     */
    private function assert_symlink_target_within_root(
        string $symlink_parent_dir,
        string $target
    ): void {
        if (str_starts_with($target, '/')) {
            $resolved = normalize_path($target);
        } else {
            $resolved = normalize_path($symlink_parent_dir . '/' . $target);
        }

        if (!path_is_within_root($resolved, $this->root)) {
            throw new RuntimeException(
                "Security: symlink target escapes root: {$target} " .
                "(resolves to {$resolved}, root is {$this->root})"
            );
        }
    }

    private function log(string $message): void
    {
        if ($this->audit_log) {
            ($this->audit_log)($message);
        }
    }
}
