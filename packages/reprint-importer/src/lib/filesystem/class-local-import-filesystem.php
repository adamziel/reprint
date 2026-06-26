<?php

namespace Reprint\Importer\Filesystem;

use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Protocol\PreserveLocalSkipException;
use RuntimeException;
use function Reprint\Exporter\assert_valid_path;
use function Reprint\Exporter\normalize_path;
use function Reprint\Exporter\path_is_within_root;

final class LocalImportFilesystem
{
    private string $fs_root;
    private string $fs_root_nonempty_behavior;

    private AuditLogger $audit;

    public function __construct(
        string $fs_root,
        string $fs_root_nonempty_behavior,
        AuditLogger $audit
    ) {
        $this->fs_root = $fs_root;
        $this->fs_root_nonempty_behavior = $fs_root_nonempty_behavior;
        $this->audit = $audit;
    }

    public function filesystem_root_path(): string
    {
        if (!is_dir($this->fs_root)) {
            if (!mkdir($this->fs_root, 0755, true) && !is_dir($this->fs_root)) {
                throw new RuntimeException(
                    "Failed to create fs root directory: {$this->fs_root}",
                );
            }
        }

        $real = realpath($this->fs_root);
        if ($real === false) {
            throw new RuntimeException(
                "Failed to resolve fs root path: {$this->fs_root}",
            );
        }

        return $real;
    }

    public function local_path_for_remote_path(string $path): string
    {
        assert_valid_path($path, "remote path");
        return $this->filesystem_root_path() . $path;
    }

    public function remove_path_without_following_symlinks(string $local_path): bool
    {
        if (!file_exists($local_path) && !is_link($local_path)) {
            return true;
        }

        if (is_link($local_path) || is_file($local_path)) {
            return true === @unlink($local_path);
        }

        if (is_dir($local_path)) {
            $entries = @scandir($local_path);
            if ($entries === false) {
                return false;
            }
            foreach ($entries as $entry) {
                if ($entry === "." || $entry === "..") {
                    continue;
                }
                if (
                    !$this->remove_path_without_following_symlinks(
                        $local_path . "/" . $entry
                    )
                ) {
                    return false;
                }
            }
            return true === @rmdir($local_path);
        }

        return true === @unlink($local_path);
    }

    public function remove_remote_path_without_following_symlinks(string $path): bool
    {
        $local_path = $this->local_path_for_remote_path($path);
        $parent = dirname($local_path);
        if ($this->path_traverses_symlink($parent)) {
            throw new RuntimeException(
                "Security: Refusing to delete path through symlink: {$path}",
            );
        }

        return $this->remove_path_without_following_symlinks($local_path);
    }

    public function path_traverses_symlink(string $path): bool
    {
        $root = $this->filesystem_root_path();
        $relative = ltrim(substr($path, strlen($root)), "/");
        if ($relative === "") {
            return false;
        }

        $current = $root;
        foreach (explode("/", $relative) as $part) {
            if ($part === "") {
                continue;
            }
            $current .= "/" . $part;
            if (is_link($current)) {
                return true;
            }
            if (!file_exists($current)) {
                break;
            }
        }
        return false;
    }

    public function ensure_directory_path(string $dir): void
    {
        $real_filesystem_root = $this->filesystem_root_path();

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
                !path_is_within_root($real_check, $real_filesystem_root)
            ) {
                if ($this->preserve_local()) {
                    throw new PreserveLocalSkipException(
                        "PRESERVE-LOCAL: path resolves outside fs root via symlink: {$dir}",
                    );
                }
                throw new RuntimeException(
                    "Security: Refusing to create directory outside fs root: {$dir}",
                );
            }
        }

        if (is_dir($dir) && !is_link($dir)) {
            if ($this->preserve_local() && !is_writable($dir)) {
                throw new PreserveLocalSkipException(
                    "PRESERVE-LOCAL: directory not writable: {$dir}",
                );
            }
            return;
        }

        if (
            $dir !== $real_filesystem_root &&
            !str_starts_with($dir, $real_filesystem_root . "/")
        ) {
            throw new RuntimeException(
                "Security: Refusing to create directory outside fs root: {$dir}",
            );
        }

        $relative = ltrim(substr($dir, strlen($real_filesystem_root)), "/");
        if ($relative === "") {
            return;
        }

        $current = $real_filesystem_root;
        foreach (explode("/", $relative) as $part) {
            if ($part === "") {
                continue;
            }
            $current .= "/" . $part;

            if (is_link($current)) {
                if ($this->preserve_local()) {
                    throw new PreserveLocalSkipException(
                        "PRESERVE-LOCAL: symlink in directory path: {$current}",
                    );
                }
                $this->audit(
                    "Removing symlink blocking directory: {$current}",
                    true,
                );
                if (!unlink($current)) {
                    throw new RuntimeException(
                        "Failed to remove symlink blocking directory: {$current}",
                    );
                }
                clearstatcache(true, $current);
            }

            if (is_file($current)) {
                if ($this->preserve_local()) {
                    throw new PreserveLocalSkipException(
                        "PRESERVE-LOCAL: file blocks directory creation: {$current}",
                    );
                }
                $this->audit(
                    "Removing file blocking directory: {$current}",
                    true,
                );
                if (!unlink($current)) {
                    throw new RuntimeException(
                        "Failed to remove file blocking directory: {$current}",
                    );
                }
            }

            if (is_dir($current)) {
                if ($this->preserve_local() && !is_writable($current)) {
                    throw new PreserveLocalSkipException(
                        "PRESERVE-LOCAL: directory not writable: {$current}",
                    );
                }
            } elseif (!mkdir($current, 0755) && !is_dir($current)) {
                throw new RuntimeException(
                    "Failed to create directory: {$current}\n" .
                    "Error: " .
                    (error_get_last()["message"] ?? "unknown"),
                );
            }

            $resolved = realpath($current);
            if ($resolved === false || !path_is_within_root($resolved, $real_filesystem_root)) {
                throw new RuntimeException(
                    "Security: Refusing to create directory outside fs root: {$current}",
                );
            }
        }
    }

    public function assert_symlink_target_within_root(
        string $symlink_parent_dir,
        string $target,
        string $root
    ): void {
        if (str_starts_with($target, "/")) {
            $resolved = normalize_path($target);
        } else {
            $resolved = normalize_path($symlink_parent_dir . "/" . $target);
        }

        if (!path_is_within_root($resolved, $root)) {
            throw new RuntimeException(
                "Security: symlink target escapes filesystem root: {$target} " .
                "(resolves to {$resolved}, root is {$root})"
            );
        }
    }

    private function preserve_local(): bool
    {
        return $this->fs_root_nonempty_behavior === 'preserve-local';
    }

    private function audit(string $message, bool $to_console): void
    {
        $this->audit->record($message, $to_console);
    }
}
