<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\Output\ImportOutput;
use RuntimeException;

final class FilesSyncWorkflow
{
    private FilesSyncRuntime $runtime;
    private ImportOutput $output;

    public function __construct(FilesSyncRuntime $runtime, ImportOutput $output)
    {
        $this->runtime = $runtime;
        $this->output = $output;
    }

    public function run_files_sync(): void
    {
        $checkpoint = $this->runtime->files_pull_checkpoint();
        $state_command = $this->runtime->current_command();
        $current_status =
            $state_command === "files-pull"
                ? $checkpoint->status ?? $this->runtime->current_run_status()
                : null;
        $has_progress =
            $state_command === "files-pull" &&
            $current_status !== null &&
            $current_status !== "complete";

        $this->runtime->recover_index_updates();

        if ($current_status === "complete") {
            $this->handle_completed_files_sync($checkpoint);
            return;
        }

        if ($this->runtime->current_filter() === "skipped-earlier") {
            throw new RuntimeException(
                "--filter=skipped-earlier was requested but there is no completed sync with skipped files. " .
                    "Run files-pull with --filter=essential-files first.",
            );
        }

        $is_delta = $this->has_local_file_index();

        if ($has_progress) {
            $this->report_files_sync_resume($checkpoint);
        } else {
            $this->start_files_sync($checkpoint, $is_delta);
        }

        $checkpoint->status = "in_progress";
        $this->runtime->save_files_pull_checkpoint($checkpoint);
        $this->runtime->record_command_status("files-pull", "in_progress");

        $this->run_files_sync_pipeline($checkpoint);

        if ($checkpoint->status === "partial") {
            $this->runtime->record_command_status("files-pull", "partial");
            return;
        }

        $this->complete_files_sync($checkpoint, $is_delta);
    }

    public function run_files_index(): void
    {
        $checkpoint = $this->runtime->files_pull_checkpoint();
        $state_command = $this->runtime->current_command();
        $current_status =
            $state_command === "files-index"
                ? $this->runtime->current_run_status()
                : null;

        if ($current_status === "complete") {
            throw new RuntimeException(
                "files-index already completed. Use --abort flag to start over.",
            );
        }

        if ($current_status === null) {
            $checkpoint->reset_for_files_pull();
            $this->runtime->save_files_pull_checkpoint($checkpoint);
            $this->runtime->record_command_status("files-index", "in_progress");
            $this->runtime->audit_log("START files-index", true);
            $this->output->show_lifecycle_line("Starting files-index\n");
            $this->runtime->output_progress([
                "type" => "lifecycle",
                "event" => "starting",
                "command" => "files-index",
                "message" => "Starting files-index",
            ], true);
        } else {
            $cursor = $checkpoint->index_cursor;
            $this->runtime->audit_log(
                sprintf(
                    "RESUME files-index | cursor=%s",
                    $cursor ? substr($cursor, 0, 20) . "..." : "none",
                ),
                true,
            );
            $this->output->show_lifecycle_line("Resuming files-index\n");
            $this->runtime->output_progress([
                "type" => "lifecycle",
                "event" => "resuming",
                "command" => "files-index",
                "message" => "Resuming files-index",
            ], true);
        }

        $this->runtime->record_command_status("files-index", "in_progress");

        $attempts = 0;
        $last_cursor = $checkpoint->index_cursor;
        while (true) {
            $complete = $this->runtime->download_remote_index($checkpoint);
            if ($complete) {
                break;
            }

            if ($this->runtime->shutdown_requested()) {
                $checkpoint->status = "partial";
                $this->runtime->save_files_pull_checkpoint($checkpoint);
                $this->runtime->record_command_status("files-index", "partial");
                return;
            }

            $current_cursor = $checkpoint->index_cursor;
            if ($current_cursor === $last_cursor) {
                throw new RuntimeException(
                    "files-index made no progress (cursor unchanged)",
                );
            }
            $last_cursor = $current_cursor;

            $attempts++;
            if ($attempts > 100000) {
                throw new RuntimeException(
                    "files-index exceeded maximum attempts",
                );
            }
        }

        if ($this->runtime->follow_symlinks()) {
            $this->runtime->discover_symlink_targets($checkpoint);
        }

        $this->runtime->sort_index_file($this->runtime->remote_index_file());
        $checkpoint->status = "complete";
        $checkpoint->stage = null;
        $this->runtime->save_files_pull_checkpoint($checkpoint);
        $this->runtime->record_command_status("files-index", "complete");

        $count = 0;
        if (file_exists($this->runtime->remote_index_file())) {
            $h = fopen($this->runtime->remote_index_file(), "r");
            if ($h) {
                while (fgets($h) !== false) {
                    $count++;
                }
                fclose($h);
            }
        }
        $this->runtime->audit_log(
            sprintf("files-index complete: %d entries indexed", $count),
            true,
        );

        $this->output->show_lifecycle_line("files-index complete: {$count} entries indexed\n");
        $this->output->show_lifecycle_line("Remote index: {$this->runtime->remote_index_file()}\n");
        $this->output->show_lifecycle_line("Audit log: {$this->runtime->audit_log_file()}\n");
        $this->runtime->output_progress([
            "type" => "lifecycle",
            "event" => "complete",
            "command" => "files-index",
            "entries_indexed" => $count,
            "remote_index" => $this->runtime->remote_index_file(),
            "audit_log" => $this->runtime->audit_log_file(),
            "message" => "files-index complete: {$count} entries indexed",
        ], true);
    }

