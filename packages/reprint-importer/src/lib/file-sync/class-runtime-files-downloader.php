<?php

namespace Reprint\Importer\FileSync;

use CURLFile;
use Reprint\Importer\Filesystem\LocalImportFilesystem;
use Reprint\Importer\FileSync\Port\FileSyncStreamClient;
use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Protocol\StreamingContext;
use RuntimeException;

final class RuntimeFilesDownloader
{
    private FileSyncStreamClient $stream;
    private AuditLogger $audit;

    public function __construct(
        FileSyncStreamClient $stream,
        AuditLogger $audit
    ) {
        $this->stream = $stream;
        $this->audit = $audit;
    }

    /**
     * Download auto_prepend_file and auto_append_file scripts into runtime_files/.
     *
     * @param array<string, mixed> $preflight_data
     */
    public function download(array $preflight_data, string $runtime_dir): int
    {
        if (is_link($runtime_dir) || is_file($runtime_dir)) {
            @unlink($runtime_dir);
            $this->audit->record("RUNTIME FILES | removed non-directory {$runtime_dir}");
        } elseif (is_dir($runtime_dir)) {
            $this->remove_directory_recursive($runtime_dir);
            $this->audit->record("RUNTIME FILES | deleted {$runtime_dir}");
        }

        $files = $this->files_from_preflight($preflight_data);
        if (empty($files)) {
            $this->audit->record("RUNTIME FILES | no prepend/append scripts to download");
            return 0;
        }

        if (!is_dir($runtime_dir) && !mkdir($runtime_dir, 0755, true) && !is_dir($runtime_dir)) {
            throw new RuntimeException("Failed to create runtime files directory: {$runtime_dir}");
        }

        $this->audit->record(
            "RUNTIME FILES | downloading " . count($files) . " script(s): " .
                implode(", ", $files),
        );

        $downloaded = $this->fetch_files_into($runtime_dir, $files);
        $this->audit->record("RUNTIME FILES | downloaded {$downloaded}/" . count($files) . " script(s)");

        return $downloaded;
    }

    /**
     * @param array<string, mixed> $preflight_data
     * @return string[]
     */
    private function files_from_preflight(array $preflight_data): array
    {
        $ini_all = $preflight_data["runtime"]["ini_get_all"] ?? [];
        $files = [];
        foreach (["auto_prepend_file", "auto_append_file"] as $key) {
            $path = $ini_all[$key] ?? "";
            if (is_string($path) && $path !== "") {
                $files[] = $path;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * Download a list of absolute remote paths into $target_dir,
     * preserving their directory structure.
     *
     * Issues one file_fetch request per parent directory so that an
     * inaccessible directory does not block the others. All errors are
     * logged as non-fatal.
     *
     * @param string[] $files
     */
    private function fetch_files_into(string $target_dir, array $files): int
    {
        $filesystem = new LocalImportFilesystem($target_dir, 'error', $this->audit);
        $by_dir = [];
        foreach ($files as $file) {
            $parent = dirname($file);
            if ($parent !== "" && $parent !== ".") {
                $by_dir[rtrim($parent, "/")][] = $file;
            }
        }

        $downloaded = 0;

        foreach ($by_dir as $directory => $dir_files) {
            $tmp = tempnam(sys_get_temp_dir(), "fetch-into-");
            if ($tmp === false) {
                continue;
            }

            $context = new StreamingContext();
            $context->file_handle = null;
            $context->file_path = null;
            $context->file_ctime = null;

            try {
                file_put_contents($tmp, json_encode($dir_files, JSON_UNESCAPED_SLASHES));
                $post_data = [
                    "file_list" => new CURLFile($tmp, "application/json", "file_list"),
                ];
                $url = $this->stream->build_url("file_fetch", null, ["directory" => [$directory]]);

                $context->on_chunk = function ($chunk) use ($filesystem, $context, &$downloaded): void {
                    $this->handle_chunk($chunk, $filesystem, $context, $downloaded);
                };

                $this->stream->fetch_streaming($url, null, $context, $post_data, "file_fetch");
            } catch (RuntimeException $e) {
                $this->audit->record(
                    "Fetch failed for directory {$directory} (non-fatal): " .
                        substr($e->getMessage(), 0, 200),
                );
            } finally {
                @unlink($tmp);

                if ($context->file_handle) {
                    fclose($context->file_handle);
                    $context->file_handle = null;
                }
            }
        }

        return $downloaded;
    }

    private function handle_chunk(
        array $chunk,
        LocalImportFilesystem $filesystem,
        StreamingContext $context,
        int &$downloaded
    ): void {
        $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

        if ($chunk_type === "file") {
            $this->handle_file_chunk($chunk, $filesystem, $context, $downloaded);
        } elseif ($chunk_type === "error") {
            $body = json_decode($chunk["body"] ?? "{}", true);
            $error_path = isset($body["path"]) ? base64_decode($body["path"]) : "unknown";
            $this->audit->record("Fetch error for {$error_path}: " . ($body["message"] ?? "unknown"));
        } elseif ($chunk_type === "completion") {
            $context->saw_completion = true;
        }
    }

    private function handle_file_chunk(
        array $chunk,
        LocalImportFilesystem $filesystem,
        StreamingContext $context,
        int &$downloaded
    ): void {
        $raw = $chunk["headers"]["x-file-path"] ?? "";
        $path = base64_decode($raw, true);
        if ($path === false || $path === "") {
            return;
        }

        $is_first = ($chunk["headers"]["x-first-chunk"] ?? "0") === "1";
        $is_last = ($chunk["headers"]["x-last-chunk"] ?? "0") === "1";
        try {
            $local_path = $filesystem->local_path_for_remote_path($path);
        } catch (\Throwable $e) {
            $this->audit->record(
                "RUNTIME FILES | refusing invalid runtime file path {$path}: " .
                    $e->getMessage(),
            );
            return;
        }

        if ($is_first) {
            if ($context->file_handle) {
                fclose($context->file_handle);
                $context->file_handle = null;
                $context->file_path = null;
            }

            try {
                $dir = dirname($local_path);
                $filesystem->ensure_directory_path($dir);
                if (is_link($local_path)) {
                    throw new RuntimeException(
                        "Security: Refusing to write runtime file through symlink: {$path}",
                    );
                }
            } catch (\Throwable $e) {
                $this->audit->record(
                    "RUNTIME FILES | refusing runtime file path {$path}: " .
                        $e->getMessage(),
                );
                return;
            }
            $context->file_handle = @fopen($local_path, "wb");
            if (!$context->file_handle) {
                $this->audit->record("RUNTIME FILES | failed to open {$local_path} for writing");
                $context->file_path = null;
                return;
            }
            $context->file_path = $local_path;
        }

        if ($context->file_handle && $context->file_path === $local_path && isset($chunk["body"])) {
            fwrite($context->file_handle, $chunk["body"]);
        }

        if ($is_last && $context->file_handle && $context->file_path === $local_path) {
            fclose($context->file_handle);
            $context->file_handle = null;
            $context->file_path = null;
            $downloaded++;
            $this->audit->record("Saved {$path} → {$local_path}");
        }
    }

    private function remove_directory_recursive(string $dir): void
    {
        if (is_link($dir)) {
            @unlink($dir);
            return;
        }

        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }

            $path = $dir . "/" . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->remove_directory_recursive($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
