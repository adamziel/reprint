<?php

namespace Reprint\Importer\FileSync\Port;

interface LocalFileApplyContext
{
    public function local_path_for_remote_path(string $path): string;

    public function remove_path_without_following_symlinks(string $local_path): bool;

    public function ensure_directory_path(string $dir): void;

    public function path_traverses_symlink(string $path): bool;

    public function filesystem_root_path(): string;

    public function map_absolute_symlink_target_for_local_mirror(
        string $path,
        string $local_path,
        string $target
    ): string;

    public function audit(string $message, bool $to_console = true): void;

    public function show_file_fetch_progress(string $path, int $file_size): void;

    public function emit_skip_progress(string $path): void;

    public function upsert_index_entry(string $path, int $ctime, int $size, string $type): void;

    public function clear_volatile_file(string $path): void;

    public function set_current_file(?string $path, ?int $bytes): void;

    /**
     * @param array<string, mixed> $progress
     */
    public function output_progress(array $progress, bool $force = false): void;
}
