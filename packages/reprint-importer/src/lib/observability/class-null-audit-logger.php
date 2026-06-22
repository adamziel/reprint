<?php

namespace Reprint\Importer\Observability;

final class NullAuditLogger implements AuditLogger
{
    public function record(string $message, bool $to_console = true): void
    {
    }

    public function path(): string
    {
        return '';
    }
}
