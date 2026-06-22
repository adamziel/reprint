<?php

namespace Reprint\Importer\Observability;

final class ImportEvent
{
    private string $name;

    /** @var array<string, mixed> */
    private array $payload;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $name, array $payload = [])
    {
        $this->name = $name;
        $this->payload = $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function named(string $name, array $payload = []): self
    {
        return new self($name, $payload);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
