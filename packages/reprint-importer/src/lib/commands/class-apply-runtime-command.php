<?php

namespace Reprint\Importer\Command;

use Reprint\Importer\ImportClient;

final class ApplyRuntimeCommand extends ImportCommand
{
    public function execute(ImportClient $client, array $options): void
    {
        $client->run_apply_runtime($options);
    }
}
