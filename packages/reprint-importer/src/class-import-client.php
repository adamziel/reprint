<?php

namespace Reprint\Importer;

use Exception;
use InvalidArgumentException;
use RuntimeException;
use Reprint\Importer\Command\ImportCommands;
use Reprint\Importer\Command\ImportCommandResult;
use Reprint\Importer\Command\PreflightCommand;
use Reprint\Importer\FileSync\DownloadList;
use Reprint\Importer\FileSync\FetchListBuilder;
use Reprint\Importer\FileSync\FetchListExecutor;
use Reprint\Importer\FileSync\FileFetchDownloader;
use Reprint\Importer\FileSync\FileSyncLocalApplier;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\FilesSyncWorkflow;
use Reprint\Importer\FileSync\Infrastructure\CliFileSyncEventSubscriber;
use Reprint\Importer\FileSync\IntermediateSymlinkRecreator;
use Reprint\Importer\FileSync\Infrastructure\ImportClientFetchBatchDownloader;
use Reprint\Importer\FileSync\Infrastructure\ImportClientFetchListGateway;
use Reprint\Importer\FileSync\Infrastructure\ImportClientFileFetchGateway;
use Reprint\Importer\FileSync\Infrastructure\ImportClientFileIndexGateway;
use Reprint\Importer\FileSync\Infrastructure\ImportClientFileSyncSettings;
use Reprint\Importer\FileSync\Infrastructure\ImportClientFileSyncStreamClient;
use Reprint\Importer\FileSync\Infrastructure\ImportClientFileSyncWorkspace;
use Reprint\Importer\FileSync\Infrastructure\ImportClientFilesPullTimeoutPolicy;
use Reprint\Importer\FileSync\Infrastructure\ImportClientFilesPullCheckpointStore;
use Reprint\Importer\FileSync\Infrastructure\ImportClientFilesSyncRunStore;
use Reprint\Importer\FileSync\Infrastructure\ImportClientRemoteFileIndexGateway;
use Reprint\Importer\FileSync\Infrastructure\ImportClientShutdownToken;
use Reprint\Importer\FileSync\Infrastructure\ImportClientSymlinkGateway;
use Reprint\Importer\FileSync\Infrastructure\ImportClientVolatileFileReporter;
use Reprint\Importer\FileSync\Infrastructure\ImportOutputSymlinkTargetObserver;
use Reprint\Importer\FileSync\Infrastructure\ImportOutputProgressTicker;
use Reprint\Importer\FileSync\RemoteIndexDownloader;
use Reprint\Importer\FileSync\RuntimeFilesDownloader;
use Reprint\Importer\FileSync\SymlinkTargetIndexer;
use Reprint\Importer\Filesystem\FlatDocumentRootBuilder;
use Reprint\Importer\Filesystem\LocalImportFilesystem;
use Reprint\Importer\Index\IndexFileSorter;
use Reprint\Importer\Index\IndexLineParser;
use Reprint\Importer\Index\IndexStore;
use Reprint\Importer\Input\ImportRunRequest;
use Reprint\Importer\Observability\FileAuditLogger;
use Reprint\Importer\Observability\ImportOutputMachineEventEmitter;
use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\Output\NullImportOutput;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Pull\Pull;
use Reprint\Importer\Pull\PullCheckpoint;
use Reprint\Importer\Pull\PullRuntime;
use Reprint\Importer\Session\ExportDirectoryResolver;
use Reprint\Importer\Session\ImportAbortHandler;
use Reprint\Importer\Session\ImportPaths;
use Reprint\Importer\Session\ImportRunState;
use Reprint\Importer\Session\JsonStateStore;
use Reprint\Importer\Session\PreflightCheckpoint;
use Reprint\Importer\Session\StatePathCodec;
use Reprint\Importer\Session\VolatileFileTracker;
use Reprint\Importer\Sql\DbApplyCheckpoint;
use Reprint\Importer\Sql\DbApplySourceContext;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\DbPullConfiguration;
use Reprint\Importer\Sql\DbApplyWorkflow;
use Reprint\Importer\Sql\DbPullWorkflow;
use Reprint\Importer\Sql\Infrastructure\ConfiguredSqlDumpDownloader;
use Reprint\Importer\Sql\Infrastructure\ImportClientDbApplyCheckpointStore;
use Reprint\Importer\Sql\Infrastructure\ImportClientDbApplyShutdownToken;
use Reprint\Importer\Sql\Infrastructure\ImportClientDbPullCheckpointStore;
use Reprint\Importer\Sql\Infrastructure\ImportClientDbPullTimeoutPolicy;
use Reprint\Importer\Sql\Infrastructure\ImportOutputDbApplyObserver;
use Reprint\Importer\Sql\Infrastructure\ImportOutputDbPullObserver;
use Reprint\Importer\Sql\Infrastructure\ImportClientSqlShutdownToken;
use Reprint\Importer\Sql\Infrastructure\ImportClientSqlStreamClient;
use Reprint\Importer\Sql\Infrastructure\ImportOutputSqlStreamObserver;
use Reprint\Importer\Sql\Infrastructure\JsonlDbIndexTableSinkFactory;
use Reprint\Importer\Sql\Infrastructure\JsonSqlDomainStore;
use Reprint\Importer\Sql\Infrastructure\JsonSqlStatementStatsStore;
use Reprint\Importer\Sql\Infrastructure\LocalSqlOutputSinkFactory;
use Reprint\Importer\Sql\Infrastructure\RemoteDbIndexDownloader;
use Reprint\Importer\Sql\SqlDownloader;
use Reprint\Importer\Support\ByteFormatter;
use Reprint\Importer\TargetRuntime\RuntimeConfigurationApplier;
use Reprint\Importer\TargetRuntime\RuntimeCheckpoint;
use Reprint\Importer\Transport\ImportHttpSession;

class ImportClient implements PullRuntime
{

    private const SAVE_STATE_EVERY_N_CHUNKS = 50;

    /**
     * Maximum number of consecutive cURL timeouts with no cursor progress
     * before the importer gives up. This prevents infinite retry loops
     * when the remote server is genuinely unresponsive.
     */
    private const MAX_CONSECUTIVE_TIMEOUTS = 3;

    /** @var string Export server URL. */
    private string $remote_url;

    /** @var string Directory for import state files (.reprint/run.json, db.sql, etc.). */
    private string $state_dir;

    /** @var string Directory where downloaded site files are written (no filesystem-root/ wrapper). */
    private string $fs_root;

    /** @var ImportPaths Derived filesystem paths for this import session. */
    private ImportPaths $paths;

    /** @var StatePathCodec Encodes byte-sensitive state paths for JSON persistence. */
    private StatePathCodec $state_path_codec;

    /** @var JsonStateStore Persists run state and workflow checkpoints. */
    private JsonStateStore $json_state_store;

    /** @var string Path to .reprint/run.json — persists session-level run state. */
    private $state_file;

    /**
     * @var string Path to .import-index.jsonl — sorted JSON-lines file tracking every
     * imported file's path, ctime, size, and type. Used for delta detection: on the next
     * sync we compare this against the remote index to decide what to download or delete.
     */
    private $index_file;

    /** @var IndexStore Local index persistence and pending update merging. */
    private IndexStore $index_store;

    /** @var IndexFileSorter Sorts JSONL index files by path. */
    private IndexFileSorter $index_sorter;

    /** @var string Path to .import-remote-index.jsonl — latest file index received from the server. */
    private $remote_index_file;

    /** @var string Path to .import-download-list.jsonl — files to download, computed by diffing remote vs local index. */
    private $download_list_file;

    /** @var string Path to .import-download-list-skipped.jsonl — files skipped by --filter, downloaded later with --filter=skipped-earlier. */
    private $skipped_download_list_file;

    /** @var string Path to .import-audit.log — append-only log of every operation for debugging. */
    private $audit_log;

    /** @var string Path to .import-volatile-files.json — files the server marks as frequently-changing. */
    private $volatile_files_file;

    /** @var int Running count of files imported in the current invocation. */
    private $files_imported = 0;

    /** @var int|null Total entries in the current download list.  Set once
     *  at the start of download_files_from_list() by counting newlines. */
    private $download_list_total = null;

    /** @var int|null Entries already processed (before the current offset)
     *  in the download list.  Computed at list start and incremented after
     *  each batch completes.  This is the cumulative, restart-safe counter
     *  that consumers should display as "files done". */
    private $download_list_done = null;

    /**
     * @var ImportRunState|null Session-level run state loaded from / saved to $state_file.
     * Workflow-specific progress belongs in per-workflow checkpoint files.
     */
    private ?ImportRunState $state = null;

    private ?PullCheckpoint $pull_checkpoint = null;
    private ?PreflightCheckpoint $preflight_checkpoint = null;
    private ?FilesPullCheckpoint $files_pull_checkpoint = null;

    /** @var bool Set to true by SIGTERM/SIGINT handler to finish the current chunk and exit cleanly. */
    private $shutdown_requested = false;

    /**
     * @var bool When true, tell the server to follow symlinks that point outside
     * the document root (expanding them into real files). Enabled by default,
     * disable with --no-follow-symlinks. Persisted in state so it survives
     * across invocations.
     */
    private $follow_symlinks = true;

