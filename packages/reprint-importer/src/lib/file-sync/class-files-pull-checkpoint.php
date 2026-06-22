<?php

namespace Reprint\Importer\FileSync;

use InvalidArgumentException;

final class FilesPullCheckpoint
{
    public ?string $status;
    public ?string $stage;
    public ?string $index_cursor;
    public int $diff_remote_offset;
    public ?string $diff_local_after;
    public FetchCheckpoint $fetch;
    public FetchCheckpoint $fetch_skipped;
    public ?string $current_file;
    public ?int $current_file_bytes;
    public int $consecutive_timeouts;

    public function __construct(
        ?string $status = null,
        ?string $stage = null,
        ?string $index_cursor = null,
        int $diff_remote_offset = 0,
        ?string $diff_local_after = null,
        ?FetchCheckpoint $fetch = null,
        ?FetchCheckpoint $fetch_skipped = null,
        ?string $current_file = null,
        ?int $current_file_bytes = null,
        int $consecutive_timeouts = 0
    ) {
        $this->status = $status;
        $this->stage = $stage;
        $this->index_cursor = $index_cursor;
        $this->diff_remote_offset = $diff_remote_offset;
        $this->diff_local_after = $diff_local_after;
        $this->fetch = $fetch ?? FetchCheckpoint::fresh();
        $this->fetch_skipped = $fetch_skipped ?? FetchCheckpoint::fresh();
        $this->current_file = $current_file;
        $this->current_file_bytes = $current_file_bytes;
        $this->consecutive_timeouts = $consecutive_timeouts;
    }

    public static function fresh(): self
    {
        return new self();
    }

    public function __clone()
    {
        $this->fetch = clone $this->fetch;
        $this->fetch_skipped = clone $this->fetch_skipped;
    }

    public static function from_array(array $data): self
    {
        return new self(
            isset($data["status"]) && is_string($data["status"]) ? $data["status"] : null,
            isset($data["stage"]) && is_string($data["stage"]) ? $data["stage"] : null,
            isset($data["index_cursor"]) && is_string($data["index_cursor"])
                ? $data["index_cursor"]
                : null,
            (int) ($data["diff"]["remote_offset"] ?? 0),
            isset($data["diff"]["local_after"]) && is_string($data["diff"]["local_after"])
                ? $data["diff"]["local_after"]
                : null,
            FetchCheckpoint::from_array(is_array($data["fetch"] ?? null) ? $data["fetch"] : []),
            FetchCheckpoint::from_array(
                is_array($data["fetch_skipped"] ?? null) ? $data["fetch_skipped"] : [],
            ),
            isset($data["current_file"]) && is_string($data["current_file"])
                ? $data["current_file"]
                : null,
            isset($data["current_file_bytes"]) ? (int) $data["current_file_bytes"] : null,
            (int) ($data["consecutive_timeouts"] ?? 0),
        );
    }

    public static function from_persisted_array(
        array $data,
        callable $decode_path
    ): self {
        $checkpoint = self::from_array($data);
        $checkpoint->diff_local_after = self::map_nullable_string(
            $checkpoint->diff_local_after,
            $decode_path,
        );
        $checkpoint->fetch = FetchCheckpoint::from_persisted_array(
            is_array($data["fetch"] ?? null) ? $data["fetch"] : [],
            $decode_path,
        );
        $checkpoint->fetch_skipped = FetchCheckpoint::from_persisted_array(
            is_array($data["fetch_skipped"] ?? null) ? $data["fetch_skipped"] : [],
            $decode_path,
        );
        $checkpoint->current_file = self::map_nullable_string(
            $checkpoint->current_file,
            $decode_path,
        );

        return $checkpoint;
    }

    public function to_persisted_array(callable $encode_path): array
    {
        $data = $this->to_array();
        $data["diff"]["local_after"] = self::map_nullable_string(
            $this->diff_local_after,
            $encode_path,
        );
        $data["fetch"] = $this->fetch->to_persisted_array($encode_path);
        $data["fetch_skipped"] = $this->fetch_skipped->to_persisted_array($encode_path);
        $data["current_file"] = self::map_nullable_string(
            $this->current_file,
            $encode_path,
        );

        return $data;
    }

    public function reset_for_files_pull(): void
    {
        $this->status = "in_progress";
        $this->stage = "index";
        $this->index_cursor = null;
        $this->reset_diff();
        $this->fetch->reset();
        $this->fetch_skipped->reset();
        $this->current_file = null;
        $this->current_file_bytes = null;
        $this->consecutive_timeouts = 0;
    }

    public function reset_diff(): void
    {
        $this->diff_remote_offset = 0;
        $this->diff_local_after = null;
    }

    public function diff_state(): array
    {
        return [
            "remote_offset" => $this->diff_remote_offset,
            "local_after" => $this->diff_local_after,
        ];
    }

    public function set_diff_state(array $diff): void
    {
        $this->diff_remote_offset = (int) ($diff["remote_offset"] ?? 0);
        $this->diff_local_after = isset($diff["local_after"]) && is_string($diff["local_after"])
            ? $diff["local_after"]
            : null;
    }

    public function fetch_checkpoint(string $state_key): FetchCheckpoint
    {
        if ($state_key === "fetch") {
            return $this->fetch;
        }
        if ($state_key === "fetch_skipped") {
            return $this->fetch_skipped;
        }

        throw new InvalidArgumentException("Unknown fetch checkpoint: {$state_key}");
    }

    public function reset_fetch_checkpoint(string $state_key): void
    {
        $this->fetch_checkpoint($state_key)->reset();
    }

    public function to_array(): array
    {
        return [
            "status" => $this->status,
            "stage" => $this->stage,
            "index_cursor" => $this->index_cursor,
            "diff" => $this->diff_state(),
            "fetch" => $this->fetch->to_array(),
            "fetch_skipped" => $this->fetch_skipped->to_array(),
            "current_file" => $this->current_file,
            "current_file_bytes" => $this->current_file_bytes,
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
