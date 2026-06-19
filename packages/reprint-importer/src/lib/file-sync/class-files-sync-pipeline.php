<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\Filesystem\LocalImportFilesystem;

final class FilesSyncPipeline
{
    private string $remote_index_file;
    private string $download_list_file;
    private string $skipped_download_list_file;
    private bool $follow_symlinks;
    private string $filter;
    private LocalImportFilesystem $local_filesystem;

    /** @var callable */
    private $default_state;

    /** @var callable */
    private $download_remote_index;

    /** @var callable */
    private $discover_symlink_targets;

    /** @var callable */
    private $should_stop;

    /** @var callable */
    private $sort_index_file;

    /** @var callable */
    private $diff_indexes_and_build_fetch_list;

    /** @var callable */
    private $download_files_from_list;

    /** @var callable */
    private $save_state;

    /** @var callable */
    private $audit;

    /** @var callable */
    private $write_status_file;

    /** @var callable */
    private $index_entries_counted;

    /** @var callable */
    private $notify_downloads_started;

    public function __construct(
        string $remote_index_file,
        string $download_list_file,
        string $skipped_download_list_file,
        bool $follow_symlinks,
        string $filter,
        LocalImportFilesystem $local_filesystem,
        callable $default_state,
        callable $download_remote_index,
        callable $discover_symlink_targets,
        callable $should_stop,
        callable $sort_index_file,
        callable $diff_indexes_and_build_fetch_list,
        callable $download_files_from_list,
        callable $save_state,
        callable $audit,
        callable $write_status_file,
        callable $index_entries_counted,
        callable $notify_downloads_started
    ) {
        $this->remote_index_file = $remote_index_file;
        $this->download_list_file = $download_list_file;
        $this->skipped_download_list_file = $skipped_download_list_file;
        $this->follow_symlinks = $follow_symlinks;
        $this->filter = $filter;
        $this->local_filesystem = $local_filesystem;
        $this->default_state = $default_state;
        $this->download_remote_index = $download_remote_index;
        $this->discover_symlink_targets = $discover_symlink_targets;
        $this->should_stop = $should_stop;
        $this->sort_index_file = $sort_index_file;
        $this->diff_indexes_and_build_fetch_list = $diff_indexes_and_build_fetch_list;
        $this->download_files_from_list = $download_files_from_list;
        $this->save_state = $save_state;
        $this->audit = $audit;
        $this->write_status_file = $write_status_file;
        $this->index_entries_counted = $index_entries_counted;
        $this->notify_downloads_started = $notify_downloads_started;
    }

