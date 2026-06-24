<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\Filesystem\LocalImportFilesystem;
use Reprint\Importer\Filesystem\PathUtils;
use Reprint\Importer\Index\IndexPathPrefixMatcher;
use Reprint\Importer\Index\IndexStore;
use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\FileSync\Port\LocalFileChangePlanner;
use Reprint\Importer\FileSync\Port\FileSyncStreamObserver;
use Reprint\Importer\FileSync\Port\LocalFileApplyContext;
use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Observability\MachineEventEmitter;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Session\VolatileFileTracker;
use Reprint\Importer\Support\PathDisplayFormatter;
use RuntimeException;
use function Reprint\Exporter\normalize_path;
use function Reprint\Exporter\path_is_within_root;

final class FileSyncLocalApplier implements FileSyncStreamObserver, LocalFileChangePlanner, LocalFileApplyContext
{
    private LocalImportFilesystem $filesystem;
    private IndexStore $index_store;
    private VolatileFileTracker $volatile_file_tracker;
    private ImportOutput $output;
    private string $fs_root;
    private string $remote_index_file;
    private string $fs_root_nonempty_behavior;
    private bool $follow_symlinks;
    private int $files_imported;
    private ?int $download_list_done;
    private ?int $download_list_total;
    private ?IndexPathPrefixMatcher $remote_index_prefix_matcher = null;
    private ?FilesPullCheckpoint $checkpoint;

    private AuditLogger $audit;
    private MachineEventEmitter $machine_events;

    /**
     */
    public function __construct(
        LocalImportFilesystem $filesystem,
        IndexStore $index_store,
        VolatileFileTracker $volatile_file_tracker,
        ImportOutput $output,
        string $fs_root,
        string $remote_index_file,
        string $fs_root_nonempty_behavior,
        bool $follow_symlinks,
        int $files_imported,
        ?int $download_list_done,
        ?int $download_list_total,
        ?FilesPullCheckpoint $checkpoint,
        AuditLogger $audit,
        MachineEventEmitter $machine_events
    ) {
        $this->filesystem = $filesystem;
        $this->index_store = $index_store;
        $this->volatile_file_tracker = $volatile_file_tracker;
        $this->output = $output;
        $this->fs_root = $fs_root;
        $this->remote_index_file = $remote_index_file;
        $this->fs_root_nonempty_behavior = $fs_root_nonempty_behavior;
        $this->follow_symlinks = $follow_symlinks;
        $this->files_imported = $files_imported;
        $this->download_list_done = $download_list_done;
        $this->download_list_total = $download_list_total;
        $this->checkpoint = $checkpoint;
        $this->audit = $audit;
        $this->machine_events = $machine_events;
    }

    public function files_imported(): int
    {
        return $this->files_imported;
    }

    public function emit_skip_progress(string $path): void
    {
        $this->output->show_progress_line("[skip] " . PathDisplayFormatter::short_path($path));
        $this->output_progress([
            "type" => "skip",
            "path" => $path,
            "message" => "[skip] " . $path,
        ], true);
    }

    public function delete_local_file_path(string $path): void
    {
        if ($path === "") {
            return;
        }

        try {
            $local_path = $this->local_path_for_remote_path($path);
        } catch (RuntimeException $e) {
            $this->audit(
                "Security: refusing to delete invalid path '{$path}': " . $e->getMessage(),
                true,
            );
            return;
        }

        if (!file_exists($local_path) && !is_link($local_path)) {
            return;
        }

        if ($this->remove_path_without_following_symlinks($local_path)) {
            $this->audit("Deleted: {$path}", false);
            return;
        }

        $this->audit("Failed to delete: {$path}", true);
    }

    public function should_skip_for_preserve_local(string $path): ?string
    {
        if ($this->fs_root_nonempty_behavior !== 'preserve-local') {
            return null;
        }

        $local_path = $this->local_path_for_remote_path($path);

        if (file_exists($local_path) || is_link($local_path)) {
            return "PRESERVE-LOCAL skip file (exists): {$path}";
        }

        $dir = dirname($local_path);
        if (is_dir($dir) && !is_writable($dir)) {
            return "PRESERVE-LOCAL skip file (dir not writable): {$path}";
        }
        if ($this->path_traverses_symlink($dir)) {
            return "PRESERVE-LOCAL skip file (symlink in path): {$path}";
        }

        return null;
    }

