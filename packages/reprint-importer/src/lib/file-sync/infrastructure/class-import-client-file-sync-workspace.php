<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\DownloadList;
use Reprint\Importer\FileSync\Port\FileSyncWorkspace;
use Reprint\Importer\ImportClient;

final class ImportClientFileSyncWorkspace implements FileSyncWorkspace
{
    private ImportClient $client;

    public function __construct(ImportClient $client)
    {
        $this->client = $client;
    }

    public function fs_root(): string
    {
        return $this->client->fs_root();
    }

    public function index_file(): string
    {
        return $this->client->paths()->index_file();
    }

    public function remote_index_file(): string
    {
        return $this->client->paths()->remote_index_file();
    }

    public function download_list_file(): string
    {
        return $this->client->paths()->download_list_file();
    }

    public function skipped_download_list_file(): string
    {
        return $this->client->paths()->skipped_download_list_file();
    }

    public function audit_log_file(): string
    {
        return $this->client->paths()->audit_log();
    }

    public function file_has_entries(string $file): bool
    {
        return file_exists($file) && filesize($file) > 0;
    }

    public function is_fs_root_empty(): bool
    {
        $root = $this->fs_root();
        if (!is_dir($root)) {
            return true;
        }

        $entries = scandir($root);
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
