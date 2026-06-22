<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\Port\DbPullTimeoutPolicy;
use RuntimeException;

final class DbPullCurlTimeoutPolicy implements DbPullTimeoutPolicy
{
    private AuditLogger $audit;
    private int $max_consecutive_timeouts;

    public function __construct(AuditLogger $audit, int $max_consecutive_timeouts = 3)
    {
        $this->audit = $audit;
        $this->max_consecutive_timeouts = $max_consecutive_timeouts;
    }

    public function assert_can_retry(
        DbPullCheckpoint $checkpoint,
        string $phase,
        ?string $cursor_before,
        ?string $cursor_after
    ): void {
        if ($cursor_after !== null && $cursor_after !== $cursor_before) {
            $checkpoint->consecutive_timeouts = 0;
        } else {
            $checkpoint->consecutive_timeouts++;
        }

        $count = $checkpoint->consecutive_timeouts;
        $this->audit->record(
            "CURL TIMEOUT | {$phase} | consecutive_timeouts={$count}/" .
                $this->max_consecutive_timeouts .
                " | cursor_moved=" .
                ($cursor_after !== $cursor_before ? "yes" : "no"),
            true,
        );

        if ($count >= $this->max_consecutive_timeouts) {
            throw new RuntimeException(
                "Remote server appears unreachable: {$count} consecutive " .
                "cURL timeouts with no progress during {$phase}. Giving up.",
            );
        }
    }
}
