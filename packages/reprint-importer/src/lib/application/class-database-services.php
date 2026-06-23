<?php

namespace Reprint\Importer\Application;

use Reprint\Importer\Observability\ImportOutputMachineEventEmitter;
use Reprint\Importer\Sql\DbApplySourceContext;
use Reprint\Importer\Sql\DbApplyWorkflow;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\DbPullConfiguration;
use Reprint\Importer\Sql\DbPullWorkflow;
use Reprint\Importer\Sql\Infrastructure\ConfiguredSqlDumpDownloader;
use Reprint\Importer\Sql\Infrastructure\DbPullCurlTimeoutPolicy;
use Reprint\Importer\Sql\Infrastructure\ImportOutputDbApplyObserver;
use Reprint\Importer\Sql\Infrastructure\ImportOutputDbPullObserver;
use Reprint\Importer\Sql\Infrastructure\ImportOutputSqlStreamObserver;
use Reprint\Importer\Sql\Infrastructure\JsonlDbIndexTableSinkFactory;
use Reprint\Importer\Sql\Infrastructure\JsonSqlDomainStore;
use Reprint\Importer\Sql\Infrastructure\JsonSqlStatementStatsStore;
use Reprint\Importer\Sql\Infrastructure\LocalSqlOutputSinkFactory;
use Reprint\Importer\Sql\Infrastructure\RemoteDbIndexDownloader;
use Reprint\Importer\Sql\Infrastructure\ShutdownStateDbApplyToken;
use Reprint\Importer\Sql\Infrastructure\ShutdownStateSqlToken;
use Reprint\Importer\Sql\Infrastructure\TransportSqlStreamClient;
use Reprint\Importer\Sql\Port\SqlStreamClient;
use Reprint\Importer\Sql\SqlDownloader;

final class DatabaseServices
{
    private ImportContext $context;
    private ?SqlStreamClient $stream_client;

    public function __construct(
        ImportContext $context,
        ?SqlStreamClient $stream_client = null
    ) {
        $this->context = $context;
        $this->stream_client = $stream_client;
    }

    public function pull_workflow(): DbPullWorkflow
    {
        $context = $this->context;
        $audit = $context->audit_logger();
        $stream = $this->stream_client();
        $checkpoints = $context->db_pull_checkpoint_store();
        $shutdown = $this->pull_shutdown_token();
        $timeouts = $this->pull_timeout_policy();
        $config = new DbPullConfiguration(
            $context->state_dir(),
            $context->paths()->audit_log(),
            $context->sql_output_mode(),
            $context->mysql_database(),
        );

        return new DbPullWorkflow(
            $config,
            $checkpoints,
            new RemoteDbIndexDownloader(
                $stream,
                $shutdown,
                $checkpoints,
                $timeouts,
                new JsonlDbIndexTableSinkFactory($audit),
                $audit,
                $config->tables_file(),
            ),
            new ConfiguredSqlDumpDownloader(
                new SqlDownloader(
                    $stream,
                    $shutdown,
                    $checkpoints,
                    $timeouts,
                    new LocalSqlOutputSinkFactory($audit),
                    new JsonSqlDomainStore($context->paths()->domains_file()),
                    new JsonSqlStatementStatsStore($context->paths()->sql_stats_file()),
                    new ImportOutputSqlStreamObserver(
                        $context->output(),
                        new ImportOutputMachineEventEmitter($context->output()),
                        $context->db_pull_checkpoint(),
                    ),
                    $audit,
                ),
                [
                    "mode" => $context->sql_output_mode(),
                    "state_dir" => $context->state_dir(),
                    "remote_url" => $context->remote_url(),
                    "mysql_host" => $context->mysql_host(),
                    "mysql_port" => $context->mysql_port(),
                    "mysql_user" => $context->mysql_user(),
                    "mysql_password" => $context->mysql_password(),
                    "mysql_database" => $context->mysql_database(),
                    "save_every" => ImportContext::SAVE_STATE_EVERY_N_CHUNKS,
                ],
            ),
            new ImportOutputDbPullObserver(
                $context->output(),
                new ImportOutputMachineEventEmitter($context->output()),
            ),
            $audit,
        );
    }

    public function apply_workflow(): DbApplyWorkflow
    {
        $context = $this->context;

        return new DbApplyWorkflow(
            $context->state_dir(),
            $context->remote_url(),
            $context->local_filesystem(),
            $context->db_apply_checkpoint_store(),
            $context->audit_logger(),
            new ImportOutputDbApplyObserver(
                $context->output(),
                new ImportOutputMachineEventEmitter($context->output()),
            ),
            $this->apply_shutdown_token(),
            new JsonSqlStatementStatsStore($context->paths()->sql_stats_file()),
        );
    }

    public function apply_source(): DbApplySourceContext
    {
        return new DbApplySourceContext(
            $this->context->preflight_checkpoint()->require_data(
                "db-apply requires a prior preflight run. Run 'preflight' first.",
            ),
            $this->context->detected_webhost(),
        );
    }

    public function download_sql(DbPullCheckpoint $checkpoint): DbPullCheckpoint
    {
        $context = $this->context;
        $audit = $context->audit_logger();

        return (new SqlDownloader(
            $this->stream_client(),
            $this->pull_shutdown_token(),
            $context->db_pull_checkpoint_store(),
            $this->pull_timeout_policy(),
            new LocalSqlOutputSinkFactory($audit),
            new JsonSqlDomainStore($context->paths()->domains_file()),
            new JsonSqlStatementStatsStore($context->paths()->sql_stats_file()),
            new ImportOutputSqlStreamObserver(
                $context->output(),
                new ImportOutputMachineEventEmitter($context->output()),
                $checkpoint,
            ),
            $audit,
        ))->download($checkpoint, [
            "mode" => $context->sql_output_mode(),
            "state_dir" => $context->state_dir(),
            "remote_url" => $context->remote_url(),
            "mysql_host" => $context->mysql_host(),
            "mysql_port" => $context->mysql_port(),
            "mysql_user" => $context->mysql_user(),
            "mysql_password" => $context->mysql_password(),
            "mysql_database" => $context->mysql_database(),
            "save_every" => ImportContext::SAVE_STATE_EVERY_N_CHUNKS,
        ]);
    }

    public function stream_client(): SqlStreamClient
    {
        if ($this->stream_client instanceof SqlStreamClient) {
            return $this->stream_client;
        }

        return new TransportSqlStreamClient($this->context->http_session());
    }

    private function pull_shutdown_token(): ShutdownStateSqlToken
    {
        return new ShutdownStateSqlToken($this->context->shutdown());
    }

    private function apply_shutdown_token(): ShutdownStateDbApplyToken
    {
        return new ShutdownStateDbApplyToken($this->context->shutdown());
    }

    private function pull_timeout_policy(): DbPullCurlTimeoutPolicy
    {
        return new DbPullCurlTimeoutPolicy(
            $this->context->audit_logger(),
            ImportContext::MAX_CONSECUTIVE_TIMEOUTS,
        );
    }
}