    /**
     * Runs the shared index -> diff -> fetch pipeline used by both initial and delta syncs.
     *
     * @param array<string, mixed> $state
     */
    public function run(array &$state): void
    {
        $stage = $state["stage"] ?? "index";

        if ($stage === "index") {
            $complete = $this->download_remote_index();
            if (!$complete) {
                $this->mark_partial($state);
                return;
            }

            if ($this->follow_symlinks) {
                $this->discover_symlink_targets($state);
                if ($this->should_stop()) {
                    $this->mark_partial($state);
                    return;
                }
            }

            $this->sort_index_file($this->remote_index_file);
            $state["stage"] = "diff";
            $state["diff"] = $this->default_state()["diff"];
            $this->delete_if_exists(
                $this->download_list_file,
                "clearing before diff stage",
            );
            $this->delete_if_exists(
                $this->skipped_download_list_file,
                "clearing before diff stage",
            );
            $this->save_state($state);
            $stage = "diff";
        }

        if ($stage === "diff") {
            $complete = $this->diff_indexes_and_build_fetch_list();
            if (!$complete) {
                $this->mark_partial($state);
                return;
            }

            $has_downloads = $this->has_entries($this->download_list_file);
            $has_skipped = $this->has_entries($this->skipped_download_list_file);

            if ($has_downloads) {
                $stage = "fetch";
            } elseif ($has_skipped) {
                $stage = "fetch-skipped";
            } else {
                $stage = null;
            }

            $state["stage"] = $stage;
            $this->save_state($state);

            if ($has_downloads) {
                $this->notify_downloads_started(
                    $this->index_entries_counted(),
                    $this->download_list_file,
                );
            }

            if (!$has_downloads) {
                $this->delete_if_exists(
                    $this->download_list_file,
                    "no files to fetch",
                );
            }
            if (!$has_skipped) {
                $this->delete_if_exists(
                    $this->skipped_download_list_file,
                    "no skipped files to fetch",
                );
            }
        }

        if ($stage === "fetch") {
            $complete = $this->download_files_from_list(
                $this->download_list_file,
                "fetch",
            );
            if (!$complete) {
                $this->mark_partial($state);
                return;
            }

            $state["fetch"] = $this->default_state()["fetch"];
            $this->delete_if_exists($this->download_list_file, "fetch complete");

            $has_skipped = $this->has_entries($this->skipped_download_list_file);

            if ($has_skipped && $this->filter === "essential-files") {
                $state["stage"] = null;
                $this->save_state($state);
                $this->audit(
                    "ESSENTIAL FILES COMPLETE | skipped files listed in {$this->skipped_download_list_file} - run with --filter=skipped-earlier to download them",
                    true,
                );
                $stage = null;
            } elseif ($has_skipped) {
                $state["stage"] = "fetch-skipped";
                $this->save_state($state);
                $stage = "fetch-skipped";
                $this->audit(
                    "ESSENTIAL FILES COMPLETE | transitioning to skipped files",
                    true,
                );
                $this->write_status_file();
            } else {
                $state["stage"] = null;
                $this->save_state($state);
                $stage = null;
            }
        }

        if ($stage === "fetch-skipped") {
            $complete = $this->download_files_from_list(
                $this->skipped_download_list_file,
                "fetch_skipped",
            );
            if (!$complete) {
                $this->mark_partial($state);
                return;
            }

            $state["stage"] = null;
            $state["fetch_skipped"] = $this->default_state()["fetch_skipped"];
            $this->save_state($state);

            $this->delete_if_exists(
                $this->skipped_download_list_file,
                "skipped files fetch complete",
            );
        }

        if ($this->follow_symlinks) {
            (new IntermediateSymlinkRecreator(
                $this->local_filesystem,
                function (string $message, bool $to_console): void {
                    $this->audit($message, $to_console);
                },
            ))->recreate($this->remote_index_file);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function default_state(): array
    {
        return (array) ($this->default_state)();
    }

    /**
     * @param array<string, mixed> $state
     */
    private function mark_partial(array &$state): void
    {
        $state["status"] = "partial";
        $this->save_state($state);
    }

    private function has_entries(string $file): bool
    {
        return file_exists($file) && filesize($file) > 0;
    }

    private function delete_if_exists(string $file, string $reason): void
    {
        if (!file_exists($file)) {
            return;
        }

        @unlink($file);
        $this->audit("FILE DELETE | {$file} | {$reason}");
    }

    private function download_remote_index(): bool
    {
        return (bool) ($this->download_remote_index)();
    }

    /**
     * @param array<string, mixed> $state
     */
    private function discover_symlink_targets(array &$state): void
    {
        ($this->discover_symlink_targets)($state);
    }

    private function should_stop(): bool
    {
        return (bool) ($this->should_stop)();
    }

    private function sort_index_file(string $path): void
    {
        ($this->sort_index_file)($path);
    }

    private function diff_indexes_and_build_fetch_list(): bool
    {
        return (bool) ($this->diff_indexes_and_build_fetch_list)();
    }

    private function download_files_from_list(string $list_file, string $state_key): bool
    {
        return (bool) ($this->download_files_from_list)($list_file, $state_key);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save_state(array $state): void
    {
        ($this->save_state)($state);
    }

    private function audit(string $message, bool $to_console = true): void
    {
        ($this->audit)($message, $to_console);
    }

    private function write_status_file(): void
    {
        ($this->write_status_file)();
    }

    private function index_entries_counted(): int
    {
        return (int) ($this->index_entries_counted)();
    }

    private function notify_downloads_started(int $scanned, string $download_list_file): void
    {
        ($this->notify_downloads_started)($scanned, $download_list_file);
    }
}
