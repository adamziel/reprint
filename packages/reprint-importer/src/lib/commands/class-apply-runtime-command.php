<?php

namespace Reprint\Importer\Command;


final class ApplyRuntimeCommand extends ImportCommand
{
    public function execute(ImportRuntime $client, array $options): ?ImportCommandResult
    {
        $client->run_apply_runtime($options);
        return null;
    }
}
