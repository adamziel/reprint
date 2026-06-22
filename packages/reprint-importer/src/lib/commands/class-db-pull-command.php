<?php

namespace Reprint\Importer\Command;


final class DbPullCommand extends ImportCommand
{
    public function requires_preflight(): bool
    {
        return true;
    }

    public function supports_abort(): bool
    {
        return true;
    }

    public function emits_final_status(): bool
    {
        return true;
    }

    public function execute(ImportRuntime $client, array $options): ?ImportCommandResult
    {
        $client->run_db_sync();
        return null;
    }
}
