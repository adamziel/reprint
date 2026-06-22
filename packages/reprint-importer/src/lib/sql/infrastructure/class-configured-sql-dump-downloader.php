<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\Port\SqlDumpDownloader;
use Reprint\Importer\Sql\SqlDownloader;

final class ConfiguredSqlDumpDownloader implements SqlDumpDownloader
{
    private SqlDownloader $downloader;

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(SqlDownloader $downloader, array $config)
    {
        $this->downloader = $downloader;
        $this->config = $config;
    }

    public function download(DbPullCheckpoint $checkpoint): DbPullCheckpoint
    {
        return $this->downloader->download($checkpoint, $this->config);
    }
}