    /**
     * @var bool When true, ask the server to ship the default-skipped
     * generated content (wp-content/cache, .git, node_modules, etc.).
     *
     * The server's file-index endpoint filters these by default so a
     * typical migration doesn't waste bytes on regeneratable junk. Set
     * to true with --include-caches when the consumer genuinely needs
     * those paths transferred (for example, debugging a caching plugin
     * or migrating a site whose cache holds first-render-only artifacts
     * with no source).
     */
    private $include_caches = false;

    /**
     * @var string Controls behavior when the fs root is non-empty at import start.
     *
     * 'error' (default): throw an error if the fs root is non-empty.
     * 'preserve-local': preserve existing files, symlinks, and directories in the
     * fs root instead of overwriting them; non-writable directories are skipped
     * gracefully and logged to the audit log.
     *
     * On the first sync, existing fs root content is left untouched — any file,
     * symlink, or directory that already exists at a path the remote tries to write
     * is skipped and never added to the local index.
     *
     * On subsequent delta syncs, preserved paths survive because the importer only
     * acts on paths listed in the remote index. Local-only hosting infrastructure
     * (e.g. __wp__ symlinks, drop-in symlinks, shared plugin directories) is simply
     * invisible to the diff and never touched.
     *
     * Set via --on-fs-root-nonempty, persisted in state so it survives across invocations.
     */
    private $fs_root_nonempty_behavior = 'error';

    /**
     * Controls which files are downloaded during files-pull.
     *
     *   "none"             — download everything (default)
     *   "essential-files"  — skip uploads, download only code/config/themes/plugins
     *   "skipped-earlier"  — download only files that a prior --filter=essential-files skipped
     *
     * Set via --filter=<value>, persisted in state so it survives across
     * resume cycles within the same run.
     */
    private $filter = "none";

    /** @var string|null Extra remote directory to include in the export (--extra-directory). */
    private $extra_directory = null;

    /**
     * @var int|null MySQL max_allowed_packet value for the import database connection.
     * Passed to the server so it can split SQL statements to fit within this limit.
     */
    private $max_allowed_packet = null;

    /** @var string|null Machine-readable error code from the last HTTP diagnosis. */
    private ?string $last_error_code = null;

    /** @var ImportOutput Reports progress, status, and human-readable output. */
    private ImportOutput $output;

    /** @var bool Whether per-run runtime resources have been prepared. */
    private bool $runtime_prepared = false;

    /** @var bool Whether this instance installed process signal handlers. */
    private bool $signal_handlers_installed = false;

    /** @var array<int, mixed> Signal handlers that were installed before this run. */
    private array $previous_signal_handlers = [];

    /** @var bool|null Async signal setting that was active before this run. */
    private ?bool $previous_async_signals = null;

    /** @var ImportHttpSession|null HTTP request/session policy for exporter calls. */
    private ?ImportHttpSession $http_session = null;

    /** @var LocalImportFilesystem|null Filesystem operations scoped to the import root. */
    private ?LocalImportFilesystem $local_filesystem = null;

    /** @var Pull Orchestrates the pull command pipeline. */
    private Pull $pull;

    /** @var FilesSyncWorkflow Orchestrates files-pull and files-index. */
    private FilesSyncWorkflow $files_sync;

    /** @var int Cumulative count of index entries written (survives retries). */
    private $index_entries_counted = 0;

    /** @var int|null Current step in a multi-step pipeline (1-indexed). Set via --step. */
    private $pipeline_step = null;

    /** @var int|null Total number of pipeline steps. Set via --steps. */
    private $pipeline_steps = null;

    /** @var string Path to .import-status.json — machine-readable status for external progress readers. */
    private $status_file;

    /** @var string SQL output mode: 'file' (default), 'stdout', or 'mysql'. */
    private $sql_output_mode = 'file';

    /** @var string|null MySQL host for --sql-output=mysql. */
    private $mysql_host;

    /** @var int|null MySQL port for --sql-output=mysql. */
    private $mysql_port;

    /** @var string|null MySQL user for --sql-output=mysql. */
    private $mysql_user;

    /** @var string|null MySQL password for --sql-output=mysql. */
    private $mysql_password;

    /** @var string|null MySQL database for --sql-output=mysql. */
    private $mysql_database;

    /**
     * @var int Process exit code. 0 = import complete, 2 = partial progress
     * (caller should invoke again to continue).
     */
    private int $exit_code = 0;

    public function __construct(
        string $remote_url,
        string $state_dir,
        string $fs_root,
        ?ImportOutput $output = null
    )
    {
        $this->remote_url = rtrim($remote_url, "?&");
        $this->state_dir = rtrim($state_dir, "/");
        $this->fs_root = rtrim($fs_root, "/");
        $this->output = $output ?? new NullImportOutput();
        $this->paths = new ImportPaths($this->state_dir);
        $this->json_state_store = new JsonStateStore();
        $this->state_path_codec = new StatePathCodec(function (string $message): void {
            $this->audit_log($message, true);
        });
        $this->state_file = $this->paths->state_file();
        $this->index_file = $this->paths->index_file();
        $this->remote_index_file = $this->paths->remote_index_file();
        $this->download_list_file = $this->paths->download_list_file();
        $this->skipped_download_list_file = $this->paths->skipped_download_list_file();
        $this->audit_log = $this->paths->audit_log();
        $this->volatile_files_file = $this->paths->volatile_files_file();
        $this->status_file = $this->paths->status_file();

        $this->pull = new Pull($this, $this->output->progress());
        $file_sync_workspace = new ImportClientFileSyncWorkspace($this);
        $this->files_sync = new FilesSyncWorkflow(
            new ImportClientFilesSyncRunStore($this),
            new ImportClientFilesPullCheckpointStore($this),
            new ImportClientFileSyncSettings($this),
            $file_sync_workspace,
            new ImportClientFileIndexGateway($this, $file_sync_workspace),
            new ImportClientRemoteFileIndexGateway($this),
            new ImportClientFetchListGateway($this),
            new ImportClientFileFetchGateway($this),
            new ImportClientSymlinkGateway($this),
            new ImportClientShutdownToken($this),
            new ImportClientVolatileFileReporter($this),
            new CliFileSyncEventSubscriber(
                new FileAuditLogger($this->audit_log, $this->output),
                $this->output,
                new ImportOutputMachineEventEmitter($this->output),
            ),
        );
        $this->index_store = new IndexStore(
            $this->index_file,
            $this->paths->index_updates_file(),
            function (string $message): void {
                $this->audit_log($message);
            },
        );
        $this->index_sorter = new IndexFileSorter(
            function (string $message): void {
                $this->audit_log($message);
            },
            function (): void {
                $this->output->tick_spinner();
            },
        );
    }

    private function prepare_runtime(): void
    {
        if ($this->runtime_prepared) {
            return;
        }

        $this->ensure_runtime_directories();
        $this->install_signal_handlers();
        $this->runtime_prepared = true;
    }

    private function cleanup_runtime(): void
    {
        if (!$this->runtime_prepared) {
            return;
        }

        $this->restore_signal_handlers();
        $this->runtime_prepared = false;
    }

    private function ensure_runtime_directories(): void
    {
        if (!is_dir($this->state_dir)) {
            if (!mkdir($this->state_dir, 0755, true)) {
                throw new RuntimeException("Failed to create directory: {$this->state_dir}");
            }
        }
        if (!is_dir($this->fs_root)) {
            if (!mkdir($this->fs_root, 0755, true)) {
                throw new RuntimeException("Failed to create directory: {$this->fs_root}");
            }
        }
    }

    private function install_signal_handlers(): void
    {
        if (!function_exists("pcntl_signal")) {
            return;
        }

        $signals = [SIGINT, SIGTERM];
        if (function_exists("pcntl_signal_get_handler")) {
            foreach ($signals as $signal) {
                $this->previous_signal_handlers[$signal] = pcntl_signal_get_handler($signal);
            }
        }

        if (function_exists("pcntl_async_signals")) {
            $this->previous_async_signals = pcntl_async_signals();
            pcntl_async_signals(true);
        }

        pcntl_signal(SIGINT, [$this, "handle_shutdown"]);
        pcntl_signal(SIGTERM, [$this, "handle_shutdown"]);
        $this->signal_handlers_installed = true;
    }

    private function restore_signal_handlers(): void
    {
        if (!$this->signal_handlers_installed || !function_exists("pcntl_signal")) {
            return;
        }

        foreach ([SIGINT, SIGTERM] as $signal) {
            if (array_key_exists($signal, $this->previous_signal_handlers)) {
                pcntl_signal($signal, $this->previous_signal_handlers[$signal]);
            } else {
                pcntl_signal($signal, SIG_DFL);
            }
        }

        if ($this->previous_async_signals !== null && function_exists("pcntl_async_signals")) {
            pcntl_async_signals($this->previous_async_signals);
        }

        $this->previous_signal_handlers = [];
        $this->previous_async_signals = null;
        $this->signal_handlers_installed = false;
    }

    private function http_session(): ImportHttpSession
    {
        if ($this->http_session instanceof ImportHttpSession) {
            return $this->http_session;
        }

        $this->http_session = new ImportHttpSession(
            $this->remote_url,
            $this->output,
            function (string $message, bool $to_console = true): void {
                $this->audit_log($message, $to_console);
            },
            function (array $event): void {
                $this->output_progress($event);
            },
        );

        return $this->http_session;
    }

    /**
     * Return current index size.
     */
    public function index_count(): int
    {
        return $this->index_store->count();
    }

