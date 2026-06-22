<?php

namespace Reprint\Importer\Sql;

use InvalidArgumentException;
use PDO;
use Reprint\Importer\Filesystem\LocalImportFilesystem;
use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\UrlRewrite\NewSiteUrlResolver;
use Reprint\Importer\UrlRewrite\SqlStatementRewriter;
use Reprint\Importer\UrlRewrite\StructuredDataUrlRewriter;
use RuntimeException;

final class DbApplyWorkflow
{
    private string $state_dir;
    private string $remote_url;
    private LocalImportFilesystem $filesystem;
    private ImportOutput $output;

    /** @var callable */
    private $save_state;

    /** @var callable */
    private $audit;

    /** @var callable */
    private $output_progress;

    /** @var callable */
    private $should_stop;

    public function __construct(
        string $state_dir,
        string $remote_url,
        LocalImportFilesystem $filesystem,
        ImportOutput $output,
        callable $save_state,
        callable $audit,
        callable $output_progress,
        callable $should_stop
    ) {
        $this->state_dir = $state_dir;
        $this->remote_url = $remote_url;
        $this->filesystem = $filesystem;
        $this->output = $output;
        $this->save_state = $save_state;
        $this->audit = $audit;
        $this->output_progress = $output_progress;
        $this->should_stop = $should_stop;
    }

