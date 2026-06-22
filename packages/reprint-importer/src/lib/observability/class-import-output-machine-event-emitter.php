<?php

namespace Reprint\Importer\Observability;

use Reprint\Importer\Output\ImportOutput;

final class ImportOutputMachineEventEmitter implements MachineEventEmitter
{
    private ImportOutput $output;

    public function __construct(ImportOutput $output)
    {
        $this->output = $output;
    }

    /**
     * @param array<string, mixed> $event
     */
    public function emit(array $event, bool $force = false): void
    {
        $this->output->emit_event($event, $force);
    }
}