    /**
     * Recover and merge any pending index updates from a previous run.
     */
    public function recover_index_updates(): void
    {
        $this->index_store->recover();
    }

    /**
     * Log to audit file (always) and optionally to console.
     *
     * @param string $message Message to log
     * @param bool $to_console Whether to also output to console (respects verbose mode)
     */
    public function audit_log(string $message, bool $to_console = true): void
    {
        $timestamp = date("Y-m-d H:i:s");
        $log_line = "[{$timestamp}] {$message}\n";

        // Always write to audit log
        file_put_contents($this->audit_log, $log_line, FILE_APPEND);

        // Output to console if verbose mode or if explicitly requested
        if ($to_console && $this->output->is_verbose()) {
            $this->output->write($log_line);
        }
    }

    private function run_state(): ImportRunState
    {
        if (!$this->state instanceof ImportRunState) {
            $this->state = $this->load_state();
        }

        return $this->state;
    }

    public function set_request_user_agent(string $user_agent): void
    {
        $this->run_state()->user_agent = $user_agent;
        $this->http_session()->set_user_agent($user_agent);
    }

    public function paths(): ImportPaths
    {
        return $this->paths;
    }

    public function remote_host(): string
    {
        return $this->http_session()->remote_host();
    }

    public function ensure_site_export_api_url(): void
    {
        $this->http_session()->ensure_site_export_api_url();
        $this->remote_url = $this->http_session()->remote_url();
    }

    public function default_runtime_output_dir(): string
    {
        return $this->paths->state_dir() . '/runtime';
    }

    public function fs_root(): string
    {
        return $this->fs_root;
    }

    public function has_wpcloud_docroot_link(): bool
    {
        return is_link($this->fs_root . '/__wp__');
    }

    public function exit_code(): int
    {
        return $this->exit_code;
    }

    public function set_exit_code(int $exit_code): void
    {
        $this->exit_code = $exit_code;
    }

    public function last_error_code(): ?string
    {
        return $this->http_session instanceof ImportHttpSession
            ? ($this->http_session->last_error_code() ?? $this->last_error_code)
            : $this->last_error_code;
    }

    public function current_filter(): string
    {
        return $this->run_state()->filter;
    }

    public function current_command(): ?string
    {
        return $this->run_state()->command;
    }

    public function current_run_status(): ?string
    {
        return $this->run_state()->status;
    }

    public function set_run_status(?string $status): void
    {
        $state = $this->run_state();
        $state->status = $status;
        $this->save_state($state);
    }

    public function record_command_status(string $command, ?string $status): void
    {
        $state = $this->run_state();
        $state->set_command_status($command, $status);
        $this->save_state($state);
    }

    public function prepare_repull_run_state(): void
    {
        $state = $this->run_state();
        $state->command = null;
        $state->status = null;
        $state->sql_output = null;
        $this->save_state($state);
    }

    /** True when the skipped-download list exists and still has entries. */
    public function has_skipped_files_pending(): bool
    {
        return
            file_exists($this->skipped_download_list_file) &&
            filesize($this->skipped_download_list_file) > 0;
    }

    public function remote_index_file(): string
    {
        return $this->remote_index_file;
    }

    public function download_list_file(): string
    {
        return $this->download_list_file;
    }

    public function skipped_download_list_file(): string
    {
        return $this->skipped_download_list_file;
    }

    public function audit_log_file(): string
    {
        return $this->audit_log;
    }

    public function audit_logger(): AuditLogger
    {
        return new FileAuditLogger($this->audit_log, $this->output);
    }

    public function fs_root_nonempty_behavior(): string
    {
        return $this->fs_root_nonempty_behavior;
    }

    public function follow_symlinks(): bool
    {
        return $this->follow_symlinks;
    }

    public function include_caches(): bool
    {
        return $this->include_caches;
    }

    public function index_entries_counted(): int
    {
        return $this->index_entries_counted;
    }

    public function set_file_sync_progress(
        int $files_imported,
        ?int $download_list_done,
        ?int $download_list_total
    ): void {
        $this->files_imported = $files_imported;
        $this->download_list_done = $download_list_done;
        $this->download_list_total = $download_list_total;
    }

    /**
     * Log the executed command and full argv to the audit log.
     * Called from the CLI entry point before run() so the invocation
     * is captured even if run() throws early.
     */
    public function audit_log_argv(string $command, array $argv): void
    {
        // Mask the remote URL (argv[2]) to avoid logging secrets embedded in query strings.
        $masked = $argv;
        if (isset($masked[2]) && $command !== 'apply-runtime') {
            $masked[2] = preg_replace('/SECRET_KEY=[^&\s]+/', 'SECRET_KEY=***', $masked[2]);
        }
        $this->audit_log("COMMAND | {$command} | argv=" . implode(' ', $masked), false);
    }

    private function volatile_file_tracker(): VolatileFileTracker
    {
        return new VolatileFileTracker(
            $this->volatile_files_file,
            function (string $message): void {
                $this->audit_log($message);
            },
        );
    }

    private function file_sync_local_applier(?FilesPullCheckpoint $checkpoint = null): FileSyncLocalApplier
    {
        return new FileSyncLocalApplier(
            $this->local_filesystem(),
            $this->index_store,
            $this->volatile_file_tracker(),
            $this->output,
            $this->fs_root,
            $this->remote_index_file,
            $this->fs_root_nonempty_behavior,
            $this->follow_symlinks,
            $this->files_imported,
            $this->download_list_done,
            $this->download_list_total,
            $checkpoint,
            new FileAuditLogger($this->audit_log, $this->output),
            new ImportOutputMachineEventEmitter($this->output),
        );
    }

    /**
     * Report volatile files to the user at sync completion.
     */
    public function report_volatile_files(): void
    {
        $files = $this->volatile_file_tracker()->load();
        if (empty($files)) {
            return;
        }

        $count = count($files);
        $this->audit_log(
            sprintf("VOLATILE SUMMARY | %d file(s) changed during sync", $count),
            true,
        );

        $this->output->show_lifecycle_line("{$count} file(s) changed during sync and need re-syncing (run files-pull again):\n");

        foreach ($files as $path => $changes) {
            $suffix = $changes >= 3
                ? " (changed {$changes} times — may be too volatile to sync)"
                : " (changed {$changes} time" . ($changes > 1 ? "s" : "") . ")";
            $this->audit_log("  VOLATILE FILE | path={$path} | count={$changes}");
            $this->output->show_lifecycle_line("  {$path}{$suffix}\n");
        }

        $this->output_progress(
            [
                "type" => "volatile_files",
                "files" => $files,
                "count" => $count,
                "message" => "{$count} file(s) changed during sync and need re-syncing (run files-pull again)",
            ],
            true,
        );
    }

    /**
     * Run the import process with explicit command validation.
     *
     * @param array $raw_options Options:
     *   - command: Required. One of the commands registered in ImportCommands.
     *   - abort: Optional. Clear state for the command and exit immediately
     *   - verbose: Optional. Enable verbose output
     *
     * @return ImportCommandResult|null Structured command result for the caller to format.
     */
    public function run(array $raw_options = []): ?ImportCommandResult
    {
        $this->prepare_runtime();

        try {
            $request = ImportRunRequest::from_options($raw_options);
            $options = $request->options();
            $command = $request->command();
            $command_runner = $request->command_runner();

            $this->prepare_request_state($request);

            $this->initialize_http_session($request, $options);

            if ($request->abort()) {
                if (!$command_runner->supports_abort()) {
                    throw new InvalidArgumentException("Command {$command} does not support --abort");
                }
                $command_runner->abort($this, $command);
                return null;
            }

            if ($command_runner->requires_preflight()) {
                $this->require_preflight();
            }

            try {
                $result = $command_runner->execute($this, $options);
                if ($command_runner->emits_final_status()) {
                    $this->finish_command_status($command);
                }
                return $result;
            } catch (Exception $e) {
                $this->report_command_exception($e);
                throw $e;
            }
        } finally {
            $this->cleanup_runtime();
        }
    }

    private function prepare_request_state(ImportRunRequest $request): void
    {
        $this->output->set_verbose_mode($request->verbose());
        $this->follow_symlinks = $request->value("follow_symlinks", true);
        $this->include_caches = $request->value("include_caches", false);
        $this->extra_directory = $request->value("extra_directory");
        $this->pipeline_step = $request->value("pipeline_step");
        $this->pipeline_steps = $request->value("pipeline_steps");
        $this->state = $this->load_state();

        $this->apply_follow_symlinks_option($request);
        $this->apply_fs_root_behavior_option($request);
        $this->apply_filter_option($request);
        $this->apply_max_allowed_packet_option($request);
        $this->apply_sql_output_option($request);
        $this->apply_mysql_connection_options($request);
        $this->save_state($this->state);

        $this->apply_mysql_password_option($request);
        $this->validate_sql_output_options();
    }

    private function apply_follow_symlinks_option(ImportRunRequest $request): void
    {
        if ($request->has("follow_symlinks")) {
            $this->state->follow_symlinks = $this->follow_symlinks;
            return;
        }

        $this->follow_symlinks = $this->state->follow_symlinks;
    }

    private function apply_fs_root_behavior_option(ImportRunRequest $request): void
    {
        if ($request->has("fs_root_nonempty_behavior")) {
            $this->fs_root_nonempty_behavior = $request->value("fs_root_nonempty_behavior");
            $this->state->fs_root_nonempty_behavior = $this->fs_root_nonempty_behavior;
        } else {
            $this->fs_root_nonempty_behavior = $this->state->fs_root_nonempty_behavior;
        }

        $this->local_filesystem = null;
    }

