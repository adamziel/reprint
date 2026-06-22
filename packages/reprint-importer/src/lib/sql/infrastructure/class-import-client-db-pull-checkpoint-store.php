<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\ImportClient;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\Port\DbPullCheckpointStore;

final class ImportClientDbPullCheckpointStore implements DbPullCheckpointStore
{
    private ImportClient $client;

    public function __construct(ImportClient $client)
    {
        $this->client = $client;
    }

    public function get(): DbPullCheckpoint
    {
        return $this->client->load_db_pull_checkpoint();
    }

    public function save(DbPullCheckpoint $checkpoint): void
    {
        $this->client->save_db_pull_checkpoint($checkpoint);
    }
}
