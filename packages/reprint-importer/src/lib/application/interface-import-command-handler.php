<?php

namespace Reprint\Importer\Application;

use Reprint\Importer\Command\ImportCommandResult;

interface ImportCommandHandler
{
    public function requires_preflight(): bool;

    public function supports_abort(): bool;

    public function emits_final_status(): bool;

    public function abort(ImportContext $context, ImportServices $services, string $command): void;

    public function execute(
        ImportContext $context,
        ImportServices $services,
        array $options
    ): ?ImportCommandResult;
}