    public function handle_metadata_chunk(
        array $chunk,
        StreamingContext $context
    ): void {
        $headers = $chunk["headers"];
        $filesystem_root = base64_decode($headers["x-filesystem-root"] ?? "", true);

        if ($filesystem_root) {
            $context->filesystem_root = $filesystem_root;
            $this->audit("Filesystem root: {$filesystem_root}", false);
        }
    }

    public function handle_file_chunk(
        array $chunk,
        StreamingContext $context
    ): void {
        $applier = new FileChunkApplier(
            $this->files_imported,
            $this,
        );

        try {
            $applier->handle($chunk, $context);
        } finally {
            $this->files_imported = $applier->files_imported();
        }
    }

    public function handle_directory_chunk(array $chunk): void
    {
        $applier = new DirectoryChunkApplier(
            $this->fs_root_nonempty_behavior === 'preserve-local',
            $this,
        );

        $applier->handle($chunk);
    }

    public function handle_symlink_chunk(array $chunk): void
    {
        $applier = new SymlinkChunkApplier(
            $this->fs_root_nonempty_behavior === 'preserve-local',
            $this,
        );

        $applier->handle($chunk);
    }

    public function handle_error_chunk(
        array $chunk,
        string $phase,
        StreamingContext $context
    ): void {
        $body = $chunk["body"] ?? "";
        $data = json_decode($body, true);
        if (!$data) {
            $this->audit(
                "REMOTE ERROR | phase={$phase} | raw (JSON decode failed): " .
                    substr($body, 0, 500),
                true,
            );
            return;
        }

        $error_type = $data["error_type"] ?? "unknown";
        $path = $data["path"] ?? "";
        $message = $data["message"] ?? "Error";

        $this->audit(
            "REMOTE ERROR | phase={$phase} | type={$error_type} | path={$path} | message={$message}",
            true,
        );

        $is_file_error = in_array(
            $error_type,
            ["file_changed", "file_missing", "file_open", "file_read"],
            true,
        );
        if ($path !== "" && $is_file_error) {
            $local_path = $this->fs_root . $path;
            if ($context->file_handle && $context->file_path === $local_path) {
                fclose($context->file_handle);
                $context->file_handle = null;
                $context->file_path = null;
                $context->file_ctime = null;
                $context->file_bytes_written = 0;
            }

            if (file_exists($local_path)) {
                @unlink($local_path);
            }
            $this->delete_index_entry($path);

            if ($error_type === "file_changed") {
                $this->volatile_file_tracker->record($path);
            }
        }

        $error_progress_message = "Remote error: {$error_type} " . ($path !== "" ? $path : "");
        $this->output->show_progress_line($error_progress_message);
        $this->output_progress(
            [
                "type" => "error",
                "phase" => $phase,
                "error_type" => $error_type,
                "path" => $path,
                "error_message" => $message,
                "message" => $error_progress_message,
            ],
            true,
        );
    }

    public function handle_progress(array $chunk, string $phase): void
    {
        $body = $chunk["body"] ?? "";
        $data = json_decode($body, true);
        if (!$data) {
            return;
        }

        $this->output_progress(array_merge(["phase" => $phase], $data));
    }

    public function on_metadata_chunk(array $chunk, StreamingContext $context): void
    {
        $this->handle_metadata_chunk($chunk, $context);
    }

    public function on_file_chunk(array $chunk, StreamingContext $context): void
    {
        $this->handle_file_chunk($chunk, $context);
    }

    public function on_directory_chunk(array $chunk): void
    {
        $this->handle_directory_chunk($chunk);
    }

    public function on_symlink_chunk(array $chunk): void
    {
        $this->handle_symlink_chunk($chunk);
    }

    public function on_missing_path(string $path): void
    {
        $this->audit("Missing on server: {$path}", true);
    }

    public function on_error_chunk(
        array $chunk,
        string $phase,
        StreamingContext $context
    ): void {
        $this->handle_error_chunk($chunk, $phase, $context);
    }

    public function on_progress_chunk(array $chunk, string $phase): void
    {
        $this->handle_progress($chunk, $phase);
    }

