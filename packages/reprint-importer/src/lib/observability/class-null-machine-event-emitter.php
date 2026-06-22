<?php

namespace Reprint\Importer\Observability;

final class NullMachineEventEmitter implements MachineEventEmitter
{
    /**
     * @param array<string, mixed> $event
     */
    public function emit(array $event, bool $force = false): void
    {
    }
}
