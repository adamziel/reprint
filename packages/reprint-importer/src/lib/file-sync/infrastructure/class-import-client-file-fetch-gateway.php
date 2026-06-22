<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Port\FileFetchGateway;
use Reprint\Importer\ImportClient;

final class ImportClientFileFetchGateway implements FileFetchGateway
{
    private ImportClient $client;

    public function __construct(ImportClient $client)
    {
        $this->client = $client;
    }

    public function fetch_from_list(
        FilesPullCheckpoint $checkpoint,
        string $list_file,
        string $state_key
    ): bool {
        return $this->client->download_files_from_list($checkpoint, $list_file, $state_key);
    }
}
