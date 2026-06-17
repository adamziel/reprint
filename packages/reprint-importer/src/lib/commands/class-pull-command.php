<?php

namespace Reprint\Importer\Command;

use Reprint\Importer\ImportClient;

final class PullCommand extends ImportCommand
{
    public function supports_abort(): bool
    {
        return true;
    }

    public function abort(ImportClient $client, string $command): void
    {
        $client->abort_pull();
    }

    public function execute(ImportClient $client, array $options): ?ImportCommandResult
    {
        $client->run_pull($options);
        return null;
    }
}
