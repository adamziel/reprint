<?php

namespace Reprint\Importer\Application;

use Reprint\Importer\FileSync\Infrastructure\TransportFileSyncStreamClient;
use Reprint\Importer\FileSync\Port\FileSyncStreamClient;
use Reprint\Importer\FileSync\RuntimeFilesDownloader;

final class RuntimeServices
{
    private ImportContext $context;
    private ?FileSyncStreamClient $stream_client;

    public function __construct(
        ImportContext $context,
        ?FileSyncStreamClient $stream_client = null
    ) {
        $this->context = $context;
        $this->stream_client = $stream_client;
    }

    public function download_runtime_files(): void
    {
        (new RuntimeFilesDownloader(
            $this->stream_client(),
            $this->context->audit_logger(),
        ))->download(
            $this->context->preflight_data() ?? [],
            $this->context->paths()->runtime_files_dir(),
        );
    }

    private function stream_client(): FileSyncStreamClient
    {
        if ($this->stream_client instanceof FileSyncStreamClient) {
            return $this->stream_client;
        }

        return new TransportFileSyncStreamClient($this->context->http_session());
    }
}
