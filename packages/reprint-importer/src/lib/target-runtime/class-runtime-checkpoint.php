<?php

namespace Reprint\Importer\TargetRuntime;

final class RuntimeCheckpoint
{
    /** @var string[] */
    public array $remote_paths_removed_from_local_site;

    public function __construct(array $remote_paths_removed_from_local_site = [])
    {
        $this->remote_paths_removed_from_local_site = array_values(
            $remote_paths_removed_from_local_site,
        );
    }

    public static function fresh(): self
    {
        return new self();
    }

    public static function from_array(array $data): self
    {
        return new self(
            is_array($data["remote_paths_removed_from_local_site"] ?? null)
                ? $data["remote_paths_removed_from_local_site"]
                : [],
        );
    }

    public function to_array(): array
    {
        return [
            "remote_paths_removed_from_local_site" => $this->remote_paths_removed_from_local_site,
        ];
    }
}
