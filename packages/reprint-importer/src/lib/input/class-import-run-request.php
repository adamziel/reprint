<?php

namespace Reprint\Importer\Input;

use InvalidArgumentException;
use Reprint\Importer\Command\ImportCommand;
use Reprint\Importer\Command\ImportCommands;

final class ImportRunRequest
{
    /** @var array */
    private array $options;

    private string $command;

    private ImportCommand $command_runner;

    private function __construct(array $options, string $command, ImportCommand $command_runner)
    {
        $options['command'] = $command;

        $this->options = $options;
        $this->command = $command;
        $this->command_runner = $command_runner;
    }

    public static function from_options(array $options): self
    {
        $command = ImportCommands::normalize_name($options['command'] ?? null);
        if (!$command) {
            throw new InvalidArgumentException(
                'Command is required. Valid commands: ' . ImportCommands::valid_names_message(),
            );
        }

        $command_runner = ImportCommands::get($command);
        if ($command_runner === null) {
            throw new InvalidArgumentException(
                "Invalid command: {$command}. Valid commands: " . ImportCommands::valid_names_message(),
            );
        }

        if (isset($options['fs_root_nonempty_behavior'])) {
            $behavior = $options['fs_root_nonempty_behavior'];
            if (!in_array($behavior, ['error', 'preserve-local'], true)) {
                throw new InvalidArgumentException(
                    "Invalid --on-fs-root-nonempty value: {$behavior}. " .
                        'Valid values: error, preserve-local',
                );
            }
        }

        if (isset($options['filter'])) {
            $filter = $options['filter'];
            if ($command === 'pull' && !in_array($filter, ['none', 'essential-files'], true)) {
                throw new InvalidArgumentException(
                    "Invalid --filter value for pull: {$filter}. " .
                        'Valid values: none, essential-files',
                );
            }
        }

        if (isset($options['sql_output'])) {
            $mode = $options['sql_output'];
            if (!in_array($mode, ['file', 'stdout', 'mysql'], true)) {
                throw new InvalidArgumentException(
                    "Invalid --sql-output mode: {$mode}. Valid modes: file, stdout, mysql",
                );
            }
        }

        return new self($options, $command, $command_runner);
    }

    public function options(): array
    {
        return $this->options;
    }

    public function command(): string
    {
        return $this->command;
    }

    public function command_runner(): ImportCommand
    {
        return $this->command_runner;
    }

    public function abort(): bool
    {
        return (bool) ($this->options['abort'] ?? false);
    }

    public function verbose(): bool
    {
        return (bool) ($this->options['verbose'] ?? false);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function value(string $name, $default = null)
    {
        return $this->options[$name] ?? $default;
    }
}
