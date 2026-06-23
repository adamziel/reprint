<?php

namespace Reprint\Importer\Application;

use InvalidArgumentException;

final class ImportOptions
{
    private bool $follow_symlinks = true;
    private bool $include_caches = false;
    private string $fs_root_nonempty_behavior = "error";
    private string $filter = "none";
    private ?string $extra_directory = null;
    private ?int $max_allowed_packet = null;
    private string $sql_output_mode = "file";
    private ?string $mysql_host = null;
    private ?int $mysql_port = null;
    private ?string $mysql_user = null;
    private ?string $mysql_password = null;
    private ?string $mysql_database = null;
    private ?int $pipeline_step = null;
    private ?int $pipeline_steps = null;

    public function follow_symlinks(): bool
    {
        return $this->follow_symlinks;
    }

    public function set_follow_symlinks(bool $value): void
    {
        $this->follow_symlinks = $value;
    }

    public function include_caches(): bool
    {
        return $this->include_caches;
    }

    public function set_include_caches(bool $value): void
    {
        $this->include_caches = $value;
    }

    public function fs_root_nonempty_behavior(): string
    {
        return $this->fs_root_nonempty_behavior;
    }

    public function set_fs_root_nonempty_behavior(string $value): void
    {
        $this->fs_root_nonempty_behavior = $value;
    }

    public function filter(): string
    {
        return $this->filter;
    }

    public function set_filter(string $value): void
    {
        $this->filter = $value;
    }

    public function extra_directory(): ?string
    {
        return $this->extra_directory;
    }

    public function set_extra_directory(?string $value): void
    {
        $this->extra_directory = $value;
    }

    public function max_allowed_packet(): ?int
    {
        return $this->max_allowed_packet;
    }

    public function set_max_allowed_packet(?int $value): void
    {
        $this->max_allowed_packet = $value;
    }

    public function sql_output_mode(): string
    {
        return $this->sql_output_mode;
    }

    public function set_sql_output_mode(string $value): void
    {
        $this->sql_output_mode = $value;
    }

    public function set_pipeline(?int $step, ?int $steps): void
    {
        $this->pipeline_step = $step;
        $this->pipeline_steps = $steps;
    }

    public function pipeline_step(): ?int
    {
        return $this->pipeline_step;
    }

    public function pipeline_steps(): ?int
    {
        return $this->pipeline_steps;
    }

    public function mysql_host(): ?string
    {
        return $this->mysql_host;
    }

    public function set_mysql_host(?string $value): void
    {
        $this->mysql_host = $value;
    }

    public function mysql_port(): ?int
    {
        return $this->mysql_port;
    }

    public function set_mysql_port(?int $value): void
    {
        $this->mysql_port = $value;
    }

    public function mysql_user(): ?string
    {
        return $this->mysql_user;
    }

    public function set_mysql_user(?string $value): void
    {
        $this->mysql_user = $value;
    }

    public function mysql_password(): ?string
    {
        return $this->mysql_password;
    }

    public function set_mysql_password(?string $value): void
    {
        $this->mysql_password = $value;
    }

    public function mysql_database(): ?string
    {
        return $this->mysql_database;
    }

    public function set_mysql_database(?string $value): void
    {
        $this->mysql_database = $value;
    }

    public function validate_sql_output_options(): void
    {
        if ($this->sql_output_mode !== "mysql" || !empty($this->mysql_database)) {
            return;
        }

        throw new InvalidArgumentException(
            "--mysql-database is required when using --sql-output=mysql",
        );
    }
}
