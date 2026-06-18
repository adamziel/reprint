<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\Protocol\StreamingContext;
use RuntimeException;
use function Reprint\Exporter\assert_valid_path;

final class IndexResponseHandler
{
    /** @var resource */
    private $handle;

    private ?string $cursor;
    private StreamingContext $context;
    private int $save_every;
    private int $chunks_since_save = 0;
    private int $entries_counted;
    private bool $complete = false;

    /** @var callable */
    private $should_stop;

    /** @var callable */
    private $save_checkpoint;

    /** @var callable */
    private $handle_metadata;

    /** @var callable */
    private $handle_error;

    /** @var callable */
    private $handle_progress;

    /** @var callable */
    private $show_index_progress;

    public function __construct(
        $handle,
        ?string $cursor,
        StreamingContext $context,
        int $entries_counted,
        int $save_every,
        callable $should_stop,
        callable $save_checkpoint,
        callable $handle_metadata,
        callable $handle_error,
        callable $handle_progress,
        callable $show_index_progress
    ) {
        $this->handle = $handle;
        $this->cursor = $cursor;
        $this->context = $context;
        $this->entries_counted = $entries_counted;
        $this->save_every = $save_every;
        $this->should_stop = $should_stop;
        $this->save_checkpoint = $save_checkpoint;
        $this->handle_metadata = $handle_metadata;
        $this->handle_error = $handle_error;
        $this->handle_progress = $handle_progress;
        $this->show_index_progress = $show_index_progress;
    }

    public function cursor(): ?string
    {
        return $this->cursor;
    }

    public function complete(): bool
    {
        return $this->complete;
    }

    public function entries_counted(): int
    {
        return $this->entries_counted;
    }

    public function handle(array $chunk): void
    {
        if ($this->should_stop()) {
            throw new RuntimeException("Shutdown requested");
        }

        if (function_exists("pcntl_signal_dispatch")) {
            pcntl_signal_dispatch();
        }

        $this->chunks_since_save++;
        if ($this->chunks_since_save >= $this->save_every) {
            $this->save_checkpoint($this->cursor);
            $this->chunks_since_save = 0;
        }

        if (isset($chunk["headers"]["x-cursor"])) {
            $this->cursor = $chunk["headers"]["x-cursor"];
        }

        $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

        if ($chunk_type === "index_batch") {
            $this->handle_index_batch($chunk);
        } elseif ($chunk_type === "progress") {
            $this->handle_progress($chunk, "index");
        } elseif ($chunk_type === "metadata") {
            $this->handle_metadata($chunk, $this->context);
        } elseif ($chunk_type === "completion") {
            $this->handle_completion($chunk);
        } elseif ($chunk_type === "error") {
            $this->handle_error($chunk, "index", $this->context);
        }
    }

    private function handle_index_batch(array $chunk): void
    {
        $body = $chunk["body"] ?? "";
        if ($body === "") {
            return;
        }

        $items = json_decode($body, true);
        if (!is_array($items)) {
            throw new RuntimeException(
                "Invalid index batch JSON received from server",
            );
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $this->write_index_item($item);
        }

        $this->show_index_progress($this->entries_counted);
    }

    private function write_index_item(array $item): void
    {
        $path_encoded = $item["path"] ?? "";
        if (!is_string($path_encoded) || $path_encoded === "") {
            throw new RuntimeException(
                "Invalid index batch item: missing path",
            );
        }

        $path = base64_decode($path_encoded, true);
        if ($path === "" || $path === false) {
            throw new RuntimeException(
                "Invalid index batch item: path base64 decode failed",
            );
        }
        assert_valid_path($path, "index batch path");

        $entry = [
            "path" => base64_encode($path),
            "ctime" => (int) ($item["ctime"] ?? 0),
            "size" => (int) ($item["size"] ?? 0),
            "type" => (string) ($item["type"] ?? "file"),
        ];
        if (isset($item["target"]) && is_string($item["target"]) && $item["target"] !== "") {
            $entry["target"] = $item["target"];
        }
        if (!empty($item["intermediate"])) {
            $entry["intermediate"] = true;
        }

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }

        $bytes = fwrite($this->handle, $line . "\n");
        if ($bytes === false) {
            throw new RuntimeException("Failed to write to remote index file (disk full?)");
        }
        $this->entries_counted++;
    }

    private function handle_completion(array $chunk): void
    {
        $headers = $chunk["headers"];
        $this->complete = ($headers["x-status"] ?? "") === "complete";
        $this->context->saw_completion = true;
        $this->context->response_stats = [
            "status" => $headers["x-status"] ?? null,
            "entries_processed" =>
                isset($headers["x-total-entries"])
                    ? (int) $headers["x-total-entries"]
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

    private function should_stop(): bool
    {
        return (bool) ($this->should_stop)();
    }

    private function save_checkpoint(?string $cursor): void
    {
        ($this->save_checkpoint)($cursor);
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

    private function show_index_progress(int $entries_counted): void
    {
        ($this->show_index_progress)($entries_counted);
    }
}
