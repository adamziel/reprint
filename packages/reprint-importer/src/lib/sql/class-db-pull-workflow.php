<?php

namespace Reprint\Importer\Sql;

use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Sql\Port\DbIndexDownloader;
use Reprint\Importer\Sql\Port\DbPullCheckpointStore;
use Reprint\Importer\Sql\Port\DbPullObserver;
use Reprint\Importer\Sql\Port\SqlDumpDownloader;
use RuntimeException;

final class DbPullWorkflow
{
    private DbPullConfiguration $config;
    private DbPullCheckpointStore $checkpoints;
    private DbIndexDownloader $db_index_downloader;
    private SqlDumpDownloader $sql_downloader;
    private DbPullObserver $observer;
    private AuditLogger $audit;

    public function __construct(
        DbPullConfiguration $config,
        DbPullCheckpointStore $checkpoints,
        DbIndexDownloader $db_index_downloader,
        SqlDumpDownloader $sql_downloader,
        DbPullObserver $observer,
        AuditLogger $audit
    ) {
        $this->config = $config;
        $this->checkpoints = $checkpoints;
        $this->db_index_downloader = $db_index_downloader;
        $this->sql_downloader = $sql_downloader;
        $this->observer = $observer;
        $this->audit = $audit;
    }

    public function run(DbPullCheckpoint $checkpoint): DbPullCheckpoint
    {
        $current_status = $checkpoint->status;
        $has_progress = $current_status === "in_progress";

        if ($current_status === "complete") {
            $this->assert_can_start_completed_sync($this->config->sql_file());
        }

        if ($has_progress) {
            $this->report_resume($checkpoint);
        } else {
            $this->start_fresh($checkpoint);
        }

        $this->checkpoints->save($checkpoint);

        if ($checkpoint->stage === "db-index") {
            $this->observer->on_stage_starting("db-index", "Downloading table metadata");

            $checkpoint = $this->download_db_index($checkpoint);
            if ($checkpoint->status === "partial") {
                return $checkpoint;
            }

            $this->audit->record(
                sprintf("db-pull db-index stage complete: %d tables", $checkpoint->db_index->tables),
            );

            $checkpoint->stage = "sql";
            $checkpoint->cursor = null;
            $this->checkpoints->save($checkpoint);
        }

        $this->observer->on_stage_starting("sql", "Downloading SQL dump");

        $checkpoint = $this->sql_downloader->download($checkpoint);
        if ($checkpoint->status === "partial") {
            return $checkpoint;
        }

        $checkpoint->status = "complete";
        $this->checkpoints->save($checkpoint);

        $this->audit->record("db-pull complete", true);
        $this->observer->on_complete($this->config);

        return $checkpoint;
    }

    public function download_db_index(
        DbPullCheckpoint $checkpoint,
        ?string $tables_file = null
    ): DbPullCheckpoint {
        return $this->db_index_downloader->download($checkpoint, $tables_file);
    }

    private function assert_can_start_completed_sync(string $sql_file): void
    {
        if ($this->config->sql_output_mode() !== "file") {
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
        $this->audit->record(
            sprintf(
                "RESUME db-pull | stage=%s | cursor=%s",
                $checkpoint->stage,
                $checkpoint->cursor !== null
                    ? substr($checkpoint->cursor, 0, 20) . "..."
                    : "none",
            ),
            true,
        );
        $this->observer->on_resuming($checkpoint);
    }

    private function start_fresh(DbPullCheckpoint $checkpoint): void
    {
        $checkpoint->reset();
        $this->checkpoints->save($checkpoint);

        $this->audit->record("START db-pull", true);
        $this->observer->on_starting();
    }
}
