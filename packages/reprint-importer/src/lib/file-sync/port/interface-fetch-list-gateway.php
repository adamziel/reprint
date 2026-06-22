<?php

namespace Reprint\Importer\FileSync\Port;

use Reprint\Importer\FileSync\FilesPullCheckpoint;

interface FetchListGateway
{
    public function build(FilesPullCheckpoint $checkpoint): bool;
}