    private function apply_filter_option(ImportRunRequest $request): void
    {
        if (!$request->has("filter")) {
            $this->filter = $this->state->filter;
            return;
        }

        $next = $request->value("filter");
        $prev = $this->state->filter;
        $status = $this->state->status;
        $is_mid_flight = $prev !== null && $prev !== $next && $status !== null && $status !== "complete";
        if ($is_mid_flight) {
            throw new RuntimeException(
                "Cannot change --filter from '{$prev}' to '{$next}' while a sync is in progress. " .
                    "Finish the current sync or use --abort to start over.",
            );
        }

        $this->filter = $next;
        $this->state->filter = $this->filter;
    }

    private function apply_max_allowed_packet_option(ImportRunRequest $request): void
    {
        if ($request->has("max_allowed_packet")) {
            $this->max_allowed_packet = (int) $request->value("max_allowed_packet");
            $this->state->max_allowed_packet = $this->max_allowed_packet;
            return;
        }

        if ($this->state->max_allowed_packet !== null) {
            $this->max_allowed_packet = $this->state->max_allowed_packet;
        }
    }

    private function apply_sql_output_option(ImportRunRequest $request): void
    {
        if ($request->has("sql_output")) {
            $this->sql_output_mode = $request->value("sql_output");
            $this->state->sql_output = $this->sql_output_mode;
        } elseif ($this->state->sql_output !== null) {
            $this->sql_output_mode = $this->state->sql_output;
        }

        if ($this->sql_output_mode === "stdout") {
            $this->output->use_error_stream();
        }
    }

    private function apply_mysql_connection_options(ImportRunRequest $request): void
    {
        if ($request->has("mysql_host")) {
            $this->mysql_host = $request->value("mysql_host");
            $this->state->mysql_host = $this->mysql_host;
        } elseif ($this->state->mysql_host !== null) {
            $this->mysql_host = $this->state->mysql_host;
        }

        if ($request->has("mysql_port")) {
            $this->mysql_port = (int) $request->value("mysql_port");
            $this->state->mysql_port = $this->mysql_port;
        } elseif ($this->state->mysql_port !== null) {
            $this->mysql_port = $this->state->mysql_port;
        }

        if ($request->has("mysql_user")) {
            $this->mysql_user = $request->value("mysql_user");
            $this->state->mysql_user = $this->mysql_user;
        } elseif ($this->state->mysql_user !== null) {
            $this->mysql_user = $this->state->mysql_user;
        }

        if ($request->has("mysql_database")) {
            $this->mysql_database = $request->value("mysql_database");
            $this->state->mysql_database = $this->mysql_database;
        } elseif ($this->state->mysql_database !== null) {
            $this->mysql_database = $this->state->mysql_database;
        }
    }

    private function apply_mysql_password_option(ImportRunRequest $request): void
    {
        if ($request->has("mysql_password")) {
            $this->mysql_password = $request->value("mysql_password");
        } elseif (getenv("MYSQL_PASSWORD") !== false) {
            $this->mysql_password = getenv("MYSQL_PASSWORD");
        }
    }

    private function validate_sql_output_options(): void
    {
        if ($this->sql_output_mode !== "mysql" || !empty($this->mysql_database)) {
            return;
        }

        throw new InvalidArgumentException(
            "--mysql-database is required when using --sql-output=mysql",
        );
    }

    private function initialize_http_session(
        ImportRunRequest $request,
        array $options
    ): void {
        $session = $this->http_session();

        $config = $this->state->tuning_config();
        $state = $this->state->tuning_state();
        $cli_config = $options["tuning_config"] ?? [];
        $config = array_merge($config, $cli_config);

        $session->configure_tuner($config, $state, $this->max_allowed_packet);
        $this->state->set_tuning($session->tuning_config(), $session->tuning_state());

        $this->audit_log(
            "TUNER CONFIG | " . json_encode($this->state->tuning_config()),
            false,
        );

        $secret = $request->value("secret");
        if (empty($secret)) {
            $session->set_hmac_secret(null);
            return;
        }

        if (!class_exists(\Reprint\Exporter\Site_Export_HMAC_Client::class)) {
            throw new RuntimeException(
                'Streaming exporter runtime not found. Run composer install before using --secret.'
            );
        }

        $session->set_hmac_secret($secret);
    }

    public function run_pull(array $options): void
    {
        $this->pull->run($options);
    }

    public function abort_pull(): void
    {
        $this->pull->abort();
    }

    public function abort_command(string $command): void
    {
        $this->handle_abort($command);
    }

    public function finish_command_status(string $command): void
    {
        $final_status = $this->state?->status ?? "complete";
        $this->output_progress(["status" => $final_status, "message" => "{$command} {$final_status}"]);

        // Exit code 2 signals "partial progress, call me again" so
        // runner scripts can loop on $? without reading the state file.
        if ($final_status === "partial") {
            $this->exit_code = 2;
        }
    }

    public function report_command_exception(Exception $e): void
    {
        $this->output_progress([
            "status" => "error",
            "error" => $e->getMessage(),
            "error_code" => $this->last_error_code,
            "message" => "Error: " . $e->getMessage(),
        ]);
        $this->write_status_file($e->getMessage());
    }

    /**
     * Handle --abort for any command: clear relevant state and exit.
     *
     * Each command has its own set of files and state fields that need clearing.
     * After clearing, we save state and return — the caller exits without
     * running the actual sync. The user then runs the command again to start fresh.
     */
    private function handle_abort(string $command): void
    {
        $handler = new ImportAbortHandler(
            $this->paths,
            $this->index_store,
            $this->audit_logger(),
        );

        $this->state = $handler->abort(
            $this->state ?? $this->default_state(),
            $command,
            $this->sql_output_mode,
        );
        if ($command === "files-pull" || $command === "files-index") {
            $this->files_pull_checkpoint = null;
        }
        $this->save_state($this->state);

        $this->output->show_lifecycle_line("State cleared for {$command}.\n");

        $this->output_progress(["status" => "aborted", "message" => "State cleared for {$command}."]);
    }

    /**
     * Run a cheap preflight check to record exporter environment details.
     */
    public function run_preflight(): void
    {
        (new PreflightCommand())->fetch($this);
    }

    /**
     * Download auto_prepend_file and auto_append_file scripts into
     * state_dir/runtime_files/.
     *
     * Called on every preflight: the directory is wiped and recreated
     * so it always reflects the current server state.  Download
     * failures are tolerated since the scripts may live on paths not
     * accessible to the web server process.
     */
    public function download_runtime_files(): void
    {
        (new RuntimeFilesDownloader(
            new ImportClientFileSyncStreamClient($this),
            $this->audit_logger(),
        ))->download(
            $this->preflight_data() ?? [],
            $this->paths->runtime_files_dir(),
        );
    }

    /**
     * Assert that a preflight has already been run and stored in state.
     * All commands except preflight/preflight-assert call this before starting work.
     */
    public function require_preflight(): void
    {
        if ($this->preflight_data() === null) {
            throw new RuntimeException(
                "No preflight data found. Run 'preflight' or 'preflight-assert' first.",
            );
        }
    }

    /**
     * Build request params for an endpoint using the adaptive tuner.
     */
    public function get_tuned_params(string $endpoint): array
    {
        return $this->http_session()->tuned_params($endpoint);
    }

    /**
     * Record request metrics, apply tuning decisions, and sleep if needed.
     */
    private function finalize_tuned_request(
        string $endpoint,
        float $wall_time,
        array $response_stats
    ): void {
        $this->http_session()->finalize_tuned_request(
            $endpoint,
            $wall_time,
            $response_stats,
        );
    }

    /**
     * Command: files-pull
     *
     * Unified file synchronization that auto-detects initial vs delta mode:
     * - No prior completed files-pull → initial mode (index all, fetch all)
     * - Prior completed files-pull → delta mode (re-index, diff, fetch changes)
     * - In-progress files-pull → resume from saved state
     *
     * Both modes share the same pipeline: index → diff → fetch.
     */
    public function run_files_sync(): void
    {
        $this->files_sync->run_files_sync();
    }

    /**
     * Command: files-index
     *
     * Rules:
     * - Streams the full remote index (DFS across directories) until complete
     * - If already completed: require --abort flag
     * - If abort flag: clear remote index file and index cursor
     */
    public function run_files_index(): void
    {
        $this->files_sync->run_files_index();
    }

    /**
     * Recursively discover directories that need indexing beyond the primary
     * export roots.
     *
     * Scans the remote index for symlink entries with a "target" field,
     * resolves relative targets to absolute paths, and indexes each target
     * directory. Repeats until the queue is drained, with cycle detection.
     */
    public function discover_symlink_targets(FilesPullCheckpoint $checkpoint): void
    {
        (new SymlinkTargetIndexer(
            $this->remote_index_file,
            new ImportClientRemoteFileIndexGateway($this),
            new ImportClientFilesPullCheckpointStore($this),
            new ImportClientShutdownToken($this),
            $this->audit_logger(),
            new ImportOutputSymlinkTargetObserver(
                $this->output,
                new ImportOutputMachineEventEmitter($this->output),
            ),
        ))->discover($checkpoint, $this->get_root_directories_from_preflight());
    }

