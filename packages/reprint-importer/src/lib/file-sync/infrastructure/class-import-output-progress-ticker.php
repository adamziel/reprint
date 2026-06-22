<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\Port\ProgressTicker;
use Reprint\Importer\Output\ImportOutput;

final class ImportOutputProgressTicker implements ProgressTicker
{
    private ImportOutput $output;

    public function __construct(ImportOutput $output)
    {
        $this->output = $output;
    }

    public function tick(): void
    {
        $this->output->tick_spinner();
    }
}
