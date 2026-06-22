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
        $pull->run_runtime_stage($this->name(), $options);
        if ($pull->check_plugin_installed()) {
            $pull->runtime()->set_exit_code(1);
            return;
        }

        $pull->print_done($this->name(), $pull->preflight_summary());
    }
}
