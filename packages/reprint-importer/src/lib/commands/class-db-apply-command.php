<?php

namespace Reprint\Importer\Command;

use Reprint\Importer\ImportClient;

final class DbApplyCommand extends ImportCommand
{
    public function supports_abort(): bool
    {
        return true;
    }

    public function emits_final_status(): bool
    {
        return true;
    }

    public function execute(ImportClient $client, array $options): void
    {
        $client->run_db_apply($options);
    }
}
