<?php

namespace Reprint\Importer\FileSync;

final class FileSyncTransferProgress
{
    private int $files_imported = 0;
    private ?int $download_list_done = null;
    private ?int $download_list_total = null;
    private int $index_entries_counted = 0;

    public function files_imported(): int
    {
        return $this->files_imported;
    }

    public function download_list_done(): ?int
    {
        return $this->download_list_done;
    }

    public function download_list_total(): ?int
    {
        return $this->download_list_total;
    }

    public function index_entries_counted(): int
    {
        return $this->index_entries_counted;
    }

    public function set_transfer_counts(
        int $files_imported,
        ?int $download_list_done,
        ?int $download_list_total
    ): void {
        $this->files_imported = $files_imported;
        $this->download_list_done = $download_list_done;
        $this->download_list_total = $download_list_total;
    }

    public function reset_transfer_counts(): void
    {
        $this->set_transfer_counts(0, null, null);
    }

    public function set_files_imported(int $files_imported): void
    {
        $this->files_imported = $files_imported;
    }

    public function set_index_entries_counted(int $index_entries_counted): void
    {
        $this->index_entries_counted = $index_entries_counted;
    }
}
