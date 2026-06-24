<?php

namespace Reprint\Exporter\Command;

use Exception;
use PDO;
use Throwable;
use Reprint\Exporter\MySQLDumpProducer;
use Reprint\Exporter\ResourceBudget;
use function Reprint\Exporter\begin_multipart_stream;
use function Reprint\Exporter\create_db_connection;
use function Reprint\Exporter\E2E\call_hook;
use function Reprint\Exporter\E2E\load_test_hooks_if_needed;
use function Reprint\Exporter\emit_error_chunk;
use function Reprint\Exporter\prepare_streaming_response;
use function Reprint\Exporter\require_int_range;
use function Reprint\Exporter\resolve_db_credentials;

final class SqlChunkCommand extends BudgetedExportCommand
{
    public function execute(array $config, ResourceBudget $budget): array
    {
        prepare_streaming_response();
        $creds = resolve_db_credentials();
    
        // -- Parse request parameters --
        $fragments_per_batch = $config["fragments_per_batch"] ?? 1000;
        $fragments_per_batch = require_int_range(
            "fragments_per_batch",
            (int) $fragments_per_batch,
            1,
            10000,
        );
    
        $pdo_options = [];
        if (!empty($config["db_unbuffered"])) {
            $pdo_options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;
        }
        $mysql = create_db_connection($creds, $pdo_options);
    
        $producer_options = [
            "create_table_query" => $config["create_table_query"] ?? true,
        ];
    
        // -- Cap statement size to the smaller of client and server max_allowed_packet --
        // If the client sent its max_allowed_packet, cap the producer's
        // max_statement_size so the dump stays importable on the client.
        // We query the server's own max_allowed_packet too and use the
        // smaller of the two (both scaled to 80% for protocol headroom).
        if (!empty($config["max_allowed_packet"])) {
            $client_max = (int) $config["max_allowed_packet"];
            if ($client_max >= 1048576 && $client_max <= 1073741824) {
                $client_statement_size = (int) ($client_max * 0.8);
                $server_statement_size = null;
                try {
                    $row = $mysql
                        ->query("SELECT @@max_allowed_packet AS v")
                        ->fetch(PDO::FETCH_ASSOC);
                    if ($row && isset($row["v"])) {
                        $server_statement_size = (int) ((int) $row["v"] * 0.8);
                    }
                } catch (Exception $e) {
                    // Ignore — producer will auto-detect
                }
                if ($server_statement_size !== null) {
                    $producer_options["max_statement_size"] = min(
                        $client_statement_size,
                        $server_statement_size,
                    );
                } else {
                    $producer_options["max_statement_size"] = $client_statement_size;
                }
            }
        }
    
        if (!empty($config["db_query_time_limit"])) {
            $execution_budget_ms = (int) ($budget->max_time * 1000 * 0.8);
            $query_time_limit = require_int_range(
                "db_query_time_limit",
                (int) $config["db_query_time_limit"],
                0,
                300_000,
            );
            $query_time_limit = min($query_time_limit, $execution_budget_ms);
            if ($query_time_limit > 0) {
                $producer_options["query_time_limit_ms"] = $query_time_limit;
            }
        }
    
        if (isset($config["cursor"])) {
            $producer_options["cursor"] = $config["cursor"];
        }
    
        $reader = new MySQLDumpProducer(
            $mysql,
            $producer_options,
        );
    
        if (ob_get_level()) {
            ob_end_flush();
        }
    
    
        ['gz' => $gz, 'boundary' => $boundary] = begin_multipart_stream(true);
    
        // E2E test hook: after gzip stream initialization
        if (getenv('SITE_EXPORT_TEST_MODE')) {
            load_test_hooks_if_needed($config);
            $hook_args = [$gz, $boundary];
            call_hook('test_hook_after_gzip_init', $hook_args);
        }
    
        // -- Stream SQL fragments --
        // Pull SQL fragments from the producer in batches, writing each batch
        // as a multipart chunk. Stop when the producer is exhausted or the
        // resource budget (time/memory) runs out.
        $batches_processed = 0;
        $sql_bytes_processed = 0;
        $aborted = false;
    
        try {
            while (
                $budget->has_remaining()
            ) {
                $sql = [];
    
                $i = 0;
                while ($reader->next_sql_fragment()) {
                    $sql[] = $reader->get_sql_fragment();
                    $i++;
    
                    if ($i >= $fragments_per_batch) {
                        break;
                    }
    
                    if (
                        !$budget->has_remaining()
                    ) {
                        break;
                    }
                }
                $sql = implode("", $sql);
                $sql_bytes_processed += strlen($sql);
    
                // Does this chunk end on a complete statement boundary?
                // The producer terminates complete statements with ";" and
                // intermediate INSERT rows with ",", so checking the last
                // character is sufficient.
                $trimmed = rtrim($sql);
                $query_complete = $trimmed !== "" && $trimmed[-1] === ";";
    
                // E2E test hook: before SQL batch is emitted
                if (getenv('SITE_EXPORT_TEST_MODE')) {
                    $cursor_for_hook = $reader->get_reentrancy_cursor();
                    $hook_args = [&$sql, $cursor_for_hook];
                    call_hook('test_hook_before_sql_batch', $hook_args);
                }
    
                $cursor = $reader->get_reentrancy_cursor();
                $gz->write(
                    "--{$boundary}\r\n" .
                    "Content-Type: application/sql\r\n" .
                    "Content-Length: " . strlen($sql) . "\r\n" .
                    "X-Chunk-Type: sql\r\n" .
                    "X-Query-Complete: " . ($query_complete ? "1" : "0") . "\r\n" .
                    "X-Cursor: " . base64_encode($cursor) . "\r\n" .
                    "\r\n",
                );
                $gz->write($sql);
                $gz->write("\r\n");
                $gz->sync();
    
                $batches_processed++;
    
                if ($reader->is_finished()) {
                    break;
                }
            }
        } catch (Throwable $e) {
            $aborted = true;
            error_log("SQL streaming error: " . $e->getMessage());
            emit_error_chunk($gz, $boundary, $e->getMessage());
        }
    
        // Best-effort completion chunk — the client already has the data chunks.
        $status = $aborted ? "partial" : ($reader->is_finished() ? "complete" : "partial");
    
        // E2E test hook: before completion chunk
        if (getenv('SITE_EXPORT_TEST_MODE')) {
            $hook_args = [$status, $gz, $boundary];
            call_hook('test_hook_before_completion', $hook_args);
        }
    
        try {
            $gz->write(
                "--{$boundary}\r\n" .
                "Content-Type: application/octet-stream\r\n" .
                "Content-Length: 0\r\n" .
                "X-Chunk-Type: completion\r\n" .
                "X-Status: {$status}\r\n" .
                "X-Batches-Processed: {$batches_processed}\r\n" .
                "X-SQL-Bytes: {$sql_bytes_processed}\r\n" .
                "X-Memory-Used: " . memory_get_peak_usage(true) . "\r\n" .
                "X-Memory-Limit: " . $budget->max_memory . "\r\n" .
                "X-Time-Elapsed: " . (microtime(true) - $budget->start_time) . "\r\n" .
                "\r\n" .
                "\r\n" .
                "--{$boundary}--\r\n",
            );
            $gz->finish();
        } catch (\Throwable $e) {
            error_log("Export: failed to write completion chunk: " . $e->getMessage());
        }
    
        return [
            "status" => $status,
            "stats" => [
                "batches_processed" => $batches_processed,
                "sql_bytes" => $sql_bytes_processed,
                "memory_used" => memory_get_peak_usage(true),
                "time_elapsed" => microtime(true) - $budget->start_time,
            ],
        ];
    }
}
