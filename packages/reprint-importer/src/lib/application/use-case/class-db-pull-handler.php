<?php

namespace Reprint\Importer\Application\UseCase;

use Reprint\Importer\Application\AbstractCommandHandler;
use Reprint\Importer\Application\ImportContext;
use Reprint\Importer\Application\ImportServices;
use Reprint\Importer\Command\ImportCommandResult;

final class DbPullHandler extends AbstractCommandHandler
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

    public function execute(
        ImportContext $context,
        ImportServices $services,
        array $options
    ): ?ImportCommandResult {
        $checkpoint = $services->db_pull_workflow()->run($context->db_pull_checkpoint());
        $context->record_command_status("db-pull", $checkpoint->status);
        return null;
    }
}
