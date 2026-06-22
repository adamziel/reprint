<?php

namespace Reprint\Importer\Sql\Port;

use Reprint\Importer\Sql\DbPullConfiguration;
use Reprint\Importer\Sql\DbPullCheckpoint;

interface DbPullObserver
{
    public function on_starting(): void;

    public function on_resuming(DbPullCheckpoint $checkpoint): void;

    public function on_stage_starting(string $phase, string $message): void;

    public function on_complete(DbPullConfiguration $config): void;
}