    /**
     * Command: db-pull
     *
     * Rules:
     * - Stream next portion of SQL from last saved cursor
     * - If already completed and db.sql exists: require --abort flag
     * - If db.sql missing but state says complete: warn and require --abort flag
     * - Otherwise: error
     */
    public function run_db_sync(): void
    {
        $checkpoint = $this->db_pull_workflow()->run(
            $this->load_db_pull_checkpoint(),
        );
        $this->record_command_status("db-pull", $checkpoint->status);
    }

    private function db_pull_workflow(): DbPullWorkflow
    {
        $audit = $this->audit_logger();
        $stream = new ImportClientSqlStreamClient($this);
        $checkpoints = new ImportClientDbPullCheckpointStore($this);
        $shutdown = new ImportClientSqlShutdownToken($this);
        $timeouts = new ImportClientDbPullTimeoutPolicy($this);
        $config = new DbPullConfiguration(
            $this->state_dir,
            $this->audit_log,
            $this->sql_output_mode,
            $this->mysql_database,
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
                    new JsonSqlDomainStore($this->paths->domains_file()),
                    new JsonSqlStatementStatsStore($this->state_dir . "/.import-sql-stats.json"),
                    new ImportOutputSqlStreamObserver(
                        $this->output,
                        new ImportOutputMachineEventEmitter($this->output),
                        $this->load_db_pull_checkpoint(),
                    ),
                    $audit,
                ),
                [
                    "mode" => $this->sql_output_mode,
                    "state_dir" => $this->state_dir,
                    "remote_url" => $this->remote_url,
                    "mysql_host" => $this->mysql_host,
                    "mysql_port" => $this->mysql_port,
                    "mysql_user" => $this->mysql_user,
                    "mysql_password" => $this->mysql_password,
                    "mysql_database" => $this->mysql_database,
                    "save_every" => self::SAVE_STATE_EVERY_N_CHUNKS,
                ],
            ),
            new ImportOutputDbPullObserver(
                $this->output,
                new ImportOutputMachineEventEmitter($this->output),
            ),
            $audit,
        );
    }

    public function load_db_pull_checkpoint(): DbPullCheckpoint
    {
        return DbPullCheckpoint::from_persisted_array(
            $this->json_state_store->load($this->paths->db_pull_checkpoint_file()) ?? [],
            [$this->state_path_codec, 'decode_value'],
        );
    }

    public function save_db_pull_checkpoint(DbPullCheckpoint $checkpoint): void
    {
        $this->output->tick_spinner();
        $this->json_state_store->save(
            $this->paths->db_pull_checkpoint_file(),
            $checkpoint->to_persisted_array([$this->state_path_codec, 'encode_value']),
        );
    }

    public function db_apply_checkpoint(): DbApplyCheckpoint
    {
        return DbApplyCheckpoint::from_array(
            $this->json_state_store->load($this->paths->db_apply_checkpoint_file()) ?? [],
        );
    }

    public function save_db_apply_checkpoint(DbApplyCheckpoint $checkpoint): void
    {
        $this->output->tick_spinner();
        $this->json_state_store->save(
            $this->paths->db_apply_checkpoint_file(),
            $checkpoint->to_array(),
        );
    }

    public function runtime_checkpoint(): RuntimeCheckpoint
    {
        return RuntimeCheckpoint::from_array(
            $this->json_state_store->load($this->paths->runtime_checkpoint_file()) ?? [],
        );
    }

    public function save_runtime_checkpoint(RuntimeCheckpoint $checkpoint): void
    {
        $this->output->tick_spinner();
        $this->json_state_store->save(
            $this->paths->runtime_checkpoint_file(),
            $checkpoint->to_array(),
        );
    }

    public function preflight_checkpoint(): PreflightCheckpoint
    {
        if ($this->preflight_checkpoint instanceof PreflightCheckpoint) {
            return $this->preflight_checkpoint;
        }

        $this->preflight_checkpoint = PreflightCheckpoint::from_persisted_array(
            $this->json_state_store->load($this->paths->preflight_checkpoint_file()) ?? [],
            [$this->state_path_codec, 'decode_preflight_data_paths'],
        );

        return $this->preflight_checkpoint;
    }

    public function save_preflight_checkpoint(PreflightCheckpoint $checkpoint): void
    {
        $this->output->tick_spinner();
        $this->preflight_checkpoint = $checkpoint;
        $this->json_state_store->save(
            $this->paths->preflight_checkpoint_file(),
            $checkpoint->to_persisted_array([$this->state_path_codec, 'encode_preflight_data_paths']),
        );
    }

    public function preflight_entry(): ?array
    {
        return $this->preflight_checkpoint()->entry;
    }

    public function preflight_data(): ?array
    {
        return $this->preflight_checkpoint()->data();
    }

    public function detected_webhost(): string
    {
        return $this->preflight_checkpoint()->detected_webhost();
    }

    public function pull_checkpoint(): PullCheckpoint
    {
        if ($this->pull_checkpoint instanceof PullCheckpoint) {
            return $this->pull_checkpoint;
        }

        $this->pull_checkpoint = PullCheckpoint::from_array(
            $this->json_state_store->load($this->paths->pull_checkpoint_file()) ?? [],
        );

        return $this->pull_checkpoint;
    }

    public function save_pull_checkpoint(PullCheckpoint $checkpoint): void
    {
        $this->output->tick_spinner();
        $this->pull_checkpoint = $checkpoint;
        $this->json_state_store->save(
            $this->paths->pull_checkpoint_file(),
            $checkpoint->to_array(),
        );
        $this->write_status_file();
    }

    public function delete_pull_checkpoint(): void
    {
        $this->pull_checkpoint = null;
        $this->json_state_store->delete($this->paths->pull_checkpoint_file());
    }

    public function files_pull_checkpoint(): FilesPullCheckpoint
    {
        if ($this->files_pull_checkpoint instanceof FilesPullCheckpoint) {
            return $this->files_pull_checkpoint;
        }

        $data = $this->json_state_store->load($this->paths->files_pull_checkpoint_file()) ?? [];
        $this->files_pull_checkpoint = FilesPullCheckpoint::from_persisted_array(
            $data,
            [$this->state_path_codec, 'decode_value'],
        );

        return $this->files_pull_checkpoint;
    }

    public function save_files_pull_checkpoint(FilesPullCheckpoint $checkpoint): void
    {
        $this->output->tick_spinner();
        $this->files_pull_checkpoint = $checkpoint;
        $this->json_state_store->save(
            $this->paths->files_pull_checkpoint_file(),
            $checkpoint->to_persisted_array([$this->state_path_codec, 'encode_value']),
        );
        $this->write_status_file();
    }

    /**
     * Format a byte count into a human-readable string.
     */
    private function format_bytes(int $bytes): string
    {
        return ByteFormatter::format($bytes);
    }

    /**
     * Generate runtime configuration for the imported site.
     *
     * Reads the detected webhost from state (set during preflight), runs the
     * appropriate host analyzer to produce a runtime manifest, then applies
     * it using the chosen runtime applier. The manifest captures what the
     * source site needs (constants, INI directives, error handlers);
     * the applier writes the files the target server needs to fulfill those
     * requirements.
     *
     * The effective fs root is --fs-root + the remote site's document_root
     * prefix (from preflight). For example, if the remote document_root is
     * /srv/htdocs and --fs-root is ./files, the effective fs root is
     * ./files/srv/htdocs. If the site was flattened with flat-docroot,
     * pass the flattened directory as --fs-root directly and the prefix
     * is not applied.
     */
    public function run_apply_runtime(array $options): void
    {
        $preflight_data = $this->preflight_data();
        if ($preflight_data === null) {
            throw new RuntimeException(
                "apply-runtime requires a prior preflight run. " .
                "Run 'preflight' first to capture the source site's environment."
            );
        }

        $webhost = $this->detected_webhost();

        $result = (new RuntimeConfigurationApplier($this->audit_logger()))->apply(
            [
                "runtime" => $options["runtime"] ?? null,
                "output_dir" => $options["output_dir"] ?? null,
                "fs_root" => $this->fs_root,
                "flat_document_root" => $options["flat_document_root"] ?? null,
                "preflight_data" => $preflight_data,
                "webhost" => $webhost,
                "apply_state" => $this->db_apply_checkpoint()->to_array(),
                "host" => $options["host"] ?? null,
                "port" => $options["port"] ?? null,
                "enable_remote_upload_proxy" => $this->should_enable_remote_upload_proxy(),
                "state_dir" => $this->state_dir,
            ],
        );

        $checkpoint = $this->runtime_checkpoint();
        $checkpoint->remote_paths_removed_from_local_site = $result["paths_removed"];
        $this->save_runtime_checkpoint($checkpoint);

        // Output the summary and manifest as structured JSON for callers,
        // and print the human-readable summary to stderr.
        $this->output_progress([
            "status" => "complete",
            "command" => "apply-runtime",
            "runtime" => $result["runtime"],
            "webhost" => $result["webhost"],
            "webhost_source" => $result["webhost_source"],
            "target_engine" => $result["target_engine"],
            "paths_removed" => $result["paths_removed"],
            "extra_directories" => $result["extra_directories"],
            "start_config" => $result["start_config"],
            "message" => "apply-runtime complete (runtime: " . $result["runtime"] . ")",
        ]);

        if (!$this->output->is_quiet_lifecycle()) {
            $summary = "\n";
            $summary .= "Runtime: " . $result["runtime"] . "\n";
            $summary .= "Source host: " . $result["webhost"] . "\n";
            if ($result["target_engine"] !== null) {
                $summary .= "Target database: " . $result["target_engine"] . "\n";
            }
            $summary .= "\n";
            foreach ($result["summary"] as $line) {
                $summary .= "{$line}\n";
            }
            $this->output->write_error($summary);
        }
    }

    /**
     * Decide whether runtime should proxy missing uploads from the source.
     *
     * Once files-pull is fully complete and no skipped uploads remain, the
     * proxy is disabled so requests are served only from local files.
     */
    private function should_enable_remote_upload_proxy(): bool
    {
        if ($this->has_skipped_files_pending()) {
            return true;
        }

        $state = $this->run_state();
        if ($state->command !== "files-pull") {
            return false;
        }

        $status = $state->status;
        return $status !== null && $status !== "complete";
    }

    /**
     * Command: flat-docroot
     *
     * Creates a directory at the specified --flatten-to path that mirrors
     * a vanilla WordPress installation layout by symlinking entries from
     * the import fs root. Uses preflight data (paths_urls) to determine
     * where each WordPress component actually lives, rather than blindly
     * scanning fs root top-level entries.
     *
     * This is essential when the source site uses a non-standard layout
     * (e.g. WP Cloud with ABSPATH=/srv/htdocs and WP_CONTENT_DIR=/tmp/__wp__/wp-content)
     * and the target needs a conventional wp-admin/, wp-includes/,
     * wp-content/, wp-load.php structure.
     *
     * The command is idempotent: re-running refreshes all symlinks.
     * If a path that should be a symlink is a regular file/directory,
     * the command stops with an error unless --force is specified.
     */
    public function run_flat_document_root(array $options): void
    {
        $flatten_to = $options["flatten_to"] ?? null;
        if (empty($flatten_to)) {
            throw new InvalidArgumentException(
                "flat-docroot requires --flatten-to=PATH",
            );
        }

        $flatten_to = rtrim($flatten_to, "/");
        $force = (bool) ($options["force"] ?? false);

        // Require preflight data so we know where WP components live
        $this->require_preflight();
        $preflight = $this->preflight_data() ?? [];

        $result = FlatDocumentRootBuilder::build(
            $this->fs_root,
            $flatten_to,
            $preflight,
            $force,
            function (string $message, bool $to_console = true): void {
                $this->audit_log($message, $to_console);
            },
        );

        if (!$this->output->is_quiet_lifecycle()) {
            $this->output->write(json_encode($result) . "\n");
        }
        $this->output_progress(array_merge(["type" => "flat_docroot_complete"], $result));
    }

    public function run_db_apply(array $options): void
    {
        $source = new DbApplySourceContext(
            $this->preflight_checkpoint()->require_data(
                "db-apply requires a prior preflight run. Run 'preflight' first.",
            ),
            $this->detected_webhost(),
        );

        $checkpoint = (new DbApplyWorkflow(
            $this->state_dir,
            $this->remote_url,
            $this->local_filesystem(),
            new ImportClientDbApplyCheckpointStore($this),
            $this->audit_logger(),
            new ImportOutputDbApplyObserver(
                $this->output,
                new ImportOutputMachineEventEmitter($this->output),
            ),
            new ImportClientDbApplyShutdownToken($this),
            new JsonSqlStatementStatsStore($this->state_dir . "/.import-sql-stats.json"),
        ))->run(
            $this->db_apply_checkpoint(),
            $source,
            $options,
        );
        $this->record_command_status("db-apply", $checkpoint->status);
    }

    /**
     * Command: db-index
     *
     * Streams table metadata (name/rows/size) for planning and diagnostics.
     */
    public function run_db_index(): void
    {
        $tables_file = $this->state_dir . "/db-tables.jsonl";
        $checkpoint = $this->load_db_pull_checkpoint();
        $state = $this->run_state();

        $has_cursor =
            $state->command === "db-index" &&
            $checkpoint->cursor !== null;
        $current_status = $state->command === "db-index"
            ? $checkpoint->status
            : null;
        $tables_exists = file_exists($tables_file);

        if ($current_status === "complete") {
            if ($tables_exists) {
                throw new RuntimeException(
                    "db-index already completed and db-tables.jsonl exists. Use --abort flag to start over.",
                );
            } else {
                throw new RuntimeException(
                    "db-index marked complete but db-tables.jsonl is missing. Use --abort flag to re-run.",
                );
            }
        }

        if (!$has_cursor) {
            $checkpoint = DbPullCheckpoint::fresh();
            $checkpoint->status = "in_progress";
            $checkpoint->stage = "db-index";
            $this->save_db_pull_checkpoint($checkpoint);
            $this->record_command_status("db-index", "in_progress");

            $this->audit_log("START db-index", true);
            $this->output->show_lifecycle_line("Starting db-index\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "starting",
                "command" => "db-index",
                "message" => "Starting db-index",
            ], true);
        } else {
            $this->audit_log(
                sprintf(
                    "RESUME db-index | cursor=%s",
                    substr((string) $checkpoint->cursor, 0, 20) . "...",
                ),
                true,
            );
            $this->output->show_lifecycle_line("Resuming db-index\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "resuming",
                "command" => "db-index",
                "message" => "Resuming db-index",
            ], true);
        }

        $this->record_command_status("db-index", $checkpoint->status);

        $checkpoint = $this->db_pull_workflow()->download_db_index($checkpoint, $tables_file);
        if ($checkpoint->status === "partial") {
            $this->record_command_status("db-index", "partial");
            return;
        }

        $checkpoint->status = "complete";
        $this->save_db_pull_checkpoint($checkpoint);
        $this->record_command_status("db-index", "complete");

        $tables = $checkpoint->db_index->tables;
        $this->audit_log(
            sprintf("db-index complete: %d tables", $tables),
            true,
        );

        $this->output->show_lifecycle_line("db-index complete: {$tables} tables\n");
        $this->output->show_lifecycle_line("Table stats: {$tables_file}\n");
        $this->output->show_lifecycle_line("Audit log: {$this->audit_log}\n");
        $this->output_progress([
            "type" => "lifecycle",
            "event" => "complete",
            "command" => "db-index",
            "tables" => $tables,
            "tables_file" => $tables_file,
            "audit_log" => $this->audit_log,
            "message" => "db-index complete: {$tables} tables",
        ], true);
    }

    /**
     * Download file content for a prepared file list (file_fetch).
     *
     * @param array|null $post_data Optional POST data
     * @param string|null $cursor Cursor for resumption within the current batch
     */
    public function download_file_fetch(
        FilesPullCheckpoint $checkpoint,
        ?array $post_data,
        ?string $cursor,
        string $state_key = "fetch"
    ): bool {
        $local_applier = $this->file_sync_local_applier($checkpoint);
        $workspace = new ImportClientFileSyncWorkspace($this);

        $downloader = new FileFetchDownloader(
            new ImportClientFileSyncStreamClient($this),
            new ImportClientShutdownToken($this),
            new ImportClientFilesPullCheckpointStore($this),
            $local_applier,
            new ImportClientFilesPullTimeoutPolicy($this),
            new ImportClientFileIndexGateway($this, $workspace),
            new FileAuditLogger($this->audit_log, $this->output),
        );

        try {
            return $downloader->download(
                $checkpoint,
                [
                    "post_data" => $post_data,
                    "cursor" => $cursor,
                    "state_key" => $state_key,
                    "export_dirs" => $this->get_export_directories(),
                    "save_every" => self::SAVE_STATE_EVERY_N_CHUNKS,
                ],
            );
        } finally {
            $this->files_imported = $local_applier->files_imported();
        }
    }

    /**
     * Download the remote index stream and write to disk.
     */
    public function download_remote_index(
        FilesPullCheckpoint $checkpoint,
        ?string $list_dir_override = null
    ): bool
    {
        $local_applier = $this->file_sync_local_applier($checkpoint);
        $workspace = new ImportClientFileSyncWorkspace($this);

        return (new RemoteIndexDownloader(
            new ImportClientFileSyncStreamClient($this),
            new ImportClientShutdownToken($this),
            new ImportClientFilesPullCheckpointStore($this),
            $local_applier,
            new ImportClientFilesPullTimeoutPolicy($this),
            $workspace,
            new FileAuditLogger($this->audit_log, $this->output),
        ))->download(
            $checkpoint,
            [
                "remote_index_file" => $this->remote_index_file,
                "roots" => $this->get_root_directories_from_preflight(),
                "export_dirs" => $this->get_export_directories(),
                "list_dir_override" => $list_dir_override,
                "follow_symlinks" => $this->follow_symlinks,
                "include_caches" => $this->include_caches,
                "save_every" => self::SAVE_STATE_EVERY_N_CHUNKS,
            ],
            $this->index_entries_counted,
        );
    }

    /**
     * Diff local index against remote index and build download list.
     */
    public function diff_indexes_and_build_fetch_list(FilesPullCheckpoint $checkpoint): bool
    {
        $local_applier = $this->file_sync_local_applier($checkpoint);

        $builder = new FetchListBuilder(
            $this->index_store,
            $local_applier,
            new ImportClientFilesPullCheckpointStore($this),
            new ImportClientShutdownToken($this),
            new ImportOutputProgressTicker($this->output),
            new FileAuditLogger($this->audit_log, $this->output),
        );

        return $builder->build(
            $checkpoint,
            $this->remote_index_file,
            $this->index_file,
            $this->download_list_file,
            $this->skipped_download_list_file,
            $this->filter,
            $this->filter === "essential-files" ? $this->get_uploads_basedir() : null,
        );
    }

    /**
     * Count newlines in a file using buffered reads.  Much faster than
     * fgets() on large JSONL files because it never allocates per-line
     * strings - just scans raw bytes in 64 KB chunks.
     *
     * @param string $file       Path to the file.
     * @param int    $up_to_byte Stop after this byte offset (-1 = entire file).
     */
    public function count_newlines(string $file, int $up_to_byte = -1): int
    {
        return DownloadList::count_lines($file, $up_to_byte);
    }

    /**
     * Download files from a prepared list.
     *
     * @param string $list_file Path to the JSONL download list to process.
     * @param string $state_key FilesPullCheckpoint fetch slot to update
     *                          (e.g. "fetch" or "fetch_skipped").
     */
    public function download_files_from_list(
        FilesPullCheckpoint $checkpoint,
        string $list_file,
        string $state_key
    ): bool {
        $executor = new FetchListExecutor(
            $this->download_list_total,
            $this->download_list_done,
            $this->files_imported,
            $this->get_max_request_bytes(),
            new ImportClientFetchBatchDownloader($this, $checkpoint),
            new ImportClientFilesPullCheckpointStore($this),
            new FileAuditLogger($this->audit_log, $this->output),
        );

        try {
            return $executor->run(
                $list_file,
                $state_key,
                $checkpoint,
            );
        } finally {
            $this->download_list_total = $executor->download_list_total();
            $this->download_list_done = $executor->download_list_done();
            $this->files_imported = $executor->files_imported();
        }
    }

    /**
     * Determine maximum request size for file_fetch uploads.
     */
    private function get_max_request_bytes(): int
    {
        $data = $this->preflight_data() ?? [];
        $preflight = $data["limits"] ?? null;
        $max_request = null;
        if (is_array($preflight) && isset($preflight["max_request_bytes"])) {
            $max_request = (int) $preflight["max_request_bytes"];
        }

        if ($max_request === null || $max_request <= 0) {
            return 4 * 1024 * 1024;
        }

        return $max_request;
    }

    /**
     * Return the uploads basedir from preflight data (e.g. "/wp-content/uploads").
     *
     * Falls back to a heuristic pattern match if the preflight doesn't contain
     * explicit uploads path information.
     */
    private function get_uploads_basedir(): ?string
    {
        $data = $this->preflight_data() ?? [];
        $paths_urls = $data["database"]["wp"]["paths_urls"] ?? null;
        if (!is_array($paths_urls)) {
            return null;
        }
        $basedir = $paths_urls["uploads"]["basedir"] ?? null;
        if (!is_string($basedir) || $basedir === "") {
            return null;
        }
        return rtrim($basedir, "/") . "/";
    }

    private function local_filesystem(): LocalImportFilesystem
    {
        if ($this->local_filesystem instanceof LocalImportFilesystem) {
            return $this->local_filesystem;
        }

        $this->local_filesystem = new LocalImportFilesystem(
            $this->fs_root,
            $this->fs_root_nonempty_behavior,
            $this->audit_logger(),
        );

        return $this->local_filesystem;
    }

    public function recreate_intermediate_symlinks(): void
    {
        (new IntermediateSymlinkRecreator(
            $this->local_filesystem(),
            $this->audit_logger(),
        ))->recreate($this->remote_index_file);
    }

    /**
     * Parse one JSON index line into an array.
     */
    public function parse_index_line(string $line): ?array
    {
        return IndexLineParser::parse($line);
    }

    /**
     * Merge the collected updates with the existing sorted index without loading it into memory.
     */
    public function finalize_index_updates(): void
    {
        $this->index_store->finalize_updates();
    }

    /**
     * Download SQL from remote.
     */
    private function download_sql(DbPullCheckpoint $checkpoint): DbPullCheckpoint
    {
        $audit = $this->audit_logger();

        $checkpoint = (new SqlDownloader(
            new ImportClientSqlStreamClient($this),
            new ImportClientSqlShutdownToken($this),
            new ImportClientDbPullCheckpointStore($this),
            new ImportClientDbPullTimeoutPolicy($this),
            new LocalSqlOutputSinkFactory($audit),
            new JsonSqlDomainStore($this->paths->domains_file()),
            new JsonSqlStatementStatsStore($this->state_dir . "/.import-sql-stats.json"),
            new ImportOutputSqlStreamObserver(
                $this->output,
                new ImportOutputMachineEventEmitter($this->output),
                $checkpoint,
            ),
            $audit,
        ))->download(
            $checkpoint,
            [
                "mode" => $this->sql_output_mode,
                "state_dir" => $this->state_dir,
                "remote_url" => $this->remote_url,
                "mysql_host" => $this->mysql_host,
                "mysql_port" => $this->mysql_port,
                "mysql_user" => $this->mysql_user,
                "mysql_password" => $this->mysql_password,
                "mysql_database" => $this->mysql_database,
                "save_every" => self::SAVE_STATE_EVERY_N_CHUNKS,
            ],
        );

        return $checkpoint;
    }

    /**
     * Build request URL with endpoint and cursor.
     */
    public function build_url(
        string $endpoint,
        ?string $cursor,
        array $params = []
    ): string {
        return $this->http_session()->build_url($endpoint, $cursor, $params);
    }

    public function stream_export_endpoint(
        string $endpoint,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data = null,
        array $params = []
    ): void {
        $this->fetch_streaming(
            $this->build_url($endpoint, $cursor, $params),
            $cursor,
            $context,
            $post_data,
            $endpoint,
        );
    }

    public function shutdown_requested(): bool
    {
        return $this->shutdown_requested;
    }

    public function assert_can_retry_db_pull_timeout(
        DbPullCheckpoint $checkpoint,
        string $phase,
        ?string $cursor_before,
        ?string $cursor_after
    ): void {
        if ($cursor_after !== null && $cursor_after !== $cursor_before) {
            $checkpoint->consecutive_timeouts = 0;
        } else {
            $checkpoint->consecutive_timeouts++;
        }

        $count = $checkpoint->consecutive_timeouts;
        $this->audit_log(
            "CURL TIMEOUT | {$phase} | consecutive_timeouts={$count}/" .
                self::MAX_CONSECUTIVE_TIMEOUTS .
                " | cursor_moved=" .
                ($cursor_after !== $cursor_before ? "yes" : "no"),
            true,
        );

        if ($count >= self::MAX_CONSECUTIVE_TIMEOUTS) {
            throw new RuntimeException(
                "Remote server appears unreachable: {$count} consecutive " .
                "cURL timeouts with no progress during {$phase}. Giving up.",
            );
        }
    }

    public function assert_can_retry_files_pull_timeout(
        FilesPullCheckpoint $checkpoint,
        string $phase,
        ?string $cursor_before,
        ?string $cursor_after
    ): void {
        if ($cursor_after !== null && $cursor_after !== $cursor_before) {
            $checkpoint->consecutive_timeouts = 0;
        } else {
            $checkpoint->consecutive_timeouts++;
        }

        $count = $checkpoint->consecutive_timeouts;
        $this->audit_log(
            "CURL TIMEOUT | {$phase} | consecutive_timeouts={$count}/" .
                self::MAX_CONSECUTIVE_TIMEOUTS .
                " | cursor_moved=" .
                ($cursor_after !== $cursor_before ? "yes" : "no"),
            true,
        );

        if ($count >= self::MAX_CONSECUTIVE_TIMEOUTS) {
            throw new RuntimeException(
                "Remote server appears unreachable: {$count} consecutive " .
                "cURL timeouts with no progress during {$phase}. Giving up.",
            );
        }
    }

    public function finalize_stream_request(
        string $endpoint,
        float $wall_time,
        array $response_stats
    ): void {
        $this->finalize_tuned_request($endpoint, $wall_time, $response_stats);
    }

    public function download_sql_stage(DbPullCheckpoint $checkpoint): DbPullCheckpoint
    {
        return $this->download_sql($checkpoint);
    }

    /**
     * Extract root directories from preflight wp_detect data.
     * Falls back to this when the URL doesn't contain directory[] params.
     */
    private function get_root_directories_from_preflight(): array
    {
        return ExportDirectoryResolver::root_directories_from_preflight(
            $this->preflight_data() ?? [],
            function (string $message): void {
                $this->audit_log($message);
            },
        );
    }

    /**
     * Build the list of directories the server should traverse.
     *
     * Starts from the wp_detect roots (ABSPATH, etc.) and adds
     * WP_CONTENT_DIR and document_root when they live outside those
     * roots. On managed hosts like wp.com Atomic, these are on
     * separate paths (e.g. /srv/htdocs/wp-content and /srv/htdocs
     * vs /wordpress/core/6.9.4) so the server won't discover them
     * by traversing ABSPATH alone.
     */
    private function get_export_directories(): array
    {
        return ExportDirectoryResolver::export_directories(
            $this->preflight_data() ?? [],
            $this->extra_directory,
            function (string $message): void {
                $this->audit_log($message);
            },
        );
    }

    /**
     * Sorts an index file by path and removes duplicate entries.
     */
    public function sort_index_file(string $path): void
    {
        $this->index_sorter->sort($path);
    }

    /**
     * User-Agent strings to try during preflight, in order of preference.
     * Some WAFs block browser UAs that carry custom auth headers, so we
     * start with an honest non-browser identity and fall back to common
     * browser strings.
     */
    public const USER_AGENTS = ImportHttpSession::USER_AGENTS;

    /**
     * Fetch a JSON response for a lightweight request (non-streaming).
     */
    public function fetch_json(string $url): array
    {
        $result = $this->http_session()->fetch_json($url);
        $this->sync_http_session_error_code();
        return $result;
    }

    /**
     * Fetch URL with streaming multipart parsing.
     */
    public function fetch_export_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data = null,
        ?string $endpoint = null
    ): void {
        $this->fetch_streaming($url, $cursor, $context, $post_data, $endpoint);
    }

    protected function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data = null,
        ?string $endpoint = null
    ): void {
        try {
            $this->http_session()->fetch_streaming(
                $url,
                $cursor,
                $context,
                $post_data,
                $endpoint,
            );
        } finally {
            $this->sync_http_session_error_code();
        }
    }

    private function sync_http_session_error_code(): void
    {
        if (
            $this->http_session instanceof ImportHttpSession &&
            $this->http_session->last_error_code() !== null
        ) {
            $this->last_error_code = $this->http_session->last_error_code();
        }
    }

    private function default_state(): ImportRunState
    {
        return ImportRunState::fresh();
    }

    /**
     * Load import state from disk.
     */
    private function load_state(): ImportRunState
    {
        try {
            $state = $this->json_state_store->load($this->state_file);
        } catch (RuntimeException $e) {
            $this->audit_log($e->getMessage(), true);
            return $this->default_state();
        }
        if ($state === null) {
            return $this->default_state();
        }

        $this->migrate_preflight_checkpoint_from_state($state);

        $run_state = ImportRunState::from_array($state);

        $state_cmd = $run_state->command;
        if (is_string($state_cmd)) {
            $run_state->command = ImportCommands::normalize_name($state_cmd);
        }

        return $run_state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function migrate_preflight_checkpoint_from_state(array $state): void
    {
        $legacy_keys = [
            "preflight",
            "remote_protocol_version",
            "remote_protocol_min_version",
            "version",
            "webhost",
        ];
        $has_legacy_preflight_state = false;
        foreach ($legacy_keys as $key) {
            if (array_key_exists($key, $state)) {
                $has_legacy_preflight_state = true;
                break;
            }
        }
        if (!$has_legacy_preflight_state) {
            return;
        }

        $existing = $this->json_state_store->load($this->paths->preflight_checkpoint_file()) ?? [];
        if ($existing !== []) {
            return;
        }

        $checkpoint = PreflightCheckpoint::from_persisted_array(
            $state,
            [$this->state_path_codec, 'decode_preflight_data_paths'],
        );
        if (
            $checkpoint->entry === null &&
            $checkpoint->remote_protocol_version === null &&
            $checkpoint->remote_protocol_min_version === null &&
            $checkpoint->exporter_version === null &&
            $checkpoint->webhost === null
        ) {
            return;
        }

        $this->save_preflight_checkpoint($checkpoint);
    }

    /**
     * Save import state to disk.
     *
     * Uses atomic write (temp file + rename) to prevent corruption if
     * the process is killed mid-write.
     */
    private function save_state(ImportRunState $state): void
    {
        // Keep the spinner alive between curl requests. save_state is
        // called frequently during streaming operations, so this fills
        // the gaps where curl's progress callback doesn't fire.
        $this->output->tick_spinner();

        if (
            $this->http_session instanceof ImportHttpSession &&
            $this->http_session->has_tuner()
        ) {
            $state->set_tuning(
                $this->http_session->tuning_config(),
                $this->http_session->tuning_state(),
            );
        }
        $this->state = $state;
        $data = $state->to_array();

        $this->json_state_store->save($this->state_file, $data);

        $indexed = $this->index_count();
        $files_imported = $this->files_imported; // Completed in this run
        $files_checkpoint = in_array($state->command, ["files-pull", "files-index"], true)
            ? $this->files_pull_checkpoint()
            : null;
        $has_cursor = $files_checkpoint !== null && (
            !empty($files_checkpoint->index_cursor) ||
            !empty($files_checkpoint->fetch->cursor) ||
            !empty($files_checkpoint->fetch_skipped->cursor)
        );
        $cursor_info = $has_cursor ? "cursor=saved" : "cursor=none";

        $this->audit_log(
            sprintf(
                "SAVE CURSOR | total_indexed=%d | completed_this_run=%d | %s",
                $indexed,
                $files_imported,
                $cursor_info,
            ),
            false,
        );

        $this->write_status_file();
    }

    /**
     * Write a flat status file for external consumers (e.g. web UI polling).
     *
     * Derives a simple JSON object from the current state and pipeline
     * position. Written atomically via temp file + rename so readers
     * never see a partial write.
     */
    public function write_status_file(?string $error = null): void
    {
        $command = $this->state?->command;
        $files_checkpoint = in_array($command, ["files-pull", "files-index"], true)
            ? $this->files_pull_checkpoint()
            : null;
        $db_pull_checkpoint = in_array($command, ["db-pull", "db-index"], true)
            ? $this->load_db_pull_checkpoint()
            : null;
        $status = $error !== null
            ? "error"
            : (
                $files_checkpoint->status ??
                $db_pull_checkpoint->status ??
                $this->state?->status ??
                "in_progress"
            );

        $phase = $files_checkpoint->stage ?? $db_pull_checkpoint->stage ?? null;

        $payload = [
            "step" => $this->pipeline_step,
            "steps" => $this->pipeline_steps,
            "command" => $command,
            "status" => $status,
            "phase" => $phase,
            "error" => $error,
            "error_code" => $error !== null ? $this->last_error_code : null,
            "ts" => microtime(true),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT);
        if ($json === false) {
            return; // Best-effort — don't crash the import over a status file
        }
        $tmp = $this->status_file . ".tmp";
        if (file_put_contents($tmp, $json) !== false) {
            rename($tmp, $this->status_file);
        }
    }

    /**
     * Handle shutdown signals (SIGINT, SIGTERM).
     * Saves state before exiting.
     */
    public function handle_shutdown(int $signal): void
    {
        // Prevent multiple signal handling
        static $already_shutting_down = false;
        if ($already_shutting_down) {
            // Force kill on second signal
            if (
                function_exists("posix_kill") &&
                function_exists("posix_getpid")
            ) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            die("\nForced exit.\n");
        }
        $already_shutting_down = true;

        $this->shutdown_requested = true;
        $this->output->clear_progress_line();

        // Flush index updates so progress is not lost on interrupt
        try {
            $this->finalize_index_updates();
        } catch (Exception $e) {
            $this->audit_log(
                "Failed to finalize index updates on shutdown: " .
                    $e->getMessage(),
                true,
            );
        }

        // Log final progress before exit
        $indexed = $this->index_count();
        $files_imported = $this->files_imported; // Files completed in this run
        $current_command = $this->state?->command ?? "unknown";

        $this->audit_log(
            sprintf(
                "SHUTDOWN REQUESTED | command=%s | total_indexed=%d files | completed_this_run=%d files",
                $current_command,
                $indexed,
                $files_imported,
            ),
            true,
        );

        $this->output->show_lifecycle_line("\nInterrupted - saving state...\n");
        $this->output->show_lifecycle_line("  Command: {$current_command}\n");
        $this->output->show_lifecycle_line("  Total files indexed: {$indexed}\n");
        $this->output->show_lifecycle_line("  Files completed in this run: {$files_imported}\n");
        $this->output_progress([
            "type" => "interrupt",
            "command" => $current_command,
            "files_indexed" => $indexed,
            "files_completed" => $files_imported,
            "message" => "Interrupted - saving state...",
        ], true);

        // Save current state (with timeout protection)
        try {
            if ($this->state instanceof ImportRunState) {
                $this->save_state($this->state);
            }
            $this->output->show_lifecycle_line("✓ State saved successfully\n");
            $this->output_progress([
                "type" => "state_saved",
                "message" => "State saved successfully",
            ], true);
        } catch (Exception $e) {
            $this->output->write("Warning: Failed to save state: " . $e->getMessage() . "\n");
        }

        $this->output->show_lifecycle_line("Exiting...\n");

        // CRITICAL: Use SIGKILL for immediate termination
        // Regular exit() hangs because PHP's shutdown sequence tries to
        // close the curl handle gracefully, which blocks waiting for server.
        // curl_close() also hangs when called during an active curl_exec().
        // SIGKILL bypasses all cleanup and terminates at OS level immediately.
        if (function_exists("posix_kill") && function_exists("posix_getpid")) {
            posix_kill(posix_getpid(), SIGKILL);
        }

        // Fallback if posix functions not available
        die();
    }

    /**
     * Output progress as JSON line.
     * Only outputs in verbose mode or non-TTY mode (for programmatic consumption).
     *
     * @param array $data Progress data to output
     * @param bool $force Force output regardless of throttle
     */
    public function output_progress(array $data, bool $force = false): void
    {
        if (!$this->output->emit_event($data, $force)) {
            // Broken pipe — save state and exit cleanly.
            if ($this->state instanceof ImportRunState) {
                $this->save_state($this->state);
            }
            exit(0);
        }
    }
}
