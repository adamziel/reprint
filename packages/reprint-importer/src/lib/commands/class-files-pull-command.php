<?php

namespace Reprint\Importer\Command;


final class FilesPullCommand extends ImportCommand
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
        $client->run_files_sync();
        return null;
    }
}
