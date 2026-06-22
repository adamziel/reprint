<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\FetchListExecutor;
use Reprint\Importer\FileSync\FileFetchDownloader;
use Reprint\Importer\FileSync\FileSyncTransferProgress;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Port\FileFetchGateway;
use Reprint\Importer\FileSync\Port\FileIndexGateway;
use Reprint\Importer\FileSync\Port\FileSyncStreamClient;
use Reprint\Importer\FileSync\Port\FileSyncStreamObserver;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\FileSync\Port\FilesPullTimeoutPolicy;
use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\Observability\AuditLogger;

final class FetchListFileFetchGateway implements FileFetchGateway
{
    private FileSyncStreamClient $stream;
    private ShutdownToken $shutdown;
    private FilesPullCheckpointStore $checkpoints;
    private FileSyncStreamObserver $observer;
    private FilesPullTimeoutPolicy $timeout_policy;
    private FileIndexGateway $index;
    private AuditLogger $audit;
    private FileSyncTransferProgress $progress;
    private int $max_request_bytes;
    private array $export_dirs;
    private int $save_every;

    /**
     * @param array<int, string> $export_dirs
     */
    public function __construct(
        FileSyncStreamClient $stream,
        ShutdownToken $shutdown,
        FilesPullCheckpointStore $checkpoints,
        FileSyncStreamObserver $observer,
        FilesPullTimeoutPolicy $timeout_policy,
        FileIndexGateway $index,
        AuditLogger $audit,
        FileSyncTransferProgress $progress,
        int $max_request_bytes,
        array $export_dirs,
        int $save_every
    ) {
        $this->stream = $stream;
        $this->shutdown = $shutdown;
        $this->checkpoints = $checkpoints;
        $this->observer = $observer;
        $this->timeout_policy = $timeout_policy;
        $this->index = $index;
        $this->audit = $audit;
        $this->progress = $progress;
        $this->max_request_bytes = $max_request_bytes;
        $this->export_dirs = $export_dirs;
        $this->save_every = $save_every;
    }

    public function fetch_from_list(
        FilesPullCheckpoint $checkpoint,
        string $list_file,
        string $state_key
    ): bool {
        $downloader = new FileFetchDownloader(
            $this->stream,
            $this->shutdown,
            $this->checkpoints,
            $this->observer,
            $this->timeout_policy,
            $this->index,
            $this->audit,
        );
        $executor = new FetchListExecutor(
            $this->progress->download_list_total(),
            $this->progress->download_list_done(),
            $this->progress->files_imported(),
            $this->max_request_bytes,
            new CurlFileFetchBatchDownloader(
                $downloader,
                $checkpoint,
                $this->export_dirs,
                $this->save_every,
            ),
            $this->checkpoints,
            $this->audit,
        );

        try {
            return $executor->run($list_file, $state_key, $checkpoint);
        } finally {
            $this->progress->set_transfer_counts(
                $executor->files_imported(),
                $executor->download_list_done(),
                $executor->download_list_total(),
            );
        }
    }
}
