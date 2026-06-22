<?php

namespace Reprint\Importer\Sql\Port;

use Reprint\Importer\Sql\DbPullCheckpoint;

interface DbIndexDownloader
{
    public function download(DbPullCheckpoint $checkpoint, ?string $tables_file = null): DbPullCheckpoint;
}
