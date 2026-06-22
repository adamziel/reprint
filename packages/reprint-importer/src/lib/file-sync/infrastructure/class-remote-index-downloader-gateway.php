<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\FileSyncTransferProgress;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Port\FileSyncStreamClient;
use Reprint\Importer\FileSync\Port\FileSyncStreamObserver;
use Reprint\Importer\FileSync\Port\FileSyncWorkspace;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\FileSync\Port\FilesPullTimeoutPolicy;
use Reprint\Importer\FileSync\Port\RemoteFileIndexGateway;
use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\FileSync\RemoteIndexDownloader;
use Reprint\Importer\Observability\AuditLogger;

final class RemoteIndexDownloaderGateway implements RemoteFileIndexGateway
{
    private FileSyncStreamClient $stream;
    private ShutdownToken $shutdown;
    private FilesPullCheckpointStore $checkpoints;
    private FileSyncStreamObserver $observer;
    private FilesPullTimeoutPolicy $timeout_policy;
    private FileSyncWorkspace $workspace;
    private AuditLogger $audit;
    private FileSyncTransferProgress $progress;
    private array $config;

    /**
     * @param array{
     *     remote_index_file:string,
     *     roots:array<int, string>,
     *     export_dirs:array<int, string>,
     *     follow_symlinks:bool,
     *     include_caches:bool,
     *     save_every:int
     * } $config
     */
    public function __construct(
        FileSyncStreamClient $stream,
        ShutdownToken $shutdown,
        FilesPullCheckpointStore $checkpoints,
        FileSyncStreamObserver $observer,
        FilesPullTimeoutPolicy $timeout_policy,
        FileSyncWorkspace $workspace,
        AuditLogger $audit,
        FileSyncTransferProgress $progress,
        array $config
    ) {
        $this->stream = $stream;
        $this->shutdown = $shutdown;
        $this->checkpoints = $checkpoints;
        $this->observer = $observer;
        $this->timeout_policy = $timeout_policy;
        $this->workspace = $workspace;
        $this->audit = $audit;
        $this->progress = $progress;
        $this->config = $config;
    }

    public function download(FilesPullCheckpoint $checkpoint, ?string $list_dir_override = null): bool
    {
        $config = $this->config;
        $config["list_dir_override"] = $list_dir_override;
        $entries_counted = $this->progress->index_entries_counted();

        $complete = (new RemoteIndexDownloader(
            $this->stream,
            $this->shutdown,
            $this->checkpoints,
            $this->observer,
            $this->timeout_policy,
            $this->workspace,
            $this->audit,
        ))->download($checkpoint, $config, $entries_counted);

        $this->progress->set_index_entries_counted($entries_counted);

        return $complete;
    }
}
