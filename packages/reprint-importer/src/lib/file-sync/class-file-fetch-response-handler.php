<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\FileSync\Port\FileSyncStreamObserver;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\Protocol\StreamingContext;
use RuntimeException;

final class FileFetchResponseHandler
{
    private ?string $cursor;
    private string $state_key;
    private StreamingContext $context;
    private int $save_every;
    private int $chunks_since_save = 0;
    private bool $complete = false;
    private FilesPullCheckpoint $checkpoint;
    private FilesPullCheckpointStore $checkpoints;
    private ShutdownToken $shutdown;
    private FileSyncStreamObserver $observer;

    public function __construct(
        ?string $cursor,
        string $state_key,
        StreamingContext $context,
        int $save_every,
        FilesPullCheckpoint $checkpoint,
        ShutdownToken $shutdown,
        FilesPullCheckpointStore $checkpoints,
        FileSyncStreamObserver $observer
    ) {
        $this->cursor = $cursor;
        $this->state_key = $state_key;
        $this->context = $context;
        $this->save_every = $save_every;
        $this->checkpoint = $checkpoint;
        $this->shutdown = $shutdown;
        $this->checkpoints = $checkpoints;
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

    public function handle(array $chunk): void
    {
        if ($this->should_stop()) {
            throw new RuntimeException("Shutdown requested");
        }

        if (function_exists("pcntl_signal_dispatch")) {
            pcntl_signal_dispatch();
        }

        $this->checkpoint_if_needed($chunk);

        if (isset($chunk["headers"]["x-cursor"])) {
            $this->cursor = $chunk["headers"]["x-cursor"];
        }

        $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

        if ($chunk_type === "metadata") {
            $this->handle_metadata($chunk, $this->context);
        } elseif ($chunk_type === "file") {
            $this->handle_file($chunk, $this->context);
        } elseif ($chunk_type === "directory") {
            $this->handle_directory($chunk);
        } elseif ($chunk_type === "symlink") {
            $this->handle_symlink($chunk);
        } elseif ($chunk_type === "missing") {
            $path = base64_decode($chunk["headers"]["x-file-path"] ?? "");
            if ($path) {
                $this->handle_missing($path);
            }
        } elseif ($chunk_type === "error") {
            $this->handle_error($chunk, "files", $this->context);
        } elseif ($chunk_type === "progress") {
            $this->handle_progress($chunk, "files");
        } elseif ($chunk_type === "completion") {
            $this->handle_completion($chunk);
        }
    }

    private function checkpoint_if_needed(array $chunk): void
    {
        $is_streaming_body = !empty($chunk["is_streaming_body"]);
        $is_streaming_close = !empty($chunk["is_streaming_close"]);
        if ($is_streaming_body) {
            return;
        }

        $this->chunks_since_save++;
        if (!$is_streaming_close && $this->chunks_since_save < $this->save_every) {
            return;
        }

        $this->save_checkpoint(
            $this->state_key,
            $this->cursor,
            $this->context,
        );
        $this->chunks_since_save = 0;
    }

    private function handle_completion(array $chunk): void
    {
        $headers = $chunk["headers"];
        $this->complete = ($headers["x-status"] ?? "") === "complete";
        $this->context->saw_completion = true;
        $this->context->response_stats = [
            "status" => $headers["x-status"] ?? null,
            "bytes_processed" =>
                isset($headers["x-bytes-processed"])
                    ? (int) $headers["x-bytes-processed"]
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
            "phase" => "files",
            "status" => $headers["x-status"] ?? "unknown",
            "files_completed" => (int) ($headers["x-files-completed"] ?? 0),
            "bytes_processed" => (int) ($headers["x-bytes-processed"] ?? 0),
        ]);
    }

    private function should_stop(): bool
    {
        return $this->shutdown->is_shutdown_requested();
    }

    private function save_checkpoint(
        string $state_key,
        ?string $cursor,
        StreamingContext $context
    ): void {
        $this->checkpoint->fetch_checkpoint($state_key)->cursor = $cursor;
        $this->track_current_file($context);
        $this->checkpoints->save($this->checkpoint);
    }

    private function handle_metadata(array $chunk, StreamingContext $context): void
    {
        $this->observer->on_metadata_chunk($chunk, $context);
    }

    private function handle_file(array $chunk, StreamingContext $context): void
    {
        $this->observer->on_file_chunk($chunk, $context);
    }

    private function handle_directory(array $chunk): void
    {
        $this->observer->on_directory_chunk($chunk);
    }

    private function handle_symlink(array $chunk): void
    {
        $this->observer->on_symlink_chunk($chunk);
    }

    private function handle_missing(string $path): void
    {
        $this->observer->on_missing_path($path);
    }

    private function handle_error(
        array $chunk,
        string $phase,
        StreamingContext $context
    ): void {
        $this->observer->on_error_chunk($chunk, $phase, $context);
    }

    private function handle_progress(array $chunk, string $phase): void
    {
        $this->observer->on_progress_chunk($chunk, $phase);
    }

    private function handle_completion_progress(array $progress): void
    {
        $this->observer->on_completion_progress($progress);
    }

    private function track_current_file(StreamingContext $context): void
    {
        if ($context->file_handle && $context->file_path) {
            fflush($context->file_handle);
            $this->checkpoint->current_file = $context->file_path;
            $this->checkpoint->current_file_bytes = $context->file_bytes_written;
            return;
        }

        $this->checkpoint->current_file = null;
        $this->checkpoint->current_file_bytes = null;
    }
}
