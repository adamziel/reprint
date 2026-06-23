<?php

namespace Reprint\Importer\Application;

use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Observability\FileAuditLogger;
use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\Session\ImportPaths;

final class ImportIo
{
    private ImportPaths $paths;
    private ImportOutput $output;

    public function __construct(ImportPaths $paths, ImportOutput $output)
    {
        $this->paths = $paths;
        $this->output = $output;
    }

    public function output(): ImportOutput
    {
        return $this->output;
    }

    public function audit_logger(): AuditLogger
    {
        return new FileAuditLogger($this->paths->audit_log(), $this->output);
    }

    public function audit_log(string $message, bool $to_console = true): void
    {
        $this->audit_logger()->record($message, $to_console);
    }

    public function audit_log_argv(string $command, array $argv): void
    {
        $masked = $argv;
        if (isset($masked[2]) && $command !== "apply-runtime") {
            $masked[2] = preg_replace("/SECRET_KEY=[^&\s]+/", "SECRET_KEY=***", $masked[2]);
        }

        $this->audit_log("COMMAND | {$command} | argv=" . implode(" ", $masked), false);
    }

    public function emit_event(array $data, bool $force = false): bool
    {
        return $this->output->emit_event($data, $force);
    }
}
