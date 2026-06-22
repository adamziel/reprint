<?php

namespace Reprint\Importer\Sql;

use PDO;
use PDOException;
use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\QueryStream\WP_MySQL_FastQueryStream;
use Reprint\Importer\Sql\Port\DbApplyCheckpointStore;
use Reprint\Importer\Sql\Port\DbApplyObserver;
use Reprint\Importer\Sql\Port\DbApplyShutdownToken;
use Reprint\Importer\Sql\Port\PluginDeactivationPolicy;
use Reprint\Importer\Sql\Port\SqlStatementStatsStore;
use RuntimeException;

final class SqlDumpApplier
{
    private DbApplyShutdownToken $shutdown;
    private DbApplyCheckpointStore $checkpoints;
    private AuditLogger $audit;
    private DbApplyObserver $observer;
    private SqlStatementStatsStore $statement_stats;
    private PluginDeactivationPolicy $plugin_deactivation;

    public function __construct(
        DbApplyShutdownToken $shutdown,
        DbApplyCheckpointStore $checkpoints,
        AuditLogger $audit,
        DbApplyObserver $observer,
        SqlStatementStatsStore $statement_stats,
        PluginDeactivationPolicy $plugin_deactivation
    ) {
        $this->shutdown = $shutdown;
        $this->checkpoints = $checkpoints;
        $this->audit = $audit;
        $this->observer = $observer;
        $this->statement_stats = $statement_stats;
        $this->plugin_deactivation = $plugin_deactivation;
    }

    /**
     * Apply a local SQL dump to the target database.
     *
     * @param array{
     *     sql_file:string,
     *     new_site_url:string
     * } $config
     */
    public function apply(
        DbApplyCheckpoint $checkpoint,
        array $config,
        DbApplyQueryExecutor $query_executor,
        PDO $pdo
    ): DbApplyCheckpoint {
        $sql_file = $config["sql_file"];
        $statements_executed = $checkpoint->statements_executed;
        $bytes_read = $checkpoint->bytes_read;

        $query_stream = new WP_MySQL_FastQueryStream();
        $stmt_count = 0;
        $query_stream->set_error_logger(function (array $err) use (&$stmt_count): void {
            $this->audit->record(
                sprintf(
                    "FAST QUERY STREAM fallback | reason=%s | byte_offset=%d | stmt=%d | %s | context=%.200s",
                    $err['reason'] ?? '?',
                    $err['byte_offset'] ?? 0,
                    $stmt_count,
                    $err['message'] ?? '',
                    $err['context'] ?? ''
                ),
                true
            );
            $this->observer->on_fast_query_stream_fallback((int) ($err['byte_offset'] ?? 0));
        });

        $sql_handle = fopen($sql_file, "r");
        if (!$sql_handle) {
            throw new RuntimeException("Cannot open SQL file: {$sql_file}");
        }

        $sql_file_size = filesize($sql_file);
        $total_bytes_read = 0;
        $save_every = 100;
        $stmts_since_save = 0;
        $statements_total = $this->statement_stats->load_total();

        $seek_offset = 0;
        $stmts_to_skip = 0;
        if ($bytes_read > 0 && $bytes_read < $sql_file_size) {
            fseek($sql_handle, $bytes_read);
            $total_bytes_read = $bytes_read;
            $seek_offset = $bytes_read;
        } elseif ($statements_executed > 0) {
            $stmts_to_skip = $statements_executed;
        }

        $this->observer->on_apply_starting($statements_total);

        try {
            $chunk_size = 64 * 1024;

            while (!feof($sql_handle)) {
                if ($this->shutdown->is_shutdown_requested()) {
                    $this->audit->record("SHUTDOWN REQUESTED | saving state", true);
                    break;
                }
                if (function_exists("pcntl_signal_dispatch")) {
                    pcntl_signal_dispatch();
                }

                $data = fread($sql_handle, $chunk_size);
                if ($data === false || $data === '') {
                    break;
                }
                $total_bytes_read += strlen($data);
                $query_stream->append_sql($data);

                while ($query_stream->next_query()) {
                    $stmt_count++;

                    if ($stmts_to_skip > 0) {
                        $stmts_to_skip--;
                        continue;
                    }

                    $this->execute_statement(
                        $query_stream->get_query(),
                        $stmt_count,
                        $query_executor,
                    );

                    $statements_executed++;
                    $stmts_since_save++;

                    if ($stmts_since_save >= $save_every) {
                        $checkpoint->statements_executed = $statements_executed;
                        $checkpoint->bytes_read = $seek_offset + $query_stream->get_bytes_consumed();
                        $this->checkpoints->save($checkpoint);
                        $stmts_since_save = 0;

                        $this->observer->on_apply_progress(
                            $statements_executed,
                            $statements_total,
                            $total_bytes_read,
                            $sql_file_size,
                        );
                    }
                }
            }

            $query_stream->mark_input_complete();
            while ($query_stream->next_query()) {
                $stmt_count++;

                if ($stmts_to_skip > 0) {
                    $stmts_to_skip--;
                    continue;
                }

                $this->execute_statement(
                    $query_stream->get_query(),
                    $stmt_count,
                    $query_executor,
                );

                $statements_executed++;
            }

            if ($this->shutdown->is_shutdown_requested()) {
                $checkpoint->statements_executed = $statements_executed;
                $checkpoint->bytes_read = $seek_offset + $query_stream->get_bytes_consumed();
                $checkpoint->status = "partial";
                $this->checkpoints->save($checkpoint);
                $this->audit->record(
                    sprintf(
                        "PARTIAL db-apply | %d statements executed",
                        $statements_executed,
                    ),
                    true,
                );
                $this->observer->on_apply_partial($statements_executed, $statements_total);
                return $checkpoint;
            }

            foreach ($this->plugin_deactivation->deactivate_host_specific($pdo) as $basename) {
                $this->audit->record("DB-APPLY | deactivated plugin {$basename} (host-specific)", true);
            }

            foreach (
                $this->plugin_deactivation->deactivate_path_incompatible(
                    $pdo,
                    $config["new_site_url"],
                ) as $basename
            ) {
                $this->audit->record("DB-APPLY | deactivated plugin {$basename} (path-incompatible siteurl)", true);
            }

            $checkpoint->statements_executed = $statements_executed;
            $checkpoint->bytes_read = $seek_offset + $query_stream->get_bytes_consumed();
            $checkpoint->status = "complete";
            $this->checkpoints->save($checkpoint);

            $this->audit->record(
                sprintf(
                    "db-apply complete | %d statements executed",
                    $statements_executed,
                ),
                true,
            );
            $this->observer->on_apply_complete($statements_executed, $statements_total);
        } finally {
            fclose($sql_handle);
        }

        return $checkpoint;
    }

    private function execute_statement(
        string $query,
        int $stmt_count,
        DbApplyQueryExecutor $query_executor
    ): void {
        $executed_query = $query;
        try {
            $executed_query = $query_executor->execute($query);
        } catch (PDOException $e) {
            $this->audit->record(
                sprintf(
                    "SQL ERROR | stmt=%d | %s | query=%.200s",
                    $stmt_count,
                    $e->getMessage(),
                    $executed_query,
                ),
                true,
            );
            throw new RuntimeException(
                "SQL execution error at statement {$stmt_count}: " .
                $e->getMessage(),
            );
        }
    }
}
