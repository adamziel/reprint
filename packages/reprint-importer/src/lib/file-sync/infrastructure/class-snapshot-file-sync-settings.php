<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\Port\FileSyncSettings;

final class SnapshotFileSyncSettings implements FileSyncSettings
{
    private string $filter;
    private bool $follow_symlinks;
    private string $fs_root_nonempty_behavior;

    public function __construct(
        string $filter,
        bool $follow_symlinks,
        string $fs_root_nonempty_behavior
    ) {
        $this->filter = $filter;
        $this->follow_symlinks = $follow_symlinks;
        $this->fs_root_nonempty_behavior = $fs_root_nonempty_behavior;
    }

    public function current_filter(): string
    {
        return $this->filter;
    }

    public function follow_symlinks(): bool
    {
        return $this->follow_symlinks;
    }

    public function fs_root_nonempty_behavior(): string
    {
        return $this->fs_root_nonempty_behavior;
    }
}
