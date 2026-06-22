<?php

namespace Reprint\Importer\Sql\Port;

interface SqlStatementStatsStore
{
    public function load_total(): ?int;

    public function persist_total(int $statements_total): void;
}
