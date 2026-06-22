<?php

namespace Reprint\Importer\Sql;

use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Sql\Port\DbIndexTableSink;
use RuntimeException;

final class DbIndexResponseHandler
{
    private DbIndexTableSink $sink;
    private ?string $cursor;
    private StreamingContext $context;
    private bool $complete = false;

    public function __construct(
        DbIndexTableSink $sink,
        ?string $cursor,
        StreamingContext $context
    ) {
        $this->sink = $sink;
        $this->cursor = $cursor;
        $this->context = $context;
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
        return $this->sink->tables_written();
    }

    public function rows_estimated(): int
    {
        return $this->sink->rows_estimated();
    }

    public function bytes_written(): int
    {
        return $this->sink->bytes_written();
    }

    public function handle(array $chunk): void
    {
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
            return;
        } elseif ($chunk_type === "completion") {
            $this->handle_completion($chunk);
        } elseif ($chunk_type === "error") {
            $this->handle_error($chunk);
        }
    }

    private function handle_table_stats(array $chunk): void
    {
        $data = json_decode($chunk["body"] ?? "", true);
        if (!is_array($data)) {
            return;
        }

        $this->sink->write_rows($data);
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
    }

    private function handle_error(array $chunk): void
    {
        $body = $chunk["body"] ?? "";
        $data = json_decode($body, true);
        if (is_array($data) && isset($data["message"])) {
            throw new RuntimeException((string) $data["message"]);
        }

        throw new RuntimeException(
            "Remote db-index error: " . substr((string) $body, 0, 500),
        );
    }
}
