<?php

namespace Reprint\Importer\Sql;

use PDO;
use PDOException;
use Reprint\Importer\QueryStream\WP_MySQL_FastQueryStream;
use RuntimeException;

final class SqlDumpApplier
{
    /** @var callable */
    private $should_stop;

    /** @var callable */
    private $save_state;

    /** @var callable */
    private $audit;

    /** @var callable */
    private $output_progress;

    /** @var callable */
    private $show_progress_line;

    /** @var callable */
    private $show_lifecycle_line;

    /** @var callable */
    private $clear_progress_line;

    /** @var callable */
    private $is_quiet_lifecycle;

    /** @var callable */
    private $deactivate_host_plugins;

    /** @var callable */
    private $deactivate_path_incompatible_plugins;

    public function __construct(
        callable $should_stop,
        callable $save_state,
        callable $audit,
        callable $output_progress,
        callable $show_progress_line,
        callable $show_lifecycle_line,
        callable $clear_progress_line,
        callable $is_quiet_lifecycle,
        callable $deactivate_host_plugins,
        callable $deactivate_path_incompatible_plugins
    ) {
        $this->should_stop = $should_stop;
        $this->save_state = $save_state;
        $this->audit = $audit;
        $this->output_progress = $output_progress;
        $this->show_progress_line = $show_progress_line;
        $this->show_lifecycle_line = $show_lifecycle_line;
        $this->clear_progress_line = $clear_progress_line;
        $this->is_quiet_lifecycle = $is_quiet_lifecycle;
        $this->deactivate_host_plugins = $deactivate_host_plugins;
        $this->deactivate_path_incompatible_plugins = $deactivate_path_incompatible_plugins;
    }

