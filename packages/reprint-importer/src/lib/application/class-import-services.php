<?php

namespace Reprint\Importer\Application;

use Reprint\Importer\FileSync\Port\FileSyncStreamClient;
use Reprint\Importer\Sql\Port\SqlStreamClient;

final class ImportServices
{
    private FileSyncServices $files;
    private DatabaseServices $database;
    private RuntimeServices $runtime;

    public function __construct(
        ImportContext $context,
        ?FileSyncStreamClient $file_sync_stream_client = null,
        ?SqlStreamClient $sql_stream_client = null
    ) {
        $this->files = new FileSyncServices($context, $file_sync_stream_client);
        $this->database = new DatabaseServices($context, $sql_stream_client);
        $this->runtime = new RuntimeServices($context, $file_sync_stream_client);
    }

    public function files(): FileSyncServices
    {
        return $this->files;
    }

    public function database(): DatabaseServices
    {
        return $this->database;
    }

    public function runtime(): RuntimeServices
    {
        return $this->runtime;
    }
}
