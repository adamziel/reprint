<?php

namespace Reprint\Importer\Observability;

interface MachineEventEmitter
{
    /**
     * @param array<string, mixed> $event
     */
    public function emit(array $event, bool $force = false): void;
}
