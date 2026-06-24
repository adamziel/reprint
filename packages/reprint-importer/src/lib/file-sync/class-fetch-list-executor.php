<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\FileSync\Port\FetchBatchDownloader;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\Observability\AuditLogger;

final class FetchListExecutor
{
    private ?int $download_list_total;
    private ?int $download_list_done;
    private int $files_imported;
    private int $max_request_bytes;
    private FetchBatchDownloader $batch_downloader;
    private FilesPullCheckpointStore $checkpoints;
    private AuditLogger $audit;

    public function __construct(
        ?int $download_list_total,
        ?int $download_list_done,
        int $files_imported,
        int $max_request_bytes,
        FetchBatchDownloader $batch_downloader,
        FilesPullCheckpointStore $checkpoints,
        AuditLogger $audit
    ) {
        $this->download_list_total = $download_list_total;
        $this->download_list_done = $download_list_done;
        $this->files_imported = $files_imported;
        $this->max_request_bytes = $max_request_bytes;
        $this->batch_downloader = $batch_downloader;
        $this->checkpoints = $checkpoints;
        $this->audit = $audit;
    }

    public function download_list_total(): ?int
    {
        return $this->download_list_total;
    }

    public function download_list_done(): ?int
    {
        return $this->download_list_done;
    }

    public function files_imported(): int
    {
        return $this->files_imported;
    }

    public function run(
        string $list_file,
        string $state_key,
        FilesPullCheckpoint $checkpoint
    ): bool {
        $fetch_checkpoint = $checkpoint->fetch_checkpoint($state_key);
        if (!file_exists($list_file)) {
            return true;
        }

        if (filesize($list_file) === 0) {
            return true;
        }

        if ($this->download_list_total === null) {
            $offset = $fetch_checkpoint->offset;
            $this->download_list_total = DownloadList::count_lines($list_file);
            $this->download_list_done = $offset > 0
                ? DownloadList::count_lines($list_file, $offset)
                : 0;
        }

        $batch_file = $fetch_checkpoint->batch_file;
        $batch_offset = $fetch_checkpoint->offset;
        $next_offset = $fetch_checkpoint->next_offset;
        $cursor = $fetch_checkpoint->cursor;
        $batch_entries = $fetch_checkpoint->batch_entries;
        $this->files_imported = max(
            $this->files_imported,
            $fetch_checkpoint->batch_entries_done,
        );

        if ($batch_file === null || !file_exists($batch_file)) {
            $batch = DownloadList::prepare_batch(
                $list_file,
                $batch_offset,
                $this->max_request_bytes,
            );
            if ($batch === null) {
                return true;
            }
            $batch_file = $batch["file"];
            $batch_offset = $batch["offset"];
            $next_offset = $batch["next_offset"];
            $batch_entries = $batch["entries"];
            $cursor = null;
            $fetch_checkpoint->offset = $batch_offset;
            $fetch_checkpoint->next_offset = $next_offset;
            $fetch_checkpoint->batch_file = $batch_file;
            $fetch_checkpoint->batch_entries = $batch_entries;
            $fetch_checkpoint->cursor = null;
            $fetch_checkpoint->batch_entries_done = 0;
            $this->files_imported = 0;
            $this->save_fetch_checkpoint($checkpoint, $state_key, $fetch_checkpoint);
        }

        $complete = $this->download_batch($batch_file, $cursor, $state_key);
        if (!$complete) {
            $this->record_partial_batch_progress(
                $checkpoint,
                $state_key,
                $fetch_checkpoint,
                $batch_file,
                $batch_entries,
            );
            return false;
        }

        if (file_exists($batch_file)) {
            @unlink($batch_file);
            $this->audit("FILE DELETE | {$batch_file} | fetch batch complete");
        }

        if ($this->download_list_done !== null) {
            $this->download_list_done += $batch_entries;
        }
        $this->files_imported = 0;

        $fetch_checkpoint->offset = $next_offset;
        $fetch_checkpoint->next_offset = $next_offset;
        $fetch_checkpoint->batch_file = null;
        $fetch_checkpoint->batch_entries = 0;
        $fetch_checkpoint->cursor = null;
        $fetch_checkpoint->batch_entries_done = 0;
        $this->save_fetch_checkpoint($checkpoint, $state_key, $fetch_checkpoint);

        return $next_offset >= filesize($list_file);
    }

    private function record_partial_batch_progress(
        FilesPullCheckpoint $checkpoint,
        string $state_key,
        FetchCheckpoint $fetch_checkpoint,
        string $batch_file,
        int $batch_entries
    ): void {
        $entries_done = DownloadList::count_batch_entries_through_cursor(
            $batch_file,
            $fetch_checkpoint->cursor,
        );
        $entries_done = min($entries_done, $batch_entries);
        if ($entries_done <= $fetch_checkpoint->batch_entries_done) {
            return;
        }

        $fetch_checkpoint->batch_entries_done = $entries_done;
        $this->files_imported = max($this->files_imported, $entries_done);
        $this->save_fetch_checkpoint($checkpoint, $state_key, $fetch_checkpoint);
    }

    private function download_batch(string $batch_file, ?string $cursor, string $state_key): bool
    {
        return $this->batch_downloader->download_batch($batch_file, $cursor, $state_key);
    }

    private function save_fetch_checkpoint(
        FilesPullCheckpoint $checkpoint,
        string $state_key,
        FetchCheckpoint $fetch_checkpoint
    ): void
    {
        $checkpoint->{$state_key} = $fetch_checkpoint;
        $this->checkpoints->save($checkpoint);
    }

    private function audit(string $message): void
    {
        $this->audit->record($message);
    }
}
