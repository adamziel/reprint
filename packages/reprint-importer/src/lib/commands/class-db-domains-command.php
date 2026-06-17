<?php

namespace Reprint\Importer\Command;

use Reprint\Importer\ImportClient;

final class DbDomainsCommand extends ImportCommand
{
    public function execute(ImportClient $client, array $options): void
    {
        $client->run_db_domains();
    }
}
