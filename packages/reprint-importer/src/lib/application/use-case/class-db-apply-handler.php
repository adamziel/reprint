<?php

namespace Reprint\Importer\Application\UseCase;

use Reprint\Importer\Application\AbstractCommandHandler;
use Reprint\Importer\Application\ImportContext;
use Reprint\Importer\Application\ImportServices;
use Reprint\Importer\Command\ImportCommandResult;

final class DbApplyHandler extends AbstractCommandHandler
{
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
        $checkpoint = $services->db_apply_workflow()->run(
            $context->db_apply_checkpoint(),
            $services->db_apply_source(),
            $options,
        );
        $context->record_command_status("db-apply", $checkpoint->status);
        return null;
    }
}