    /**
     * @param array<string, mixed> $session_state
     * @param array<string, mixed> $options
     */
    public function run(
        DbApplyCheckpoint $checkpoint,
        array $session_state,
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
            $this->output->show_lifecycle_line("Resuming db-apply (executed: {$statements_executed} statements)\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "resuming",
                "command" => "db-apply",
                "statements_executed" => $statements_executed,
                "bytes_read" => $bytes_read,
                "message" => "Resuming db-apply (executed: {$statements_executed} statements)",
            ], true);
        } else {
            $checkpoint->reset(!empty($url_mapping) ? $url_mapping : null);
            $this->save_state($checkpoint);
            $statements_executed = 0;
            $bytes_read = 0;

            $this->audit("START db-apply", true);
            $this->output->show_lifecycle_line("Starting db-apply\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "starting",
                "command" => "db-apply",
                "message" => "Starting db-apply",
            ], true);
        }

        if (empty($url_mapping) && !empty($checkpoint->rewrite_url)) {
            $url_mapping = $checkpoint->rewrite_url;
        }

        $stmt_rewriter = $this->statement_rewriter($session_state, $url_mapping);
        [$pdo, $connection_label] = $this->create_target_connection(
            $checkpoint,
            $session_state,
            $options,
        );
        $sqlite_prepared_pdo = $this->configure_sqlite_import_hints($pdo, $options);
        $query_executor = new DbApplyQueryExecutor($pdo, $stmt_rewriter, $sqlite_prepared_pdo);

        $this->audit("CONNECTED | {$connection_label}", false);

        return (new SqlDumpApplier(
            function (): bool {
                return $this->should_stop();
            },
            function (DbApplyCheckpoint $checkpoint): void {
                $this->save_state($checkpoint);
            },
            function (string $message, bool $to_console): void {
                $this->audit($message, $to_console);
            },
            function (array $progress, bool $force): void {
                $this->output_progress($progress, $force);
            },
            function (string $message, ?float $fraction): void {
                $this->output->show_progress_line($message, $fraction);
            },
            function (string $message): void {
                $this->output->show_lifecycle_line($message);
            },
            function (): void {
                $this->output->clear_progress_line();
            },
            function (): bool {
                return $this->output->is_quiet_lifecycle();
            },
            function (PDO $pdo) use ($session_state): array {
                return $this->deactivate_host_plugins($pdo, $session_state);
            },
            function (PDO $pdo, string $new_site_url) use ($session_state): array {
                return $this->deactivate_path_incompatible_plugins(
                    $pdo,
                    $session_state,
                    $new_site_url,
                );
            },
        ))->apply(
            $checkpoint,
            [
                "sql_file" => $sql_file,
                "state_dir" => $this->state_dir,
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
        $this->output->show_lifecycle_line("Discovered domains in SQL dump:\n");
        foreach ($domains as $domain) {
            $mapped = isset($url_mapping[$domain])
                ? " => {$url_mapping[$domain]}"
                : " (not mapped)";
            $this->output->show_lifecycle_line("  {$domain}{$mapped}\n");
        }
        $this->output->show_lifecycle_line("\n");

        $domain_map = [];
        foreach ($domains as $domain) {
            $domain_map[$domain] = $url_mapping[$domain] ?? null;
        }
        $this->output_progress([
            "type" => "domains_discovered",
            "domains" => $domain_map,
            "message" => "Discovered " . count($domains) . " domain(s) in SQL dump",
        ], true);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, string> $url_mapping
     */
    private function statement_rewriter(array $state, array $url_mapping): ?SqlStatementRewriter
    {
        if (empty($url_mapping)) {
            return null;
        }

        $table_prefix = $state["preflight"]["data"]["database"]["wp"]["table_prefix"] ?? 'wp_';
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
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @return array{0: PDO, 1: string}
     */
    private function create_target_connection(
        DbApplyCheckpoint $checkpoint,
        array $session_state,
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
            return $this->create_sqlite_target_connection($checkpoint, $session_state, $options);
        }

        return $this->create_mysql_target_connection($checkpoint, $options);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @return array{0: PDO, 1: string}
     */
    private function create_sqlite_target_connection(
        DbApplyCheckpoint $checkpoint,
        array $session_state,
        array $options
    ): array
    {
        $target_path = $options["target_sqlite_path"] ?? null;
        $target_db = $options["target_db"] ?? "sqlite_database";

        if (!$target_path) {
            $content_dir = rtrim(
                $session_state["preflight"]["data"]["database"]["wp"]["paths_urls"]["content_dir"] ?? "",
                "/",
            );
            if (!$content_dir) {
                throw new InvalidArgumentException(
                    "--target-sqlite-path option is required but was missing.",
                );
            }
            $target_path = $this->filesystem->filesystem_root_path() . $content_dir . '/database/.ht.sqlite';
            $this->audit("DB-APPLY | defaulting SQLite path to: {$target_path}");
            $this->output->show_lifecycle_line("SQLite path: {$target_path}\n");
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
     * @param array<string, mixed> $state
     * @return string[]
     */
    private function deactivate_host_plugins(PDO $pdo, array $state): array
    {
        $webhost = $state["webhost"] ?? "other";
        $analyzer = \host_analyzer_for($webhost);
        $preflight_data = $state["preflight"]["data"] ?? [];
        $manifest = $analyzer->analyze($preflight_data);

        return ActivePluginDeactivator::deactivate_for_removed_paths(
            $pdo,
            $manifest->paths_to_remove,
            $this->table_prefix($state),
            function (string $message): void {
                $this->audit($message);
            },
        );
    }

    /**
     * @param array<string, mixed> $state
     * @return string[]
     */
    private function deactivate_path_incompatible_plugins(
        PDO $pdo,
        array $state,
        string $new_site_url
    ): array {
        return ActivePluginDeactivator::deactivate_path_incompatible(
            $pdo,
            $new_site_url,
            $this->table_prefix($state),
            function (string $message): void {
                $this->audit($message);
            },
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    private function table_prefix(array $state): string
    {
        $preflight_data = $state["preflight"]["data"] ?? [];
        return $preflight_data["database"]["wp"]["table_prefix"] ?? 'wp_';
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save_state(DbApplyCheckpoint $checkpoint): void
    {
        ($this->save_state)($checkpoint);
    }

    private function audit(string $message, bool $to_console = true): void
    {
        ($this->audit)($message, $to_console);
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function output_progress(array $progress, bool $force = false): void
    {
        ($this->output_progress)($progress, $force);
    }

    private function should_stop(): bool
    {
        return (bool) ($this->should_stop)();
    }
}
