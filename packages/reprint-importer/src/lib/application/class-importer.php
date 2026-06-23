<?php

namespace Reprint\Importer\Application;

use Exception;
use Reprint\Importer\Application\Result\ImportCommandResult;
use Reprint\Importer\Output\ImportOutput;

class Importer
{
    private ImportContext $context;
    private ImportServices $services;
    private CommandRegistry $commands;
    private ImportRequestPreparer $request_preparer;

    public function __construct(
        string $remote_url,
        string $state_dir,
        string $fs_root,
        ?ImportOutput $output = null,
        ?CommandRegistry $commands = null
    ) {
        $this->context = new ImportContext($remote_url, $state_dir, $fs_root, $output);
        $this->services = new ImportServices($this->context);
        $this->commands = $commands ?? CommandRegistry::create_default();
        $this->request_preparer = new ImportRequestPreparer($this->context);
    }

    /**
     * @param array<string, mixed> $raw_options
     */
    public function run(array $raw_options = []): ?ImportCommandResult
    {
        $this->context->prepare_runtime();

        try {
            $request = ImportRequest::from_options($raw_options, $this->commands);
            $handler = $this->commands->get($request->command());

            $this->request_preparer->prepare($request);

            if ($request->abort()) {
                if (!$handler->supports_abort()) {
                    throw new \InvalidArgumentException(
                        "Command {$request->command()} does not support --abort",
                    );
                }
                $handler->abort($this->context, $this->services, $request->command());
                return null;
            }

            if ($handler->requires_preflight()) {
                $this->context->require_preflight();
            }

            try {
                $result = $handler->execute($this->context, $this->services, $request->options());
                if ($handler->emits_final_status()) {
                    $this->context->finish_command_status($request->command());
                }
                return $result;
            } catch (Exception $e) {
                $this->context->report_command_exception($e);
                throw $e;
            }
        } finally {
            $this->context->cleanup_runtime();
        }
    }

    public function context(): ImportContext
    {
        return $this->context;
    }

    public function exit_code(): int
    {
        return $this->context->exit_code();
    }

    public function set_exit_code(int $exit_code): void
    {
        $this->context->set_exit_code($exit_code);
    }

    public function last_error_code(): ?string
    {
        return $this->context->last_error_code();
    }

    public function write_status_file(?string $error = null): void
    {
        $this->context->write_status_file($error);
    }

    public function output_progress(array $data, bool $force = false): void
    {
        $this->context->output_progress($data, $force);
    }

    public function save_preflight_checkpoint(
        \Reprint\Importer\Session\PreflightCheckpoint $checkpoint
    ): void {
        $this->context->save_preflight_checkpoint($checkpoint);
    }

    public function audit_log_argv(string $command, array $argv): void
    {
        $this->context->audit_log_argv($command, $argv);
    }
}
