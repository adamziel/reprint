<?php

namespace Reprint\Importer\Application\UseCase;

use RuntimeException;
use Reprint\Importer\Application\AbstractCommandHandler;
use Reprint\Importer\Application\ImportContext;
use Reprint\Importer\Application\ImportServices;
use Reprint\Importer\Command\ImportCommandResult;
use Reprint\Importer\Sql\DbPullCheckpoint;

final class DbIndexHandler extends AbstractCommandHandler
{
    public function requires_preflight(): bool
    {
        return true;
    }

    public function supports_abort(): bool
    {
        return true;
    }

    public function emits_final_status(): bool
    {
        return true;
    }

    public function execute(
        ImportContext $context,
        ImportServices $services,
        array $options
    ): ?ImportCommandResult {
        $tables_file = $context->paths()->table_stats_file();
        $checkpoint = $context->db_pull_checkpoint();
        $state = $context->state();

        $has_cursor = $state->command === "db-index" && $checkpoint->cursor !== null;
        $current_status = $state->command === "db-index" ? $checkpoint->status : null;
        $tables_exists = file_exists($tables_file);

        if ($current_status === "complete") {
            if ($tables_exists) {
                throw new RuntimeException(
                    "db-index already completed and db-tables.jsonl exists. Use --abort flag to start over.",
                );
            }
            throw new RuntimeException(
                "db-index marked complete but db-tables.jsonl is missing. Use --abort flag to re-run.",
            );
        }

        if (!$has_cursor) {
            $checkpoint = DbPullCheckpoint::fresh();
            $checkpoint->status = "in_progress";
            $checkpoint->stage = "db-index";
            $context->save_db_pull_checkpoint($checkpoint);
            $context->record_command_status("db-index", "in_progress");
            $context->audit_log("START db-index", true);
            $context->output()->show_lifecycle_line("Starting db-index\n");
            $context->output_progress([
                "type" => "lifecycle",
                "event" => "starting",
                "command" => "db-index",
                "message" => "Starting db-index",
            ], true);
        } else {
            $context->audit_log(
                sprintf("RESUME db-index | cursor=%s", substr((string) $checkpoint->cursor, 0, 20) . "..."),
                true,
            );
            $context->output()->show_lifecycle_line("Resuming db-index\n");
            $context->output_progress([
                "type" => "lifecycle",
                "event" => "resuming",
                "command" => "db-index",
                "message" => "Resuming db-index",
            ], true);
        }

        $context->record_command_status("db-index", $checkpoint->status);
        $checkpoint = $services->db_pull_workflow()->download_db_index($checkpoint, $tables_file);
        if ($checkpoint->status === "partial") {
            $context->record_command_status("db-index", "partial");
            return null;
        }

        $checkpoint->status = "complete";
        $context->save_db_pull_checkpoint($checkpoint);
        $context->record_command_status("db-index", "complete");

        $tables = $checkpoint->db_index->tables;
        $context->audit_log(sprintf("db-index complete: %d tables", $tables), true);
        $context->output()->show_lifecycle_line("db-index complete: {$tables} tables\n");
        $context->output()->show_lifecycle_line("Table stats: {$tables_file}\n");
        $context->output()->show_lifecycle_line("Audit log: {$context->paths()->audit_log()}\n");
        $context->output_progress([
            "type" => "lifecycle",
            "event" => "complete",
            "command" => "db-index",
            "tables" => $tables,
            "tables_file" => $tables_file,
            "audit_log" => $context->paths()->audit_log(),
            "message" => "db-index complete: {$tables} tables",
        ], true);

        return null;
    }
}
