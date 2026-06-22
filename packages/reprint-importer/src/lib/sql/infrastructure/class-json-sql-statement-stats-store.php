<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Sql\Port\SqlStatementStatsStore;

final class JsonSqlStatementStatsStore implements SqlStatementStatsStore
{
    private string $stats_file;

    public function __construct(string $stats_file)
    {
        $this->stats_file = $stats_file;
    }

    public function load_total(): ?int
    {
        if (!file_exists($this->stats_file)) {
            return null;
        }

        $stats = json_decode(file_get_contents($this->stats_file), true);
        if (!is_array($stats) || !isset($stats["statements_total"])) {
            return null;
        }

        return (int) $stats["statements_total"];
    }

    public function persist_total(int $statements_total): void
    {
        file_put_contents(
            $this->stats_file,
            json_encode(["statements_total" => $statements_total]) . "\n",
        );
    }
}
