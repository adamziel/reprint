<?php

namespace Reprint\Importer\Application;

use Reprint\Importer\FileSync\DownloadList;
use Reprint\Importer\FileSync\FileFetchDownloader;
use Reprint\Importer\FileSync\FileSyncLocalApplier;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\FilesSyncWorkflow;
use Reprint\Importer\FileSync\Infrastructure\CliFileSyncEventSubscriber;
use Reprint\Importer\FileSync\Infrastructure\FetchListBuilderGateway;
use Reprint\Importer\FileSync\Infrastructure\FetchListFileFetchGateway;
use Reprint\Importer\FileSync\Infrastructure\FileSyncSymlinkGateway;
use Reprint\Importer\FileSync\Infrastructure\FilesPullCurlTimeoutPolicy;
use Reprint\Importer\FileSync\Infrastructure\ImportOutputProgressTicker;
use Reprint\Importer\FileSync\Infrastructure\ImportOutputSymlinkTargetObserver;
use Reprint\Importer\FileSync\Infrastructure\ImportPathFileSyncWorkspace;
use Reprint\Importer\FileSync\Infrastructure\IndexStoreFileIndexGateway;
use Reprint\Importer\FileSync\Infrastructure\RemoteIndexDownloaderGateway;
use Reprint\Importer\FileSync\Infrastructure\RunStateFilesSyncRunStore;
use Reprint\Importer\FileSync\Infrastructure\ShutdownStateToken;
use Reprint\Importer\FileSync\Infrastructure\SnapshotFileSyncSettings;
use Reprint\Importer\FileSync\Infrastructure\TransportFileSyncStreamClient;
use Reprint\Importer\FileSync\Infrastructure\VolatileFileSummaryReporter;
use Reprint\Importer\FileSync\IntermediateSymlinkRecreator;
use Reprint\Importer\FileSync\Port\FileSyncStreamClient;
use Reprint\Importer\FileSync\SymlinkTargetIndexer;
use Reprint\Importer\Observability\ImportOutputMachineEventEmitter;
use Reprint\Importer\Session\ExportDirectoryResolver;
use Reprint\Importer\Session\VolatileFileTracker;

final class FileSyncServices
{
    private ImportContext $context;
    private ?FileSyncStreamClient $stream_client;

    public function __construct(
        ImportContext $context,
        ?FileSyncStreamClient $stream_client = null
    ) {
        $this->context = $context;
        $this->stream_client = $stream_client;
    }

    public function workflow(): FilesSyncWorkflow
    {
        $context = $this->context;
        $audit = $context->audit_logger();
        $output = $context->output();
        $workspace = $this->workspace();
        $checkpoints = $context->files_pull_checkpoint_store();
        $shutdown = $this->shutdown_token();
        $stream = $this->stream_client();
        $timeout_policy = $this->timeout_policy();
        $local_applier = $this->local_applier($checkpoints->get());
        $index = $this->index_gateway($workspace);
        $export_dirs = $this->export_directories();
        $roots = $this->root_directories_from_preflight();
        $remote_index = new RemoteIndexDownloaderGateway(
            $stream,
            $shutdown,
            $checkpoints,
            $local_applier,
            $timeout_policy,
            $workspace,
            $audit,
            $context->file_sync_progress(),
            [
                "remote_index_file" => $context->paths()->remote_index_file(),
                "roots" => $roots,
                "export_dirs" => $export_dirs,
                "follow_symlinks" => $context->follow_symlinks(),
                "include_caches" => $context->include_caches(),
                "save_every" => ImportContext::SAVE_STATE_EVERY_N_CHUNKS,
            ],
        );

        return new FilesSyncWorkflow(
            new RunStateFilesSyncRunStore(
                $context->state(),
                $context->store(),
                $context->paths()->state_file(),
                $output,
            ),
            $checkpoints,
            new SnapshotFileSyncSettings(
                $context->filter(),
                $context->follow_symlinks(),
                $context->fs_root_nonempty_behavior(),
            ),
            $workspace,
            $index,
            $remote_index,
            new FetchListBuilderGateway(
                $context->index_store(),
                $local_applier,
                $checkpoints,
                $shutdown,
                new ImportOutputProgressTicker($output),
                $audit,
                $context->paths()->remote_index_file(),
                $context->paths()->index_file(),
                $context->paths()->download_list_file(),
                $context->paths()->skipped_download_list_file(),
                $context->filter(),
                $context->filter() === "essential-files" ? $this->uploads_basedir() : null,
            ),
            new FetchListFileFetchGateway(
                $stream,
                $shutdown,
                $checkpoints,
                $local_applier,
                $timeout_policy,
                $index,
                $audit,
                $context->file_sync_progress(),
                $this->max_request_bytes(),
                $export_dirs,
                ImportContext::SAVE_STATE_EVERY_N_CHUNKS,
            ),
            new FileSyncSymlinkGateway(
                $context->paths()->remote_index_file(),
                $remote_index,
                $checkpoints,
                $shutdown,
                $audit,
                new ImportOutputSymlinkTargetObserver(
                    $output,
                    new ImportOutputMachineEventEmitter($output),
                ),
                new IntermediateSymlinkRecreator(
                    $context->local_filesystem(),
                    $audit,
                ),
                $roots,
            ),
            $shutdown,
            new VolatileFileSummaryReporter(
                $this->volatile_file_tracker(),
                $audit,
                $output,
                new ImportOutputMachineEventEmitter($output),
            ),
            new CliFileSyncEventSubscriber(
                $audit,
                $output,
                new ImportOutputMachineEventEmitter($output),
            ),
        );
    }

