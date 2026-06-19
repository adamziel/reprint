<?php

namespace Reprint\Importer\Sql;

use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\QueryStream\WP_MySQL_Naive_Query_Stream;
use Reprint\Importer\UrlRewrite\DomainCollector;
use RuntimeException;

final class SqlResponseHandler
{
    private string $mode;
    private ?string $cursor;
    private StreamingContext $context;

    /** @var resource|null */
    private $sql_handle;

    /** @var object|null */
    private $mysql_conn;

    /** @var resource|null */
    private $buffer_handle;

    private string $sql_buffer;
    private int $sql_bytes_written;

    private ?WP_MySQL_Naive_Query_Stream $query_stream;

    private ?DomainCollector $domain_collector;

    private ?SqlDomainScanner $domain_scanner;

    private int $sql_statements_counted;
    private int $chunks_since_save;
    private int $save_every;
    private bool $complete = false;

    /** @var callable */
    private $should_stop;

    /** @var callable */
    private $save_checkpoint;

    /** @var callable */
    private $persist_domains;

    /** @var callable */
    private $show_sql_progress;

    /** @var callable */
    private $handle_progress;

    /** @var callable */
    private $handle_error;

    /** @var callable */
    private $handle_completion_progress;

    /** @var callable */
    private $handle_stdout_write_failed;

    public function __construct(
        string $mode,
        ?string $cursor,
        StreamingContext $context,
        $sql_handle,
        $mysql_conn,
        $buffer_handle,
        string $sql_buffer,
        int $sql_bytes_written,
        ?WP_MySQL_Naive_Query_Stream $query_stream,
        ?DomainCollector $domain_collector,
        ?SqlDomainScanner $domain_scanner,
        int $sql_statements_counted,
        int $chunks_since_save,
        int $save_every,
        callable $should_stop,
        callable $save_checkpoint,
        callable $persist_domains,
        callable $show_sql_progress,
        callable $handle_progress,
        callable $handle_error,
        callable $handle_completion_progress,
        callable $handle_stdout_write_failed
    ) {
        $this->mode = $mode;
        $this->cursor = $cursor;
        $this->context = $context;
        $this->sql_handle = $sql_handle;
        $this->mysql_conn = $mysql_conn;
        $this->buffer_handle = $buffer_handle;
        $this->sql_buffer = $sql_buffer;
        $this->sql_bytes_written = $sql_bytes_written;
        $this->query_stream = $query_stream;
        $this->domain_collector = $domain_collector;
        $this->domain_scanner = $domain_scanner;
        $this->sql_statements_counted = $sql_statements_counted;
        $this->chunks_since_save = $chunks_since_save;
        $this->save_every = $save_every;
        $this->should_stop = $should_stop;
        $this->save_checkpoint = $save_checkpoint;
        $this->persist_domains = $persist_domains;
        $this->show_sql_progress = $show_sql_progress;
        $this->handle_progress = $handle_progress;
        $this->handle_error = $handle_error;
        $this->handle_completion_progress = $handle_completion_progress;
        $this->handle_stdout_write_failed = $handle_stdout_write_failed;
    }

    public function cursor(): ?string
    {
        return $this->cursor;
    }

    public function complete(): bool
    {
        return $this->complete;
    }

    public function sql_bytes_written(): int
    {
        return $this->sql_bytes_written;
    }

    public function sql_buffer(): string
    {
        return $this->sql_buffer;
    }

    public function sql_statements_counted(): int
    {
        return $this->sql_statements_counted;
    }

    public function chunks_since_save(): int
    {
        return $this->chunks_since_save;
    }

    public function handle(array $chunk): void
    {
        if ($this->should_stop()) {
            throw new RuntimeException("Shutdown requested");
        }

        if (function_exists("pcntl_signal_dispatch")) {
            pcntl_signal_dispatch();
        }

        if (isset($chunk["headers"]["x-cursor"])) {
            $this->cursor = $chunk["headers"]["x-cursor"];
        }

        $this->checkpoint_if_needed();

        $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

        if ($chunk_type === "sql") {
            $this->handle_sql($chunk);
        } elseif ($chunk_type === "progress") {
            $this->handle_progress($chunk, "sql");
        } elseif ($chunk_type === "completion") {
            $this->handle_completion($chunk);
        } elseif ($chunk_type === "error") {
            $this->handle_error($chunk, "db-index", $this->context);
        }
    }

    private function checkpoint_if_needed(): void
    {
        $this->chunks_since_save++;
        if (
            $this->chunks_since_save < $this->save_every ||
            $this->sql_buffer !== ""
        ) {
            return;
        }

        if ($this->sql_handle) {
            fflush($this->sql_handle);
        }

        $this->save_checkpoint(
            $this->cursor,
            $this->sql_bytes_written,
            $this->sql_statements_counted,
        );
        $this->chunks_since_save = 0;

        $this->persist_domains();
    }

