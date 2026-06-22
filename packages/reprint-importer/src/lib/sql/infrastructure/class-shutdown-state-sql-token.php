<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Session\ShutdownState;
use Reprint\Importer\Sql\Port\SqlShutdownToken;

final class ShutdownStateSqlToken implements SqlShutdownToken
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
