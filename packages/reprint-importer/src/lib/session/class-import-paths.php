<?php

namespace Reprint\Importer\Session;

final class ImportPaths
{
    private string $state_dir;

    public function __construct(string $state_dir)
    {
        $this->state_dir = rtrim($state_dir, "/");
    }

    public function state_dir(): string
    {
        return $this->state_dir;
    }

    public function state_file(): string
    {
        return $this->state_dir . "/.import-state.json";
    }

    public function index_file(): string
    {
        return $this->state_dir . "/.import-index.jsonl";
    }

    public function index_updates_file(): string
    {
        return $this->state_dir . "/.import-index-updates.jsonl";
    }

    public function remote_index_file(): string
    {
        return $this->state_dir . "/.import-remote-index.jsonl";
    }

    public function download_list_file(): string
    {
        return $this->state_dir . "/.import-download-list.jsonl";
    }

    public function skipped_download_list_file(): string
    {
        return $this->state_dir . "/.import-download-list-skipped.jsonl";
    }

    public function audit_log(): string
    {
        return $this->state_dir . "/.import-audit.log";
    }

    public function volatile_files_file(): string
    {
        return $this->state_dir . "/.import-volatile-files.json";
    }

    public function status_file(): string
    {
        return $this->state_dir . "/.import-status.json";
    }

    public function runtime_files_dir(): string
    {
        return $this->state_dir . "/runtime_files";
    }

    public function sql_file(): string
    {
        return $this->state_dir . "/db.sql";
    }

    public function table_stats_file(): string
    {
        return $this->state_dir . "/db-tables.jsonl";
    }

    public function domains_file(): string
    {
        return $this->state_dir . "/.import-domains.json";
    }

    public function sql_stats_file(): string
    {
        return $this->state_dir . "/.import-sql-stats.json";
    }
}
