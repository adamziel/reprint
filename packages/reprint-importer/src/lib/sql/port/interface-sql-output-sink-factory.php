<?php

namespace Reprint\Importer\Sql\Port;

use Reprint\Importer\Sql\DbPullCheckpoint;

interface SqlOutputSinkFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public function create(DbPullCheckpoint $checkpoint, array $config): SqlOutputSink;
}
