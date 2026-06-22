<?php

namespace Reprint\Importer\FileSync\Port;

interface ProgressTicker
{
    public function tick(): void;
}
