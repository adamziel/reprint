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
        $pull->run_until_complete(function () use ($pull) {
            $pull->client()->run_db_sync();
        });

        $sql_file = $pull->client()->paths()->sql_file();
        $size = file_exists($sql_file) ? $pull->format_bytes((int) filesize($sql_file)) : null;
        $pull->print_done($this->name(), $size);
    }
}
