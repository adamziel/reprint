<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\DownloadList;
use Reprint\Importer\FileSync\Port\FileSyncWorkspace;
use Reprint\Importer\Session\ImportPaths;

final class ImportPathFileSyncWorkspace implements FileSyncWorkspace
{
    private ImportPaths $paths;
    private string $fs_root;

    public function __construct(ImportPaths $paths, string $fs_root)
    {
        $this->paths = $paths;
        $this->fs_root = $fs_root;
    }

    public function fs_root(): string
    {
        return $this->fs_root;
    }

    public function index_file(): string
    {
        return $this->paths->index_file();
    }

    public function remote_index_file(): string
    {
        return $this->paths->remote_index_file();
    }

    public function download_list_file(): string
    {
        return $this->paths->download_list_file();
    }

    public function skipped_download_list_file(): string
    {
        return $this->paths->skipped_download_list_file();
    }

    public function audit_log_file(): string
    {
        return $this->paths->audit_log();
    }

    public function file_has_entries(string $file): bool
    {
        return file_exists($file) && filesize($file) > 0;
    }

    public function is_fs_root_empty(): bool
    {
        if (!is_dir($this->fs_root)) {
            return true;
        }

        $entries = scandir($this->fs_root);
        if ($entries === false) {
            return true;
        }

        return count(array_diff($entries, ['.', '..'])) === 0;
    }

    public function delete_file_if_exists(string $file): bool
    {
        if (!file_exists($file)) {
            return false;
        }

        return @unlink($file);
    }

    public function count_lines(string $file): int
    {
        return DownloadList::count_lines($file);
    }
}