    private function handle_sql(array $chunk): void
    {
        $query_complete = ($chunk["headers"]["x-query-complete"] ?? "1") === "1";
        $data = $chunk["body"] ?? "";

        if ($this->mode === "file") {
            $this->write_file_sql($data);
        } elseif ($this->mode === "stdout") {
            $this->write_stdout_sql($data);
        } elseif ($this->mode === "mysql") {
            $this->write_mysql_sql($data, $query_complete);
        }

        if ($this->query_stream && $this->domain_collector && $this->domain_scanner) {
            $this->query_stream->append_sql($data);
            $this->sql_statements_counted = $this->domain_scanner->drain_query_stream(
                $this->query_stream,
                $this->domain_collector,
                $this->sql_statements_counted,
            );
        }

        $this->show_sql_progress($this->sql_bytes_written);
    }

    private function write_file_sql(string $data): void
    {
        $data_length = strlen($data);
        $bytes = fwrite($this->sql_handle, $data);
        if ($bytes === false || $bytes !== $data_length) {
            throw new RuntimeException(
                "SQL write failed: wrote " . ($bytes === false ? "0" : $bytes) .
                "/" . $data_length . " bytes (disk full?)"
            );
        }
        $this->sql_bytes_written += $bytes;
    }

    private function write_stdout_sql(string $data): void
    {
        $bytes = @fwrite(STDOUT, $data);
        if ($bytes === false) {
            $this->handle_stdout_write_failed();
            return;
        }
        $this->sql_bytes_written += $bytes;
    }

    private function write_mysql_sql(string $data, bool $query_complete): void
    {
        if ($this->buffer_handle) {
            fwrite($this->buffer_handle, $data);
            fflush($this->buffer_handle);
        }

        $this->sql_buffer .= $data;
        $this->sql_bytes_written += strlen($data);

        if (!$query_complete) {
            return;
        }

        if (!$this->mysql_conn->multi_query($this->sql_buffer)) {
            throw new RuntimeException("MySQL execution failed: " . $this->mysql_conn->error);
        }

        do {
            $result = $this->mysql_conn->store_result();
            if ($result) {
                $result->free();
            }
            if ($this->mysql_conn->errno) {
                throw new RuntimeException("MySQL statement error: " . $this->mysql_conn->error);
            }
        } while ($this->mysql_conn->more_results() && $this->mysql_conn->next_result());

        if ($this->buffer_handle) {
            ftruncate($this->buffer_handle, 0);
            rewind($this->buffer_handle);
        }
        $this->sql_buffer = "";
    }

    private function handle_completion(array $chunk): void
    {
        $headers = $chunk["headers"];
        $this->complete = ($headers["x-status"] ?? "") === "complete";
        $this->context->saw_completion = true;
        $this->context->response_stats = [
            "status" => $headers["x-status"] ?? null,
            "sql_bytes" =>
                isset($headers["x-sql-bytes"])
                    ? (int) $headers["x-sql-bytes"]
                    : null,
            "server_time" =>
                isset($headers["x-time-elapsed"])
                    ? (float) $headers["x-time-elapsed"]
                    : null,
            "memory_used" =>
                isset($headers["x-memory-used"])
                    ? (int) $headers["x-memory-used"]
                    : null,
            "memory_limit" =>
                isset($headers["x-memory-limit"])
                    ? (int) $headers["x-memory-limit"]
                    : null,
        ];
        $this->handle_completion_progress([
            "phase" => "sql",
            "status" => $headers["x-status"] ?? "unknown",
            "batches_processed" => (int) ($headers["x-batches-processed"] ?? 0),
        ]);
    }

    private function should_stop(): bool
    {
        return (bool) ($this->should_stop)();
    }

    private function save_checkpoint(
        ?string $cursor,
        int $sql_bytes_written,
        int $sql_statements_counted
    ): void {
        ($this->save_checkpoint)(
            $cursor,
            $sql_bytes_written,
            $sql_statements_counted,
        );
    }

    private function persist_domains(): void
    {
        if (!$this->domain_collector) {
            return;
        }

        $domains = $this->domain_collector->get_domains();
        if (!empty($domains)) {
            ($this->persist_domains)($domains);
        }
    }

    private function show_sql_progress(int $sql_bytes_written): void
    {
        ($this->show_sql_progress)($sql_bytes_written);
    }

    private function handle_progress(array $chunk, string $phase): void
    {
        ($this->handle_progress)($chunk, $phase);
    }

    private function handle_error(
        array $chunk,
        string $phase,
        StreamingContext $context
    ): void {
        ($this->handle_error)($chunk, $phase, $context);
    }

    private function handle_completion_progress(array $progress): void
    {
        ($this->handle_completion_progress)($progress);
    }

    private function handle_stdout_write_failed(): void
    {
        ($this->handle_stdout_write_failed)();
    }
}
