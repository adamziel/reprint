<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\FileSync\Port\FetchListGateway;
use Reprint\Importer\FileSync\Port\FileFetchGateway;
use Reprint\Importer\FileSync\Port\FileIndexGateway;
use Reprint\Importer\FileSync\Port\FileSyncSettings;
use Reprint\Importer\FileSync\Port\FileSyncWorkspace;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\FileSync\Port\FilesSyncRunStore;
use Reprint\Importer\FileSync\Port\RemoteFileIndexGateway;
use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\FileSync\Port\SymlinkGateway;
use Reprint\Importer\FileSync\Port\VolatileFileReporter;
use Reprint\Importer\Observability\EventPublisher;
use RuntimeException;

final class FilesSyncWorkflow
{
    private FilesSyncRunStore $run_store;
    private FilesPullCheckpointStore $checkpoints;
    private FileSyncSettings $settings;
    private FileSyncWorkspace $workspace;
    private FileIndexGateway $index;
    private RemoteFileIndexGateway $remote_index;
    private FetchListGateway $fetch_lists;
    private FileFetchGateway $file_fetch;
    private SymlinkGateway $symlinks;
    private ShutdownToken $shutdown;
    private VolatileFileReporter $volatile_files;
    private EventPublisher $events;

    public function __construct(
        FilesSyncRunStore $run_store,
        FilesPullCheckpointStore $checkpoints,
        FileSyncSettings $settings,
        FileSyncWorkspace $workspace,
        FileIndexGateway $index,
        RemoteFileIndexGateway $remote_index,
        FetchListGateway $fetch_lists,
        FileFetchGateway $file_fetch,
        SymlinkGateway $symlinks,
        ShutdownToken $shutdown,
        VolatileFileReporter $volatile_files,
        EventPublisher $events
    ) {
        $this->run_store = $run_store;
        $this->checkpoints = $checkpoints;
        $this->settings = $settings;
        $this->workspace = $workspace;
        $this->index = $index;
        $this->remote_index = $remote_index;
        $this->fetch_lists = $fetch_lists;
        $this->file_fetch = $file_fetch;
        $this->symlinks = $symlinks;
        $this->shutdown = $shutdown;
        $this->volatile_files = $volatile_files;
        $this->events = $events;
    }

    public function run_files_sync(): void
    {
        $checkpoint = $this->checkpoints->get();
        $state_command = $this->run_store->current_command();
        $current_status =
            $state_command === 'files-pull'
                ? $checkpoint->status ?? $this->run_store->current_status()
                : null;
        $has_progress =
            $state_command === 'files-pull' &&
            $current_status !== null &&
            $current_status !== 'complete';

        $this->index->recover_updates();

        if ($current_status === 'complete') {
            $this->handle_completed_files_sync($checkpoint);
            return;
        }

        if ($this->settings->current_filter() === 'skipped-earlier') {
            throw new RuntimeException(
                '--filter=skipped-earlier was requested but there is no completed sync with skipped files. ' .
                    'Run files-pull with --filter=essential-files first.',
            );
        }

        $is_delta = $this->index->local_index_has_entries();

        if ($has_progress) {
            $this->report_files_sync_resume($checkpoint);
        } else {
            $this->start_files_sync($checkpoint, $is_delta);
        }

        $checkpoint->status = 'in_progress';
        $this->checkpoints->save($checkpoint);
        $this->run_store->record_command_status('files-pull', 'in_progress');

        $this->run_files_sync_pipeline($checkpoint);

        if ($checkpoint->status === 'partial') {
            $this->run_store->record_command_status('files-pull', 'partial');
            return;
        }

        $this->complete_files_sync($checkpoint, $is_delta);
    }

    public function run_files_index(): void
    {
        $checkpoint = $this->checkpoints->get();
        $state_command = $this->run_store->current_command();
        $current_status =
            $state_command === 'files-index'
                ? $this->run_store->current_status()
                : null;

        if ($current_status === 'complete') {
            throw new RuntimeException(
                'files-index already completed. Use --abort flag to start over.',
            );
        }

        if ($current_status === null) {
            $checkpoint->reset_for_files_pull();
            $this->checkpoints->save($checkpoint);
            $this->run_store->record_command_status('files-index', 'in_progress');
            $this->events->publish(FileSyncEvent::named(FileSyncEvent::FILES_INDEX_STARTING));
        } else {
            $this->events->publish(FileSyncEvent::named(
                FileSyncEvent::FILES_INDEX_RESUMING,
                ['cursor' => $checkpoint->index_cursor],
            ));
        }

        $this->run_store->record_command_status('files-index', 'in_progress');

        $attempts = 0;
        $last_cursor = $checkpoint->index_cursor;
        while (true) {
            $complete = $this->remote_index->download($checkpoint);
            if ($complete) {
                break;
            }

            if ($this->shutdown->is_shutdown_requested()) {
                $checkpoint->status = 'partial';
                $this->checkpoints->save($checkpoint);
                $this->run_store->record_command_status('files-index', 'partial');
                return;
            }

            $current_cursor = $checkpoint->index_cursor;
            if ($current_cursor === $last_cursor) {
                throw new RuntimeException('files-index made no progress (cursor unchanged)');
            }
            $last_cursor = $current_cursor;

            $attempts++;
            if ($attempts > 100000) {
                throw new RuntimeException('files-index exceeded maximum attempts');
            }
        }

        if ($this->settings->follow_symlinks()) {
            $this->symlinks->discover_targets($checkpoint);
        }

        $this->index->sort_remote_index();
        $checkpoint->status = 'complete';
        $checkpoint->stage = null;
        $this->checkpoints->save($checkpoint);
        $this->run_store->record_command_status('files-index', 'complete');

        $this->events->publish(FileSyncEvent::named(
            FileSyncEvent::FILES_INDEX_COMPLETE,
            [
                'entries_indexed' => $this->index->count_remote_index(),
                'remote_index' => $this->workspace->remote_index_file(),
            ],
        ));
    }

