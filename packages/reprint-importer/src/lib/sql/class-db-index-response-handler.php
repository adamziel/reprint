<?php

namespace Reprint\Importer\Sql;

use Reprint\Importer\Protocol\StreamingContext;
use RuntimeException;

final class DbIndexResponseHandler
{
    /** @var resource */
    private $handle;

    private ?string $cursor;
    private StreamingContext $context;
    private int $tables_written;
    private int $rows_estimated;
    private int $bytes_written;
    private bool $complete = false;

    /** @var callable */
    private $should_stop;

    /** @var callable */
    private $handle_progress;

    /** @var callable */
    private $handle_error;

    /** @var callable */
    private $handle_completion_progress;

    public function __construct(
        $handle,
        ?string $cursor,
        StreamingContext $context,
        int $tables_written,
        int $rows_estimated,
        int $bytes_written,
        callable $should_stop,
        callable $handle_progress,
        callable $handle_error,
        callable $handle_completion_progress
    ) {
        $this->handle = $handle;
        $this->cursor = $cursor;
        $this->context = $context;
        $this->tables_written = $tables_written;
        $this->rows_estimated = $rows_estimated;
        $this->bytes_written = $bytes_written;
        $this->should_stop = $should_stop;
        $this->handle_progress = $handle_progress;
        $this->handle_error = $handle_error;
        $this->handle_completion_progress = $handle_completion_progress;
    }

    public function cursor(): ?string
    {
        return $this->cursor;
    }

    public function complete(): bool
    {
        return $this->complete;
    }

    public function tables_written(): int
    {
        return $this->tables_written;
    }

    public function rows_estimated(): int
    {
        return $this->rows_estimated;
    }

    public function bytes_written(): int
    {
        return $this->bytes_written;
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

        $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

        if ($chunk_type === "table_stats") {
            $this->handle_table_stats($chunk);
        } elseif ($chunk_type === "progress") {
            $this->handle_progress($chunk, "db-index");
        } elseif ($chunk_type === "completion") {
            $this->handle_completion($chunk);
        } elseif ($chunk_type === "error") {
            $this->handle_error($chunk, "sql", $this->context);
        }
    }

    private function handle_table_stats(array $chunk): void
    {
        $data = json_decode($chunk["body"] ?? "", true);
        if (!is_array($data)) {
            return;
        }

        foreach ($data as $row) {
            $line = json_encode($row) . "\n";
            $line_length = strlen($line);
            $bytes = fwrite($this->handle, $line);
            if ($bytes === false || $bytes !== $line_length) {
                throw new RuntimeException(
                    "Table stats write failed: wrote " . ($bytes === false ? "0" : $bytes) .
                    "/" . $line_length . " bytes (disk full?)"
                );
            }

            $this->bytes_written += $bytes;
            $this->tables_written++;
            if (isset($row["rows"]) && is_numeric($row["rows"])) {
                $this->rows_estimated += (int) $row["rows"];
            }
        }
    }

    private function handle_completion(array $chunk): void
    {
        $headers = $chunk["headers"];
        $this->complete = ($headers["x-status"] ?? "") === "complete";
        $this->context->saw_completion = true;
        $this->context->response_stats = [
            "status" => $headers["x-status"] ?? null,
            "tables_processed" =>
                isset($headers["x-tables-processed"])
                    ? (int) $headers["x-tables-processed"]
                    : null,
            "rows_estimated" =>
                isset($headers["x-rows-estimated"])
                    ? (int) $headers["x-rows-estimated"]
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
            "phase" => "db-index",
            "status" => $headers["x-status"] ?? "unknown",
            "tables_processed" => (int) ($headers["x-tables-processed"] ?? 0),
        ]);
    }

    private function should_stop(): bool
    {
        return (bool) ($this->should_stop)();
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
}