    /**
     * @param array<string, mixed> $progress
     */
    public function on_completion_progress(array $progress): void
    {
        $this->output_progress($progress, true);
    }

    public function on_index_progress(int $entries_counted): void
    {
        if ($entries_counted > 0) {
            $this->output->show_progress_line(
                'Scanning remote files - ' .
                number_format($entries_counted) . ' scanned',
            );
            return;
        }

        $this->output->show_progress_line('Scanning remote files');
    }

    public function show_file_fetch_progress(string $path, int $file_size): void
    {
        $files_done = ($this->download_list_done ?? 0) + $this->files_imported;
        $files_total = $this->download_list_total;
        $file_fraction = ($files_total !== null && $files_total > 0)
            ? $files_done / $files_total
            : null;
        $file_progress_message = $files_total !== null
            ? sprintf("Downloading — %s / %s entries", number_format($files_done), number_format($files_total))
            : sprintf("Downloading — %s entries", number_format($files_done));
        $this->output->show_progress_line($file_progress_message, $file_fraction);
        $progress_record = [
            "type" => "file_progress",
            "files_done" => $files_done,
            "entries_done" => $files_done,
            "path" => $path,
            "size" => $file_size,
            "message" => $file_progress_message,
        ];
        if ($this->download_list_total !== null) {
            $progress_record["files_total"] = $this->download_list_total;
            $progress_record["entries_total"] = $this->download_list_total;
        }
        $this->output_progress($progress_record);
    }

    public function map_absolute_symlink_target_for_local_mirror(
        string $path,
        string $local_path,
        string $target
    ): string {
        if (!str_starts_with($target, "/")) {
            return $target;
        }

        $root = $this->filesystem_root_path();
        $normalized_target = normalize_path($target);

        if (path_is_within_root($normalized_target, $root)) {
            return $target;
        }

        if (
            !$this->follow_symlinks ||
            !$this->remote_index_contains_path_prefix($normalized_target)
        ) {
            return $target;
        }

        $mapped_absolute = $root . $normalized_target;
        $mapped_relative = PathUtils::relative_path(
            dirname($local_path),
            $mapped_absolute
        );

        $this->audit(
            "SYMLINK TARGET REMAP | {$path}: {$target} -> {$mapped_relative}",
            false,
        );

        return $mapped_relative;
    }

    private function remote_index_contains_path_prefix(string $path): bool
    {
        if ($this->remote_index_prefix_matcher === null) {
            $this->remote_index_prefix_matcher = new IndexPathPrefixMatcher(
                $this->remote_index_file,
            );
        }

        return $this->remote_index_prefix_matcher->contains($path);
    }

    public function filesystem_root_path(): string
    {
        return $this->filesystem->filesystem_root_path();
    }

    public function local_path_for_remote_path(string $path): string
    {
        return $this->filesystem->local_path_for_remote_path($path);
    }

    public function remove_path_without_following_symlinks(string $local_path): bool
    {
        return $this->filesystem->remove_path_without_following_symlinks($local_path);
    }

    public function path_traverses_symlink(string $path): bool
    {
        return $this->filesystem->path_traverses_symlink($path);
    }

    public function ensure_directory_path(string $dir): void
    {
        $this->filesystem->ensure_directory_path($dir);
    }

    public function upsert_index_entry(
        string $path,
        int $ctime,
        int $size,
        string $type
    ): void {
        $this->index_store->upsert($path, $ctime, $size, $type);
    }

    private function delete_index_entry(string $path): void
    {
        $this->index_store->delete($path);
    }

    public function clear_volatile_file(string $path): void
    {
        $this->volatile_file_tracker->clear($path);
    }

    public function set_current_file(?string $path, ?int $bytes): void
    {
        if ($this->checkpoint === null) {
            return;
        }

        $this->checkpoint->current_file = $path;
        $this->checkpoint->current_file_bytes = $bytes;
    }

    public function audit(string $message, bool $to_console = true): void
    {
        $this->audit->record($message, $to_console);
    }

    /**
     * @param array<string, mixed> $progress
     */
    public function output_progress(array $progress, bool $force = false): void
    {
        $this->machine_events->emit($progress, $force);
    }
}
