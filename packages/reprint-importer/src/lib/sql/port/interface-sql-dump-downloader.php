<?php

namespace Reprint\Importer\Sql\Port;

use Reprint\Importer\Sql\DbPullCheckpoint;

interface SqlDumpDownloader
{
    public function download(DbPullCheckpoint $checkpoint): DbPullCheckpoint;
}
