<?php

namespace Reprint\Importer\Sql;

use Reprint\Importer\Protocol\CurlTimeoutException;
use Reprint\Importer\Protocol\StreamingContext;
use RuntimeException;

final class DbIndexDownloader
{
    /** @var callable */
    private $build_url;

    /** @var callable */
    private $fetch_streaming;

    /** @var callable */
    private $should_stop;

    /** @var callable */
    private $handle_progress;

    /** @var callable */
    private $handle_error;

    /** @var callable */
    private $handle_completion_progress;

    /** @var callable */
    private $assert_can_retry_timeout;

    /** @var callable */
    private $finalize_request;

    /** @var callable */
    private $save_state;

    /** @var callable */
    private $audit;

    public function __construct(
        callable $build_url,
        callable $fetch_streaming,
        callable $should_stop,
        callable $handle_progress,
        callable $handle_error,
        callable $handle_completion_progress,
        callable $assert_can_retry_timeout,
        callable $finalize_request,
        callable $save_state,
        callable $audit
    ) {
        $this->build_url = $build_url;
        $this->fetch_streaming = $fetch_streaming;
        $this->should_stop = $should_stop;
        $this->handle_progress = $handle_progress;
        $this->handle_error = $handle_error;
        $this->handle_completion_progress = $handle_completion_progress;
        $this->assert_can_retry_timeout = $assert_can_retry_timeout;
        $this->finalize_request = $finalize_request;
        $this->save_state = $save_state;
        $this->audit = $audit;
    }

    public function download(array &$state, string $tables_file): void
    {
        $cursor = $state["cursor"] ?? null;
        $complete = false;

        $stats = $state["db_index"] ?? [];
        $tables_written = (int) ($stats["tables"] ?? 0);
        $rows_estimated = (int) ($stats["rows_estimated"] ?? 0);
        $bytes_written = (int) ($stats["bytes"] ?? 0);

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
                $params = [
                    "tables_per_batch" => 1000,
                ];
                $url = $this->build_url("db_index", $cursor, $params);

                $context = new StreamingContext();
                $response_handler = new DbIndexResponseHandler(
                    $handle,
                    $cursor,
                    $context,
                    $tables_written,
                    $rows_estimated,
                    $bytes_written,
                    function (): bool {
                        return $this->should_stop();
                    },
                    function (array $chunk, string $phase): void {
                        $this->handle_progress($chunk, $phase);
                    },
                    function (
                        array $chunk,
                        string $phase,
                        StreamingContext $context
                    ): void {
                        $this->handle_error($chunk, $phase, $context);
                    },
                    function (array $progress): void {
                        $this->handle_completion_progress($progress);
                    },
                );
                $context->on_chunk = [$response_handler, "handle"];

                $cursor_before = $cursor;
                $request_start = microtime(true);
                try {
                    $this->fetch_streaming(
                        $url,
                        $cursor,
                        $context,
                        null,
                        "db_index",
                    );
                } catch (CurlTimeoutException $e) {
                    $cursor = $response_handler->cursor();
                    $complete = $response_handler->complete();
                    $tables_written = $response_handler->tables_written();
                    $rows_estimated = $response_handler->rows_estimated();
                    $bytes_written = $response_handler->bytes_written();

                    $this->assert_can_retry_timeout("db_index", $cursor_before, $cursor);
                    fflush($handle);
                    $state["cursor"] = $cursor;
                    $state["db_index"] = $this->state_entry(
                        $tables_file,
                        $tables_written,
                        $rows_estimated,
                        $bytes_written,
                    );
                    $state["status"] = "partial";
                    $this->save_state($state);
                    return;
                }
                $cursor = $response_handler->cursor();
                $complete = $response_handler->complete();
                $tables_written = $response_handler->tables_written();
                $rows_estimated = $response_handler->rows_estimated();
                $bytes_written = $response_handler->bytes_written();

                $state["consecutive_timeouts"] = 0;
                $wall_time = microtime(true) - $request_start;
                $this->finalize_request(
                    "db_index",
                    $wall_time,
                    $context->response_stats ?? [],
                );

                fflush($handle);
                $state["cursor"] = $cursor;
                $state["db_index"] = $this->state_entry(
                    $tables_file,
                    $tables_written,
                    $rows_estimated,
                    $bytes_written,
                );
                $this->save_state($state);
            }
        } finally {
            fclose($handle);
        }
    }

    private function state_entry(
        string $tables_file,
        int $tables_written,
        int $rows_estimated,
        int $bytes_written
    ): array {
        return [
            "file" => $tables_file,
            "tables" => $tables_written,
            "rows_estimated" => $rows_estimated,
            "bytes" => $bytes_written,
            "updated_at" => time(),
        ];
    }

    private function build_url(string $endpoint, ?string $cursor, array $params): string
    {
        return (string) ($this->build_url)($endpoint, $cursor, $params);
    }

    private function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data,
        string $phase
    ): void {
        ($this->fetch_streaming)($url, $cursor, $context, $post_data, $phase);
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

    private function assert_can_retry_timeout(
        string $phase,
        ?string $cursor_before,
        ?string $cursor_after
    ): void {
        ($this->assert_can_retry_timeout)($phase, $cursor_before, $cursor_after);
    }

    private function finalize_request(string $endpoint, float $wall_time, array $stats): void
    {
        ($this->finalize_request)($endpoint, $wall_time, $stats);
    }

    private function save_state(array $state): void
    {
        ($this->save_state)($state);
    }

    private function audit(string $message, bool $to_console): void
    {
        ($this->audit)($message, $to_console);
    }
}
