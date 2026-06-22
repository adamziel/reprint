<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\Port\VolatileFileReporter;
use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Observability\MachineEventEmitter;
use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\Session\VolatileFileTracker;

final class VolatileFileSummaryReporter implements VolatileFileReporter
{
    private VolatileFileTracker $tracker;
    private AuditLogger $audit;
    private ImportOutput $output;
    private MachineEventEmitter $machine_events;

    public function __construct(
        VolatileFileTracker $tracker,
        AuditLogger $audit,
        ImportOutput $output,
        MachineEventEmitter $machine_events
    ) {
        $this->tracker = $tracker;
        $this->audit = $audit;
        $this->output = $output;
        $this->machine_events = $machine_events;
    }

    public function report(): void
    {
        $files = $this->tracker->load();
        if (empty($files)) {
            return;
        }

        $count = count($files);
        $this->audit->record(
            sprintf("VOLATILE SUMMARY | %d file(s) changed during sync", $count),
            true,
        );
        $this->output->show_lifecycle_line(
            "{$count} file(s) changed during sync and need re-syncing (run files-pull again):\n",
        );

        foreach ($files as $path => $changes) {
            $suffix = $changes >= 3
                ? " (changed {$changes} times - may be too volatile to sync)"
                : " (changed {$changes} time" . ($changes > 1 ? "s" : "") . ")";
            $this->audit->record("  VOLATILE FILE | path={$path} | count={$changes}");
            $this->output->show_lifecycle_line("  {$path}{$suffix}\n");
        }

        $this->machine_events->emit(
            [
                "type" => "volatile_files",
                "files" => $files,
                "count" => $count,
                "message" => "{$count} file(s) changed during sync and need re-syncing (run files-pull again)",
            ],
            true,
        );
    }
}
