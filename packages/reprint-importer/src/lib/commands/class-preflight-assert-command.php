<?php

namespace Reprint\Importer\Command;

use Reprint\Importer\ImportClient;

final class PreflightAssertCommand extends ImportCommand
{
    public function requires_preflight(): bool
    {
        return true;
    }

    public function execute(ImportClient $client, array $options): void
    {
        $client->run_preflight_assert();
    }
}
