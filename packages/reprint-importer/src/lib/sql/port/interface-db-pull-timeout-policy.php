<?php

namespace Reprint\Importer\Sql\Port;

use Reprint\Importer\Sql\DbPullCheckpoint;

interface DbPullTimeoutPolicy
{
    public function assert_can_retry(
        DbPullCheckpoint $checkpoint,
        string $phase,
        ?string $cursor_before,
        ?string $cursor_after
    ): void;
}
