<?php

namespace Reprint\Importer\FileSync\Port;

interface ShutdownToken
{
    public function is_shutdown_requested(): bool;
}
