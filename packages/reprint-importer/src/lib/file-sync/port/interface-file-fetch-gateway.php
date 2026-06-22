<?php

namespace Reprint\Importer\FileSync\Port;

use Reprint\Importer\FileSync\FilesPullCheckpoint;

interface FileFetchGateway
{
    public function fetch_from_list(
        FilesPullCheckpoint $checkpoint,
        string $list_file,
        string $state_key
    ): bool;
}
