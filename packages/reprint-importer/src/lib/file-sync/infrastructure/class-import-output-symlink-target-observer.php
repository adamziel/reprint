<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\Port\SymlinkTargetObserver;
use Reprint\Importer\Observability\MachineEventEmitter;
use Reprint\Importer\Output\ImportOutput;

final class ImportOutputSymlinkTargetObserver implements SymlinkTargetObserver
{
    private ImportOutput $output;
    private MachineEventEmitter $machine_events;

    public function __construct(ImportOutput $output, MachineEventEmitter $machine_events)
    {
        $this->output = $output;
        $this->machine_events = $machine_events;
    }

    public function on_following_directory(string $directory): void
    {
        $this->output->show_lifecycle_line("Following symlink target: {$directory}\n");
        $this->machine_events->emit([
            "type" => "symlink_follow",
            "directory" => $directory,
            "message" => "Following symlink target: {$directory}",
        ], true);
    }

    public function on_rejected_directory(string $directory): void
    {
        $this->output->show_lifecycle_line("  Skipped (server rejected): {$directory}\n");
        $this->machine_events->emit([
            "type" => "symlink_follow_rejected",
            "directory" => $directory,
            "message" => "Skipped (server rejected): {$directory}",
        ], true);
    }
}
