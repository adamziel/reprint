<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\FetchListBuilder;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Port\FetchListGateway;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\FileSync\Port\LocalFileChangePlanner;
use Reprint\Importer\FileSync\Port\ProgressTicker;
use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\Index\IndexStore;
use Reprint\Importer\Observability\AuditLogger;

final class FetchListBuilderGateway implements FetchListGateway
{
    private IndexStore $index_store;
    private LocalFileChangePlanner $local_changes;
    private FilesPullCheckpointStore $checkpoints;
    private ShutdownToken $shutdown;
    private ProgressTicker $ticker;
    private AuditLogger $audit;
    private string $remote_index_file;
    private string $local_index_file;
    private string $download_list_file;
    private string $skipped_download_list_file;
    private string $filter;
    private ?string $uploads_basedir;

    public function __construct(
        IndexStore $index_store,
        LocalFileChangePlanner $local_changes,
        FilesPullCheckpointStore $checkpoints,
        ShutdownToken $shutdown,
        ProgressTicker $ticker,
        AuditLogger $audit,
        string $remote_index_file,
        string $local_index_file,
        string $download_list_file,
        string $skipped_download_list_file,
        string $filter,
        ?string $uploads_basedir
    ) {
        $this->index_store = $index_store;
        $this->local_changes = $local_changes;
        $this->checkpoints = $checkpoints;
        $this->shutdown = $shutdown;
        $this->ticker = $ticker;
        $this->audit = $audit;
        $this->remote_index_file = $remote_index_file;
        $this->local_index_file = $local_index_file;
        $this->download_list_file = $download_list_file;
        $this->skipped_download_list_file = $skipped_download_list_file;
        $this->filter = $filter;
        $this->uploads_basedir = $uploads_basedir;
    }

    public function build(FilesPullCheckpoint $checkpoint): bool
    {
        return (new FetchListBuilder(
            $this->index_store,
            $this->local_changes,
            $this->checkpoints,
            $this->shutdown,
            $this->ticker,
            $this->audit,
        ))->build(
            $checkpoint,
            $this->remote_index_file,
            $this->local_index_file,
            $this->download_list_file,
            $this->skipped_download_list_file,
            $this->filter,
            $this->uploads_basedir,
        );
    }
}
