<?php

namespace Reprint\Importer\Sql\Port;

interface DbIndexTableSink
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function write_rows(array $rows): void;

    public function tables_written(): int;

    public function rows_estimated(): int;

    public function bytes_written(): int;

    public function flush(): void;

    public function close(): void;
}
