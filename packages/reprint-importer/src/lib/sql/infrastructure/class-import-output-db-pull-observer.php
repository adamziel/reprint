<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Observability\MachineEventEmitter;
use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\Sql\DbPullConfiguration;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\Port\DbPullObserver;

final class ImportOutputDbPullObserver implements DbPullObserver
{
    private ImportOutput $output;
    private MachineEventEmitter $machine_events;

    public function __construct(ImportOutput $output, MachineEventEmitter $machine_events)
    {
        $this->output = $output;
        $this->machine_events = $machine_events;
    }

    public function on_starting(): void
    {
        $this->output->show_lifecycle_line("Starting db-pull\n");
        $this->machine_events->emit([
            "type" => "lifecycle",
            "event" => "starting",
            "command" => "db-pull",
            "message" => "Starting db-pull",
        ], true);
    }

    public function on_resuming(DbPullCheckpoint $checkpoint): void
    {
        $stage = $checkpoint->stage;
        $this->output->show_lifecycle_line("Resuming db-pull (stage: {$stage})\n");
        $this->machine_events->emit([
            "type" => "lifecycle",
            "event" => "resuming",
            "command" => "db-pull",
            "stage" => $stage,
            "message" => "Resuming db-pull (stage: {$stage})",
        ], true);
    }

    public function on_stage_starting(string $phase, string $message): void
    {
        $this->machine_events->emit([
            "status" => "starting",
            "phase" => $phase,
            "message" => $message,
        ], true);
    }

    public function on_complete(DbPullConfiguration $config): void
    {
        $mode = $config->sql_output_mode();
        $this->output->show_lifecycle_line("db-pull complete\n");
        if ($mode === "file") {
            $this->output->show_lifecycle_line("SQL file: {$config->sql_file()}\n");
        } elseif ($mode === "stdout") {
            $this->output->show_lifecycle_line("SQL written to stdout\n");
        } elseif ($mode === "mysql") {
            $this->output->show_lifecycle_line("SQL imported into {$config->mysql_database()}\n");
        }
        $this->output->show_lifecycle_line("Audit log: {$config->audit_log()}\n");

        $complete = [
            "type" => "lifecycle",
            "event" => "complete",
            "command" => "db-pull",
            "sql_output_mode" => $mode,
            "audit_log" => $config->audit_log(),
            "message" => "db-pull complete",
        ];
        if ($mode === "file") {
            $complete["sql_file"] = $config->sql_file();
        }
        $this->machine_events->emit($complete, true);
    }
}
