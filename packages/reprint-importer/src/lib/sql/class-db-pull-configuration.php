<?php

namespace Reprint\Importer\Sql;

final class DbPullConfiguration
{
    private string $state_dir;
    private string $audit_log;
    private string $sql_output_mode;
    private ?string $mysql_database;

    public function __construct(
        string $state_dir,
        string $audit_log,
        string $sql_output_mode,
        ?string $mysql_database
    ) {
        $this->state_dir = $state_dir;
        $this->audit_log = $audit_log;
        $this->sql_output_mode = $sql_output_mode;
        $this->mysql_database = $mysql_database;
    }

    public function state_dir(): string
    {
        return $this->state_dir;
    }

    public function sql_file(): string
    {
        return $this->state_dir . "/db.sql";
    }

    public function tables_file(): string
    {
        return $this->state_dir . "/db-tables.jsonl";
    }

    public function audit_log(): string
    {
        return $this->audit_log;
    }

    public function sql_output_mode(): string
    {
        return $this->sql_output_mode;
    }

    public function mysql_database(): ?string
    {
        return $this->mysql_database;
    }
}
