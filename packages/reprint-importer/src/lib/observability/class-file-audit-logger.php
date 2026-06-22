<?php

namespace Reprint\Importer\Observability;

use Reprint\Importer\Output\ImportOutput;

final class FileAuditLogger implements AuditLogger
{
    private string $path;
    private ImportOutput $output;

    public function __construct(string $path, ImportOutput $output)
    {
        $this->path = $path;
        $this->output = $output;
    }

    public function record(string $message, bool $to_console = true): void
    {
        $timestamp = date("Y-m-d H:i:s");
        $line = "[{$timestamp}] {$message}\n";
        file_put_contents($this->path, $line, FILE_APPEND);

        if ($to_console && $this->output->is_verbose()) {
            $this->output->write($line);
        }
    }

    public function path(): string
    {
        return $this->path;
    }
}
