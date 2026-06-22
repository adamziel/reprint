<?php

namespace Reprint\Importer\Sql\Port;

interface SqlOutputSink
{
    public function bytes_written(): int;

    public function pending_buffer(): string;

    public function write(string $sql, bool $query_complete): void;

    public function flush(): void;

    public function close(): void;
}
