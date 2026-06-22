<?php

namespace Reprint\Importer\Command;


final class FlatDocrootCommand extends ImportCommand
{
    public function execute(ImportRuntime $client, array $options): ?ImportCommandResult
    {
        $client->run_flat_document_root($options);
        return null;
    }
}
