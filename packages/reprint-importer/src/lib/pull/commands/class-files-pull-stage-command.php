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
        $pull->run_resumable_stage($this->name(), $options);

        $skipped_pending =
            $options['filter'] === 'essential-files' &&
            $pull->runtime()->has_skipped_files_pending();
        $pull->record_files_state($options['filter'], $skipped_pending);
        $count = $pull->runtime()->index_count();
        $summary = $count > 0 ? number_format($count) . " entries" : null;
        if ($skipped_pending) {
            $summary = $summary !== null
                ? $summary . ", deferred files pending"
                : "deferred files pending";
        }

        $pull->print_done($this->name(), $summary);
    }
}
