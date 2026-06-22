<?php

namespace Reprint\Importer\FileSync\Port;

use Reprint\Importer\FileSync\FilesPullCheckpoint;

interface FilesPullCheckpointStore
{
    public function get(): FilesPullCheckpoint;

    public function save(FilesPullCheckpoint $checkpoint): void;
}
