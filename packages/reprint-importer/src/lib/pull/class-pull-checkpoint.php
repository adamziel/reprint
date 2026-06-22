<?php

namespace Reprint\Importer\Pull;

final class PullCheckpoint
{
    public ?string $stage;
    public ?string $files_filter;
    public bool $skipped_pending;

    public function __construct(
        ?string $stage = null,
        ?string $files_filter = null,
        bool $skipped_pending = false
    ) {
        $this->stage = $stage;
        $this->files_filter = $files_filter;
        $this->skipped_pending = $skipped_pending;
    }

    public static function fresh(): self
    {
        return new self();
    }

    public static function from_array(array $data): self
    {
        return new self(
            isset($data["stage"]) && is_string($data["stage"]) ? $data["stage"] : null,
            isset($data["files_filter"]) && is_string($data["files_filter"])
                ? $data["files_filter"]
                : null,
            (bool) ($data["skipped_pending"] ?? false),
        );
    }

    public function reset(): void
    {
        $this->stage = null;
        $this->files_filter = null;
        $this->skipped_pending = false;
    }

    public function to_array(): array
    {
        return [
            "stage" => $this->stage,
            "files_filter" => $this->files_filter,
            "skipped_pending" => $this->skipped_pending,
        ];
    }
}
