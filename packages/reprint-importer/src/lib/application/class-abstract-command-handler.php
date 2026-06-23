<?php

namespace Reprint\Importer\Application;

use Reprint\Importer\Application\Result\ImportCommandResult;

abstract class AbstractCommandHandler implements ImportCommandHandler
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

    public function abort(ImportContext $context, ImportServices $services, string $command): void
    {
        $context->abort_command($command);
    }

    abstract public function execute(
        ImportContext $context,
        ImportServices $services,
        array $options
    ): ?ImportCommandResult;
}
