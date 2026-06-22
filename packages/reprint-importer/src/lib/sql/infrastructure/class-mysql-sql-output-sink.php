<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Sql\Port\SqlOutputSink;
use RuntimeException;

final class MysqlSqlOutputSink implements SqlOutputSink
{
    /** @var object|null */
    private $connection;

    /** @var resource|null */
    private $buffer_handle;

    private string $buffer_file;
    private string $sql_buffer;
    private int $bytes_written;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config, string $state_dir, int $bytes_written, AuditLogger $audit)
    {
        $host = $config["mysql_host"] ?? "127.0.0.1";
        $user = $config["mysql_user"] ?? "root";
        $pass = $config["mysql_password"] ?? "";
        $name = $config["mysql_database"];

        $port = $config["mysql_port"] ?? 3306;
        $socket = null;
        if (strpos($host, ":") !== false) {
            list($host, $port_or_socket) = explode(":", $host, 2);
            if ($port_or_socket[0] === "/") {
                $socket = $port_or_socket;
            } elseif (($config["mysql_port"] ?? null) === null) {
                $port = (int) $port_or_socket;
            }
        }

        $this->connection = new \mysqli($host, $user, $pass, $name, $port, $socket);
        if ($this->connection->connect_error) {
            throw new RuntimeException("MySQL connection failed: " . $this->connection->connect_error);
        }
        $this->connection->set_charset("utf8mb4");

        $audit->record(
            "SQL OUTPUT mysql | connected via multi_query(): {$user}@{$host}:{$port}/{$name}",
            true,
        );

        $this->buffer_file = $state_dir . "/.sql-buffer";
        $this->sql_buffer = "";
        if (file_exists($this->buffer_file)) {
            $this->sql_buffer = file_get_contents($this->buffer_file);
            $audit->record(
                sprintf("CRASH RECOVERY | Restored %d bytes from .sql-buffer", strlen($this->sql_buffer)),
                true,
            );
        }

        $this->buffer_handle = fopen($this->buffer_file, $this->sql_buffer !== "" ? "a" : "w");
        if (!$this->buffer_handle) {
            throw new RuntimeException("Cannot open SQL buffer file: {$this->buffer_file}");
        }

        $this->bytes_written = $bytes_written;
    }

    public function bytes_written(): int
    {
        return $this->bytes_written;
    }

    public function pending_buffer(): string
    {
        return $this->sql_buffer;
    }

    public function write(string $sql, bool $query_complete): void
    {
        if ($this->buffer_handle) {
            fwrite($this->buffer_handle, $sql);
            fflush($this->buffer_handle);
        }

        $this->sql_buffer .= $sql;
        $this->bytes_written += strlen($sql);

        if (!$query_complete) {
            return;
        }

        if (!$this->connection->multi_query($this->sql_buffer)) {
            throw new RuntimeException("MySQL execution failed: " . $this->connection->error);
        }

        do {
            $result = $this->connection->store_result();
            if ($result) {
                $result->free();
            }
            if ($this->connection->errno) {
                throw new RuntimeException("MySQL statement error: " . $this->connection->error);
            }
        } while ($this->connection->more_results() && $this->connection->next_result());

        if ($this->buffer_handle) {
            ftruncate($this->buffer_handle, 0);
            rewind($this->buffer_handle);
        }
        $this->sql_buffer = "";
    }

    public function flush(): void
    {
        if ($this->buffer_handle) {
            fflush($this->buffer_handle);
        }
    }

    public function close(): void
    {
        if ($this->buffer_handle) {
            fclose($this->buffer_handle);
            $this->buffer_handle = null;
        }

        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
        }

        if ($this->sql_buffer === "" && file_exists($this->buffer_file)) {
            unlink($this->buffer_file);
        }
    }
}
