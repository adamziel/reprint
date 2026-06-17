<?php

namespace Reprint\Importer\Pull\Command;

use Reprint\Importer\Pull\Pull;

abstract class PullStageCommand
{
    abstract public function name(): string;

    abstract public function label(): string;

    abstract public function execute(Pull $pull, array $options): void;
}
