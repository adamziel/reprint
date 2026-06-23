<?php

namespace Reprint\Importer\Application;

use InvalidArgumentException;

final class ImportRequest
{
    /** @var array<string, mixed> */
    private array $options;
    private string $command;

    /**
     * @param array<string, mixed> $options
     */
    private function __construct(array $options, string $command)
    {
        $options["command"] = $command;
        $this->options = $options;
        $this->command = $command;
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function from_options(array $options, CommandRegistry $commands): self
    {
        $command = CommandRegistry::normalize_name($options["command"] ?? null);
        if (!$command) {
            throw new InvalidArgumentException(
                "Command is required. Valid commands: " . $commands->valid_names_message(),
            );
        }

        $commands->get($command);
        self::validate_options($command, $options);

        return new self($options, $command);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function validate_options(string $command, array $options): void
    {
        if (isset($options["fs_root_nonempty_behavior"])) {
            $behavior = $options["fs_root_nonempty_behavior"];
            if (!in_array($behavior, ["error", "preserve-local"], true)) {
                throw new InvalidArgumentException(
                    "Invalid --on-fs-root-nonempty value: {$behavior}. " .
                        "Valid values: error, preserve-local",
                );
            }
        }

        if (isset($options["filter"])) {
            $filter = $options["filter"];
            if ($command === "pull" && !in_array($filter, ["none", "essential-files"], true)) {
                throw new InvalidArgumentException(
                    "Invalid --filter value for pull: {$filter}. " .
                        "Valid values: none, essential-files",
                );
            }
        }

        if (isset($options["sql_output"])) {
            $mode = $options["sql_output"];
            if (!in_array($mode, ["file", "stdout", "mysql"], true)) {
                throw new InvalidArgumentException(
                    "Invalid --sql-output mode: {$mode}. Valid modes: file, stdout, mysql",
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function command(): string
    {
        return $this->command;
    }

    public function abort(): bool
    {
        return (bool) ($this->options["abort"] ?? false);
    }

    public function verbose(): bool
    {
        return (bool) ($this->options["verbose"] ?? false);
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
