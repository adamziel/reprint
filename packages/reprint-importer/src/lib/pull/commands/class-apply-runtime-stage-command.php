<?php

namespace Reprint\Importer\Pull\Command;

use Reprint\Importer\Pull\Pull;

final class ApplyRuntimeStageCommand extends PullStageCommand
{
    public function name(): string
    {
        return 'apply-runtime';
    }

    public function label(): string
    {
        return 'Preparing runtime';
    }

    public function execute(Pull $pull, array $options): void
    {
        $pull->client()->run_apply_runtime($options);
        $pull->print_done($this->name());
    }
}
