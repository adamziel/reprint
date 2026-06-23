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
use Reprint\Importer\FileSync\RuntimeFilesDownloader;
use Reprint\Importer\FileSync\SymlinkTargetIndexer;
use Reprint\Importer\Observability\ImportOutputMachineEventEmitter;
use Reprint\Importer\Session\ExportDirectoryResolver;
use Reprint\Importer\Session\VolatileFileTracker;
use Reprint\Importer\Sql\DbApplySourceContext;
use Reprint\Importer\Sql\DbApplyWorkflow;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\DbPullConfiguration;
use Reprint\Importer\Sql\DbPullWorkflow;
use Reprint\Importer\Sql\Infrastructure\ConfiguredSqlDumpDownloader;
use Reprint\Importer\Sql\Infrastructure\DbPullCurlTimeoutPolicy;
use Reprint\Importer\Sql\Infrastructure\ImportOutputDbApplyObserver;
use Reprint\Importer\Sql\Infrastructure\ImportOutputDbPullObserver;
use Reprint\Importer\Sql\Infrastructure\ImportOutputSqlStreamObserver;
use Reprint\Importer\Sql\Infrastructure\JsonlDbIndexTableSinkFactory;
use Reprint\Importer\Sql\Infrastructure\JsonSqlDomainStore;
use Reprint\Importer\Sql\Infrastructure\JsonSqlStatementStatsStore;
use Reprint\Importer\Sql\Infrastructure\LocalSqlOutputSinkFactory;
use Reprint\Importer\Sql\Infrastructure\RemoteDbIndexDownloader;
use Reprint\Importer\Sql\Infrastructure\ShutdownStateDbApplyToken;
use Reprint\Importer\Sql\Infrastructure\ShutdownStateSqlToken;
use Reprint\Importer\Sql\Infrastructure\TransportSqlStreamClient;
use Reprint\Importer\Sql\SqlDownloader;
use Reprint\Importer\Sql\Port\SqlStreamClient;

final class ImportServices
{
    private ImportContext $context;
    private ?FileSyncStreamClient $file_sync_stream_client;
    private ?SqlStreamClient $sql_stream_client;

    public function __construct(
        ImportContext $context,
        ?FileSyncStreamClient $file_sync_stream_client = null,
        ?SqlStreamClient $sql_stream_client = null
    )
    {
        $this->context = $context;
        $this->file_sync_stream_client = $file_sync_stream_client;
        $this->sql_stream_client = $sql_stream_client;
    }

