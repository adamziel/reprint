<?php

namespace Reprint\Importer\Sql;

final class DbPullCheckpoint
{
    public ?string $status;
    public string $stage;
    public ?string $cursor;
    public DbIndexCheckpoint $db_index;
    public ?int $sql_bytes;
    public int $sql_statements_counted;
    public int $consecutive_timeouts;

    public function __construct(
        ?string $status = null,
        string $stage = "db-index",
        ?string $cursor = null,
        ?DbIndexCheckpoint $db_index = null,
        ?int $sql_bytes = null,
        int $sql_statements_counted = 0,
        int $consecutive_timeouts = 0
    ) {
        $this->status = $status;
        $this->stage = $stage;
        $this->cursor = $cursor;
        $this->db_index = $db_index ?? new DbIndexCheckpoint();
        $this->sql_bytes = $sql_bytes;
        $this->sql_statements_counted = $sql_statements_counted;
        $this->consecutive_timeouts = $consecutive_timeouts;
    }

    public static function fresh(): self
    {
        return new self();
    }

    public function __clone()
    {
        $this->db_index = clone $this->db_index;
    }

    public static function from_array(array $data): self
    {
        return new self(
            isset($data["status"]) && is_string($data["status"]) ? $data["status"] : null,
            isset($data["stage"]) && is_string($data["stage"]) ? $data["stage"] : "db-index",
            isset($data["cursor"]) && is_string($data["cursor"]) ? $data["cursor"] : null,
            DbIndexCheckpoint::from_array(is_array($data["db_index"] ?? null) ? $data["db_index"] : []),
            isset($data["sql_bytes"]) ? (int) $data["sql_bytes"] : null,
            (int) ($data["sql_statements_counted"] ?? 0),
            (int) ($data["consecutive_timeouts"] ?? 0),
        );
    }

    public static function from_persisted_array(
        array $data,
        callable $decode_path
    ): self {
        $checkpoint = self::from_array($data);
        $checkpoint->db_index->file = self::map_nullable_string(
            $checkpoint->db_index->file,
            $decode_path,
        );

        return $checkpoint;
    }

    public function to_persisted_array(callable $encode_path): array
    {
        $data = $this->to_array();
        $data["db_index"]["file"] = self::map_nullable_string(
            $this->db_index->file,
            $encode_path,
        );

        return $data;
    }

    public function reset(): void
    {
        $this->status = "in_progress";
        $this->stage = "db-index";
        $this->cursor = null;
        $this->db_index = new DbIndexCheckpoint();
        $this->sql_bytes = null;
        $this->sql_statements_counted = 0;
        $this->consecutive_timeouts = 0;
    }

    public function to_array(): array
    {
        return [
            "status" => $this->status,
            "stage" => $this->stage,
            "cursor" => $this->cursor,
            "db_index" => $this->db_index->to_array(),
            "sql_bytes" => $this->sql_bytes,
            "sql_statements_counted" => $this->sql_statements_counted,
            "consecutive_timeouts" => $this->consecutive_timeouts,
        ];
    }

    /**
     * @param mixed $value
     */
    private static function map_nullable_string($value, callable $map): ?string
    {
        $mapped = call_user_func($map, $value);

        return is_string($mapped) ? $mapped : null;
    }
}
