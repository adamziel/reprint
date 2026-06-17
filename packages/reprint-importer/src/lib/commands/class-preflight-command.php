<?php

namespace Reprint\Importer\Command;

use Reprint\Importer\ImportClient;

final class PreflightCommand extends ImportCommand
{
    public function execute(ImportClient $client, array $options): void
    {
        $client->run_preflight();
        $client->run_preflight_report();
    }
}
