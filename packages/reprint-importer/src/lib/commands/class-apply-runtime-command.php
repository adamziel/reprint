<?php

namespace Reprint\Importer\Command;

use Reprint\Importer\ImportClient;

final class ApplyRuntimeCommand extends ImportCommand
{
    public function execute(ImportClient $client, array $options): ?ImportCommandResult
    {
        $client->run_apply_runtime($options);
        return null;
    }
}
