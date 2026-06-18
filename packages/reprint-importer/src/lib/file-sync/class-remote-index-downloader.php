<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\Protocol\CurlTimeoutException;
use Reprint\Importer\Protocol\StreamingContext;
use RuntimeException;

final class RemoteIndexDownloader
{
    /** @var callable */
    private $build_url;

    /** @var callable */
    private $fetch_streaming;

    /** @var callable */
    private $get_tuned_params;

    /** @var callable */
    private $should_stop;

    /** @var callable */
    private $save_state;

    /** @var callable */
    private $handle_metadata;

    /** @var callable */
    private $handle_error;

    /** @var callable */
    private $handle_progress;

    /** @var callable */
    private $show_progress;

    /** @var callable */
    private $assert_can_retry_timeout;

    /** @var callable */
    private $finalize_request;

    /** @var callable */
    private $count_lines;

    /** @var callable */
    private $audit;

    public function __construct(
        callable $build_url,
        callable $fetch_streaming,
        callable $get_tuned_params,
        callable $should_stop,
        callable $save_state,
        callable $handle_metadata,
        callable $handle_error,
        callable $handle_progress,
        callable $show_progress,
        callable $assert_can_retry_timeout,
        callable $finalize_request,
        callable $count_lines,
        callable $audit
    ) {
        $this->build_url = $build_url;
        $this->fetch_streaming = $fetch_streaming;
        $this->get_tuned_params = $get_tuned_params;
        $this->should_stop = $should_stop;
        $this->save_state = $save_state;
        $this->handle_metadata = $handle_metadata;
        $this->handle_error = $handle_error;
        $this->handle_progress = $handle_progress;
        $this->show_progress = $show_progress;
        $this->assert_can_retry_timeout = $assert_can_retry_timeout;
        $this->finalize_request = $finalize_request;
        $this->count_lines = $count_lines;
        $this->audit = $audit;
    }

    /**
     * Download the remote file index stream and append/write it to disk.
     *
     * @param array<string, mixed> $state
     * @param array{
     *     remote_index_file:string,
     *     roots:array<int, string>,
     *     export_dirs:array<int, string>,
     *     list_dir_override?:?string,
     *     follow_symlinks:bool,
     *     include_caches:bool,
     *     save_every:int
     * } $config
     */
    public function download(array &$state, array $config, int &$entries_counted): bool
    {
        $index_state = $state["index"] ?? [];
        $cursor = $index_state["cursor"] ?? null;

        $roots = $config["roots"];
        if (empty($roots)) {
            throw new RuntimeException(
                "No root directories found. Either add directory[]=... to the " .
                    "export URL, or run preflight first so directories can be auto-detected.",
            );
        }

        $remote_index_file = $config["remote_index_file"];
        $mode = file_exists($remote_index_file) ? "a" : "w";
        if ($mode === "a" && $entries_counted === 0) {
            $entries_counted = $this->count_lines($remote_index_file);
        }

        if ($mode === "w") {
            $this->audit(
                "FILE CREATE | {$remote_index_file} | downloading fresh remote index",
                true,
            );
        } else {
            $this->audit(
                "FILE APPEND | {$remote_index_file} | resuming remote index download",
                true,
            );
        }

        $handle = fopen($remote_index_file, $mode);
        if (!$handle) {
            throw new RuntimeException("Failed to open remote index file");
        }

        try {
            $params = $this->get_tuned_params("file_index");
            if ($cursor === null) {
                $params["list_dir"] = $config["list_dir_override"] ?? $roots[0];
            }
            if ($config["follow_symlinks"]) {
                $params["follow_symlinks"] = "1";
            }
            if ($config["include_caches"]) {
                $params["include_caches"] = "1";
            }
            $export_dirs = $config["export_dirs"];
            if (!empty($export_dirs)) {
                $params["directory"] = $export_dirs;
            }

            $url = $this->build_url("file_index", $cursor, $params);
            $context = new StreamingContext();

            $response_handler = new IndexResponseHandler(
                $handle,
                $cursor,
                $context,
                $entries_counted,
                $config["save_every"],
                function (): bool {
                    return $this->should_stop();
                },
                function (?string $cursor) use (&$state): void {
                    $state["index"] = [
                        "cursor" => $cursor,
                    ];
                    $this->save_state($state);
                },
                function (array $chunk, StreamingContext $context): void {
                    $this->handle_metadata($chunk, $context);
                },
                function (array $chunk, string $phase, StreamingContext $context): void {
                    $this->handle_error($chunk, $phase, $context);
                },
                function (array $chunk, string $phase): void {
                    $this->handle_progress($chunk, $phase);
                },
                function (int $entries_counted): void {
                    $this->show_progress($entries_counted);
                },
            );
            $context->on_chunk = [$response_handler, 'handle'];

            $cursor_before = $cursor;
            $request_start = microtime(true);
            try {
                $this->fetch_streaming($url, $cursor, $context, null, "file_index");
            } catch (CurlTimeoutException $e) {
                $cursor = $response_handler->cursor();
                $entries_counted = $response_handler->entries_counted();
                $this->assert_can_retry_timeout("file_index", $cursor_before, $cursor);

                fclose($handle);
                $handle = null;

                $state["index"] = ["cursor" => $cursor];
                $state["status"] = "partial";
                $this->save_state($state);
                return false;
            }

            $cursor = $response_handler->cursor();
            $complete = $response_handler->complete();
            $entries_counted = $response_handler->entries_counted();
            $state["consecutive_timeouts"] = 0;
            $wall_time = microtime(true) - $request_start;
            $this->finalize_request(
                "file_index",
                $wall_time,
                $context->response_stats ?? [],
            );

            fclose($handle);
            $handle = null;

            $state["index"] = [
                "cursor" => $complete ? null : $cursor,
            ];
            $this->save_state($state);

            return $complete;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
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

    private function get_tuned_params(string $endpoint): array
    {
        return (array) ($this->get_tuned_params)($endpoint);
    }

    private function should_stop(): bool
    {
        return (bool) ($this->should_stop)();
    }

    private function save_state(array $state): void
    {
        ($this->save_state)($state);
    }

    private function handle_metadata(array $chunk, StreamingContext $context): void
    {
        ($this->handle_metadata)($chunk, $context);
    }

    private function handle_error(
        array $chunk,
        string $phase,
        StreamingContext $context
    ): void {
        ($this->handle_error)($chunk, $phase, $context);
    }

    private function handle_progress(array $chunk, string $phase): void
    {
        ($this->handle_progress)($chunk, $phase);
    }

    private function show_progress(int $entries_counted): void
    {
        ($this->show_progress)($entries_counted);
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

    private function count_lines(string $path): int
    {
        return (int) ($this->count_lines)($path);
    }

    private function audit(string $message, bool $to_console): void
    {
        ($this->audit)($message, $to_console);
    }
}
