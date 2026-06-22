<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\ImportClient;
use Reprint\Importer\Sql\DbApplyCheckpoint;
use Reprint\Importer\Sql\Port\DbApplyCheckpointStore;

final class ImportClientDbApplyCheckpointStore implements DbApplyCheckpointStore
{
    private ImportClient $client;

    public function __construct(ImportClient $client)
    {
        $this->client = $client;
    }

    public function get(): DbApplyCheckpoint
    {
        return $this->client->db_apply_checkpoint();
    }

    public function save(DbApplyCheckpoint $checkpoint): void
    {
        $this->client->save_db_apply_checkpoint($checkpoint);
    }
}
