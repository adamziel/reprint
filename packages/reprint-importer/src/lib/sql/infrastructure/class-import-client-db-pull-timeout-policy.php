<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\ImportClient;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\Port\DbPullTimeoutPolicy;

final class ImportClientDbPullTimeoutPolicy implements DbPullTimeoutPolicy
{
    private ImportClient $client;

    public function __construct(ImportClient $client)
    {
        $this->client = $client;
    }

    public function assert_can_retry(
        DbPullCheckpoint $checkpoint,
        string $phase,
        ?string $cursor_before,
        ?string $cursor_after
    ): void {
        $this->client->assert_can_retry_db_pull_timeout(
            $checkpoint,
            $phase,
            $cursor_before,
            $cursor_after,
        );
    }
}