    private function handle_completed_files_sync(FilesPullCheckpoint $checkpoint): void
    {
        $has_skipped = $this->has_skipped_download_list();

        if ($this->runtime->current_filter() === "skipped-earlier") {
            $this->start_skipped_files_fetch($checkpoint, $has_skipped);
            return;
        }

        $this->report_files_sync_already_complete($has_skipped);
    }

    private function has_skipped_download_list(): bool
    {
        return $this->file_has_entries($this->runtime->skipped_download_list_file());
    }

    private function start_skipped_files_fetch(
        FilesPullCheckpoint $checkpoint,
        bool $has_skipped
    ): void
    {
        if (!$has_skipped) {
            throw new RuntimeException(
                "--filter=skipped-earlier was requested but there is no skipped file list. " .
                    "Run files-pull with --filter=essential-files first.",
            );
        }

        $this->runtime->audit_log(
            "FETCH SKIPPED | files-pull was complete — downloading previously skipped files",
            true,
        );
        $this->output->show_lifecycle_line("Downloading previously skipped files\n");
        $this->runtime->output_progress([
            "type" => "lifecycle",
            "event" => "starting",
            "command" => "files-pull",
            "stage" => "fetch-skipped",
            "message" => "Downloading previously skipped files",
        ], true);
        $checkpoint->status = "in_progress";
        $checkpoint->stage = "fetch-skipped";
        $this->runtime->save_files_pull_checkpoint($checkpoint);
        $this->runtime->record_command_status("files-pull", "in_progress");

        $this->run_files_sync_pipeline($checkpoint);
        if ($checkpoint->status === "partial") {
            $this->runtime->record_command_status("files-pull", "partial");
            return;
        }

        $checkpoint->status = "complete";
        $checkpoint->stage = null;
        $this->runtime->save_files_pull_checkpoint($checkpoint);
        $this->runtime->record_command_status("files-pull", "complete");
    }

    private function report_files_sync_already_complete(bool $has_skipped): void
    {
        $index_size = $this->runtime->index_count();
        $this->output->clear_progress_line();

        $skipped_note = $has_skipped
            ? " (some files were skipped — re-run with --filter=skipped-earlier to download them)"
            : "";
        $this->runtime->audit_log(
            sprintf("files-pull already complete: %d files indexed%s", $index_size, $skipped_note),
            true,
        );

        $this->output->show_lifecycle_line("files-pull already complete: {$index_size} files indexed\n");
        if ($has_skipped) {
            $this->output->show_lifecycle_line("Some files were skipped. Re-run with --filter=skipped-earlier to download them.\n");
        } else {
            $this->output->show_lifecycle_line("To re-sync, run with --abort first to clear state.\n");
        }
        $this->runtime->output_progress([
            "type" => "lifecycle",
            "event" => "already_complete",
            "command" => "files-pull",
            "files_indexed" => $index_size,
            "has_skipped" => $has_skipped,
            "message" => "files-pull already complete: {$index_size} files indexed",
        ], true);
    }

    private function has_local_file_index(): bool
    {
        return $this->file_has_entries($this->runtime->index_file());
    }

    private function is_fs_root_empty(): bool
    {
        return !is_dir($this->runtime->fs_root()) || count(array_diff(
            scandir($this->runtime->fs_root()) ?: [],
            [".", ".."]
        )) === 0;
    }

    private function report_files_sync_resume(FilesPullCheckpoint $checkpoint): void
    {
        $index_size = $this->runtime->index_count();
        $stage = $checkpoint->stage ?? "index";

        $this->runtime->audit_log(
            sprintf(
                "RESUME files-pull | stage=%s | indexed_files=%d",
                $stage,
                $index_size,
            ),
            true,
        );

        $this->output->show_lifecycle_line("Resuming files-pull\n");
        $this->output->show_lifecycle_line("  Stage: {$stage}\n");
        $this->output->show_lifecycle_line("  Already indexed: {$index_size} files\n");
        $this->runtime->output_progress([
            "type" => "lifecycle",
            "event" => "resuming",
            "command" => "files-pull",
            "stage" => $stage,
            "index_size" => $index_size,
            "message" => "Resuming files-pull (stage: {$stage}, indexed: {$index_size} files)",
        ], true);
    }

