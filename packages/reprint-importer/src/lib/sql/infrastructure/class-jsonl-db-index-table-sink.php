<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Sql\DbIndexCheckpoint;
use Reprint\Importer\Sql\Port\DbIndexTableSink;
use RuntimeException;

final class JsonlDbIndexTableSink implements DbIndexTableSink
{
    private string $tables_file;

    /** @var resource|null */
    private $handle;

    private int $tables_written;
    private int $rows_estimated;
    private int $bytes_written;

    public function __construct(
        string $tables_file,
        ?string $cursor,
        DbIndexCheckpoint $checkpoint,
        AuditLogger $audit
    ) {
        $this->tables_file = $tables_file;
        $this->tables_written = $checkpoint->tables;
        $this->rows_estimated = $checkpoint->rows_estimated;
        $this->bytes_written = $checkpoint->bytes;

        if ($this->bytes_written > 0 && file_exists($tables_file)) {
            $actual_size = filesize($tables_file);
            if ($actual_size > $this->bytes_written) {
                $audit->record(
                    sprintf(
                        "CRASH RECOVERY | Truncating db-tables.jsonl from %d to %d bytes",
                        $actual_size,
                        $this->bytes_written,
                    ),
                    true,
                );
                $truncate_handle = fopen($tables_file, "r+");
                if ($truncate_handle) {
                    ftruncate($truncate_handle, $this->bytes_written);
                    fclose($truncate_handle);
                }
            }
        }

        $this->handle = fopen($tables_file, $cursor ? "a" : "w");
        if (!$this->handle) {
            throw new RuntimeException("Cannot open table stats file: {$tables_file}");
        }
    }

    public function write_rows(array $rows): void
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $line = json_encode($row) . "\n";
            $line_length = strlen($line);
            $bytes = fwrite($this->handle, $line);
            if ($bytes === false || $bytes !== $line_length) {
                throw new RuntimeException(
                    "Table stats write failed: wrote " . ($bytes === false ? "0" : $bytes) .
                    "/" . $line_length . " bytes (disk full?)"
                );
            }

            $this->bytes_written += $bytes;
            $this->tables_written++;
            if (isset($row["rows"]) && is_numeric($row["rows"])) {
                $this->rows_estimated += (int) $row["rows"];
            }
        }
    }

    public function tables_written(): int
    {
        return $this->tables_written;
    }

    public function rows_estimated(): int
    {
        return $this->rows_estimated;
    }

    public function bytes_written(): int
    {
        return $this->bytes_written;
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
