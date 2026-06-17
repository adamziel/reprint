<?php

namespace Reprint\Importer\Command;

use Reprint\Importer\ImportClient;

final class FilesStatsCommand extends ImportCommand
{
    public function execute(ImportClient $client, array $options): ?ImportCommandResult
    {
        $remote_index = $client->remote_index_file();
        $download_list = $client->download_list_file();

        $size_by_path = [];

        if (is_file($remote_index)) {
            $handle = fopen($remote_index, "r");
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    $entry = $client->parse_index_line($line);
                    if ($entry === null) {
                        continue;
                    }
                    $size_by_path[$entry["path"]] = $entry["size"];
                }
                fclose($handle);
            }
        }

        $pending_count = 0;
        $pending_bytes = 0;
        $fetch_offset = $client->state["fetch"]["offset"] ?? 0;
        if (is_file($download_list)) {
            $handle = fopen($download_list, "r");
            if ($handle) {
                if ($fetch_offset > 0) {
                    fseek($handle, $fetch_offset);
                }
                while (($line = fgets($handle)) !== false) {
                    $path = $this->read_download_list_path($line);
                    if ($path === null) {
                        continue;
                    }
                    $pending_count++;
                    $pending_bytes += $size_by_path[$path] ?? 0;
                }
                fclose($handle);
            }
        }

        $skipped_pending_count = 0;
        $skipped_pending_bytes = 0;
        $skipped_offset = $client->state["fetch_skipped"]["offset"] ?? 0;
        $skipped_list = $client->skipped_download_list_file();
        if (is_file($skipped_list)) {
            $handle = fopen($skipped_list, "r");
            if ($handle) {
                if ($skipped_offset > 0) {
                    fseek($handle, $skipped_offset);
                }
                while (($line = fgets($handle)) !== false) {
                    $path = $this->read_download_list_path($line);
                    if ($path === null) {
                        continue;
                    }
                    $skipped_pending_count++;
                    $skipped_pending_bytes += $size_by_path[$path] ?? 0;
                }
                fclose($handle);
            }
        }

        $stats = [
            "indexed" => [
                "files" => count($size_by_path),
                "bytes" => array_sum($size_by_path),
            ],
            "pending" => [
                "files" => $pending_count,
                "bytes" => $pending_bytes,
            ],
        ];

        if ($skipped_pending_count > 0 || is_file($skipped_list)) {
            $stats["pending_skipped"] = [
                "files" => $skipped_pending_count,
                "bytes" => $skipped_pending_bytes,
            ];
        }

        return new FilesStatsResult($stats);
    }

    private function read_download_list_path(string $line): ?string
    {
        $line = trim($line);
        if ($line === "") {
            return null;
        }

        $data = json_decode($line, true);
        if (!is_array($data)) {
            return null;
        }

        $path_encoded = $data["path"] ?? "";
        $path = base64_decode($path_encoded, true);
        if ($path === false || $path === "") {
            return null;
        }

        return $path;
    }
}
