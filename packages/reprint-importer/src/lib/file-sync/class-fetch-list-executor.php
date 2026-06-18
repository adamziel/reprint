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
        array $fetch_state,
        array $default_fetch_state
    ): bool {
        if (!file_exists($list_file)) {
            return true;
        }

        if (filesize($list_file) === 0) {
            return true;
        }

        if ($this->download_list_total === null) {
            $offset = (int) ($fetch_state["offset"] ?? 0);
            $this->download_list_total = DownloadList::count_lines($list_file);
            $this->download_list_done = $offset > 0
                ? DownloadList::count_lines($list_file, $offset)
                : 0;
        }

        $fetch_state = array_merge($default_fetch_state, $fetch_state);
        $batch_file = $fetch_state["batch_file"] ?? null;
        $batch_offset = (int) ($fetch_state["offset"] ?? 0);
        $next_offset = (int) ($fetch_state["next_offset"] ?? 0);
        $cursor = $fetch_state["cursor"] ?? null;
        $batch_entries = (int) ($fetch_state["batch_entries"] ?? 0);

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
            $this->save_fetch_state($state_key, [
                "offset" => $batch_offset,
                "next_offset" => $next_offset,
                "batch_file" => $batch_file,
                "batch_entries" => $batch_entries,
                "cursor" => null,
            ]);
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

        $this->save_fetch_state($state_key, [
            "offset" => $next_offset,
            "next_offset" => $next_offset,
            "batch_file" => null,
            "cursor" => null,
        ]);

        return $next_offset >= filesize($list_file);
    }

    private function download_batch(string $batch_file, $cursor, string $state_key): bool
    {
        return (bool) ($this->download_batch)($batch_file, $cursor, $state_key);
    }

    private function save_fetch_state(string $state_key, array $fetch_state): void
    {
        ($this->save_fetch_state)($state_key, $fetch_state);
    }

    private function audit(string $message): void
    {
        ($this->audit)($message);
    }
}
