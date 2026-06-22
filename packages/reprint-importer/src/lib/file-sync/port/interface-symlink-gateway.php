<?php

namespace Reprint\Importer\FileSync\Port;

use Reprint\Importer\FileSync\FilesPullCheckpoint;

interface SymlinkGateway
{
    public function discover_targets(FilesPullCheckpoint $checkpoint): void;

    public function recreate_intermediate_symlinks(): void;
}
