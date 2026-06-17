<?php

namespace Reprint\Importer\Pull\Command;

use Reprint\Importer\Pull\Pull;

final class FilesPullStageCommand extends PullStageCommand
{
    public function name(): string
    {
        return 'files-pull';
    }

    public function label(): string
    {
        return 'Pulling files';
    }

    public function execute(Pull $pull, array $options): void
    {
        $pull->run_until_complete(function () use ($pull) {
            $pull->client()->run_files_sync();
        });

        $skipped_pending =
            $options['filter'] === 'essential-files' &&
            $pull->client()->has_skipped_files_pending();
        $pull->client()->set_pull_files_state($options['filter'], $skipped_pending);
        $count = $pull->client()->index_count();
        $summary = $count > 0 ? number_format($count) . " files" : null;
        if ($skipped_pending) {
            $summary = $summary !== null
                ? $summary . ", deferred files pending"
                : "deferred files pending";
        }

        $pull->print_done($this->name(), $summary);
    }
}
