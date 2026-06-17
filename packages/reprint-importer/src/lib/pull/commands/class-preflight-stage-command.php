<?php

namespace Reprint\Importer\Pull\Command;

use Reprint\Importer\Pull\Pull;

final class PreflightStageCommand extends PullStageCommand
{
    public function name(): string
    {
        return 'preflight';
    }

    public function label(): string
    {
        return 'Connecting';
    }

    public function execute(Pull $pull, array $options): void
    {
        $pull->client()->run_preflight();
        if ($pull->check_plugin_installed()) {
            $pull->client()->exit_code = 1;
            return;
        }

        $pull->print_done($this->name(), $pull->preflight_summary());
    }
}
