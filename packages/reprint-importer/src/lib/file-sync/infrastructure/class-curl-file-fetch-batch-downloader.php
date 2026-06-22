<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use CURLFile;
use Reprint\Importer\FileSync\FileFetchDownloader;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Port\FetchBatchDownloader;

final class CurlFileFetchBatchDownloader implements FetchBatchDownloader
{
    private FileFetchDownloader $downloader;
    private FilesPullCheckpoint $checkpoint;
    private array $export_dirs;
    private int $save_every;

    /**
     * @param array<int, string> $export_dirs
     */
    public function __construct(
        FileFetchDownloader $downloader,
        FilesPullCheckpoint $checkpoint,
        array $export_dirs,
        int $save_every
    ) {
        $this->downloader = $downloader;
        $this->checkpoint = $checkpoint;
        $this->export_dirs = $export_dirs;
        $this->save_every = $save_every;
    }

    public function download_batch(
        string $batch_file,
        ?string $cursor,
        string $state_key
    ): bool {
        return $this->downloader->download(
            $this->checkpoint,
            [
                "post_data" => [
                    "file_list" => new CURLFile(
                        $batch_file,
                        "application/json",
                        "file-list.json",
                    ),
                ],
                "cursor" => $cursor,
                "state_key" => $state_key,
                "export_dirs" => $this->export_dirs,
                "save_every" => $this->save_every,
            ],
        );
    }
}