    private function start_files_sync(FilesPullCheckpoint $checkpoint, bool $is_delta): void
    {
        $is_empty = $this->is_fs_root_empty();
        if (!$is_empty && !$is_delta && $this->runtime->fs_root_nonempty_behavior() === 'error') {
            throw new RuntimeException(
                "Target directory is not empty and no cursor found. " .
                    "Either clear the target directory, use --abort flag, or use --on-fs-root-nonempty=preserve-local to sync while preserving the existing content.",
            );
        }

        $checkpoint->reset_for_files_pull();
        $this->runtime->save_files_pull_checkpoint($checkpoint);
        $this->runtime->record_command_status("files-pull", "in_progress");

        if ($is_delta) {
            $this->report_files_sync_delta_start();
        } else {
            $this->report_files_sync_initial_start($is_empty);
        }
    }

    private function report_files_sync_delta_start(): void
    {
        $this->runtime->set_file_sync_progress(0, null, null);
        $index_size = $this->runtime->index_count();

        $this->runtime->audit_log(
            "START files-pull (delta) | index_files={$index_size}",
            true,
        );

        $this->output->show_lifecycle_line("Starting files-pull (delta)\n");
        $this->output->show_lifecycle_line("  Index contains: {$index_size} files\n");
        $this->output->show_lifecycle_line("  Stage: index\n");
        $this->runtime->output_progress([
            "type" => "lifecycle",
            "event" => "starting",
            "command" => "files-pull",
            "delta" => true,
            "index_size" => $index_size,
            "message" => "Starting files-pull (delta, {$index_size} files indexed)",
        ], true);
    }

    private function report_files_sync_initial_start(bool $is_empty): void
    {
        $this->runtime->audit_log(
            "START files-pull ({$this->runtime->fs_root_nonempty_behavior()} mode, ".($is_empty ? 'empty directory' : 'non-empty directory').")",
            true,
        );

        $this->output->show_lifecycle_line("Starting files-pull\n");
        $this->runtime->output_progress([
            "type" => "lifecycle",
            "event" => "starting",
            "command" => "files-pull",
            "message" => "Starting files-pull",
        ], true);
    }

    private function complete_files_sync(
        FilesPullCheckpoint $checkpoint,
        bool $is_delta
    ): void
    {
        $checkpoint->status = "complete";
        $checkpoint->stage = null;
        $this->runtime->save_files_pull_checkpoint($checkpoint);
        $this->runtime->record_command_status("files-pull", "complete");

        $this->output->clear_progress_line();
        $index_size = $this->runtime->index_count();
        $label = $is_delta ? "files-pull (delta)" : "files-pull";

        $this->runtime->audit_log(
            sprintf("%s complete: %d files indexed", $label, $index_size),
            true,
        );

        $this->output->show_lifecycle_line("{$label} complete: {$index_size} files indexed\n");
        $this->output->show_lifecycle_line("Audit log: {$this->runtime->audit_log_file()}\n");
        $this->runtime->output_progress([
            "type" => "lifecycle",
            "event" => "complete",
            "command" => "files-pull",
            "delta" => $is_delta,
            "files_indexed" => $index_size,
            "audit_log" => $this->runtime->audit_log_file(),
            "message" => "{$label} complete: {$index_size} files indexed",
        ], true);

        $this->runtime->report_volatile_files();
    }

