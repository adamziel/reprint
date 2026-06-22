<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Sql\DbIndexCheckpoint;
use Reprint\Importer\Sql\Port\DbIndexTableSink;
use Reprint\Importer\Sql\Port\DbIndexTableSinkFactory;

final class JsonlDbIndexTableSinkFactory implements DbIndexTableSinkFactory
{
    private AuditLogger $audit;

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    public function create(string $tables_file, ?string $cursor, DbIndexCheckpoint $checkpoint): DbIndexTableSink
    {
        return new JsonlDbIndexTableSink($tables_file, $cursor, $checkpoint, $this->audit);
    }
}
