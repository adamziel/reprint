<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\Port\FilesSyncRunStore;
use Reprint\Importer\ImportClient;

final class ImportClientFilesSyncRunStore implements FilesSyncRunStore
{
    private ImportClient $client;

    public function __construct(ImportClient $client)
    {
        $this->client = $client;
    }

    public function current_command(): ?string
    {
        return $this->client->current_command();
    }

    public function current_status(): ?string
    {
        return $this->client->current_run_status();
    }

    public function record_command_status(string $command, ?string $status): void
    {
        $this->client->record_command_status($command, $status);
    }
}
