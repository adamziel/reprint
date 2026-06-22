<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Port\FilesPullTimeoutPolicy;
use Reprint\Importer\ImportClient;

final class ImportClientFilesPullTimeoutPolicy implements FilesPullTimeoutPolicy
{
    private ImportClient $client;

    public function __construct(ImportClient $client)
    {
        $this->client = $client;
    }

    public function assert_can_retry(
        FilesPullCheckpoint $checkpoint,
        string $phase,
        ?string $cursor_before,
        ?string $cursor_after
    ): void {
        $this->client->assert_can_retry_files_pull_timeout(
            $checkpoint,
            $phase,
            $cursor_before,
            $cursor_after,
        );
    }
}
