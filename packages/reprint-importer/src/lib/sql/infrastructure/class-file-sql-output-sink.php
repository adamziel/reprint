<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Sql\Port\SqlOutputSink;
use RuntimeException;

final class FileSqlOutputSink implements SqlOutputSink
{
    private string $sql_file;

    /** @var resource|null */
    private $handle;

    private int $bytes_written;

    public function __construct(
        string $sql_file,
        ?string $cursor,
        ?int $tracked_bytes,
        AuditLogger $audit
    ) {
        $this->sql_file = $sql_file;

        if ($tracked_bytes !== null && file_exists($sql_file)) {
            $actual_size = filesize($sql_file);
            if ($actual_size > $tracked_bytes) {
                $audit->record(
                    sprintf(
                        "CRASH RECOVERY | Truncating db.sql from %d to %d bytes",
                        $actual_size,
                        $tracked_bytes,
                    ),
                    true,
                );
                $handle = fopen($sql_file, "r+");
                if ($handle) {
                    ftruncate($handle, $tracked_bytes);
                    fclose($handle);
                }
            }
        }

        $this->bytes_written = file_exists($sql_file) ? filesize($sql_file) : 0;
        $this->handle = fopen($sql_file, $cursor ? "a" : "w");
        if (!$this->handle) {
            throw new RuntimeException("Cannot open SQL file: {$sql_file}");
        }
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
        $data_length = strlen($sql);
        $bytes = fwrite($this->handle, $sql);
        if ($bytes === false || $bytes !== $data_length) {
            throw new RuntimeException(
                "SQL write failed: wrote " . ($bytes === false ? "0" : $bytes) .
                "/" . $data_length . " bytes (disk full?)"
            );
        }

        $this->bytes_written += $bytes;
    }

    public function flush(): void
    {
        if ($this->handle) {
            fflush($this->handle);
        }
    }

    public function close(): void
    {
        if ($this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
