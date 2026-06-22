<?php

namespace Reprint\Importer\FileSync\Port;

interface FileSyncSettings
{
    public function current_filter(): string;

    public function follow_symlinks(): bool;

    public function fs_root_nonempty_behavior(): string;
}
