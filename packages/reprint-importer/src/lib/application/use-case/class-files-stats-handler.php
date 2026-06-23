<?php

namespace Reprint\Importer\Application\UseCase;

use Reprint\Importer\Application\AbstractCommandHandler;
use Reprint\Importer\Application\ImportContext;
use Reprint\Importer\Application\ImportServices;
use Reprint\Importer\Command\FilesStatsResult;
use Reprint\Importer\Command\ImportCommandResult;
use Reprint\Importer\FileSync\DownloadList;

final class FilesStatsHandler extends AbstractCommandHandler
{
    public function execute(
        ImportContext $context,
        ImportServices $services,
        array $options
    ): ?ImportCommandResult {
        $remote_index = $context->paths()->remote_index_file();
        $download_list = $context->paths()->download_list_file();
        $size_by_path = [];

        if (is_file($remote_index)) {
            $handle = fopen($remote_index, "r");
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    $entry = $context->parse_index_line($line);
                    if ($entry !== null) {
                        $size_by_path[$entry["path"]] = $entry["size"];
                    }
                }
                fclose($handle);
            }
        }

        $checkpoint = $context->files_pull_checkpoint();
        [$pending_count, $pending_bytes] = $this->count_pending(
            $download_list,
            $checkpoint->fetch->offset,
            $size_by_path,
        );

        $skipped_list = $context->paths()->skipped_download_list_file();
        [$skipped_count, $skipped_bytes] = $this->count_pending(
            $skipped_list,
            $checkpoint->fetch_skipped->offset,
            $size_by_path,
        );

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

        if ($skipped_count > 0 || is_file($skipped_list)) {
            $stats["pending_skipped"] = [
                "files" => $skipped_count,
                "bytes" => $skipped_bytes,
            ];
        }

        return new FilesStatsResult($stats);
    }

    /**
     * @param array<string, int> $size_by_path
     * @return array{0: int, 1: int}
     */
    private function count_pending(string $file, int $offset, array $size_by_path): array
    {
        $count = 0;
        $bytes = 0;
        if (!is_file($file)) {
            return [$count, $bytes];
        }

        $handle = fopen($file, "r");
        if (!$handle) {
            return [$count, $bytes];
        }

        if ($offset > 0) {
            fseek($handle, $offset);
        }
        while (($line = fgets($handle)) !== false) {
            $path = DownloadList::read_path($line);
            if ($path === null) {
                continue;
            }
            $count++;
            $bytes += $size_by_path[$path] ?? 0;
        }
        fclose($handle);

        return [$count, $bytes];
    }
}
