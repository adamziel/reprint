<?php

namespace Reprint\Importer\Session;

final class ImportRunState
{
    public ?string $command;
    public ?string $status;
    public bool $follow_symlinks;
    public string $fs_root_nonempty_behavior;
    public string $filter;
    public ?int $max_allowed_packet;
    public ?string $sql_output;
    public ?string $mysql_host;
    public ?int $mysql_port;
    public ?string $mysql_user;
    public ?string $mysql_database;
    public ?string $user_agent = null;

    /** @var array<string, mixed> */
    private array $tuning_config;

    /** @var array<string, mixed> */
    private array $tuning_state;

    /**
     * @param array<string, mixed> $tuning_config
     * @param array<string, mixed> $tuning_state
     */
    public function __construct(
        ?string $command = null,
        ?string $status = null,
        bool $follow_symlinks = true,
        string $fs_root_nonempty_behavior = "error",
        string $filter = "none",
        ?int $max_allowed_packet = null,
        ?string $sql_output = null,
        ?string $mysql_host = null,
        ?int $mysql_port = null,
        ?string $mysql_user = null,
        ?string $mysql_database = null,
        array $tuning_config = [],
        array $tuning_state = []
    ) {
        $this->command = $command;
        $this->status = $status;
        $this->follow_symlinks = $follow_symlinks;
        $this->fs_root_nonempty_behavior = $fs_root_nonempty_behavior;
        $this->filter = $filter;
        $this->max_allowed_packet = $max_allowed_packet;
        $this->sql_output = $sql_output;
        $this->mysql_host = $mysql_host;
        $this->mysql_port = $mysql_port;
        $this->mysql_user = $mysql_user;
        $this->mysql_database = $mysql_database;
        $this->tuning_config = $tuning_config;
        $this->tuning_state = $tuning_state;
    }

    public static function fresh(): self
    {
        return new self();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function from_array(array $data): self
    {
        $tuning = is_array($data["tuning"] ?? null) ? $data["tuning"] : [];

        return new self(
            isset($data["command"]) && is_string($data["command"]) ? $data["command"] : null,
            isset($data["status"]) && is_string($data["status"]) ? $data["status"] : null,
            array_key_exists("follow_symlinks", $data) ? (bool) $data["follow_symlinks"] : true,
            isset($data["fs_root_nonempty_behavior"]) && is_string($data["fs_root_nonempty_behavior"])
                ? $data["fs_root_nonempty_behavior"]
                : "error",
            isset($data["filter"]) && is_string($data["filter"]) ? $data["filter"] : "none",
            isset($data["max_allowed_packet"]) ? (int) $data["max_allowed_packet"] : null,
            isset($data["sql_output"]) && is_string($data["sql_output"]) ? $data["sql_output"] : null,
            isset($data["mysql_host"]) && is_string($data["mysql_host"]) ? $data["mysql_host"] : null,
            isset($data["mysql_port"]) ? (int) $data["mysql_port"] : null,
            isset($data["mysql_user"]) && is_string($data["mysql_user"]) ? $data["mysql_user"] : null,
            isset($data["mysql_database"]) && is_string($data["mysql_database"]) ? $data["mysql_database"] : null,
            is_array($tuning["config"] ?? null) ? $tuning["config"] : [],
            is_array($tuning["state"] ?? null) ? $tuning["state"] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function to_array(): array
    {
        return [
            "command" => $this->command,
            "status" => $this->status,
            "follow_symlinks" => $this->follow_symlinks,
            "fs_root_nonempty_behavior" => $this->fs_root_nonempty_behavior,
            "filter" => $this->filter,
            "max_allowed_packet" => $this->max_allowed_packet,
            "sql_output" => $this->sql_output,
            "mysql_host" => $this->mysql_host,
            "mysql_port" => $this->mysql_port,
            "mysql_user" => $this->mysql_user,
            "mysql_database" => $this->mysql_database,
            "tuning" => [
                "config" => $this->tuning_config,
                "state" => $this->tuning_state,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tuning_config(): array
    {
        return $this->tuning_config;
    }

    /**
     * @return array<string, mixed>
     */
    public function tuning_state(): array
    {
        return $this->tuning_state;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $state
     */
    public function set_tuning(array $config, array $state): void
    {
        $this->tuning_config = $config;
        $this->tuning_state = $state;
    }

    public function set_command_status(?string $command, ?string $status): void
    {
        $this->command = $command;
        $this->status = $status;
    }

    public function status_for_command(string $command): ?string
    {
        return $this->command === $command ? $this->status : null;
    }

    public function reset_for_restart(): self
    {
        return new self(
            null,
            null,
            $this->follow_symlinks,
            $this->fs_root_nonempty_behavior,
            "none",
            $this->max_allowed_packet,
        );
    }
}
