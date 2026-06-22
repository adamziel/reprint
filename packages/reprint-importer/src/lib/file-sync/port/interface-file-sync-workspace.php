<?php

namespace Reprint\Importer\FileSync\Port;

interface FileSyncWorkspace
{
    public function fs_root(): string;

    public function index_file(): string;

    public function remote_index_file(): string;

    public function download_list_file(): string;

    public function skipped_download_list_file(): string;

    public function audit_log_file(): string;

    public function file_has_entries(string $file): bool;

    public function is_fs_root_empty(): bool;

    public function delete_file_if_exists(string $file): bool;

    public function count_lines(string $file): int;
}
