<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\FileSync\Port\LocalFileChangePlanner;
use Reprint\Importer\FileSync\Port\ProgressTicker;
use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\Index\IndexLineParser;
use Reprint\Importer\Index\IndexStore;
use Reprint\Importer\Observability\AuditLogger;
use RuntimeException;

final class FetchListBuilder
{
    private IndexStore $index_store;
    private LocalFileChangePlanner $local_changes;
    private FilesPullCheckpointStore $checkpoints;
    private ShutdownToken $shutdown;
    private ProgressTicker $ticker;
    private AuditLogger $audit;

    public function __construct(
        IndexStore $index_store,
        LocalFileChangePlanner $local_changes,
        FilesPullCheckpointStore $checkpoints,
        ShutdownToken $shutdown,
        ProgressTicker $ticker,
        AuditLogger $audit
    ) {
        $this->index_store = $index_store;
        $this->local_changes = $local_changes;
        $this->checkpoints = $checkpoints;
        $this->shutdown = $shutdown;
        $this->ticker = $ticker;
        $this->audit = $audit;
    }

    public function build(
        FilesPullCheckpoint $checkpoint,
        string $remote_index_file,
        string $local_index_file,
        string $download_list_file,
        string $skipped_download_list_file,
        string $filter,
        ?string $uploads_basedir
    ): bool {
        if (!file_exists($remote_index_file)) {
            throw new RuntimeException("Remote index file not found");
        }

        $diff = $checkpoint->diff_state();
        $remote_offset = (int) ($diff["remote_offset"] ?? 0);
        $local_after = $diff["local_after"] ?? null;
        $download_mode = $remote_offset > 0 ? "a" : "w";

        $this->audit_download_list_open($download_list_file, $download_mode);
        $download_handle = fopen($download_list_file, $download_mode);
        if (!$download_handle) {
            throw new RuntimeException("Failed to open download list file");
        }

        $skipped_handle = null;
        if ($filter === "essential-files") {
            $this->audit_skipped_list_open($skipped_download_list_file, $download_mode);
            $skipped_handle = fopen($skipped_download_list_file, $download_mode);
            if (!$skipped_handle) {
                fclose($download_handle);
                throw new RuntimeException("Failed to open skipped download list file");
            }
            $this->audit(
                "FILTER | essential-files | uploads_basedir=" . ($uploads_basedir ?? "(fallback: wp-content/uploads/)"),
            );
        }

        $remote_handle = fopen($remote_index_file, "r");
        if (!$remote_handle) {
            fclose($download_handle);
            if ($skipped_handle !== null) {
                fclose($skipped_handle);
            }
            throw new RuntimeException("Failed to open remote index file");
        }
        if ($remote_offset > 0) {
            fseek($remote_handle, $remote_offset);
        }

        $local_handle = file_exists($local_index_file)
            ? fopen($local_index_file, "r")
            : null;
        $local = $this->index_store->read_index_line($local_handle);
        if ($local_after) {
            while (
                $local !== null &&
                strcmp($local["path"], $local_after) <= 0
            ) {
                $local = $this->index_store->read_index_line($local_handle);
            }
        }
        $this->index_store->begin_updates();
        $processed = 0;

        while (($line = fgets($remote_handle)) !== false) {
            if ($this->should_stop()) {
                break;
            }

            if (function_exists("pcntl_signal_dispatch")) {
                pcntl_signal_dispatch();
            }

            $position = ftell($remote_handle);
            if ($position !== false) {
                $remote_offset = (int) $position;
            }
            $remote = IndexLineParser::parse($line);
            if (!$remote) {
                continue;
            }

            while (
                $local !== null &&
                strcmp($local["path"], $remote["path"]) < 0
            ) {
                $this->delete_local_path($local["path"]);
                $this->index_store->delete($local["path"]);
                $local_after = $local["path"];
                $local = $this->index_store->read_index_line($local_handle);
            }

            if ($local !== null && $local["path"] === $remote["path"]) {
                if (
                    $local["ctime"] !== $remote["ctime"] ||
                    $local["size"] !== $remote["size"] ||
                    $local["type"] !== $remote["type"]
                ) {
                    $target_handle = $this->target_download_handle(
                        $download_handle,
                        $skipped_handle,
                        $remote["path"],
                        $uploads_basedir,
                    );
                    $this->append_download_list($remote["path"], $target_handle);
                }
                $local_after = $local["path"];
                $local = $this->index_store->read_index_line($local_handle);
            } elseif (
                $local === null ||
                strcmp($local["path"], $remote["path"]) > 0
            ) {
                $skip_reason = $this->should_skip($remote["path"]);
                if ($skip_reason) {
                    $this->audit($skip_reason, true);
                    $this->emit_skip($remote["path"]);
                } else {
                    $target_handle = $this->target_download_handle(
                        $download_handle,
                        $skipped_handle,
                        $remote["path"],
                        $uploads_basedir,
                    );
                    $this->append_download_list($remote["path"], $target_handle);
                }
            }

            $processed++;
            if ($processed % 200 === 0) {
                $this->persist_diff($checkpoint, $remote_offset, $local_after);
                $this->tick();
            }
        }

        while ($local !== null) {
            $this->delete_local_path($local["path"]);
            $this->index_store->delete($local["path"]);
            $local_after = $local["path"];
            $local = $this->index_store->read_index_line($local_handle);
        }

        if ($local_handle) {
            fclose($local_handle);
        }
        fclose($remote_handle);
        fclose($download_handle);
        if ($skipped_handle !== null) {
            fclose($skipped_handle);
        }

        $this->persist_diff($checkpoint, $remote_offset, $local_after);
        $this->index_store->finalize_updates();

        return !$this->should_stop();
    }

