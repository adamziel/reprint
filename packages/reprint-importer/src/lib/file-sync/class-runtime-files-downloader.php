<?php

namespace Reprint\Importer\FileSync;

use CURLFile;
use Reprint\Importer\Protocol\StreamingContext;
use RuntimeException;

final class RuntimeFilesDownloader
{
    /** @var callable */
    private $build_url;

    /** @var callable */
    private $fetch_streaming;

    /** @var callable */
    private $audit;

    public function __construct(
        callable $build_url,
        callable $fetch_streaming,
        callable $audit
    ) {
        $this->build_url = $build_url;
        $this->fetch_streaming = $fetch_streaming;
        $this->audit = $audit;
    }

    /**
     * Download auto_prepend_file and auto_append_file scripts into runtime_files/.
     *
     * @param array<string, mixed> $preflight_data
     */
    public function download(array $preflight_data, string $runtime_dir): int
    {
        if (is_dir($runtime_dir)) {
            $this->remove_directory_recursive($runtime_dir);
            ($this->audit)("RUNTIME FILES | deleted {$runtime_dir}");
        }

        $files = $this->files_from_preflight($preflight_data);
        if (empty($files)) {
            ($this->audit)("RUNTIME FILES | no prepend/append scripts to download");
            return 0;
        }

        if (!is_dir($runtime_dir)) {
            mkdir($runtime_dir, 0755, true);
        }

        ($this->audit)(
            "RUNTIME FILES | downloading " . count($files) . " script(s): " .
                implode(", ", $files),
        );

        $downloaded = $this->fetch_files_into($runtime_dir, $files);
        ($this->audit)("RUNTIME FILES | downloaded {$downloaded}/" . count($files) . " script(s)");

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
                $url = (string) ($this->build_url)("file_fetch", null, ["directory" => [$directory]]);

                $context->on_chunk = function ($chunk) use ($target_dir, $context, &$downloaded): void {
                    $this->handle_chunk($chunk, $target_dir, $context, $downloaded);
                };

                ($this->fetch_streaming)($url, null, $context, $post_data, "file_fetch");
            } catch (RuntimeException $e) {
                ($this->audit)(
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
        string $target_dir,
        StreamingContext $context,
        int &$downloaded
    ): void {
        $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

        if ($chunk_type === "file") {
            $this->handle_file_chunk($chunk, $target_dir, $context, $downloaded);
        } elseif ($chunk_type === "error") {
            $body = json_decode($chunk["body"] ?? "{}", true);
            $error_path = isset($body["path"]) ? base64_decode($body["path"]) : "unknown";
            ($this->audit)("Fetch error for {$error_path}: " . ($body["message"] ?? "unknown"));
        } elseif ($chunk_type === "completion") {
            $context->saw_completion = true;
        }
    }

    private function handle_file_chunk(
        array $chunk,
        string $target_dir,
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
        $local_path = $target_dir . $path;

        if ($is_first) {
            if ($context->file_handle) {
                fclose($context->file_handle);
                $context->file_handle = null;
            }
            $dir = dirname($local_path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $context->file_handle = @fopen($local_path, "wb");
            $context->file_path = $local_path;
        }

        if ($context->file_handle && isset($chunk["body"])) {
            fwrite($context->file_handle, $chunk["body"]);
        }

        if ($is_last && $context->file_handle) {
            fclose($context->file_handle);
            $context->file_handle = null;
            $downloaded++;
            ($this->audit)("Saved {$path} → {$local_path}");
        }
    }

    private function remove_directory_recursive(string $dir): void
    {
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
