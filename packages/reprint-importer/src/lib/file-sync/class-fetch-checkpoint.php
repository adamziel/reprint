<?php

namespace Reprint\Importer\FileSync;

final class FetchCheckpoint
{
    public int $offset;
    public int $next_offset;
    public ?string $batch_file;
    public int $batch_entries;
    public ?string $cursor;

    public function __construct(
        int $offset = 0,
        int $next_offset = 0,
        ?string $batch_file = null,
        int $batch_entries = 0,
        ?string $cursor = null
    ) {
        $this->offset = $offset;
        $this->next_offset = $next_offset;
        $this->batch_file = $batch_file;
        $this->batch_entries = $batch_entries;
        $this->cursor = $cursor;
    }

    public static function fresh(): self
    {
        return new self();
    }

    public static function from_array(array $data): self
    {
        return new self(
            (int) ($data["offset"] ?? 0),
            (int) ($data["next_offset"] ?? 0),
            isset($data["batch_file"]) && is_string($data["batch_file"])
                ? $data["batch_file"]
                : null,
            (int) ($data["batch_entries"] ?? 0),
            isset($data["cursor"]) && is_string($data["cursor"])
                ? $data["cursor"]
                : null,
        );
    }

    public static function from_persisted_array(
        array $data,
        callable $decode_path
    ): self {
        $data["batch_file"] = self::map_nullable_string(
            $data["batch_file"] ?? null,
            $decode_path,
        );

        return self::from_array($data);
    }

    public function reset(): void
    {
        $this->offset = 0;
        $this->next_offset = 0;
        $this->batch_file = null;
        $this->batch_entries = 0;
        $this->cursor = null;
    }

    public function to_array(): array
    {
        return [
            "offset" => $this->offset,
            "next_offset" => $this->next_offset,
            "batch_file" => $this->batch_file,
            "batch_entries" => $this->batch_entries,
            "cursor" => $this->cursor,
        ];
    }

    public function to_persisted_array(callable $encode_path): array
    {
        $data = $this->to_array();
        $data["batch_file"] = self::map_nullable_string(
            $data["batch_file"],
            $encode_path,
        );

        return $data;
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
