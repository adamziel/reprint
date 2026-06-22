<?php

namespace Reprint\Importer\Pull\Command;

use Reprint\Importer\Pull\Pull;

final class DbApplyStageCommand extends PullStageCommand
{
    public function name(): string
    {
        return 'db-apply';
    }

    public function label(): string
    {
        return 'Importing database';
    }

    public function execute(Pull $pull, array $options): void
    {
        $pull->run_until_complete(function () use ($pull, $options) {
            $pull->client()->run_db_apply($options);
        });

        $statements = $pull->client()->db_apply_checkpoint()->statements_executed;
        $summary = $statements > 0 ? number_format($statements) . " statements" : null;
        $pull->print_done($this->name(), $summary);
    }
}