    public function file_sync_workflow(): FilesSyncWorkflow
    {
        $context = $this->context;
        $audit = $context->audit_logger();
        $output = $context->output();
        $workspace = $this->file_sync_workspace();
        $checkpoints = $context->files_pull_checkpoint_store();
        $shutdown = $this->file_sync_shutdown_token();
        $stream = $this->file_sync_stream_client();
        $timeout_policy = $this->file_sync_timeout_policy();
        $local_applier = $this->file_sync_local_applier($checkpoints->get());
        $index = $this->file_index_gateway($workspace);
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
        $workspace = $this->file_sync_workspace();

        (new SymlinkTargetIndexer(
            $context->paths()->remote_index_file(),
            new RemoteIndexDownloaderGateway(
                $this->file_sync_stream_client(),
                $this->file_sync_shutdown_token(),
                $context->files_pull_checkpoint_store(),
                $this->file_sync_local_applier($checkpoint),
                $this->file_sync_timeout_policy(),
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
            $this->file_sync_shutdown_token(),
            $audit,
            new ImportOutputSymlinkTargetObserver(
                $context->output(),
                new ImportOutputMachineEventEmitter($context->output()),
            ),
        ))->discover($checkpoint, $this->root_directories_from_preflight());
    }

    public function db_pull_workflow(): DbPullWorkflow
    {
        $context = $this->context;
        $audit = $context->audit_logger();
        $stream = $this->sql_stream_client();
        $checkpoints = $context->db_pull_checkpoint_store();
        $shutdown = $this->sql_shutdown_token();
        $timeouts = $this->db_pull_timeout_policy();
        $config = new DbPullConfiguration(
            $context->state_dir(),
            $context->paths()->audit_log(),
            $context->sql_output_mode(),
            $context->mysql_database(),
        );

        return new DbPullWorkflow(
            $config,
            $checkpoints,
            new RemoteDbIndexDownloader(
                $stream,
                $shutdown,
                $checkpoints,
                $timeouts,
                new JsonlDbIndexTableSinkFactory($audit),
                $audit,
                $config->tables_file(),
            ),
            new ConfiguredSqlDumpDownloader(
                new SqlDownloader(
                    $stream,
                    $shutdown,
                    $checkpoints,
                    $timeouts,
                    new LocalSqlOutputSinkFactory($audit),
                    new JsonSqlDomainStore($context->paths()->domains_file()),
                    new JsonSqlStatementStatsStore($context->paths()->sql_stats_file()),
                    new ImportOutputSqlStreamObserver(
                        $context->output(),
                        new ImportOutputMachineEventEmitter($context->output()),
                        $context->db_pull_checkpoint(),
                    ),
                    $audit,
                ),
                [
                    "mode" => $context->sql_output_mode(),
                    "state_dir" => $context->state_dir(),
                    "remote_url" => $context->remote_url(),
                    "mysql_host" => $context->mysql_host(),
                    "mysql_port" => $context->mysql_port(),
                    "mysql_user" => $context->mysql_user(),
                    "mysql_password" => $context->mysql_password(),
                    "mysql_database" => $context->mysql_database(),
                    "save_every" => ImportContext::SAVE_STATE_EVERY_N_CHUNKS,
                ],
            ),
            new ImportOutputDbPullObserver(
                $context->output(),
                new ImportOutputMachineEventEmitter($context->output()),
            ),
            $audit,
        );
    }

    public function db_apply_workflow(): DbApplyWorkflow
    {
        $context = $this->context;

        return new DbApplyWorkflow(
            $context->state_dir(),
            $context->remote_url(),
            $context->local_filesystem(),
            $context->db_apply_checkpoint_store(),
            $context->audit_logger(),
            new ImportOutputDbApplyObserver(
                $context->output(),
                new ImportOutputMachineEventEmitter($context->output()),
            ),
            $this->db_apply_shutdown_token(),
            new JsonSqlStatementStatsStore($context->paths()->sql_stats_file()),
        );
    }

    public function db_apply_source(): DbApplySourceContext
    {
        return new DbApplySourceContext(
            $this->context->preflight_checkpoint()->require_data(
                "db-apply requires a prior preflight run. Run 'preflight' first.",
            ),
            $this->context->detected_webhost(),
        );
    }

    public function download_runtime_files(): void
    {
        (new RuntimeFilesDownloader(
            $this->file_sync_stream_client(),
            $this->context->audit_logger(),
        ))->download(
            $this->context->preflight_data() ?? [],
            $this->context->paths()->runtime_files_dir(),
        );
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

    public function file_sync_local_applier(?FilesPullCheckpoint $checkpoint = null): FileSyncLocalApplier
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
        $workspace = $this->file_sync_workspace();

        return new FileFetchDownloader(
            $this->file_sync_stream_client(),
            $this->file_sync_shutdown_token(),
            $this->context->files_pull_checkpoint_store(),
            $this->file_sync_local_applier($checkpoint),
            $this->file_sync_timeout_policy(),
            $this->file_index_gateway($workspace),
            $this->context->audit_logger(),
        );
    }

    public function download_sql(DbPullCheckpoint $checkpoint): DbPullCheckpoint
    {
        $context = $this->context;
        $audit = $context->audit_logger();

        return (new SqlDownloader(
            $this->sql_stream_client(),
            $this->sql_shutdown_token(),
            $context->db_pull_checkpoint_store(),
            $this->db_pull_timeout_policy(),
            new LocalSqlOutputSinkFactory($audit),
            new JsonSqlDomainStore($context->paths()->domains_file()),
            new JsonSqlStatementStatsStore($context->paths()->sql_stats_file()),
            new ImportOutputSqlStreamObserver(
                $context->output(),
                new ImportOutputMachineEventEmitter($context->output()),
                $checkpoint,
            ),
            $audit,
        ))->download($checkpoint, [
            "mode" => $context->sql_output_mode(),
            "state_dir" => $context->state_dir(),
            "remote_url" => $context->remote_url(),
            "mysql_host" => $context->mysql_host(),
            "mysql_port" => $context->mysql_port(),
            "mysql_user" => $context->mysql_user(),
            "mysql_password" => $context->mysql_password(),
            "mysql_database" => $context->mysql_database(),
            "save_every" => ImportContext::SAVE_STATE_EVERY_N_CHUNKS,
        ]);
    }

    public function download_file_fetch(
        FilesPullCheckpoint $checkpoint,
        ?array $post_data,
        ?string $cursor,
        string $state_key = "fetch"
    ): bool {
        $local_applier = $this->file_sync_local_applier($checkpoint);
        $workspace = $this->file_sync_workspace();
        $downloader = new FileFetchDownloader(
            $this->file_sync_stream_client(),
            $this->file_sync_shutdown_token(),
            $this->context->files_pull_checkpoint_store(),
            $local_applier,
            $this->file_sync_timeout_policy(),
            $this->file_index_gateway($workspace),
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
        $local_applier = $this->file_sync_local_applier($checkpoint);
        $workspace = $this->file_sync_workspace();

        return (new RemoteIndexDownloaderGateway(
            $this->file_sync_stream_client(),
            $this->file_sync_shutdown_token(),
            $context->files_pull_checkpoint_store(),
            $local_applier,
            $this->file_sync_timeout_policy(),
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
        $local_applier = $this->file_sync_local_applier($checkpoint);

        return (new FetchListBuilderGateway(
            $context->index_store(),
            $local_applier,
            $context->files_pull_checkpoint_store(),
            $this->file_sync_shutdown_token(),
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
        $workspace = $this->file_sync_workspace();
        $local_applier = $this->file_sync_local_applier($checkpoint);
        $gateway = new FetchListFileFetchGateway(
            $this->file_sync_stream_client(),
            $this->file_sync_shutdown_token(),
            $context->files_pull_checkpoint_store(),
            $local_applier,
            $this->file_sync_timeout_policy(),
            $this->file_index_gateway($workspace),
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

    public function sql_stream_client(): SqlStreamClient
    {
        if ($this->sql_stream_client instanceof SqlStreamClient) {
            return $this->sql_stream_client;
        }

        return new TransportSqlStreamClient($this->context->http_session());
    }

    private function file_sync_workspace(): ImportPathFileSyncWorkspace
    {
        return new ImportPathFileSyncWorkspace($this->context->paths(), $this->context->fs_root());
    }

    private function file_sync_stream_client(): FileSyncStreamClient
    {
        if ($this->file_sync_stream_client instanceof FileSyncStreamClient) {
            return $this->file_sync_stream_client;
        }

        return new TransportFileSyncStreamClient($this->context->http_session());
    }

    private function file_sync_shutdown_token(): ShutdownStateToken
    {
        return new ShutdownStateToken($this->context->shutdown());
    }

    private function sql_shutdown_token(): ShutdownStateSqlToken
    {
        return new ShutdownStateSqlToken($this->context->shutdown());
    }

    private function db_apply_shutdown_token(): ShutdownStateDbApplyToken
    {
        return new ShutdownStateDbApplyToken($this->context->shutdown());
    }

    private function file_sync_timeout_policy(): FilesPullCurlTimeoutPolicy
    {
        return new FilesPullCurlTimeoutPolicy(
            $this->context->audit_logger(),
            ImportContext::MAX_CONSECUTIVE_TIMEOUTS,
        );
    }

    private function db_pull_timeout_policy(): DbPullCurlTimeoutPolicy
    {
        return new DbPullCurlTimeoutPolicy(
            $this->context->audit_logger(),
            ImportContext::MAX_CONSECUTIVE_TIMEOUTS,
        );
    }

    private function file_index_gateway(
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
