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

        $state = $pull->client()->state;
        $statements = (int) ($state["apply"]["statements_executed"] ?? 0);
        $summary = $statements > 0 ? number_format($statements) . " statements" : null;
        $pull->print_done($this->name(), $summary);
    }
}
