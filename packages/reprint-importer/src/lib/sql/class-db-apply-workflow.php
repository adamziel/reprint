<?php

namespace Reprint\Importer\Sql;

use InvalidArgumentException;
use PDO;
use Reprint\Importer\Filesystem\LocalImportFilesystem;
use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Sql\Infrastructure\HostPluginDeactivationPolicy;
use Reprint\Importer\Sql\Port\DbApplyCheckpointStore;
use Reprint\Importer\Sql\Port\DbApplyObserver;
use Reprint\Importer\Sql\Port\DbApplyShutdownToken;
use Reprint\Importer\Sql\Port\SqlStatementStatsStore;
use Reprint\Importer\UrlRewrite\NewSiteUrlResolver;
use Reprint\Importer\UrlRewrite\SqlStatementRewriter;
use Reprint\Importer\UrlRewrite\StructuredDataUrlRewriter;
use RuntimeException;

final class DbApplyWorkflow
{
    private string $state_dir;
    private string $remote_url;
    private LocalImportFilesystem $filesystem;
    private DbApplyCheckpointStore $checkpoints;
    private AuditLogger $audit;
    private DbApplyObserver $observer;
    private DbApplyShutdownToken $shutdown;
    private SqlStatementStatsStore $statement_stats;

    public function __construct(
        string $state_dir,
        string $remote_url,
        LocalImportFilesystem $filesystem,
        DbApplyCheckpointStore $checkpoints,
        AuditLogger $audit,
        DbApplyObserver $observer,
        DbApplyShutdownToken $shutdown,
        SqlStatementStatsStore $statement_stats
    ) {
        $this->state_dir = $state_dir;
        $this->remote_url = $remote_url;
        $this->filesystem = $filesystem;
        $this->checkpoints = $checkpoints;
        $this->audit = $audit;
        $this->observer = $observer;
        $this->shutdown = $shutdown;
        $this->statement_stats = $statement_stats;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function run(
        DbApplyCheckpoint $checkpoint,
        DbApplySourceContext $source,
        array $options
    ): DbApplyCheckpoint
    {
        $sql_file = $this->state_dir . "/db.sql";
        if (!file_exists($sql_file)) {
            throw new RuntimeException(
                "db.sql not found in {$this->state_dir}. Run db-pull first.",
            );
        }

        $options = NewSiteUrlResolver::resolve_options($options, $this->remote_url);
        $url_mapping = $this->url_mapping($options);
        $this->show_discovered_domains($url_mapping);

        $current_status = $checkpoint->status;

        if ($current_status === "complete") {
            throw new RuntimeException(
                "db-apply already completed. Use --abort flag to re-run.",
            );
        }

        $statements_executed = $checkpoint->statements_executed;
        $bytes_read = $checkpoint->bytes_read;
        $is_resume = $current_status === "in_progress" && $statements_executed > 0;

        if ($is_resume) {
            $this->audit(
                sprintf(
                    "RESUME db-apply | statements=%d | bytes_read=%d",
                    $statements_executed,
                    $bytes_read,
                ),
                true,
            );
            $this->observer->on_workflow_resuming($statements_executed, $bytes_read);
        } else {
            $checkpoint->reset(!empty($url_mapping) ? $url_mapping : null);
            $this->save_state($checkpoint);
            $statements_executed = 0;
            $bytes_read = 0;

            $this->audit("START db-apply", true);
            $this->observer->on_workflow_starting();
        }

        if (empty($url_mapping) && !empty($checkpoint->rewrite_url)) {
            $url_mapping = $checkpoint->rewrite_url;
        }

        $stmt_rewriter = $this->statement_rewriter($source, $url_mapping);
        [$pdo, $connection_label] = $this->create_target_connection(
            $checkpoint,
            $source,
            $options,
        );
        $sqlite_prepared_pdo = $this->configure_sqlite_import_hints($pdo, $options);
        $query_executor = new DbApplyQueryExecutor($pdo, $stmt_rewriter, $sqlite_prepared_pdo);

        $this->audit("CONNECTED | {$connection_label}", false);

        return (new SqlDumpApplier(
            $this->shutdown,
            $this->checkpoints,
            $this->audit,
            $this->observer,
            $this->statement_stats,
            new HostPluginDeactivationPolicy($source, $this->audit),
        ))->apply(
            $checkpoint,
            [
                "sql_file" => $sql_file,
                "new_site_url" => (string) ($options["new_site_url"] ?? ""),
            ],
            $query_executor,
            $pdo,
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function url_mapping(array $options): array
    {
        $url_mapping = [];
        if (empty($options["rewrite_url"])) {
            return $url_mapping;
        }

        foreach ($options["rewrite_url"] as $pair) {
            $url_mapping[$pair[0]] = $pair[1];
        }

        return $url_mapping;
    }

    /**
     * @param array<string, string> $url_mapping
     */
    private function show_discovered_domains(array $url_mapping): void
    {
        $domains_file = $this->state_dir . "/.import-domains.json";
        if (!file_exists($domains_file)) {
            return;
        }

        $contents = file_get_contents($domains_file);
        if ($contents === false) {
            return;
        }

        $domains = json_decode($contents, true);
        if (!is_array($domains) || empty($domains)) {
            return;
        }

        $this->audit(
            sprintf("DISCOVERED DOMAINS | %s", implode(", ", $domains)),
            false,
        );
        $this->observer->on_domains_discovered($domains, $url_mapping);
    }

    /**
     * @param array<string, string> $url_mapping
     */
    private function statement_rewriter(
        DbApplySourceContext $source,
        array $url_mapping
    ): ?SqlStatementRewriter
    {
        if (empty($url_mapping)) {
            return null;
        }

        $table_prefix = $source->table_prefix();
        $this->audit(
            sprintf(
                "URL MAPPING | %d mapping(s): %s",
                count($url_mapping),
                implode(", ", array_map(
                    fn($from, $to) => "{$from} => {$to}",
                    array_keys($url_mapping),
                    array_values($url_mapping),
                )),
            ),
            false,
        );

        return new SqlStatementRewriter(
            new StructuredDataUrlRewriter($url_mapping),
            $table_prefix,
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: PDO, 1: string}
     */
    private function create_target_connection(
        DbApplyCheckpoint $checkpoint,
        DbApplySourceContext $source,
        array $options
    ): array
    {
        $target_engine = strtolower((string) ($options["target_engine"] ?? "mysql"));
        if (!in_array($target_engine, ["mysql", "sqlite"], true)) {
            throw new InvalidArgumentException(
                "Invalid --target-engine value: {$target_engine}. Valid engines: mysql, sqlite.",
            );
        }

        if ($target_engine === "sqlite") {
            return $this->create_sqlite_target_connection($checkpoint, $source, $options);
        }

        return $this->create_mysql_target_connection($checkpoint, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: PDO, 1: string}
     */
    private function create_sqlite_target_connection(
        DbApplyCheckpoint $checkpoint,
        DbApplySourceContext $source,
        array $options
    ): array
    {
        $target_path = $options["target_sqlite_path"] ?? null;
        $target_db = $options["target_db"] ?? "sqlite_database";

        if (!$target_path) {
            $content_dir = $source->content_dir();
            if (!$content_dir) {
                throw new InvalidArgumentException(
                    "--target-sqlite-path option is required but was missing.",
                );
            }
            $target_path = $this->filesystem->filesystem_root_path() . $content_dir . '/database/.ht.sqlite';
            $this->audit("DB-APPLY | defaulting SQLite path to: {$target_path}");
            $this->observer->on_lifecycle_line("SQLite path: {$target_path}\n");
        }

        $checkpoint->target_engine = "sqlite";
        $checkpoint->target_db = $target_db;
        $checkpoint->target_sqlite_path = $target_path;

        return [
            TargetDatabaseConnectionFactory::sqlite($target_path, $target_db),
            sprintf(
                "engine=sqlite path=%s db=%s",
                $target_path,
                $target_db,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @return array{0: PDO, 1: string}
     */
    private function create_mysql_target_connection(
        DbApplyCheckpoint $checkpoint,
        array $options
    ): array
    {
        $target_host = $options["target_host"] ?? "127.0.0.1";
        $target_port = (int) ($options["target_port"] ?? 3306);
        $target_user = $options["target_user"] ?? null;
        $target_pass = $options["target_pass"] ?? "";
        $target_db = $options["target_db"] ?? null;

        if (!$target_user || !$target_db) {
            throw new InvalidArgumentException(
                "db-apply with --target-engine=mysql requires --target-user and --target-db.",
            );
        }

        $checkpoint->target_engine = "mysql";
        $checkpoint->target_db = $target_db;
        $checkpoint->target_host = $target_host;
        $checkpoint->target_port = $target_port;
        $checkpoint->target_user = $target_user;
        $checkpoint->target_pass = $target_pass;

        return [
            TargetDatabaseConnectionFactory::mysql(
                $target_host,
                $target_port,
                $target_db,
                $target_user,
                $target_pass,
            ),
            sprintf(
                "engine=mysql host=%s port=%d db=%s user=%s",
                $target_host,
                $target_port,
                $target_db,
                $target_user,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function configure_sqlite_import_hints(PDO $pdo, array $options): ?PDO
    {
        if (
            strtolower((string) ($options["target_engine"] ?? "mysql")) !== "sqlite" ||
            !method_exists($pdo, 'get_connection')
        ) {
            return null;
        }

        $sqlite_pdo = $pdo->get_connection()->get_pdo();
        // These are connection-local import hints. Avoid journal/sync/locking
        // PRAGMAs because they alter durability or observable database state.
        $sqlite_pdo->exec('PRAGMA temp_store = MEMORY');
        $sqlite_pdo->exec('PRAGMA cache_size = -32768');
        $this->audit(
            'SQLite db-apply PRAGMAs | temp_store=MEMORY | cache_size=32768 KiB',
            false,
        );

        return $sqlite_pdo;
    }

    /**
     * @return string[]
     */
    private function deactivate_host_plugins(
        PDO $pdo,
        DbApplySourceContext $source
    ): array
    {
        $analyzer = \host_analyzer_for($source->webhost());
        $preflight_data = $source->preflight_data();
        $manifest = $analyzer->analyze($preflight_data);

        return ActivePluginDeactivator::deactivate_for_removed_paths(
            $pdo,
            $manifest->paths_to_remove,
            $source->table_prefix(),
            function (string $message): void {
                $this->audit($message);
            },
        );
    }

    /**
     * @return string[]
     */
    private function deactivate_path_incompatible_plugins(
        PDO $pdo,
        DbApplySourceContext $source,
        string $new_site_url
    ): array {
        return ActivePluginDeactivator::deactivate_path_incompatible(
            $pdo,
            $new_site_url,
            $source->table_prefix(),
            function (string $message): void {
                $this->audit($message);
            },
        );
    }

    private function save_state(DbApplyCheckpoint $checkpoint): void
    {
        $this->checkpoints->save($checkpoint);
    }

    private function audit(string $message, bool $to_console = true): void
    {
        $this->audit->record($message, $to_console);
    }
}
