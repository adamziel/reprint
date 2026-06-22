<?php

namespace Reprint\Importer\Command;


final class PullCommand extends ImportCommand
{
    public function supports_abort(): bool
    {
        return true;
    }

    public function abort(ImportRuntime $client, string $command): void
    {
        $client->abort_pull();
    }

    public function execute(ImportRuntime $client, array $options): ?ImportCommandResult
    {
        $client->run_pull($options);
        return null;
    }
}