    public function discover_symlink_targets(FilesPullCheckpoint $checkpoint): void
    {
        $context = $this->context;
        $audit = $context->audit_logger();
        $workspace = $this->workspace();

        (new SymlinkTargetIndexer(
            $context->paths()->remote_index_file(),
            new RemoteIndexDownloaderGateway(
                $this->stream_client(),
                $this->shutdown_token(),
                $context->files_pull_checkpoint_store(),
                $this->local_applier($checkpoint),
                $this->timeout_policy(),
                $workspace,
                $audit,
                $context->file_sync_progress(),
                [
                    "remote_index_file" => $context->paths()->remote_index_file(),
                    "roots" => $this->root_directories_from_preflight(),
                    "export_dirs" => $this->export_directories(),
                    "follow_symlinks" => $context->follow_symlinks(),
                    "include_caches" => $context->include_caches(),
                    "save_every" => ImportContext::SAVE_STATE_EVERY_N_CHUNKS,
                ],
            ),
            $context->files_pull_checkpoint_store(),
            $this->shutdown_token(),
            $audit,
            new ImportOutputSymlinkTargetObserver(
                $context->output(),
                new ImportOutputMachineEventEmitter($context->output()),
            ),
        ))->discover($checkpoint, $this->root_directories_from_preflight());
    }

    public function root_directories_from_preflight(): array
    {
        return ExportDirectoryResolver::root_directories_from_preflight(
            $this->context->preflight_data() ?? [],
            function (string $message): void {
                $this->context->audit_log($message);
            },
        );
    }

    public function export_directories(): array
    {
        return ExportDirectoryResolver::export_directories(
            $this->context->preflight_data() ?? [],
            $this->context->extra_directory(),
            function (string $message): void {
                $this->context->audit_log($message);
            },
        );
    }

    public function uploads_basedir(): ?string
    {
        $data = $this->context->preflight_data() ?? [];
        $paths_urls = $data["database"]["wp"]["paths_urls"] ?? null;
        if (!is_array($paths_urls)) {
            return null;
        }
        $basedir = $paths_urls["uploads"]["basedir"] ?? null;
        if (!is_string($basedir) || $basedir === "") {
            return null;
        }
        return rtrim($basedir, "/") . "/";
    }

    public function max_request_bytes(): int
    {
        $data = $this->context->preflight_data() ?? [];
        $preflight = $data["limits"] ?? null;
        $max_request = null;
        if (is_array($preflight) && isset($preflight["max_request_bytes"])) {
            $max_request = (int) $preflight["max_request_bytes"];
        }

        return $max_request !== null && $max_request > 0
            ? $max_request
            : 4 * 1024 * 1024;
    }

    public function local_applier(?FilesPullCheckpoint $checkpoint = null): FileSyncLocalApplier
    {
        $context = $this->context;

        return new FileSyncLocalApplier(
            $context->local_filesystem(),
            $context->index_store(),
            $this->volatile_file_tracker(),
            $context->output(),
            $context->fs_root(),
            $context->paths()->remote_index_file(),
            $context->fs_root_nonempty_behavior(),
            $context->follow_symlinks(),
            $context->file_sync_progress()->files_imported(),
            $context->file_sync_progress()->download_list_done(),
            $context->file_sync_progress()->download_list_total(),
            $checkpoint,
            $context->audit_logger(),
            new ImportOutputMachineEventEmitter($context->output()),
        );
    }

    public function file_fetch_downloader(?FilesPullCheckpoint $checkpoint = null): FileFetchDownloader
    {
        $checkpoint = $checkpoint ?? $this->context->files_pull_checkpoint();
        $workspace = $this->workspace();

        return new FileFetchDownloader(
            $this->stream_client(),
            $this->shutdown_token(),
            $this->context->files_pull_checkpoint_store(),
            $this->local_applier($checkpoint),
            $this->timeout_policy(),
            $this->index_gateway($workspace),
            $this->context->audit_logger(),
        );
    }

