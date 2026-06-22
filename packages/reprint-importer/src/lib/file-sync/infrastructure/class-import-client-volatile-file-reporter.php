<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\Port\VolatileFileReporter;
use Reprint\Importer\ImportClient;

final class ImportClientVolatileFileReporter implements VolatileFileReporter
{
    private ImportClient $client;

    public function __construct(ImportClient $client)
    {
        $this->client = $client;
    }

    public function report(): void
    {
        $this->client->report_volatile_files();
    }
}
