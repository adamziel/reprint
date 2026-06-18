<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\Protocol\PreserveLocalSkipException;
use RuntimeException;

final class DirectoryChunkApplier
{
    private bool $preserve_local;

    /** @var callable */
    private $local_path_for_remote_path;

    /** @var callable */
    private $path_traverses_symlink;

    /** @var callable */
    private $remove_path;

    /** @var callable */
    private $ensure_directory;

    /** @var callable */
    private $audit;

    /** @var callable */
    private $emit_skip;

    /** @var callable */
    private $upsert_index_entry;

    public function __construct(
        bool $preserve_local,
        callable $local_path_for_remote_path,
        callable $path_traverses_symlink,
        callable $remove_path,
        callable $ensure_directory,
        callable $audit,
        callable $emit_skip,
        callable $upsert_index_entry
    ) {
        $this->preserve_local = $preserve_local;
        $this->local_path_for_remote_path = $local_path_for_remote_path;
        $this->path_traverses_symlink = $path_traverses_symlink;
        $this->remove_path = $remove_path;
        $this->ensure_directory = $ensure_directory;
        $this->audit = $audit;
        $this->emit_skip = $emit_skip;
        $this->upsert_index_entry = $upsert_index_entry;
    }

    public function handle(array $chunk): void
    {
        $headers = $chunk["headers"];
        $raw_header = $headers["x-directory-path"] ?? "";
        $path = base64_decode($raw_header, true);
        $ctime = (int) ($headers["x-directory-ctime"] ?? 0);

        if ($path === false || $path === "") {
            if ($raw_header !== "") {
                $this->audit(
                    "Warning: base64_decode failed for x-directory-path header: " .
                    substr($raw_header, 0, 100),
                    true,
                );
            }
            return;
        }

        $local_path = $this->local_path_for_remote_path($path);

        if ($this->preserve_local) {
            if (is_dir($local_path)) {
                $this->skip_and_index($path, $ctime, "PRESERVE-LOCAL skip directory (exists): {$path}");
                return;
            }
            if ($this->path_traverses_symlink($local_path)) {
                $this->skip_and_index($path, $ctime, "PRESERVE-LOCAL skip directory (symlink in path): {$path}");
                return;
            }
        }

        if (
            (file_exists($local_path) || is_link($local_path)) &&
            (!is_dir($local_path) || is_link($local_path))
        ) {
            if (!$this->remove_path($local_path)) {
                throw new RuntimeException(
                    "Failed to replace path with directory: {$path}",
                );
            }
        }

        try {
            $this->ensure_directory($local_path);
        } catch (PreserveLocalSkipException $e) {
            $this->audit($e->getMessage(), true);
            $this->emit_skip($path);
            return;
        }

        $this->audit("Directory: {$path}", false);

        if ($ctime > 0) {
            $this->upsert_index_entry($path, $ctime, 0, "dir");
        }
    }

    private function skip_and_index(string $path, int $ctime, string $message): void
    {
        $this->audit($message, true);
        $this->emit_skip($path);
        if ($ctime > 0) {
            $this->upsert_index_entry($path, $ctime, 0, "dir");
        }
    }

    private function local_path_for_remote_path(string $path): string
    {
        return (string) ($this->local_path_for_remote_path)($path);
    }

    private function path_traverses_symlink(string $path): bool
    {
        return (bool) ($this->path_traverses_symlink)($path);
    }

    private function remove_path(string $local_path): bool
    {
        return (bool) ($this->remove_path)($local_path);
    }

    private function ensure_directory(string $dir): void
    {
        ($this->ensure_directory)($dir);
    }

    private function audit(string $message, bool $to_console): void
    {
        ($this->audit)($message, $to_console);
    }

    private function emit_skip(string $path): void
    {
        ($this->emit_skip)($path);
    }

    private function upsert_index_entry(
        string $path,
        int $ctime,
        int $size,
        string $type
    ): void {
        ($this->upsert_index_entry)($path, $ctime, $size, $type);
    }
}
