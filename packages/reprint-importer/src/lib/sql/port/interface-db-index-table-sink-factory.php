<?php

namespace Reprint\Importer\Sql\Port;

use Reprint\Importer\Sql\DbIndexCheckpoint;

interface DbIndexTableSinkFactory
{
    public function create(string $tables_file, ?string $cursor, DbIndexCheckpoint $checkpoint): DbIndexTableSink;
}
