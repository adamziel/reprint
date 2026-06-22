<?php

namespace Reprint\Importer\Sql;

final class DbApplyCheckpoint
{
    public ?string $status;
    public int $statements_executed;
    public int $bytes_read;
    public ?array $rewrite_url;
    public ?string $target_engine;
    public ?string $target_db;
    public ?string $target_host;
    public ?int $target_port;
    public ?string $target_user;
    public ?string $target_pass;
    public ?string $target_sqlite_path;

    public function __construct(
        ?string $status = null,
        int $statements_executed = 0,
        int $bytes_read = 0,
        ?array $rewrite_url = null,
        ?string $target_engine = null,
        ?string $target_db = null,
        ?string $target_host = null,
        ?int $target_port = null,
        ?string $target_user = null,
        ?string $target_pass = null,
        ?string $target_sqlite_path = null
    ) {
        $this->status = $status;
        $this->statements_executed = $statements_executed;
        $this->bytes_read = $bytes_read;
        $this->rewrite_url = $rewrite_url;
        $this->target_engine = $target_engine;
        $this->target_db = $target_db;
        $this->target_host = $target_host;
        $this->target_port = $target_port;
        $this->target_user = $target_user;
        $this->target_pass = $target_pass;
        $this->target_sqlite_path = $target_sqlite_path;
    }

    public static function fresh(): self
    {
        return new self();
    }

    public static function from_array(array $data): self
    {
        return new self(
            isset($data["status"]) && is_string($data["status"]) ? $data["status"] : null,
            (int) ($data["statements_executed"] ?? 0),
            (int) ($data["bytes_read"] ?? 0),
            is_array($data["rewrite_url"] ?? null) ? $data["rewrite_url"] : null,
            isset($data["target_engine"]) && is_string($data["target_engine"]) ? $data["target_engine"] : null,
            isset($data["target_db"]) && is_string($data["target_db"]) ? $data["target_db"] : null,
            isset($data["target_host"]) && is_string($data["target_host"]) ? $data["target_host"] : null,
            isset($data["target_port"]) ? (int) $data["target_port"] : null,
            isset($data["target_user"]) && is_string($data["target_user"]) ? $data["target_user"] : null,
            isset($data["target_pass"]) && is_string($data["target_pass"]) ? $data["target_pass"] : null,
            isset($data["target_sqlite_path"]) && is_string($data["target_sqlite_path"]) ? $data["target_sqlite_path"] : null,
        );
    }

    public function reset(?array $rewrite_url): void
    {
        $this->status = "in_progress";
        $this->statements_executed = 0;
        $this->bytes_read = 0;
        $this->rewrite_url = $rewrite_url;
        $this->target_engine = null;
        $this->target_db = null;
        $this->target_host = null;
        $this->target_port = null;
        $this->target_user = null;
        $this->target_pass = null;
        $this->target_sqlite_path = null;
    }

    public function to_array(): array
    {
        return [
            "status" => $this->status,
            "statements_executed" => $this->statements_executed,
            "bytes_read" => $this->bytes_read,
            "rewrite_url" => $this->rewrite_url,
            "target_engine" => $this->target_engine,
            "target_db" => $this->target_db,
            "target_host" => $this->target_host,
            "target_port" => $this->target_port,
            "target_user" => $this->target_user,
            "target_pass" => $this->target_pass,
            "target_sqlite_path" => $this->target_sqlite_path,
        ];
    }
}
