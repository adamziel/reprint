<?php

namespace Reprint\Importer\Pull\Command;

use Reprint\Importer\Pull\Pull;

final class StartStageCommand extends PullStageCommand
{
    public function name(): string
    {
        return 'start';
    }

    public function label(): string
    {
        return 'Starting server';
    }

    public function execute(Pull $pull, array $options): void
    {
        $pull->start_server($options);
    }
}
