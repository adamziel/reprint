<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\FileSync\Port\LocalFileApplyContext;
use Reprint\Importer\Protocol\PreserveLocalSkipException;
use RuntimeException;
use function Reprint\Exporter\normalize_path;
use function Reprint\Exporter\path_is_within_root;

final class SymlinkChunkApplier
{
    private bool $preserve_local;
    private LocalFileApplyContext $local;

    public function __construct(
        bool $preserve_local,
        LocalFileApplyContext $local
    ) {
        $this->preserve_local = $preserve_local;
        $this->local = $local;
    }

    public function handle(array $chunk): void
    {
        $headers = $chunk["headers"];
        $raw_path = $headers["x-symlink-path"] ?? "";
        $path = base64_decode($raw_path, true);
        $target = base64_decode($headers["x-symlink-target"] ?? "", true);
        $ctime = (int) ($headers["x-symlink-ctime"] ?? 0);

        if ($path === false || $path === "" || $target === false || $target === "") {
            if ($raw_path !== "" && ($path === false || $path === "")) {
                $this->audit(
                    "Warning: base64_decode failed for x-symlink-path header: " .
                    substr($raw_path, 0, 100),
                    true,
                );
            }
            return;
        }

        $local_path = $this->local_path_for_remote_path($path);
        $target_for_local = $this->map_target_for_local($path, $local_path, $target);

        if ($this->preserve_local) {
            if (file_exists($local_path) || is_link($local_path)) {
                $this->audit("PRESERVE-LOCAL skip symlink (path exists): {$path} -> {$target}", true);
                $this->emit_skip($path);
                return;
            }
            if ($this->path_traverses_symlink(dirname($local_path))) {
                $this->audit("PRESERVE-LOCAL skip symlink (symlink in path): {$path} -> {$target}", true);
                $this->emit_skip($path);
                return;
            }
        }

        try {
            $this->assert_target_within_root(
                dirname($local_path),
                $target_for_local,
                $this->filesystem_root(),
            );
        } catch (RuntimeException $e) {
            $this->audit($e->getMessage(), true);
            $this->emit_symlink_error($path, $target, $target_for_local, $e->getMessage());
            return;
        }

        if (file_exists($local_path) || is_link($local_path)) {
            if (!$this->remove_path($local_path)) {
                $this->audit(
                    "Failed to remove existing path for symlink: {$local_path}",
                    true,
                );
                $this->emit_symlink_error($path, $target, $target_for_local, "Failed to replace existing path");
                return;
            }
        }

        $dir = dirname($local_path);
        if (!is_dir($dir)) {
            try {
                $this->ensure_directory($dir);
            } catch (PreserveLocalSkipException $e) {
                $this->audit($e->getMessage(), true);
                $this->emit_skip($path);
                return;
            } catch (RuntimeException $e) {
                $this->audit(
                    "Failed to create directory for symlink: {$dir}",
                    true,
                );
                $this->emit_symlink_error($path, $target, $target_for_local, "Failed to create parent directory");
                return;
            }
        }

        $symlink_result = symlink($target_for_local, $local_path);
        if (true !== $symlink_result || !is_link($local_path)) {
            $this->audit(
                "Failed to create symlink: {$local_path} -> {$target_for_local}",
                true,
            );
            $this->emit_symlink_error($path, $target, $target_for_local, "Failed to create symlink");
            return;
        }

        if ($ctime > 0) {
            @touch($local_path, $ctime);
        }

        $this->audit("Symlink: {$path} -> {$target_for_local}", false);

        if ($ctime > 0) {
            $this->upsert_index_entry($path, $ctime, 0, "link");
        }

        $this->emit_progress([
            "type" => "symlink",
            "path" => $path,
            "target" => $target_for_local,
            "message" => "Symlink: {$path} -> {$target}",
        ]);
    }

    private function assert_target_within_root(
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

    private function emit_symlink_error(
        string $path,
        string $target,
        string $target_for_local,
        string $error
    ): void {
        $this->emit_progress([
            "type" => "symlink_error",
            "path" => $path,
            "target" => $target_for_local,
            "error" => $error,
            "message" => "Symlink error: {$path} -> {$target}",
        ]);
    }

    private function local_path_for_remote_path(string $path): string
    {
        return $this->local->local_path_for_remote_path($path);
    }

    private function map_target_for_local(
        string $path,
        string $local_path,
        string $target
    ): string {
        return $this->local->map_absolute_symlink_target_for_local_mirror(
            $path,
            $local_path,
            $target,
        );
    }

    private function filesystem_root(): string
    {
        return $this->local->filesystem_root_path();
    }

    private function path_traverses_symlink(string $path): bool
    {
        return $this->local->path_traverses_symlink($path);
    }

    private function remove_path(string $local_path): bool
    {
        return $this->local->remove_path_without_following_symlinks($local_path);
    }

    private function ensure_directory(string $dir): void
    {
        $this->local->ensure_directory_path($dir);
    }

    private function audit(string $message, bool $to_console): void
    {
        $this->local->audit($message, $to_console);
    }

    private function emit_skip(string $path): void
    {
        $this->local->emit_skip_progress($path);
    }

    private function upsert_index_entry(
        string $path,
        int $ctime,
        int $size,
        string $type
    ): void {
        $this->local->upsert_index_entry($path, $ctime, $size, $type);
    }

    private function emit_progress(array $progress): void
    {
        $this->local->output_progress($progress);
    }
}