    private function handle_completed_files_sync(FilesPullCheckpoint $checkpoint): void
    {
        $has_skipped = $this->has_skipped_download_list();

        if ($this->settings->current_filter() === 'skipped-earlier') {
            $this->start_skipped_files_fetch($checkpoint, $has_skipped);
            return;
        }

        $this->events->publish(FileSyncEvent::named(
            FileSyncEvent::FILES_PULL_ALREADY_COMPLETE,
            [
                'files_indexed' => $this->index->count_local_index(),
                'has_skipped' => $has_skipped,
            ],
        ));
    }

    private function start_skipped_files_fetch(
        FilesPullCheckpoint $checkpoint,
        bool $has_skipped
    ): void {
        if (!$has_skipped) {
            throw new RuntimeException(
                '--filter=skipped-earlier was requested but there is no skipped file list. ' .
                    'Run files-pull with --filter=essential-files first.',
            );
        }

        $this->events->publish(FileSyncEvent::named(
            FileSyncEvent::FILES_PULL_FETCH_SKIPPED_STARTING,
        ));
        $checkpoint->status = 'in_progress';
        $checkpoint->stage = 'fetch-skipped';
        $this->checkpoints->save($checkpoint);
        $this->run_store->record_command_status('files-pull', 'in_progress');

        $this->run_files_sync_pipeline($checkpoint);
        if ($checkpoint->status === 'partial') {
            $this->run_store->record_command_status('files-pull', 'partial');
            return;
        }

        $checkpoint->status = 'complete';
        $checkpoint->stage = null;
        $this->checkpoints->save($checkpoint);
        $this->run_store->record_command_status('files-pull', 'complete');
    }

    private function report_files_sync_resume(FilesPullCheckpoint $checkpoint): void
    {
        $this->events->publish(FileSyncEvent::named(
            FileSyncEvent::FILES_PULL_RESUMING,
            [
                'stage' => $checkpoint->stage ?? 'index',
                'index_size' => $this->index->count_local_index(),
            ],
        ));
    }

    private function start_files_sync(FilesPullCheckpoint $checkpoint, bool $is_delta): void
    {
        $is_empty = $this->workspace->is_fs_root_empty();
        if (
            !$is_empty &&
            !$is_delta &&
            $this->settings->fs_root_nonempty_behavior() === 'error'
        ) {
            throw new RuntimeException(
                'Target directory is not empty and no cursor found. ' .
                    'Either clear the target directory, use --abort flag, or use --on-fs-root-nonempty=preserve-local to sync while preserving the existing content.',
            );
        }

        $checkpoint->reset_for_files_pull();
        $this->checkpoints->save($checkpoint);
        $this->run_store->record_command_status('files-pull', 'in_progress');

        if ($is_delta) {
            $this->index->reset_transfer_progress();
        }

        $this->events->publish(FileSyncEvent::named(
            FileSyncEvent::FILES_PULL_STARTING,
            [
                'delta' => $is_delta,
                'index_size' => $this->index->count_local_index(),
                'is_empty' => $is_empty,
                'fs_root_nonempty_behavior' => $this->settings->fs_root_nonempty_behavior(),
            ],
        ));
    }

    private function complete_files_sync(
        FilesPullCheckpoint $checkpoint,
        bool $is_delta
    ): void {
        $checkpoint->status = 'complete';
        $checkpoint->stage = null;
        $this->checkpoints->save($checkpoint);
        $this->run_store->record_command_status('files-pull', 'complete');

        $this->events->publish(FileSyncEvent::named(
            FileSyncEvent::FILES_PULL_COMPLETE,
            [
                'delta' => $is_delta,
                'files_indexed' => $this->index->count_local_index(),
            ],
        ));

        $this->volatile_files->report();
    }

