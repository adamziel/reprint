<?php

namespace Reprint\Importer\FileSync;

final class FetchListExecutor
{
    private ?int $download_list_total;
    private ?int $download_list_done;
    private int $files_imported;
    private int $max_request_bytes;

    /** @var callable */
    private $download_batch;

    /** @var callable */
    private $save_fetch_state;

    /** @var callable */
    private $audit;

    public function __construct(
        ?int $download_list_total,
        ?int $download_list_done,
        int $files_imported,
        int $max_request_bytes,
        callable $download_batch,
        callable $save_fetch_state,
        callable $audit
    ) {
        $this->download_list_total = $download_list_total;
        $this->download_list_done = $download_list_done;
        $this->files_imported = $files_imported;
        $this->max_request_bytes = $max_request_bytes;
        $this->download_batch = $download_batch;
        $this->save_fetch_state = $save_fetch_state;
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
        FetchCheckpoint $fetch_checkpoint
    ): bool {
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
            $this->save_fetch_checkpoint($state_key, $fetch_checkpoint);
        }

        $complete = $this->download_batch($batch_file, $cursor, $state_key);
        if (!$complete) {
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
        $this->save_fetch_checkpoint($state_key, $fetch_checkpoint);

        return $next_offset >= filesize($list_file);
    }

    private function download_batch(string $batch_file, $cursor, string $state_key): bool
    {
        return (bool) ($this->download_batch)($batch_file, $cursor, $state_key);
    }

    private function save_fetch_checkpoint(
        string $state_key,
        FetchCheckpoint $fetch_checkpoint
    ): void
    {
        ($this->save_fetch_state)($state_key, $fetch_checkpoint);
    }

    private function audit(string $message): void
    {
        ($this->audit)($message);
    }
}
