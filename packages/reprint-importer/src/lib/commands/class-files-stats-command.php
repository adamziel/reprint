<?php

namespace Reprint\Importer\Command;

use Reprint\Importer\FileSync\DownloadList;
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
        $checkpoint = $client->files_pull_checkpoint();
        $fetch_offset = $checkpoint->fetch->offset;
        if (is_file($download_list)) {
            $handle = fopen($download_list, "r");
            if ($handle) {
                if ($fetch_offset > 0) {
                    fseek($handle, $fetch_offset);
                }
                while (($line = fgets($handle)) !== false) {
                    $path = DownloadList::read_path($line);
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
        $skipped_offset = $checkpoint->fetch_skipped->offset;
        $skipped_list = $client->skipped_download_list_file();
        if (is_file($skipped_list)) {
            $handle = fopen($skipped_list, "r");
            if ($handle) {
                if ($skipped_offset > 0) {
                    fseek($handle, $skipped_offset);
                }
                while (($line = fgets($handle)) !== false) {
                    $path = DownloadList::read_path($line);
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
}
