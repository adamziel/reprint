<?php

namespace Reprint\Importer\FileSync\Port;

interface VolatileFileReporter
{
    public function report(): void;
}
