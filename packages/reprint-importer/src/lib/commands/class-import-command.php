<?php

namespace Reprint\Importer\Command;


abstract class ImportCommand
{
    public function requires_preflight(): bool
    {
        return false;
    }

    public function supports_abort(): bool
    {
        return false;
    }

    public function emits_final_status(): bool
    {
        return false;
    }

    public function abort(ImportRuntime $client, string $command): void
    {
        $client->abort_command($command);
    }

    abstract public function execute(ImportRuntime $client, array $options): ?ImportCommandResult;
}
