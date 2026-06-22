<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Sql\Port\SqlOutputSink;
use Reprint\Importer\Sql\SqlStdoutWriteFailedException;

final class StdoutSqlOutputSink implements SqlOutputSink
{
    private int $bytes_written;

    public function __construct(int $bytes_written)
    {
        $this->bytes_written = $bytes_written;
    }

    public function bytes_written(): int
    {
        return $this->bytes_written;
    }

    public function pending_buffer(): string
    {
        return "";
    }

    public function write(string $sql, bool $query_complete): void
    {
        $bytes = @fwrite(STDOUT, $sql);
        if ($bytes === false) {
            throw new SqlStdoutWriteFailedException("stdout write failed");
        }

        $this->bytes_written += $bytes;
    }

    public function flush(): void
    {
    }

    public function close(): void
    {
    }
}
