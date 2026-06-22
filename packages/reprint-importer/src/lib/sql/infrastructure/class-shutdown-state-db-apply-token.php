<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Session\ShutdownState;
use Reprint\Importer\Sql\Port\DbApplyShutdownToken;

final class ShutdownStateDbApplyToken implements DbApplyShutdownToken
{
    private ShutdownState $shutdown;

    public function __construct(ShutdownState $shutdown)
    {
        $this->shutdown = $shutdown;
    }

    public function is_shutdown_requested(): bool
    {
        return $this->shutdown->requested();
    }
}
