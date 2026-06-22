<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\Port\FileSyncSettings;
use Reprint\Importer\ImportClient;

final class ImportClientFileSyncSettings implements FileSyncSettings
{
    private ImportClient $client;

    public function __construct(ImportClient $client)
    {
        $this->client = $client;
    }

    public function current_filter(): string
    {
        return $this->client->current_filter();
    }

    public function follow_symlinks(): bool
    {
        return $this->client->follow_symlinks();
    }

    public function fs_root_nonempty_behavior(): string
    {
        return $this->client->fs_root_nonempty_behavior();
    }
}