    /**
     * Apply a local SQL dump to the target database.
     *
     * @param array<string, mixed> $state
     * @param array{
     *     sql_file:string,
     *     state_dir:string,
     *     statements_executed:int,
     *     bytes_read:int,
     *     new_site_url:string
     * } $config
     */
    public function apply(
        array &$state,
        array $config,
        DbApplyQueryExecutor $query_executor,
        PDO $pdo
    ): void {
        $sql_file = $config["sql_file"];
        $statements_executed = $config["statements_executed"];
        $bytes_read = $config["bytes_read"];

        $query_stream = new WP_MySQL_FastQueryStream();
        $stmt_count = 0;
        $query_stream->set_error_logger(function (array $err) use (&$stmt_count): void {
            $this->audit(
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
            $this->show_lifecycle_line(
                "Fast query stream fell back to lexer-based parser at byte offset "
                . ($err['byte_offset'] ?? 0) . "; see audit log for details\n"
            );
        });

        $sql_handle = fopen($sql_file, "r");
        if (!$sql_handle) {
            throw new RuntimeException("Cannot open SQL file: {$sql_file}");
        }

        $sql_file_size = filesize($sql_file);
        $total_bytes_read = 0;
        $save_every = 100;
        $stmts_since_save = 0;

        $statements_total = $this->load_statements_total($config["state_dir"]);

        $seek_offset = 0;
        $stmts_to_skip = 0;
        if ($bytes_read > 0 && $bytes_read < $sql_file_size) {
            fseek($sql_handle, $bytes_read);
            $total_bytes_read = $bytes_read;
            $seek_offset = $bytes_read;
        } elseif ($statements_executed > 0) {
            $stmts_to_skip = $statements_executed;
        }

        $this->output_progress([
            "status" => "starting",
            "phase" => "db-apply",
            "statements_total" => $statements_total,
            "message" => "Applying SQL" . ($statements_total !== null ? " ({$statements_total} statements)" : ""),
        ], false);

        try {
            $chunk_size = 64 * 1024;

            while (!feof($sql_handle)) {
                if ($this->should_stop()) {
                    $this->audit("SHUTDOWN REQUESTED | saving state", true);
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
                        $state["apply"]["statements_executed"] = $statements_executed;
                        $state["apply"]["bytes_read"] = $seek_offset + $query_stream->get_bytes_consumed();
                        $this->save_state($state);
                        $stmts_since_save = 0;

                        $this->show_apply_progress(
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

            if ($this->should_stop()) {
                $state["apply"]["statements_executed"] = $statements_executed;
                $state["apply"]["bytes_read"] = $seek_offset + $query_stream->get_bytes_consumed();
                $state["status"] = "partial";
                $this->save_state($state);
                $this->audit(
                    sprintf(
                        "PARTIAL db-apply | %d statements executed",
                        $statements_executed,
                    ),
                    true,
                );
                $this->output_progress([
                    "status" => "partial",
                    "phase" => "db-apply",
                    "statements_executed" => $statements_executed,
                    "statements_total" => $statements_total,
                    "message" => "db-apply partial: {$statements_executed} statements executed",
                ], true);
                return;
            }

            foreach ($this->deactivate_host_plugins($pdo) as $basename) {
                $this->audit("DB-APPLY | deactivated plugin {$basename} (host-specific)", true);
            }

            foreach (
                $this->deactivate_path_incompatible_plugins(
                    $pdo,
                    $config["new_site_url"],
                ) as $basename
            ) {
                $this->audit("DB-APPLY | deactivated plugin {$basename} (path-incompatible siteurl)", true);
            }

            $state["apply"]["statements_executed"] = $statements_executed;
            $state["apply"]["bytes_read"] = $seek_offset + $query_stream->get_bytes_consumed();
            $state["status"] = "complete";
            $this->save_state($state);

            $this->audit(
                sprintf(
                    "db-apply complete | %d statements executed",
                    $statements_executed,
                ),
                true,
            );

            $this->output_progress([
                "status" => "complete",
                "phase" => "db-apply",
                "statements_executed" => $statements_executed,
                "statements_total" => $statements_total,
                "message" => "db-apply complete ({$statements_executed} statements executed)",
            ], false);

            if (!$this->is_quiet_lifecycle()) {
                $this->clear_progress_line();
            }
            $this->show_lifecycle_line("db-apply complete ({$statements_executed} statements executed)\n");
        } finally {
            fclose($sql_handle);
        }
    }

    private function load_statements_total(string $state_dir): ?int
    {
        $sql_stats_file = $state_dir . "/.import-sql-stats.json";
        if (!file_exists($sql_stats_file)) {
            return null;
        }

        $stats = json_decode(file_get_contents($sql_stats_file), true);
        if (!is_array($stats) || !isset($stats["statements_total"])) {
            return null;
        }

        return (int) $stats["statements_total"];
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
            $this->audit(
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

    private function show_apply_progress(
        int $statements_executed,
        ?int $statements_total,
        int $total_bytes_read,
        int $sql_file_size
    ): void {
        $apply_fraction = $sql_file_size > 0
            ? $total_bytes_read / $sql_file_size
            : null;
        $pct = $apply_fraction !== null ? round($apply_fraction * 100, 1) : 0;

        $progress_message = sprintf(
            "%s statements",
            $statements_total === null
                ? number_format($statements_executed)
                : number_format($statements_executed) . " / " . number_format($statements_total),
        );

        $this->output_progress([
            "phase" => "db-apply",
            "statements_executed" => $statements_executed,
            "bytes_read" => $total_bytes_read,
            "bytes_total" => $sql_file_size,
            "pct" => $pct,
            "statements_total" => $statements_total,
            "message" => $progress_message,
        ], false);

        $this->show_progress_line($progress_message, $apply_fraction);
    }

    private function should_stop(): bool
    {
        return (bool) ($this->should_stop)();
    }

    private function save_state(array $state): void
    {
        ($this->save_state)($state);
    }

    private function audit(string $message, bool $to_console): void
    {
        ($this->audit)($message, $to_console);
    }

    private function output_progress(array $progress, bool $force): void
    {
        ($this->output_progress)($progress, $force);
    }

    private function show_progress_line(string $message, ?float $fraction): void
    {
        ($this->show_progress_line)($message, $fraction);
    }

    private function show_lifecycle_line(string $message): void
    {
        ($this->show_lifecycle_line)($message);
    }

    private function clear_progress_line(): void
    {
        ($this->clear_progress_line)();
    }

    private function is_quiet_lifecycle(): bool
    {
        return (bool) ($this->is_quiet_lifecycle)();
    }

    /**
     * @return string[]
     */
    private function deactivate_host_plugins(PDO $pdo): array
    {
        return (array) ($this->deactivate_host_plugins)($pdo);
    }

    /**
     * @return string[]
     */
    private function deactivate_path_incompatible_plugins(PDO $pdo, string $new_site_url): array
    {
        return (array) ($this->deactivate_path_incompatible_plugins)($pdo, $new_site_url);
    }
}
