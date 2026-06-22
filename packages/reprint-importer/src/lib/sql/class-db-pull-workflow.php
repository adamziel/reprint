<?php

namespace Reprint\Importer\Sql;

use Reprint\Importer\ImportClient;
use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\Protocol\CurlTimeoutException;
use Reprint\Importer\Protocol\StreamingContext;
use RuntimeException;

final class DbPullWorkflow
{
    private ImportClient $client;
    private string $state_dir;
    private string $audit_log;
    private string $sql_output_mode;
    private ?string $mysql_database;
    private ImportOutput $output;

    public function __construct(
        ImportClient $client,
        string $state_dir,
        string $audit_log,
        string $sql_output_mode,
        ?string $mysql_database,
        ImportOutput $output
    ) {
        $this->client = $client;
        $this->state_dir = $state_dir;
        $this->audit_log = $audit_log;
        $this->sql_output_mode = $sql_output_mode;
        $this->mysql_database = $mysql_database;
        $this->output = $output;
    }

    public function run(DbPullCheckpoint $checkpoint): DbPullCheckpoint
    {
        $sql_file = $this->state_dir . "/db.sql";
        $current_status = $checkpoint->status;
        $has_progress = $current_status === "in_progress";

        if ($current_status === "complete") {
            $this->assert_can_start_completed_sync($sql_file);
        }

        if ($has_progress) {
            $this->report_resume($checkpoint);
        } else {
            $this->start_fresh($checkpoint);
        }

        $this->save_checkpoint($checkpoint);

        $stage = $checkpoint->stage;
        if ($stage === "db-index") {
            $this->output_progress([
                "status" => "starting",
                "phase" => "db-index",
                "message" => "Downloading table metadata",
            ]);

            $checkpoint = $this->download_db_index($checkpoint);
            if ($checkpoint->status === "partial") {
                return $checkpoint;
            }

            $tables = $checkpoint->db_index->tables;
            $this->audit(
                sprintf("db-pull db-index stage complete: %d tables", $tables),
            );

            $checkpoint->stage = "sql";
            $checkpoint->cursor = null;
            $this->save_checkpoint($checkpoint);
        }

        $this->output_progress([
            "status" => "starting",
            "phase" => "sql",
            "message" => "Downloading SQL dump",
        ]);

        $checkpoint = $this->client->download_sql_stage($checkpoint);
        if ($checkpoint->status === "partial") {
            return $checkpoint;
        }

        $checkpoint->status = "complete";
        $this->save_checkpoint($checkpoint);

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

        return $checkpoint;
    }

    public function download_db_index(
        DbPullCheckpoint $checkpoint,
        ?string $tables_file = null
    ): DbPullCheckpoint
    {
        $tables_file = $tables_file ?? $this->state_dir . "/db-tables.jsonl";
        $cursor = $checkpoint->cursor;
        $complete = false;

        $tables_written = $checkpoint->db_index->tables;
        $rows_estimated = $checkpoint->db_index->rows_estimated;
        $bytes_written = $checkpoint->db_index->bytes;

        if ($bytes_written > 0 && file_exists($tables_file)) {
            $actual_size = filesize($tables_file);
            if ($actual_size > $bytes_written) {
                $this->audit(
                    sprintf(
                        "CRASH RECOVERY | Truncating db-tables.jsonl from %d to %d bytes",
                        $actual_size,
                        $bytes_written,
                    ),
                    true,
                );
                $truncate_handle = fopen($tables_file, "r+");
                if ($truncate_handle) {
                    ftruncate($truncate_handle, $bytes_written);
                    fclose($truncate_handle);
                }
            }
        }

        $handle = fopen($tables_file, $cursor ? "a" : "w");
        if (!$handle) {
            throw new RuntimeException("Cannot open table stats file: {$tables_file}");
        }

        try {
            while (!$complete) {
                if ($this->client->shutdown_requested()) {
                    throw new RuntimeException("Shutdown requested");
                }

                $context = new StreamingContext();
                $response_handler = new DbIndexResponseHandler(
                    $handle,
                    $cursor,
                    $context,
                    $tables_written,
                    $rows_estimated,
                    $bytes_written,
                );
                $context->on_chunk = [$response_handler, "handle"];

                $cursor_before = $cursor;
                $request_start = microtime(true);
                try {
                    $this->client->stream_export_endpoint(
                        "db_index",
                        $cursor,
                        $context,
                        null,
                        ["tables_per_batch" => 1000],
                    );
                } catch (CurlTimeoutException $e) {
                    $cursor = $response_handler->cursor();
                    $complete = $response_handler->complete();
                    $tables_written = $response_handler->tables_written();
                    $rows_estimated = $response_handler->rows_estimated();
                    $bytes_written = $response_handler->bytes_written();

                    $this->client->assert_can_retry_db_pull_timeout(
                        $checkpoint,
                        "db_index",
                        $cursor_before,
                        $cursor,
                    );
                    fflush($handle);
                    $checkpoint->cursor = $cursor;
                    $checkpoint->db_index = $this->state_entry(
                        $tables_file,
                        $tables_written,
                        $rows_estimated,
                        $bytes_written,
                    );
                    $checkpoint->status = "partial";
                    $this->save_checkpoint($checkpoint);
                    return $checkpoint;
                }

                $cursor = $response_handler->cursor();
                $complete = $response_handler->complete();
                $tables_written = $response_handler->tables_written();
                $rows_estimated = $response_handler->rows_estimated();
                $bytes_written = $response_handler->bytes_written();

                $checkpoint->consecutive_timeouts = 0;
                $wall_time = microtime(true) - $request_start;
                $this->client->finalize_stream_request(
                    "db_index",
                    $wall_time,
                    $context->response_stats ?? [],
                );

                fflush($handle);
                $checkpoint->cursor = $cursor;
                $checkpoint->db_index = $this->state_entry(
                    $tables_file,
                    $tables_written,
                    $rows_estimated,
                    $bytes_written,
                );
                $this->save_checkpoint($checkpoint);
            }
        } finally {
            fclose($handle);
        }

        return $checkpoint;
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

    private function report_resume(DbPullCheckpoint $checkpoint): void
    {
        $stage = $checkpoint->stage;
        $this->audit(
            sprintf(
                "RESUME db-pull | stage=%s | cursor=%s",
                $stage,
                $checkpoint->cursor !== null
                    ? substr($checkpoint->cursor, 0, 20) . "..."
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

    private function start_fresh(DbPullCheckpoint $checkpoint): void
    {
        $checkpoint->reset();
        $this->save_checkpoint($checkpoint);

        $this->audit("START db-pull", true);

        $this->output->show_lifecycle_line("Starting db-pull\n");
        $this->output_progress([
            "type" => "lifecycle",
            "event" => "starting",
            "command" => "db-pull",
            "message" => "Starting db-pull",
        ], true);
    }

    private function save_checkpoint(DbPullCheckpoint $checkpoint): void
    {
        $this->client->save_db_pull_checkpoint($checkpoint);
    }

    private function audit(string $message, bool $to_console = true): void
    {
        $this->client->audit_log($message, $to_console);
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function output_progress(array $progress, bool $force = false): void
    {
        $this->client->output_progress($progress, $force);
    }

    private function state_entry(
        string $tables_file,
        int $tables_written,
        int $rows_estimated,
        int $bytes_written
    ): DbIndexCheckpoint {
        return new DbIndexCheckpoint(
            $tables_file,
            $tables_written,
            $rows_estimated,
            $bytes_written,
            time(),
        );
    }
}
