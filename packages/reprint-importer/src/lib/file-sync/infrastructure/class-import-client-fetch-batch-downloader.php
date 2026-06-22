<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use CURLFile;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Port\FetchBatchDownloader;
use Reprint\Importer\ImportClient;

final class ImportClientFetchBatchDownloader implements FetchBatchDownloader
{
    private ImportClient $client;
    private FilesPullCheckpoint $checkpoint;

    public function __construct(ImportClient $client, FilesPullCheckpoint $checkpoint)
    {
        $this->client = $client;
        $this->checkpoint = $checkpoint;
    }

    public function download_batch(
        string $batch_file,
        ?string $cursor,
        string $state_key
    ): bool {
        $post_data = [
            'file_list' => new CURLFile(
                $batch_file,
                'application/json',
                'file-list.json',
            ),
        ];

        return $this->client->download_file_fetch(
            $this->checkpoint,
            $post_data,
            $cursor,
            $state_key,
        );
    }
}
