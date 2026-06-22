<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Port\SymlinkGateway;
use Reprint\Importer\ImportClient;

final class ImportClientSymlinkGateway implements SymlinkGateway
{
    private ImportClient $client;

    public function __construct(ImportClient $client)
    {
        $this->client = $client;
    }

    public function discover_targets(FilesPullCheckpoint $checkpoint): void
    {
        $this->client->discover_symlink_targets($checkpoint);
    }

    public function recreate_intermediate_symlinks(): void
    {
        $this->client->recreate_intermediate_symlinks();
    }
}
