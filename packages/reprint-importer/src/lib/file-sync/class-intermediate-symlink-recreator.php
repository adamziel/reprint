<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\Filesystem\LocalImportFilesystem;
use Reprint\Importer\Observability\AuditLogger;
use RuntimeException;

final class IntermediateSymlinkRecreator
{
    private LocalImportFilesystem $filesystem;

    private AuditLogger $audit;

    public function __construct(LocalImportFilesystem $filesystem, AuditLogger $audit)
    {
        $this->filesystem = $filesystem;
        $this->audit = $audit;
    }

    public function recreate(string $remote_index_file): int
    {
        if (!file_exists($remote_index_file)) {
            return 0;
        }

        $handle = fopen($remote_index_file, "r");
        if (!$handle) {
            return 0;
        }

        $created = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                $entry = json_decode($line, true);
                if (!is_array($entry) || !$this->is_intermediate_link_entry($entry)) {
                    continue;
                }

                $path = base64_decode((string) $entry["path"], true);
                $target = base64_decode((string) $entry["target"], true);
                if ($path === false || $path === "" || $target === false || $target === "") {
                    continue;
                }

                if ($this->recreate_one($path, $target)) {
                    $created++;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($created > 0) {
            $this->audit("Recreated {$created} intermediate symlink(s)", false);
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function is_intermediate_link_entry(array $entry): bool
    {
        return ($entry["type"] ?? "") === "link"
            && !empty($entry["intermediate"])
            && is_string($entry["path"] ?? null)
            && $entry["path"] !== ""
            && is_string($entry["target"] ?? null)
            && $entry["target"] !== "";
    }

    private function recreate_one(string $path, string $target): bool
    {
        try {
            $local_path = $this->filesystem->local_path_for_remote_path($path);
        } catch (RuntimeException $e) {
            $this->audit(
                "INTERMEDIATE SYMLINK SKIP: invalid path {$path}: " . $e->getMessage(),
                true,
            );
            return false;
        }

        if (is_link($local_path) && readlink($local_path) === $target) {
            return false;
        }

        $parent = dirname($local_path);
        if (!is_dir($parent)) {
            try {
                $this->filesystem->ensure_directory_path($parent);
            } catch (RuntimeException $e) {
                $this->audit(
                    "INTERMEDIATE SYMLINK SKIP: failed to prepare parent for {$path}: " .
                        $e->getMessage(),
                    true,
                );
                return false;
            }
        }

        if (is_link($local_path)) {
            @unlink($local_path);
        }

        if (file_exists($local_path)) {
            $this->audit(
                "INTERMEDIATE SYMLINK SKIP: {$path} already exists as a real file/dir",
                true,
            );
            return false;
        }

        try {
            $this->filesystem->assert_symlink_target_within_root(
                $parent,
                $target,
                $this->filesystem->filesystem_root_path(),
            );
        } catch (RuntimeException $e) {
            $this->audit("INTERMEDIATE SYMLINK SKIP: " . $e->getMessage(), true);
            return false;
        }

        if (@symlink($target, $local_path)) {
            $this->audit("INTERMEDIATE SYMLINK: {$path} -> {$target}", false);
            return true;
        }

        $this->audit(
            "Failed to create intermediate symlink: {$path} -> {$target}",
            true,
        );
        return false;
    }

    private function audit(string $message, bool $to_console): void
    {
        $this->audit->record($message, $to_console);
    }
}
