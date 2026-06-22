<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\Port\FileIndexGateway;
use Reprint\Importer\FileSync\Port\FileSyncWorkspace;
use Reprint\Importer\ImportClient;

final class ImportClientFileIndexGateway implements FileIndexGateway
{
    private ImportClient $client;
    private FileSyncWorkspace $workspace;

    public function __construct(ImportClient $client, FileSyncWorkspace $workspace)
    {
        $this->client = $client;
        $this->workspace = $workspace;
    }

    public function recover_updates(): void
    {
        $this->client->recover_index_updates();
    }

    public function local_index_has_entries(): bool
    {
        return $this->workspace->file_has_entries($this->workspace->index_file());
    }

    public function count_local_index(): int
    {
        return $this->client->index_count();
    }

    public function count_remote_index(): int
    {
        $remote_index = $this->workspace->remote_index_file();
        if (!file_exists($remote_index)) {
            return 0;
        }

        return $this->workspace->count_lines($remote_index);
    }

    public function sort_remote_index(): void
    {
        $this->client->sort_index_file($this->workspace->remote_index_file());
    }

    public function index_entries_counted(): int
    {
        return $this->client->index_entries_counted();
    }

    public function reset_transfer_progress(): void
    {
        $this->client->set_file_sync_progress(0, null, null);
    }

    public function finalize_updates(): void
    {
        $this->client->finalize_index_updates();
    }
}
