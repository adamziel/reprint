<?php

namespace Reprint\Importer\Sql;

use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\QueryStream\WP_MySQL_Naive_Query_Stream;
use Reprint\Importer\Sql\Port\DbPullCheckpointStore;
use Reprint\Importer\Sql\Port\SqlDomainStore;
use Reprint\Importer\Sql\Port\SqlOutputSink;
use Reprint\Importer\Sql\Port\SqlShutdownToken;
use Reprint\Importer\Sql\Port\SqlStreamObserver;
use Reprint\Importer\UrlRewrite\DomainCollector;
use RuntimeException;

final class SqlResponseHandler
{
    private ?string $cursor;
    private StreamingContext $context;
    private SqlOutputSink $output;
    private ?WP_MySQL_Naive_Query_Stream $query_stream;
    private ?DomainCollector $domain_collector;
    private ?SqlDomainScanner $domain_scanner;
    private int $sql_statements_counted;
    private int $chunks_since_save;
    private int $save_every;
    private bool $complete = false;
    private DbPullCheckpoint $checkpoint;
    private SqlShutdownToken $shutdown;
    private DbPullCheckpointStore $checkpoints;
    private SqlDomainStore $domain_store;
    private SqlStreamObserver $observer;

    public function __construct(
        ?string $cursor,
        StreamingContext $context,
        SqlOutputSink $output,
        ?WP_MySQL_Naive_Query_Stream $query_stream,
        ?DomainCollector $domain_collector,
        ?SqlDomainScanner $domain_scanner,
        int $sql_statements_counted,
        int $chunks_since_save,
        int $save_every,
        DbPullCheckpoint $checkpoint,
        SqlShutdownToken $shutdown,
        DbPullCheckpointStore $checkpoints,
        SqlDomainStore $domain_store,
        SqlStreamObserver $observer
    ) {
        $this->cursor = $cursor;
        $this->context = $context;
        $this->output = $output;
        $this->query_stream = $query_stream;
        $this->domain_collector = $domain_collector;
        $this->domain_scanner = $domain_scanner;
        $this->sql_statements_counted = $sql_statements_counted;
        $this->chunks_since_save = $chunks_since_save;
        $this->save_every = $save_every;
        $this->checkpoint = $checkpoint;
        $this->shutdown = $shutdown;
        $this->checkpoints = $checkpoints;
        $this->domain_store = $domain_store;
        $this->observer = $observer;
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
        return $this->output->bytes_written();
    }

    public function sql_buffer(): string
    {
        return $this->output->pending_buffer();
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
        if ($this->shutdown->is_shutdown_requested()) {
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
            $this->observer->on_progress_chunk($chunk, "sql");
        } elseif ($chunk_type === "completion") {
            $this->handle_completion($chunk);
        } elseif ($chunk_type === "error") {
            $this->observer->on_error_chunk($chunk, "db-index", $this->context);
        }
    }

    private function checkpoint_if_needed(): void
    {
        $this->chunks_since_save++;
        if (
            $this->chunks_since_save < $this->save_every ||
            $this->output->pending_buffer() !== ""
        ) {
            return;
        }

        $this->output->flush();
        $this->save_checkpoint();
        $this->chunks_since_save = 0;
        $this->persist_domains();
    }

    private function handle_sql(array $chunk): void
    {
        $query_complete = ($chunk["headers"]["x-query-complete"] ?? "1") === "1";
        $data = $chunk["body"] ?? "";

        try {
            $this->output->write($data, $query_complete);
        } catch (SqlStdoutWriteFailedException $e) {
            $this->observer->on_stdout_write_failed();
            return;
        }

        if ($this->query_stream && $this->domain_collector && $this->domain_scanner) {
            $this->query_stream->append_sql($data);
            $this->sql_statements_counted = $this->domain_scanner->drain_query_stream(
                $this->query_stream,
                $this->domain_collector,
                $this->sql_statements_counted,
            );
        }

        $this->observer->on_sql_progress($this->output->bytes_written());
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
        $this->observer->on_completion_progress([
            "phase" => "sql",
            "status" => $headers["x-status"] ?? "unknown",
            "batches_processed" => (int) ($headers["x-batches-processed"] ?? 0),
        ]);
    }

    private function save_checkpoint(): void
    {
        $this->checkpoint->cursor = $this->cursor;
        $this->checkpoint->sql_bytes = $this->output->bytes_written();
        $this->checkpoint->sql_statements_counted = $this->sql_statements_counted;
        $this->checkpoints->save($this->checkpoint);
    }

    private function persist_domains(): void
    {
        if (!$this->domain_collector) {
            return;
        }

        $domains = $this->domain_collector->get_domains();
        if (!empty($domains)) {
            $this->domain_store->persist($domains);
        }
    }
}
