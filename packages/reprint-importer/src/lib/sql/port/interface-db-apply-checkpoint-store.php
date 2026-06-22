<?php

namespace Reprint\Importer\Sql\Port;

use Reprint\Importer\Sql\DbApplyCheckpoint;

interface DbApplyCheckpointStore
{
    public function get(): DbApplyCheckpoint;

    public function save(DbApplyCheckpoint $checkpoint): void;
}