    private function run_files_sync_pipeline(FilesPullCheckpoint $checkpoint): void
    {
        $stage = $checkpoint->stage ?? "index";

        if ($stage === "index") {
            if (!$this->runtime->download_remote_index($checkpoint)) {
                $this->mark_files_sync_partial($checkpoint);
                return;
            }

            if ($this->runtime->follow_symlinks()) {
                $this->runtime->discover_symlink_targets($checkpoint);
                if ($this->runtime->shutdown_requested()) {
                    $this->mark_files_sync_partial($checkpoint);
                    return;
                }
            }

            $this->runtime->sort_index_file($this->runtime->remote_index_file());
            $checkpoint->stage = "diff";
            $checkpoint->reset_diff();
            $this->delete_file_if_exists(
                $this->runtime->download_list_file(),
                "clearing before diff stage",
            );
            $this->delete_file_if_exists(
                $this->runtime->skipped_download_list_file(),
                "clearing before diff stage",
            );
            $this->runtime->save_files_pull_checkpoint($checkpoint);
            $stage = "diff";
        }

        if ($stage === "diff") {
            if (!$this->runtime->diff_indexes_and_build_fetch_list($checkpoint)) {
                $this->mark_files_sync_partial($checkpoint);
                return;
            }

            $has_downloads = $this->file_has_entries($this->runtime->download_list_file());
            $has_skipped = $this->file_has_entries($this->runtime->skipped_download_list_file());

            if ($has_downloads) {
                $stage = "fetch";
            } elseif ($has_skipped) {
                $stage = "fetch-skipped";
            } else {
                $stage = null;
            }

            $checkpoint->stage = $stage;
            $this->runtime->save_files_pull_checkpoint($checkpoint);

            if ($has_downloads) {
                $this->show_download_progress_start(
                    $this->runtime->index_entries_counted(),
                    $this->runtime->download_list_file(),
                );
            }

            if (!$has_downloads) {
                $this->delete_file_if_exists(
                    $this->runtime->download_list_file(),
                    "no files to fetch",
                );
            }
            if (!$has_skipped) {
                $this->delete_file_if_exists(
                    $this->runtime->skipped_download_list_file(),
                    "no skipped files to fetch",
                );
            }
        }

        if ($stage === "fetch") {
            if (!$this->runtime->download_files_from_list(
                $checkpoint,
                $this->runtime->download_list_file(),
                "fetch",
            )) {
                $this->mark_files_sync_partial($checkpoint);
                return;
            }

            $checkpoint->fetch->reset();
            $this->delete_file_if_exists($this->runtime->download_list_file(), "fetch complete");

            $has_skipped = $this->file_has_entries($this->runtime->skipped_download_list_file());

            if ($has_skipped && $this->runtime->current_filter() === "essential-files") {
                $checkpoint->stage = null;
                $this->runtime->save_files_pull_checkpoint($checkpoint);
                $this->runtime->audit_log(
                    "ESSENTIAL FILES COMPLETE | skipped files listed in {$this->runtime->skipped_download_list_file()} - run with --filter=skipped-earlier to download them",
                    true,
                );
                $stage = null;
            } elseif ($has_skipped) {
                $checkpoint->stage = "fetch-skipped";
                $this->runtime->save_files_pull_checkpoint($checkpoint);
                $stage = "fetch-skipped";
                $this->runtime->audit_log(
                    "ESSENTIAL FILES COMPLETE | transitioning to skipped files",
                    true,
                );
                $this->runtime->write_status_file();
            } else {
                $checkpoint->stage = null;
                $this->runtime->save_files_pull_checkpoint($checkpoint);
                $stage = null;
            }
        }

        if ($stage === "fetch-skipped") {
            if (!$this->runtime->download_files_from_list(
                $checkpoint,
                $this->runtime->skipped_download_list_file(),
                "fetch_skipped",
            )) {
                $this->mark_files_sync_partial($checkpoint);
                return;
            }

            $checkpoint->stage = null;
            $checkpoint->fetch_skipped->reset();
            $this->runtime->save_files_pull_checkpoint($checkpoint);

            $this->delete_file_if_exists(
                $this->runtime->skipped_download_list_file(),
                "skipped files fetch complete",
            );
        }

        if ($this->runtime->follow_symlinks()) {
            $this->runtime->recreate_intermediate_symlinks();
        }
    }

    private function mark_files_sync_partial(FilesPullCheckpoint $checkpoint): void
    {
        $checkpoint->status = "partial";
        $this->runtime->save_files_pull_checkpoint($checkpoint);
    }

    private function file_has_entries(string $file): bool
    {
        return file_exists($file) && filesize($file) > 0;
    }

    private function delete_file_if_exists(string $file, string $reason): void
    {
        if (!file_exists($file)) {
            return;
        }

        @unlink($file);
        $this->runtime->audit_log("FILE DELETE | {$file} | {$reason}");
    }

    private function show_download_progress_start(
        int $scanned,
        string $download_list_file
    ): void {
        if (!$this->output->is_quiet_lifecycle()) {
            return;
        }

        $green = "\033[32m";
        $dim = "\033[2m";
        $r = "\033[0m";
        $this->output->clear_progress_line();
        $this->output->print_line(
            "  {$green}✓{$r} Scanned {$dim}— " .
            number_format($scanned) .
            " entries{$r}\n",
        );
        $this->output->set_active_label(null);
        $total = DownloadList::count_lines($download_list_file);
        $this->output->show_progress_line(
            "Downloading — 0 / " . number_format($total) . " files",
            0.0,
        );
    }
}
