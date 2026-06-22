<?php

namespace Reprint\Importer\FileSync\Port;

use Reprint\Importer\FileSync\FilesPullCheckpoint;

interface FilesPullTimeoutPolicy
{
    public function assert_can_retry(
        FilesPullCheckpoint $checkpoint,
        string $phase,
        ?string $cursor_before,
        ?string $cursor_after
    ): void;
}
