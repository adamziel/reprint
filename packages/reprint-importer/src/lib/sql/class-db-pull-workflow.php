<?php

namespace Reprint\Importer\Sql;

use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\Session\ImportStateSchema;
use RuntimeException;

final class DbPullWorkflow
{
    private string $state_dir;
    private string $audit_log;
    private string $sql_output_mode;
    private ?string $mysql_database;
    private ImportOutput $output;

    /** @var callable */
    private $save_state;

    /** @var callable */
    private $audit;

    /** @var callable */
    private $output_progress;

    /** @var callable */
    private $download_db_index;

    /** @var callable */
    private $download_sql;

    public function __construct(
        string $state_dir,
        string $audit_log,
        string $sql_output_mode,
        ?string $mysql_database,
        ImportOutput $output,
        callable $save_state,
        callable $audit,
        callable $output_progress,
        callable $download_db_index,
        callable $download_sql
    ) {
        $this->state_dir = $state_dir;
        $this->audit_log = $audit_log;
        $this->sql_output_mode = $sql_output_mode;
        $this->mysql_database = $mysql_database;
        $this->output = $output;
        $this->save_state = $save_state;
        $this->audit = $audit;
        $this->output_progress = $output_progress;
        $this->download_db_index = $download_db_index;
        $this->download_sql = $download_sql;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function run(array &$state): void
    {
        $state_command = $state["command"] ?? null;
        $sql_file = $this->state_dir . "/db.sql";

        $has_progress =
            $state_command === "db-pull" &&
            ($state["status"] ?? null) === "in_progress";
        $current_status =
            $state_command === "db-pull"
                ? $state["status"] ?? null
                : null;

        if ($current_status === "complete") {
            $this->assert_can_start_completed_sync($sql_file);
        }

        if ($has_progress) {
            $this->report_resume($state);
        } else {
            $this->start_fresh($state);
        }

        $state["command"] = "db-pull";
        $this->save_state($state);

        $stage = $state["stage"] ?? "db-index";
        if ($stage === "db-index") {
            $this->output_progress([
                "status" => "starting",
                "phase" => "db-index",
                "message" => "Downloading table metadata",
            ]);

            $this->download_db_index($state);
            if (($state["status"] ?? null) === "partial") {
                return;
            }

            $tables = (int) ($state["db_index"]["tables"] ?? 0);
            $this->audit(
                sprintf("db-pull db-index stage complete: %d tables", $tables),
            );

            $state["stage"] = "sql";
            $state["cursor"] = null;
            $this->save_state($state);
        }

        $this->output_progress([
            "status" => "starting",
            "phase" => "sql",
            "message" => "Downloading SQL dump",
        ]);

        $this->download_sql($state);
        if (($state["status"] ?? null) === "partial") {
            return;
        }

        $state["status"] = "complete";
        $this->save_state($state);

        $this->audit("db-pull complete", true);

        $this->output->show_lifecycle_line("db-pull complete\n");
        if ($this->sql_output_mode === "file") {
            $this->output->show_lifecycle_line("SQL file: {$sql_file}\n");
        } elseif ($this->sql_output_mode === "stdout") {
            $this->output->show_lifecycle_line("SQL written to stdout\n");
        } elseif ($this->sql_output_mode === "mysql") {
            $this->output->show_lifecycle_line("SQL imported into {$this->mysql_database}\n");
        }
        $this->output->show_lifecycle_line("Audit log: {$this->audit_log}\n");

        $complete = [
            "type" => "lifecycle",
            "event" => "complete",
            "command" => "db-pull",
            "sql_output_mode" => $this->sql_output_mode,
            "audit_log" => $this->audit_log,
            "message" => "db-pull complete",
        ];
        if ($this->sql_output_mode === "file") {
            $complete["sql_file"] = $sql_file;
        }
        $this->output_progress($complete, true);
    }

    private function assert_can_start_completed_sync(string $sql_file): void
    {
        if ($this->sql_output_mode !== "file") {
            throw new RuntimeException(
                "db-pull already completed. Use --abort flag to start over.",
            );
        }

        if (file_exists($sql_file)) {
            throw new RuntimeException(
                "db-pull already completed and db.sql exists. Use --abort flag to start over.",
            );
        }

        throw new RuntimeException(
            "db-pull marked complete but db.sql is missing. Use --abort flag to re-sync.",
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    private function report_resume(array $state): void
    {
        $stage = $state["stage"] ?? "db-index";
        $this->audit(
            sprintf(
                "RESUME db-pull | stage=%s | cursor=%s",
                $stage,
                !empty($state["cursor"])
                    ? substr($state["cursor"], 0, 20) . "..."
                    : "none",
            ),
            true,
        );

        $this->output->show_lifecycle_line("Resuming db-pull (stage: {$stage})\n");
        $this->output_progress([
            "type" => "lifecycle",
            "event" => "resuming",
            "command" => "db-pull",
            "stage" => $stage,
            "message" => "Resuming db-pull (stage: {$stage})",
        ], true);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function start_fresh(array &$state): void
    {
        $default_state = ImportStateSchema::default_state();
        $state["command"] = "db-pull";
        $state["status"] = "in_progress";
        $state["cursor"] = null;
        $state["stage"] = "db-index";
        $state["diff"] = $default_state["diff"];
        $state["db_index"] = $default_state["db_index"];
        $this->save_state($state);

        $this->audit("START db-pull", true);

        $this->output->show_lifecycle_line("Starting db-pull\n");
        $this->output_progress([
            "type" => "lifecycle",
            "event" => "starting",
            "command" => "db-pull",
            "message" => "Starting db-pull",
        ], true);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save_state(array $state): void
    {
        ($this->save_state)($state);
    }

    private function audit(string $message, bool $to_console = true): void
    {
        ($this->audit)($message, $to_console);
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function output_progress(array $progress, bool $force = false): void
    {
        ($this->output_progress)($progress, $force);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function download_db_index(array &$state): void
    {
        ($this->download_db_index)($state);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function download_sql(array &$state): void
    {
        ($this->download_sql)($state);
    }
}
