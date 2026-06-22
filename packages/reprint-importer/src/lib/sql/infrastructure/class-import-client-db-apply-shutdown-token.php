<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\ImportClient;
use Reprint\Importer\Sql\Port\DbApplyShutdownToken;

final class ImportClientDbApplyShutdownToken implements DbApplyShutdownToken
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
