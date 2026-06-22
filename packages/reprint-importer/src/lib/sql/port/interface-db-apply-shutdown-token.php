<?php

namespace Reprint\Importer\Sql\Port;

interface DbApplyShutdownToken
{
    public function is_shutdown_requested(): bool;
}
