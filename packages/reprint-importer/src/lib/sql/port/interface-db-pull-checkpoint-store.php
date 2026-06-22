<?php

namespace Reprint\Importer\Sql\Port;

use Reprint\Importer\Sql\DbPullCheckpoint;

interface DbPullCheckpointStore
{
    public function get(): DbPullCheckpoint;

    public function save(DbPullCheckpoint $checkpoint): void;
}
