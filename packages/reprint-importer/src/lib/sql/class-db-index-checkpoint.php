<?php

namespace Reprint\Importer\Sql;

final class DbIndexCheckpoint
{
    public ?string $file;
    public int $tables;
    public int $rows_estimated;
    public int $bytes;
    public ?int $updated_at;

    public function __construct(
        ?string $file = null,
        int $tables = 0,
        int $rows_estimated = 0,
        int $bytes = 0,
        ?int $updated_at = null
    ) {
        $this->file = $file;
        $this->tables = $tables;
        $this->rows_estimated = $rows_estimated;
        $this->bytes = $bytes;
        $this->updated_at = $updated_at;
    }

    public static function from_array(array $data): self
    {
        return new self(
            isset($data["file"]) && is_string($data["file"]) ? $data["file"] : null,
            (int) ($data["tables"] ?? 0),
            (int) ($data["rows_estimated"] ?? 0),
            (int) ($data["bytes"] ?? 0),
            isset($data["updated_at"]) ? (int) $data["updated_at"] : null,
        );
    }

    public function to_array(): array
    {
        return [
            "file" => $this->file,
            "tables" => $this->tables,
            "rows_estimated" => $this->rows_estimated,
            "bytes" => $this->bytes,
            "updated_at" => $this->updated_at,
        ];
    }
}
