<?php

namespace Reprint\Importer\FileSync\Port;

interface FilesSyncRunStore
{
    public function current_command(): ?string;

    public function current_status(): ?string;

    public function record_command_status(string $command, ?string $status): void;
}
