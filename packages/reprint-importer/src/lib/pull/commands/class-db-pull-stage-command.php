<?php

namespace Reprint\Importer\Pull\Command;

use Reprint\Importer\Pull\Pull;

final class DbPullStageCommand extends PullStageCommand
{
    public function name(): string
    {
        return 'db-pull';
    }

    public function label(): string
    {
        return 'Pulling database';
    }

    public function execute(Pull $pull, array $options): void
    {
        $pull->run_resumable_stage($this->name(), $options);

        $sql_file = $pull->runtime()->paths()->sql_file();
        $size = file_exists($sql_file) ? $pull->format_bytes((int) filesize($sql_file)) : null;
        $pull->print_done($this->name(), $size);
    }
}
