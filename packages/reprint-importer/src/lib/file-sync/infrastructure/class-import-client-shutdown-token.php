<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\ImportClient;

final class ImportClientShutdownToken implements ShutdownToken
{
    private ImportClient $client;

    public function __construct(ImportClient $client)
    {
        $this->client = $client;
    }

    public function is_shutdown_requested(): bool
    {
        return $this->client->shutdown_requested();
    }
}
