<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\Protocol\CurlTimeoutException;
use Reprint\Importer\Protocol\StreamingContext;

final class FileFetchDownloader
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
    private $handle_file;

    /** @var callable */
    private $handle_directory;

    /** @var callable */
    private $handle_symlink;

    /** @var callable */
    private $handle_error;

    /** @var callable */
    private $handle_progress;

    /** @var callable */
    private $handle_completion_progress;

    /** @var callable */
    private $assert_can_retry_timeout;

    /** @var callable */
    private $finalize_request;

    /** @var callable */
    private $finalize_index_updates;

    /** @var callable */
    private $audit;

    public function __construct(
        callable $build_url,
        callable $fetch_streaming,
        callable $get_tuned_params,
        callable $should_stop,
        callable $save_state,
        callable $handle_metadata,
        callable $handle_file,
        callable $handle_directory,
        callable $handle_symlink,
        callable $handle_error,
        callable $handle_progress,
        callable $handle_completion_progress,
        callable $assert_can_retry_timeout,
        callable $finalize_request,
        callable $finalize_index_updates,
        callable $audit
    ) {
        $this->build_url = $build_url;
        $this->fetch_streaming = $fetch_streaming;
        $this->get_tuned_params = $get_tuned_params;
        $this->should_stop = $should_stop;
        $this->save_state = $save_state;
        $this->handle_metadata = $handle_metadata;
        $this->handle_file = $handle_file;
        $this->handle_directory = $handle_directory;
        $this->handle_symlink = $handle_symlink;
        $this->handle_error = $handle_error;
        $this->handle_progress = $handle_progress;
        $this->handle_completion_progress = $handle_completion_progress;
        $this->assert_can_retry_timeout = $assert_can_retry_timeout;
        $this->finalize_request = $finalize_request;
        $this->finalize_index_updates = $finalize_index_updates;
        $this->audit = $audit;
    }

    /**
     * Download file content for a prepared file list.
     *
     * @param array<string, mixed> $state
     * @param array{
     *     post_data:?array,
     *     cursor:?string,
     *     state_key:string,
     *     export_dirs:array<int, string>,
     *     save_every:int
     * } $config
     */
    public function download(array &$state, array $config): bool
    {
        $state_key = $config["state_key"];
        $cursor = $config["cursor"] ?? null;
        $cursor = $cursor ?? ($state[$state_key]["cursor"] ?? null);

        $tracked_file = $state["current_file"] ?? null;
        $tracked_bytes = $state["current_file_bytes"] ?? null;
        if ($tracked_file !== null && $tracked_bytes !== null && file_exists($tracked_file)) {
            $actual_size = filesize($tracked_file);
            if ($actual_size > $tracked_bytes) {
                $this->audit(
                    sprintf(
                        "CRASH RECOVERY | Truncating %s from %d to %d bytes",
                        $tracked_file,
                        $actual_size,
                        $tracked_bytes,
                    ),
                    true,
                );
                $handle = fopen($tracked_file, "r+");
                if ($handle) {
                    ftruncate($handle, $tracked_bytes);
                    fclose($handle);
                }
            }
        }

        $params = $this->get_tuned_params("file_fetch");
        $export_dirs = $config["export_dirs"];
        if (!empty($export_dirs)) {
            $params["directory"] = $export_dirs;
        }

        $url = $this->build_url("file_fetch", $cursor, $params);
        $post_data = $config["post_data"];
        $this->audit("Downloading file fetch from {$url}", true);
        $this->audit("POST data: " . json_encode($post_data), true);

        $context = new StreamingContext();
        $context->file_handle = null;
        $context->file_path = null;
        $context->file_ctime = null;

        if ($tracked_file !== null && $tracked_bytes !== null && file_exists($tracked_file)) {
            $context->file_handle = fopen($tracked_file, "ab");
            if ($context->file_handle) {
                $context->file_path = $tracked_file;
                $context->file_bytes_written = $tracked_bytes;
                $this->audit(
                    sprintf(
                        "RESUME FILE | Re-opened %s at %d bytes for continued download",
                        $tracked_file,
                        $tracked_bytes,
                    ),
                    true,
                );
            }
        }

        $response_handler = new FileFetchResponseHandler(
            $cursor,
            $state_key,
            $context,
            $config["save_every"],
            function (): bool {
                return $this->should_stop();
            },
            function (string $state_key, ?string $cursor, StreamingContext $context) use (&$state): void {
                $this->save_file_fetch_checkpoint($state, $state_key, $cursor, $context);
            },
            function (array $chunk, StreamingContext $context): void {
                $this->handle_metadata($chunk, $context);
            },
            function (array $chunk, StreamingContext $context): void {
                $this->handle_file($chunk, $context);
            },
            function (array $chunk): void {
                $this->handle_directory($chunk);
            },
            function (array $chunk): void {
                $this->handle_symlink($chunk);
            },
            function (string $path): void {
                $this->audit("Missing on server: {$path}", true);
            },
            function (array $chunk, string $phase, StreamingContext $context): void {
                $this->handle_error($chunk, $phase, $context);
            },
            function (array $chunk, string $phase): void {
                $this->handle_progress($chunk, $phase);
            },
            function (array $progress): void {
                $this->handle_completion_progress($progress);
            },
        );
        $context->on_chunk = [$response_handler, 'handle'];

        $cursor_before = $cursor;
        $request_start = microtime(true);
        try {
            $this->fetch_streaming(
                $url,
                $cursor,
                $context,
                $post_data,
                "file_fetch",
            );
        } catch (CurlTimeoutException $e) {
            $cursor = $response_handler->cursor();
            $this->assert_can_retry_timeout("file_fetch", $cursor_before, $cursor);
            $state[$state_key]["cursor"] = $cursor;
            $this->finalize_index_updates();
            $this->track_current_file_if_active($state, $context);
            $state["status"] = "partial";
            $this->save_state($state);
            return false;
        }

        $cursor = $response_handler->cursor();
        $complete = $response_handler->complete();
        $state["consecutive_timeouts"] = 0;
        $wall_time = microtime(true) - $request_start;

        $this->finalize_request(
            "file_fetch",
            $wall_time,
            $context->response_stats ?? [],
        );
        $state[$state_key]["cursor"] = $cursor;
        $this->finalize_index_updates();
        $this->track_current_file($state, $context);
        $this->save_state($state);

        return $complete;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save_file_fetch_checkpoint(
        array &$state,
        string $state_key,
        ?string $cursor,
        StreamingContext $context
    ): void {
        $state[$state_key]["cursor"] = $cursor;
        $this->track_current_file($state, $context);
        $this->save_state($state);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function track_current_file(array &$state, StreamingContext $context): void
    {
        if ($context->file_handle && $context->file_path) {
            fflush($context->file_handle);
            $state["current_file"] = $context->file_path;
            $state["current_file_bytes"] = $context->file_bytes_written;
            return;
        }

        $state["current_file"] = null;
        $state["current_file_bytes"] = null;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function track_current_file_if_active(array &$state, StreamingContext $context): void
    {
        if ($context->file_handle && $context->file_path) {
            fflush($context->file_handle);
            $state["current_file"] = $context->file_path;
            $state["current_file_bytes"] = $context->file_bytes_written;
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

    private function handle_file(array $chunk, StreamingContext $context): void
    {
        ($this->handle_file)($chunk, $context);
    }

    private function handle_directory(array $chunk): void
    {
        ($this->handle_directory)($chunk);
    }

    private function handle_symlink(array $chunk): void
    {
        ($this->handle_symlink)($chunk);
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

    private function finalize_index_updates(): void
    {
        ($this->finalize_index_updates)();
    }

    private function audit(string $message, bool $to_console): void
    {
        ($this->audit)($message, $to_console);
    }
}
