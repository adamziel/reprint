<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\FileSyncTransferProgress;
use Reprint\Importer\FileSync\Port\FileIndexGateway;
use Reprint\Importer\FileSync\Port\FileSyncWorkspace;
use Reprint\Importer\Index\IndexFileSorter;
use Reprint\Importer\Index\IndexStore;

final class IndexStoreFileIndexGateway implements FileIndexGateway
{
    private IndexStore $index_store;
    private IndexFileSorter $sorter;
    private FileSyncWorkspace $workspace;
    private FileSyncTransferProgress $progress;

    public function __construct(
        IndexStore $index_store,
        IndexFileSorter $sorter,
        FileSyncWorkspace $workspace,
        FileSyncTransferProgress $progress
    ) {
        $this->index_store = $index_store;
        $this->sorter = $sorter;
        $this->workspace = $workspace;
        $this->progress = $progress;
    }

    public function recover_updates(): void
    {
        $this->index_store->recover();
    }

    public function local_index_has_entries(): bool
    {
        return $this->workspace->file_has_entries($this->workspace->index_file());
    }

    public function count_local_index(): int
    {
        return $this->index_store->count();
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
        $this->sorter->sort($this->workspace->remote_index_file());
    }

    public function index_entries_counted(): int
    {
        return $this->progress->index_entries_counted();
    }

    public function reset_transfer_progress(): void
    {
        $this->progress->reset_transfer_counts();
    }

    public function finalize_updates(): void
    {
        $this->index_store->finalize_updates();
    }
}
