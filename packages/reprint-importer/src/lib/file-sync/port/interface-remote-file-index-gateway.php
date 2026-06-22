<?php

namespace Reprint\Importer\FileSync\Port;

use Reprint\Importer\FileSync\FilesPullCheckpoint;

interface RemoteFileIndexGateway
{
    public function download(FilesPullCheckpoint $checkpoint, ?string $list_dir_override = null): bool;
}
