<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\Session\ShutdownState;

final class ShutdownStateToken implements ShutdownToken
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
