<?php

namespace Reprint\Importer\Command;

use Reprint\Importer\ImportClient;

final class FlatDocrootCommand extends ImportCommand
{
    public function execute(ImportClient $client, array $options): ?ImportCommandResult
    {
        $client->run_flat_document_root($options);
        return null;
    }
}
