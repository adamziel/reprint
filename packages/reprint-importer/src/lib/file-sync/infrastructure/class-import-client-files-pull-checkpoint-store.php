<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\ImportClient;

final class ImportClientFilesPullCheckpointStore implements FilesPullCheckpointStore
{
    private ImportClient $client;

    public function __construct(ImportClient $client)
    {
        $this->client = $client;
    }

    public function get(): FilesPullCheckpoint
    {
        return $this->client->files_pull_checkpoint();
    }

    public function save(FilesPullCheckpoint $checkpoint): void
    {
        $this->client->save_files_pull_checkpoint($checkpoint);
    }
}