    private function target_download_handle(
        $download_handle,
        $skipped_handle,
        string $path,
        ?string $uploads_basedir
    ) {
        if (
            $skipped_handle !== null &&
            $this->is_uploads_path($path, $uploads_basedir)
        ) {
            return $skipped_handle;
        }

        return $download_handle;
    }

    private function is_uploads_path(string $path, ?string $uploads_basedir): bool
    {
        if ($uploads_basedir !== null) {
            return strpos($path, $uploads_basedir) !== false;
        }

        return strpos($path, "wp-content/uploads/") !== false;
    }

    private function append_download_list(string $path, $handle): void
    {
        DownloadList::append_path($handle, $path);
        $this->audit("Added to the download list: {$path}", false);
    }

    private function audit_download_list_open(string $path, string $mode): void
    {
        if ($mode === "w") {
            $this->audit("FILE CREATE | {$path} | building download list");
            return;
        }

        $this->audit("FILE APPEND | {$path} | resuming download list build");
    }

    private function audit_skipped_list_open(string $path, string $mode): void
    {
        if ($mode === "w") {
            $this->audit("FILE CREATE | {$path} | building skipped download list (uploads)");
            return;
        }

        $this->audit("FILE APPEND | {$path} | resuming skipped download list build");
    }

    private function persist_diff(
        FilesPullCheckpoint $checkpoint,
        int $remote_offset,
        ?string $local_after
    ): void
    {
        $checkpoint->set_diff_state([
            "remote_offset" => $remote_offset,
            "local_after" => $local_after,
        ]);
        $this->checkpoints->save($checkpoint);
    }

    private function delete_local_path(string $path): void
    {
        $this->local_changes->delete_local_file_path($path);
    }

    private function should_skip(string $path): ?string
    {
        return $this->local_changes->should_skip_for_preserve_local($path);
    }

    private function emit_skip(string $path): void
    {
        $this->local_changes->emit_skip_progress($path);
    }

    private function should_stop(): bool
    {
        return $this->shutdown->is_shutdown_requested();
    }

    private function tick(): void
    {
        $this->ticker->tick();
    }

    private function audit(string $message, bool $to_console = true): void
    {
        $this->audit->record($message, $to_console);
    }
}
