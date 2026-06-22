<?php

namespace Reprint\Importer\FileSync\Port;

interface SymlinkTargetObserver
{
    public function on_following_directory(string $directory): void;

    public function on_rejected_directory(string $directory): void;
}
