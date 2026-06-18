<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\Protocol\PreserveLocalSkipException;
use Reprint\Importer\Protocol\StreamingContext;
use RuntimeException;

final class FileChunkApplier
{
    private int $files_imported;

    /** @var callable */
    private $local_path_for_remote_path;

    /** @var callable */
    private $remove_path;

    /** @var callable */
    private $ensure_directory;

    /** @var callable */
    private $audit;

    /** @var callable */
    private $file_started;

    /** @var callable */
    private $emit_skip;

    /** @var callable */
    private $upsert_index_entry;

    /** @var callable */
    private $clear_volatile_file;

    /** @var callable */
    private $set_current_file;

    public function __construct(
        int $files_imported,
        callable $local_path_for_remote_path,
        callable $remove_path,
        callable $ensure_directory,
        callable $audit,
        callable $file_started,
        callable $emit_skip,
        callable $upsert_index_entry,
        callable $clear_volatile_file,
        callable $set_current_file
    ) {
        $this->files_imported = $files_imported;
        $this->local_path_for_remote_path = $local_path_for_remote_path;
        $this->remove_path = $remove_path;
        $this->ensure_directory = $ensure_directory;
        $this->audit = $audit;
        $this->file_started = $file_started;
        $this->emit_skip = $emit_skip;
        $this->upsert_index_entry = $upsert_index_entry;
        $this->clear_volatile_file = $clear_volatile_file;
        $this->set_current_file = $set_current_file;
    }

    public function files_imported(): int
    {
        return $this->files_imported;
    }

    public function handle(array $chunk, StreamingContext $context): void
    {
        $headers = $chunk["headers"];
        $raw_header = $headers["x-file-path"] ?? "";
        $path = base64_decode($raw_header, true);
        $is_first = ($headers["x-first-chunk"] ?? "0") === "1";
        $is_last = ($headers["x-last-chunk"] ?? "0") === "1";

        if ($path === false || $path === "") {
            if ($raw_header !== "") {
                $this->audit(
                    "Warning: base64_decode failed for x-file-path header: " .
                    substr($raw_header, 0, 100),
                    true,
                );
            }
            return;
        }

        $local_path = $this->local_path_for_remote_path($path);

        if ($is_first) {
            $context->skip_current_file = false;

            if (
                (file_exists($local_path) || is_link($local_path)) &&
                (!is_file($local_path) || is_link($local_path))
            ) {
                if (!$this->remove_path($local_path)) {
                    throw new RuntimeException(
                        "Failed to replace path with file: {$path}",
                    );
                }
            }

            $exists_locally = file_exists($local_path);
            $local_size = $exists_locally ? filesize($local_path) : 0;
            $file_size = (int) ($headers["x-file-size"] ?? 0);

            $this->audit(
                sprintf(
                    "File: %s (remote_size=%d, ctime=%d, local_exists=%s, local_size=%d)",
                    $path,
                    $file_size,
                    (int) ($headers["x-file-ctime"] ?? 0),
                    $exists_locally ? "yes" : "no",
                    $local_size,
                ),
                false,
            );
            $this->file_started($path, $file_size);
        }

        if ($context->skip_current_file) {
            return;
        }

        if ($is_first) {
            if ($context->file_handle) {
                fclose($context->file_handle);
                if ($context->file_ctime && $context->file_path) {
                    touch($context->file_path, $context->file_ctime);
                }
            }

            $dir = dirname($local_path);
            if (!is_dir($dir)) {
                try {
                    $this->ensure_directory($dir);
                } catch (PreserveLocalSkipException $e) {
                    $context->skip_current_file = true;
                    $this->audit($e->getMessage(), true);
                    $this->emit_skip($path);
                    return;
                }
            }

            $context->file_handle = fopen($local_path, "wb");
            if (!$context->file_handle) {
                $error = error_get_last();
                throw new RuntimeException(
                    "Failed to open file for writing: {$local_path}\n" .
                    "Parent directory: {$dir}\n" .
                    "Directory exists: " .
                    (is_dir($dir) ? "yes" : "no") .
                    "\n" .
                    "Error: " .
                    ($error["message"] ?? "unknown"),
                );
            }
            $context->file_path = $local_path;
            $context->file_ctime = (int) ($headers["x-file-ctime"] ?? 0);
            $context->file_bytes_written = 0;
        }

        if (isset($chunk["body"]) && $chunk["body"] !== "") {
            if ($context->file_handle) {
                $data = $chunk["body"];
                $bytes = fwrite($context->file_handle, $data);
                if ($bytes === false || $bytes !== strlen($data)) {
                    throw new RuntimeException(
                        "Write failed for {$context->file_path}: wrote " .
                        ($bytes === false ? "0" : $bytes) . "/" . strlen($data) .
                        " bytes (disk full?)"
                    );
                }
                $context->file_bytes_written += $bytes;
            }
        }

        if ($is_last && $context->file_handle) {
            fclose($context->file_handle);

            if ($context->file_ctime && $context->file_path) {
                touch($context->file_path, $context->file_ctime);
            }

            $file_size = (int) ($headers["x-file-size"] ?? 0);
            $final_size = file_exists($context->file_path)
                ? filesize($context->file_path)
                : 0;
            $file_changed = ($headers["x-file-changed"] ?? "0") === "1";

            if ($context->file_ctime && !$file_changed) {
                $this->upsert_index_entry(
                    $path,
                    $context->file_ctime,
                    $file_size,
                    "file",
                );
                $this->files_imported++;
                $this->clear_volatile_file($path);
                $this->audit(
                    sprintf("  Indexed (wrote %d bytes)", $final_size),
                    false,
                );
            } elseif ($file_changed) {
                $this->audit(
                    "  File changed during stream; index not updated",
                    true,
                );
            }

            $context->file_handle = null;
            $context->file_path = null;
            $context->file_ctime = null;
            $context->file_bytes_written = 0;
            $this->set_current_file(null, null);
        }
    }

    private function local_path_for_remote_path(string $path): string
    {
        return (string) ($this->local_path_for_remote_path)($path);
    }

    private function remove_path(string $local_path): bool
    {
        return (bool) ($this->remove_path)($local_path);
    }

    private function ensure_directory(string $dir): void
    {
        ($this->ensure_directory)($dir);
    }

    private function audit(string $message, bool $to_console): void
    {
        ($this->audit)($message, $to_console);
    }

    private function file_started(string $path, int $file_size): void
    {
        ($this->file_started)($path, $file_size);
    }

    private function emit_skip(string $path): void
    {
        ($this->emit_skip)($path);
    }

    private function upsert_index_entry(
        string $path,
        int $ctime,
        int $size,
        string $type
    ): void {
        ($this->upsert_index_entry)($path, $ctime, $size, $type);
    }

    private function clear_volatile_file(string $path): void
    {
        ($this->clear_volatile_file)($path);
    }

    private function set_current_file(?string $path, ?int $bytes): void
    {
        ($this->set_current_file)($path, $bytes);
    }
}