    public function download_file_fetch(
        FilesPullCheckpoint $checkpoint,
        ?array $post_data,
        ?string $cursor,
        string $state_key = "fetch"
    ): bool {
        $local_applier = $this->local_applier($checkpoint);
        $workspace = $this->workspace();
        $downloader = new FileFetchDownloader(
            $this->stream_client(),
            $this->shutdown_token(),
            $this->context->files_pull_checkpoint_store(),
            $local_applier,
            $this->timeout_policy(),
            $this->index_gateway($workspace),
            $this->context->audit_logger(),
        );

        try {
            return $downloader->download($checkpoint, [
                "post_data" => $post_data,
                "cursor" => $cursor,
                "state_key" => $state_key,
                "export_dirs" => $this->export_directories(),
                "save_every" => ImportContext::SAVE_STATE_EVERY_N_CHUNKS,
            ]);
        } finally {
            $this->context->set_file_sync_progress(
                $local_applier->files_imported(),
                $this->context->file_sync_progress()->download_list_done(),
                $this->context->file_sync_progress()->download_list_total(),
            );
        }
    }

    public function download_remote_index(
        FilesPullCheckpoint $checkpoint,
        ?string $list_dir_override = null
    ): bool {
        $context = $this->context;
        $local_applier = $this->local_applier($checkpoint);
        $workspace = $this->workspace();

        return (new RemoteIndexDownloaderGateway(
            $this->stream_client(),
            $this->shutdown_token(),
            $context->files_pull_checkpoint_store(),
            $local_applier,
            $this->timeout_policy(),
            $workspace,
            $context->audit_logger(),
            $context->file_sync_progress(),
            [
                "remote_index_file" => $context->paths()->remote_index_file(),
                "roots" => $this->root_directories_from_preflight(),
                "export_dirs" => $this->export_directories(),
                "follow_symlinks" => $context->follow_symlinks(),
                "include_caches" => $context->include_caches(),
                "save_every" => ImportContext::SAVE_STATE_EVERY_N_CHUNKS,
            ],
        ))->download($checkpoint, $list_dir_override);
    }

    public function build_fetch_list(FilesPullCheckpoint $checkpoint): bool
    {
        $context = $this->context;
        $local_applier = $this->local_applier($checkpoint);

        return (new FetchListBuilderGateway(
            $context->index_store(),
            $local_applier,
            $context->files_pull_checkpoint_store(),
            $this->shutdown_token(),
            new ImportOutputProgressTicker($context->output()),
            $context->audit_logger(),
            $context->paths()->remote_index_file(),
            $context->paths()->index_file(),
            $context->paths()->download_list_file(),
            $context->paths()->skipped_download_list_file(),
            $context->filter(),
            $context->filter() === "essential-files" ? $this->uploads_basedir() : null,
        ))->build($checkpoint);
    }

    public function fetch_files_from_list(
        FilesPullCheckpoint $checkpoint,
        string $list_file,
        string $state_key
    ): bool {
        $context = $this->context;
        $workspace = $this->workspace();
        $local_applier = $this->local_applier($checkpoint);
        $gateway = new FetchListFileFetchGateway(
            $this->stream_client(),
            $this->shutdown_token(),
            $context->files_pull_checkpoint_store(),
            $local_applier,
            $this->timeout_policy(),
            $this->index_gateway($workspace),
            $context->audit_logger(),
            $context->file_sync_progress(),
            $this->max_request_bytes(),
            $this->export_directories(),
            ImportContext::SAVE_STATE_EVERY_N_CHUNKS,
        );

        try {
            return $gateway->fetch_from_list($checkpoint, $list_file, $state_key);
        } finally {
            $context->set_file_sync_progress(
                $context->file_sync_progress()->files_imported(),
                $context->file_sync_progress()->download_list_done(),
                $context->file_sync_progress()->download_list_total(),
            );
        }
    }

    public function count_download_list_lines(string $file, int $up_to_byte = -1): int
    {
        return DownloadList::count_lines($file, $up_to_byte);
    }

    private function workspace(): ImportPathFileSyncWorkspace
    {
        return new ImportPathFileSyncWorkspace($this->context->paths(), $this->context->fs_root());
    }

    private function stream_client(): FileSyncStreamClient
    {
        if ($this->stream_client instanceof FileSyncStreamClient) {
            return $this->stream_client;
        }

        return new TransportFileSyncStreamClient($this->context->http_session());
    }

    private function shutdown_token(): ShutdownStateToken
    {
        return new ShutdownStateToken($this->context->shutdown());
    }

    private function timeout_policy(): FilesPullCurlTimeoutPolicy
    {
        return new FilesPullCurlTimeoutPolicy(
            $this->context->audit_logger(),
            ImportContext::MAX_CONSECUTIVE_TIMEOUTS,
        );
    }

    private function index_gateway(
        ImportPathFileSyncWorkspace $workspace
    ): IndexStoreFileIndexGateway {
        return new IndexStoreFileIndexGateway(
            $this->context->index_store(),
            $this->context->index_sorter(),
            $workspace,
            $this->context->file_sync_progress(),
        );
    }

    private function volatile_file_tracker(): VolatileFileTracker
    {
        return new VolatileFileTracker(
            $this->context->paths()->volatile_files_file(),
            function (string $message): void {
                $this->context->audit_log($message);
            },
        );
    }
}
