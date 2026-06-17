<?php

namespace Reprint\Importer\Pull\Command;

use Reprint\Importer\Pull\Pull;

final class FlatDocrootStageCommand extends PullStageCommand
{
    public function name(): string
    {
        return 'flat-docroot';
    }

    public function label(): string
    {
        return 'Flattening layout';
    }

    public function execute(Pull $pull, array $options): void
    {
        $pull->client()->run_flat_document_root($options);
        $pull->print_done($this->name());
    }
}