    private function run_files_sync_pipeline(FilesPullCheckpoint $checkpoint): void
    {
        $stage = $checkpoint->stage ?? 'index';

        if ($stage === 'index') {
            if (!$this->remote_index->download($checkpoint)) {
                $this->mark_files_sync_partial($checkpoint);
                return;
            }

            if ($this->settings->follow_symlinks()) {
                $this->symlinks->discover_targets($checkpoint);
                if ($this->shutdown->is_shutdown_requested()) {
                    $this->mark_files_sync_partial($checkpoint);
                    return;
                }
            }

            $this->index->sort_remote_index();
            $checkpoint->stage = 'diff';
            $checkpoint->reset_diff();
            $this->delete_file_if_exists(
                $this->workspace->download_list_file(),
                'clearing before diff stage',
            );
            $this->delete_file_if_exists(
                $this->workspace->skipped_download_list_file(),
                'clearing before diff stage',
            );
            $this->checkpoints->save($checkpoint);
            $stage = 'diff';
        }

        if ($stage === 'diff') {
            if (!$this->fetch_lists->build($checkpoint)) {
                $this->mark_files_sync_partial($checkpoint);
                return;
            }

            $has_downloads = $this->workspace->file_has_entries($this->workspace->download_list_file());
            $has_skipped = $this->workspace->file_has_entries($this->workspace->skipped_download_list_file());

            if ($has_downloads) {
                $stage = 'fetch';
            } elseif ($has_skipped) {
                $stage = 'fetch-skipped';
            } else {
                $stage = null;
            }

            $checkpoint->stage = $stage;
            $this->checkpoints->save($checkpoint);

            if ($has_downloads) {
                $this->events->publish(FileSyncEvent::named(
                    FileSyncEvent::DOWNLOAD_PROGRESS_STARTING,
                    [
                        'scanned' => $this->index->index_entries_counted(),
                        'total' => $this->workspace->count_lines($this->workspace->download_list_file()),
                    ],
                ));
            }

            if (!$has_downloads) {
                $this->delete_file_if_exists(
                    $this->workspace->download_list_file(),
                    'no files to fetch',
                );
            }
            if (!$has_skipped) {
                $this->delete_file_if_exists(
                    $this->workspace->skipped_download_list_file(),
                    'no skipped files to fetch',
                );
            }
        }

        if ($stage === 'fetch') {
            if (!$this->file_fetch->fetch_from_list(
                $checkpoint,
                $this->workspace->download_list_file(),
                'fetch',
            )) {
                $this->mark_files_sync_partial($checkpoint);
                return;
            }

            $checkpoint->fetch->reset();
            $this->delete_file_if_exists($this->workspace->download_list_file(), 'fetch complete');

            $has_skipped = $this->workspace->file_has_entries($this->workspace->skipped_download_list_file());

            if ($has_skipped && $this->settings->current_filter() === 'essential-files') {
                $checkpoint->stage = null;
                $this->checkpoints->save($checkpoint);
                $this->events->publish(FileSyncEvent::audit(
                    'ESSENTIAL FILES COMPLETE | skipped files listed in ' .
                        $this->workspace->skipped_download_list_file() .
                        ' - run with --filter=skipped-earlier to download them',
                    true,
                ));
                $stage = null;
            } elseif ($has_skipped) {
                $checkpoint->stage = 'fetch-skipped';
                $this->checkpoints->save($checkpoint);
                $stage = 'fetch-skipped';
                $this->events->publish(FileSyncEvent::audit(
                    'ESSENTIAL FILES COMPLETE | transitioning to skipped files',
                    true,
                ));
            } else {
                $checkpoint->stage = null;
                $this->checkpoints->save($checkpoint);
                $stage = null;
            }
        }

        if ($stage === 'fetch-skipped') {
            if (!$this->file_fetch->fetch_from_list(
                $checkpoint,
                $this->workspace->skipped_download_list_file(),
                'fetch_skipped',
            )) {
                $this->mark_files_sync_partial($checkpoint);
                return;
            }

            $checkpoint->stage = null;
            $checkpoint->fetch_skipped->reset();
            $this->checkpoints->save($checkpoint);

            $this->delete_file_if_exists(
                $this->workspace->skipped_download_list_file(),
                'skipped files fetch complete',
            );
        }

        if ($this->settings->follow_symlinks()) {
            $this->symlinks->recreate_intermediate_symlinks();
        }
    }

    private function mark_files_sync_partial(FilesPullCheckpoint $checkpoint): void
    {
        $checkpoint->status = 'partial';
        $this->checkpoints->save($checkpoint);
    }

    private function has_skipped_download_list(): bool
    {
        return $this->workspace->file_has_entries($this->workspace->skipped_download_list_file());
    }

    private function delete_file_if_exists(string $file, string $reason): void
    {
        if (!$this->workspace->delete_file_if_exists($file)) {
            return;
        }

        $this->events->publish(FileSyncEvent::named(
            FileSyncEvent::FILE_DELETED,
            [
                'file' => $file,
                'reason' => $reason,
            ],
        ));
    }
}
