<?php

namespace Reprint\Importer\Command;

final class PreflightReportResult implements ImportCommandResult
{
    /** @var array<string, mixed>|null */
    private $entry;

    /**
     * @param array<string, mixed>|null $entry
     */
    public function __construct(?array $entry)
    {
        $this->entry = $entry;
    }

    public function type(): string
    {
        return 'preflight-report';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function entry(): ?array
    {
        return $this->entry;
    }

    public function is_ok(): bool
    {
        $data = is_array($this->entry) ? ($this->entry['data'] ?? null) : null;

        return
            is_array($this->entry) &&
            is_array($data) &&
            ($this->entry['http_code'] ?? 0) === 200 &&
            !empty($data['ok']);
    }

    public function to_array(): array
    {
        return [
            'type' => $this->type(),
            'entry' => $this->entry,
        ];
    }
}
