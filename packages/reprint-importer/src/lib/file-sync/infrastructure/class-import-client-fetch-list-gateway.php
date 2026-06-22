<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Port\FetchListGateway;
use Reprint\Importer\ImportClient;

final class ImportClientFetchListGateway implements FetchListGateway
{
    private ImportClient $client;

    public function __construct(ImportClient $client)
    {
        $this->client = $client;
    }

    public function build(FilesPullCheckpoint $checkpoint): bool
    {
        return $this->client->diff_indexes_and_build_fetch_list($checkpoint);
    }
}
