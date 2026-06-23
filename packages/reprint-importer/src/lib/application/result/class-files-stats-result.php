<?php

namespace Reprint\Importer\Application\Result;

final class FilesStatsResult implements ImportCommandResult
{
    /** @var array<string, mixed> */
    private $stats;

    /**
     * @param array<string, mixed> $stats
     */
    public function __construct(array $stats)
    {
        $this->stats = $stats;
    }

    public function type(): string
    {
        return 'files-stats';
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return $this->stats;
    }

    public function to_array(): array
    {
        return [
            'type' => $this->type(),
            'stats' => $this->stats,
        ];
    }
}
