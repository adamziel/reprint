<?php

namespace Reprint\Importer\Session;

final class PreflightCheckpoint
{
    /** @var array<string, mixed>|null */
    public ?array $entry;
    public ?int $remote_protocol_version;
    public ?int $remote_protocol_min_version;
    public ?string $exporter_version;
    public ?string $webhost;

    /**
     * @param array<string, mixed>|null $entry
     */
    public function __construct(
        ?array $entry = null,
        ?int $remote_protocol_version = null,
        ?int $remote_protocol_min_version = null,
        ?string $exporter_version = null,
        ?string $webhost = null
    ) {
        $this->entry = $entry;
        $this->remote_protocol_version = $remote_protocol_version;
        $this->remote_protocol_min_version = $remote_protocol_min_version;
        $this->exporter_version = $exporter_version;
        $this->webhost = $webhost;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function from_array(array $data): self
    {
        return new self(
            is_array($data["preflight"] ?? null) ? $data["preflight"] : null,
            isset($data["remote_protocol_version"]) ? (int) $data["remote_protocol_version"] : null,
            isset($data["remote_protocol_min_version"]) ? (int) $data["remote_protocol_min_version"] : null,
            isset($data["version"]) && is_string($data["version"]) ? $data["version"] : null,
            isset($data["webhost"]) && is_string($data["webhost"]) ? $data["webhost"] : null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function from_persisted_array(
        array $data,
        callable $decode_preflight_data_paths
    ): self {
        $checkpoint = self::from_array($data);
        $checkpoint->map_preflight_data_paths($decode_preflight_data_paths);

        return $checkpoint;
    }

    public function to_persisted_array(callable $encode_preflight_data_paths): array
    {
        $clone = clone $this;
        $clone->map_preflight_data_paths($encode_preflight_data_paths);

        return $clone->to_array();
    }

    /**
     * @return array<string, mixed>
     */
    public function to_array(): array
    {
        return [
            "preflight" => $this->entry,
            "remote_protocol_version" => $this->remote_protocol_version,
            "remote_protocol_min_version" => $this->remote_protocol_min_version,
            "version" => $this->exporter_version,
            "webhost" => $this->webhost,
        ];
    }

    public function data(): ?array
    {
        $data = $this->entry["data"] ?? null;

        return is_array($data) ? $data : null;
    }

    public function require_data(string $message): array
    {
        $data = $this->data();
        if ($data === null) {
            throw new \RuntimeException($message);
        }

        return $data;
    }

    public function detected_webhost(): string
    {
        return is_string($this->webhost) && $this->webhost !== ""
            ? $this->webhost
            : "other";
    }

    private function map_preflight_data_paths(callable $map): void
    {
        if (!is_array($this->entry) || !is_array($this->entry["data"] ?? null)) {
            return;
        }

        $this->entry["data"] = call_user_func($map, $this->entry["data"]);
    }
}
