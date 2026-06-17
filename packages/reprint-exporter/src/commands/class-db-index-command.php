<?php

namespace Reprint\Exporter\Command;

use InvalidArgumentException;
use PDO;
use Reprint\Exporter\ResourceBudget;
use function Reprint\Exporter\json_encode_or_throw;

final class DbIndexCommand extends BudgetedExportCommand
{
    public function execute(array $config, ResourceBudget $budget): array
    {
        prepare_streaming_response();
    
        $creds = resolve_db_credentials();
    
        $tables_per_batch = $config["tables_per_batch"] ?? 1000;
        $tables_per_batch = require_int_range(
            "tables_per_batch",
            (int) $tables_per_batch,
            10,
            10000,
        );
    
        $cursor = null;
        if (isset($config["cursor"])) {
            $cursor = json_decode($config["cursor"], true);
            if ($cursor === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException(
                    "Invalid cursor format: " . json_last_error_msg(),
                );
            }
        }
        $last_table = $cursor["last_table"] ?? "";
    
        $mysql = create_db_connection($creds);
    
        ['gz' => $gz, 'boundary' => $boundary] = begin_multipart_stream();
    
        $tables_processed = 0;
        $rows_estimated = 0;
        $status = "partial";
        $aborted = false;
    
        try {
            while (
                $budget->has_remaining()
            ) {
                $sql =
                    "SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, ENGINE, " .
                    "TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES " .
                    "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME > :last " .
                    "ORDER BY TABLE_NAME ASC LIMIT {$tables_per_batch}";
                $stmt = $mysql->prepare($sql);
                $stmt->bindValue(":last", $last_table, PDO::PARAM_STR);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
                if (!$rows) {
                    $status = "complete";
                    break;
                }
    
                $tables = [];
                foreach ($rows as $row) {
                    $name = (string) ($row["TABLE_NAME"] ?? "");
                    $tables[] = [
                        "name" => $name,
                        "rows" =>
                            isset($row["TABLE_ROWS"]) && is_numeric($row["TABLE_ROWS"])
                                ? (int) $row["TABLE_ROWS"]
                                : null,
                        "data_bytes" =>
                            isset($row["DATA_LENGTH"]) && is_numeric($row["DATA_LENGTH"])
                                ? (int) $row["DATA_LENGTH"]
                                : null,
                        "index_bytes" =>
                            isset($row["INDEX_LENGTH"]) && is_numeric($row["INDEX_LENGTH"])
                                ? (int) $row["INDEX_LENGTH"]
                                : null,
                        "engine" => $row["ENGINE"] ?? null,
                        "collation" => $row["TABLE_COLLATION"] ?? null,
                    ];
                    $last_table = $name;
                    $tables_processed++;
                    if (
                        isset($row["TABLE_ROWS"]) &&
                        is_numeric($row["TABLE_ROWS"])
                    ) {
                        $rows_estimated += (int) $row["TABLE_ROWS"];
                    }
                }
    
                $payload = json_encode_or_throw($tables);
                $cursor_json = json_encode_or_throw([
                    "phase" => "tables",
                    "last_table" => $last_table,
                ]);
    
                $gz->write(
                    "--{$boundary}\r\n" .
                    "Content-Type: application/json\r\n" .
                    "Content-Length: " . strlen($payload) . "\r\n" .
                    "X-Chunk-Type: table_stats\r\n" .
                    "X-Tables: " . count($tables) . "\r\n" .
                    "X-Cursor: " . base64_encode($cursor_json) . "\r\n" .
                    "\r\n" .
                    $payload . "\r\n",
                );
                $gz->sync();
    
                if (count($rows) < $tables_per_batch) {
                    $status = "complete";
                    break;
                }
            }
        } catch (\Throwable $e) {
            $aborted = true;
            emit_error_chunk($gz, $boundary, get_class($e) . ": " . $e->getMessage());
        }
    
        try {
            $gz->write(
                "--{$boundary}\r\n" .
                "Content-Type: application/octet-stream\r\n" .
                "Content-Length: 0\r\n" .
                "X-Chunk-Type: completion\r\n" .
                "X-Status: " . ($aborted ? "partial" : $status) . "\r\n" .
                "X-Tables-Processed: {$tables_processed}\r\n" .
                "X-Rows-Estimated: {$rows_estimated}\r\n" .
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
                "tables_processed" => $tables_processed,
                "rows_estimated" => $rows_estimated,
                "memory_used" => memory_get_peak_usage(true),
                "time_elapsed" => microtime(true) - $budget->start_time,
            ],
        ];
    }
}
