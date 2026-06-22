<?php

namespace Reprint\Importer\Observability;

interface AuditLogger
{
    public function record(string $message, bool $to_console = true): void;

    public function path(): string;
}
