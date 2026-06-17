<?php

namespace Reprint\Importer\Command;

use Reprint\Importer\ImportClient;

final class FilesStatsCommand extends ImportCommand
{
    public function execute(ImportClient $client, array $options): void
    {
        $client->run_files_stats();
    }
}
