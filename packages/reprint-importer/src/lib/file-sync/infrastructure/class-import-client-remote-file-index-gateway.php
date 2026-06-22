<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Port\RemoteFileIndexGateway;
use Reprint\Importer\ImportClient;

final class ImportClientRemoteFileIndexGateway implements RemoteFileIndexGateway
{
    private ImportClient $client;

    public function __construct(ImportClient $client)
    {
        $this->client = $client;
    }

    public function download(FilesPullCheckpoint $checkpoint, ?string $list_dir_override = null): bool
    {
        return $this->client->download_remote_index($checkpoint, $list_dir_override);
    }
}
