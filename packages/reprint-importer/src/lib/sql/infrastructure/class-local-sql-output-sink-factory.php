<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\Port\SqlOutputSink;
use Reprint\Importer\Sql\Port\SqlOutputSinkFactory;
use RuntimeException;

final class LocalSqlOutputSinkFactory implements SqlOutputSinkFactory
{
    private AuditLogger $audit;

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function create(DbPullCheckpoint $checkpoint, array $config): SqlOutputSink
    {
        $mode = $config["mode"];
        $state_dir = $config["state_dir"];

        if ($mode === "file") {
            return new FileSqlOutputSink(
                $state_dir . "/db.sql",
                $checkpoint->cursor,
                $checkpoint->sql_bytes,
                $this->audit,
            );
        }

        if ($mode === "stdout") {
            return new StdoutSqlOutputSink($checkpoint->sql_bytes ?? 0);
        }

        if ($mode === "mysql") {
            return new MysqlSqlOutputSink(
                $config,
                $state_dir,
                $checkpoint->sql_bytes ?? 0,
                $this->audit,
            );
        }

        throw new RuntimeException("Unsupported SQL output mode: {$mode}");
    }
}
