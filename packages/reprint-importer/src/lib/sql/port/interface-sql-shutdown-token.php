<?php

namespace Reprint\Importer\Sql\Port;

interface SqlShutdownToken
{
    public function is_shutdown_requested(): bool;
}
