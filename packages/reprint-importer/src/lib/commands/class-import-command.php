<?php

namespace Reprint\Importer\Command;

use Reprint\Importer\ImportClient;

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

    public function abort(ImportClient $client, string $command): void
    {
        $client->abort_command($command);
    }

    abstract public function execute(ImportClient $client, array $options): ?ImportCommandResult;
}
