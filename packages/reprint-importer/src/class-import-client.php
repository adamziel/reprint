<?php

namespace Reprint\Importer;

use CURLFile;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use Reprint\Importer\Command\ImportCommands;
use Reprint\Importer\Command\ImportCommandResult;
use Reprint\Importer\Command\PreflightCommand;
use Reprint\Importer\FileSync\DownloadList;
use Reprint\Importer\FileSync\FetchCheckpoint;
use Reprint\Importer\FileSync\FetchListBuilder;
use Reprint\Importer\FileSync\FetchListExecutor;
use Reprint\Importer\FileSync\FileFetchDownloader;
use Reprint\Importer\FileSync\FileSyncLocalApplier;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\IntermediateSymlinkRecreator;
use Reprint\Importer\FileSync\RemoteIndexDownloader;
use Reprint\Importer\FileSync\RuntimeFilesDownloader;
use Reprint\Importer\FileSync\SymlinkTargetIndexer;
use Reprint\Importer\Filesystem\FlatDocumentRootBuilder;
use Reprint\Importer\Filesystem\LocalImportFilesystem;
use Reprint\Importer\Index\IndexFileSorter;
use Reprint\Importer\Index\IndexLineParser;
use Reprint\Importer\Index\IndexStore;
use Reprint\Importer\Input\ImportRunRequest;
use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\Output\NullImportOutput;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Pull\Pull;
use Reprint\Importer\Pull\PullCheckpoint;
use Reprint\Importer\Session\ExportDirectoryResolver;
use Reprint\Importer\Session\ImportAbortHandler;
use Reprint\Importer\Session\ImportPaths;
use Reprint\Importer\Session\ImportStateSchema;
use Reprint\Importer\Session\JsonStateStore;
use Reprint\Importer\Session\StatePathCodec;
use Reprint\Importer\Session\VolatileFileTracker;
use Reprint\Importer\Sql\DbApplyCheckpoint;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\DbApplyWorkflow;
use Reprint\Importer\Sql\DbPullWorkflow;
use Reprint\Importer\Sql\SqlDownloader;
use Reprint\Importer\Support\ByteFormatter;
use Reprint\Importer\TargetRuntime\RuntimeConfigurationApplier;
use Reprint\Importer\Transport\ImportHttpTransport;
use Reprint\Importer\Transport\HttpRequestBuilder;
use Reprint\Importer\Tuning\AdaptiveTuner;

class ImportClient
{

    private const SAVE_STATE_EVERY_N_CHUNKS = 50;

    /**
     * Maximum number of consecutive cURL timeouts with no cursor progress
     * before the importer gives up. This prevents infinite retry loops
     * when the remote server is genuinely unresponsive.
     */
    private const MAX_CONSECUTIVE_TIMEOUTS = 3;

    /** @var string Export server URL. */
    public $remote_url;

    /** @var string Directory for import state files (.reprint/run.json, db.sql, etc.). */
    public $state_dir;

    /** @var string Directory where downloaded site files are written (no filesystem-root/ wrapper). */
    public $fs_root;

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
     * @var array Session-level run state loaded from / saved to $state_file.
     * Workflow-specific progress belongs in per-workflow checkpoint files.
     * @var array|null
     */
    public $state;

    private ?PullCheckpoint $pull_checkpoint = null;
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

    /** @var AdaptiveTuner|null Adjusts request pacing based on server response times and errors. */
    private $tuner = null;

    /** @var \Reprint\Exporter\Site_Export_HMAC_Client|null Signs requests when HMAC auth is configured. */
    private $hmac_client = null;

    /**
     * @var int|null MySQL max_allowed_packet value for the import database connection.
     * Passed to the server so it can split SQL statements to fit within this limit.
     */
    private $max_allowed_packet = null;

    /** @var string|null Machine-readable error code from the last HTTP diagnosis. */
    public $last_error_code = null;

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

    /** @var ImportHttpTransport|null HTTP and streaming multipart transport. */
    private ?ImportHttpTransport $http_transport = null;

    /** @var LocalImportFilesystem|null Filesystem operations scoped to the import root. */
    private ?LocalImportFilesystem $local_filesystem = null;

    /** @var Pull Orchestrates the pull command pipeline. */
    private Pull $pull;

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
    public $exit_code = 0;

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

    private function http_transport(): ImportHttpTransport
    {
        if ($this->http_transport instanceof ImportHttpTransport) {
            return $this->http_transport;
        }

        $this->http_transport = new ImportHttpTransport($this->output);

        return $this->http_transport;
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
    private function recover_index_updates(): void
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

    /**
     * Apply a mutation to the state and persist it. Used by orchestrator
     * commands (Pull) that need to update multiple fields atomically.
     */
    public function mutate_state(callable $mutator): void
    {
        $this->state = $mutator($this->state);
        $this->save_state($this->state);
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
            function (string $message, bool $to_console = true): void {
                $this->audit_log($message, $to_console);
            },
            function (array $progress, bool $force = false): void {
                $this->output_progress($progress, $force);
            },
        );
    }

    /**
     * Report volatile files to the user at sync completion.
     */
    private function report_volatile_files(): void
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

            $this->initialize_tuner($options);
            $this->initialize_hmac_client($request);

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
            $this->state["follow_symlinks"] = $this->follow_symlinks;
            return;
        }

        if (isset($this->state["follow_symlinks"])) {
            $this->follow_symlinks = $this->state["follow_symlinks"];
        }
    }

    private function apply_fs_root_behavior_option(ImportRunRequest $request): void
    {
        if ($request->has("fs_root_nonempty_behavior")) {
            $this->fs_root_nonempty_behavior = $request->value("fs_root_nonempty_behavior");
            $this->state["fs_root_nonempty_behavior"] = $this->fs_root_nonempty_behavior;
        } else {
            $this->fs_root_nonempty_behavior = $this->state["fs_root_nonempty_behavior"] ?? 'error';
        }

        $this->local_filesystem = null;
    }

    private function apply_filter_option(ImportRunRequest $request): void
    {
        if (!$request->has("filter")) {
            if (isset($this->state["filter"])) {
                $this->filter = $this->state["filter"];
            }
            return;
        }

        $next = $request->value("filter");
        $prev = $this->state["filter"] ?? null;
        $status = $this->state["status"] ?? null;
        $is_mid_flight = $prev !== null && $prev !== $next && $status !== null && $status !== "complete";
        if ($is_mid_flight) {
            throw new RuntimeException(
                "Cannot change --filter from '{$prev}' to '{$next}' while a sync is in progress. " .
                    "Finish the current sync or use --abort to start over.",
            );
        }

        $this->filter = $next;
        $this->state["filter"] = $this->filter;
    }

    private function apply_max_allowed_packet_option(ImportRunRequest $request): void
    {
        if ($request->has("max_allowed_packet")) {
            $this->max_allowed_packet = (int) $request->value("max_allowed_packet");
            $this->state["max_allowed_packet"] = $this->max_allowed_packet;
            return;
        }

        if (isset($this->state["max_allowed_packet"])) {
            $this->max_allowed_packet = (int) $this->state["max_allowed_packet"];
        }
    }

    private function apply_sql_output_option(ImportRunRequest $request): void
    {
        if ($request->has("sql_output")) {
            $this->sql_output_mode = $request->value("sql_output");
            $this->state["sql_output"] = $this->sql_output_mode;
        } elseif (isset($this->state["sql_output"])) {
            $this->sql_output_mode = $this->state["sql_output"];
        }

        if ($this->sql_output_mode === "stdout") {
            $this->output->use_error_stream();
        }
    }

    private function apply_mysql_connection_options(ImportRunRequest $request): void
    {
        if ($request->has("mysql_host")) {
            $this->mysql_host = $request->value("mysql_host");
            $this->state["mysql_host"] = $this->mysql_host;
        } elseif (isset($this->state["mysql_host"])) {
            $this->mysql_host = $this->state["mysql_host"];
        }

        if ($request->has("mysql_port")) {
            $this->mysql_port = (int) $request->value("mysql_port");
            $this->state["mysql_port"] = $this->mysql_port;
        } elseif (isset($this->state["mysql_port"])) {
            $this->mysql_port = (int) $this->state["mysql_port"];
        }

        if ($request->has("mysql_user")) {
            $this->mysql_user = $request->value("mysql_user");
            $this->state["mysql_user"] = $this->mysql_user;
        } elseif (isset($this->state["mysql_user"])) {
            $this->mysql_user = $this->state["mysql_user"];
        }

        if ($request->has("mysql_database")) {
            $this->mysql_database = $request->value("mysql_database");
            $this->state["mysql_database"] = $this->mysql_database;
        } elseif (isset($this->state["mysql_database"])) {
            $this->mysql_database = $this->state["mysql_database"];
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

    private function initialize_hmac_client(ImportRunRequest $request): void
    {
        $secret = $request->value("secret");
        if (empty($secret)) {
            return;
        }

        if (!class_exists(\Reprint\Exporter\Site_Export_HMAC_Client::class)) {
            throw new RuntimeException(
                'Streaming exporter runtime not found. Run composer install before using --secret.'
            );
        }

        $this->hmac_client = new \Reprint\Exporter\Site_Export_HMAC_Client($secret);
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
        $final_status = $this->state["status"] ?? "complete";
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
            function (string $message, bool $to_console = true): void {
                $this->audit_log($message, $to_console);
            },
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
     * Initialize adaptive tuning from CLI options and persisted state.
     */
    private function initialize_tuner(array $options): void
    {
        $config = $this->state["tuning"]["config"] ?? [];
        $state = $this->state["tuning"]["state"] ?? [];
        $cli_config = $options["tuning_config"] ?? [];

        $config = array_merge($config, $cli_config);

        $this->tuner = new AdaptiveTuner($config, $state);
        $this->state["tuning"] = [
            "config" => $this->tuner->get_config(),
            "state" => $this->tuner->get_state(),
        ];

        $this->audit_log(
            "TUNER CONFIG | " . json_encode($this->state["tuning"]["config"]),
            false,
        );
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
            function (string $endpoint, ?string $cursor, array $params): string {
                return $this->build_url($endpoint, $cursor, $params);
            },
            function (
                string $url,
                ?string $cursor,
                StreamingContext $context,
                ?array $post_data,
                string $phase
            ): void {
                $this->fetch_streaming($url, $cursor, $context, $post_data, $phase);
            },
            function (string $message): void {
                $this->audit_log($message);
            },
        ))->download(
            $this->state["preflight"]["data"] ?? [],
            $this->paths->runtime_files_dir(),
        );
    }

    /**
     * Assert that a preflight has already been run and stored in state.
     * All commands except preflight/preflight-assert call this before starting work.
     */
    public function require_preflight(): void
    {
        $entry = $this->state["preflight"] ?? null;
        if (!is_array($entry) || empty($entry["data"])) {
            throw new RuntimeException(
                "No preflight data found. Run 'preflight' or 'preflight-assert' first.",
            );
        }
    }

    /**
     * Build request params for an endpoint using the adaptive tuner.
     */
    private function get_tuned_params(string $endpoint): array
    {
        if (!$this->tuner instanceof AdaptiveTuner) {
            return [];
        }
        $params = $this->tuner->get_request_params($endpoint);
        // Tell the server about the client's max_allowed_packet so it can
        // cap SQL statements to a size the client can actually import.
        if ($endpoint === "sql_chunk" && $this->max_allowed_packet !== null) {
            $params["max_allowed_packet"] = $this->max_allowed_packet;
        }
        if (!empty($params)) {
            $this->audit_log(
                "TUNER REQUEST | endpoint={$endpoint} | params=" .
                    json_encode($params),
                false,
            );
        }
        return $params;
    }

    private function handle_tuner_error(string $endpoint, array $error): void
    {
        if (!$this->tuner instanceof AdaptiveTuner) {
            return;
        }

        $decision = $this->tuner->record_error($endpoint, $error);
        $log = [
            "TUNER ERROR",
            "endpoint={$endpoint}",
            "decision={$decision["decision"]}",
            "http_code=" . (int) ($decision["http_code"] ?? 0),
            "timeout=" . (!empty($decision["timeout"]) ? "yes" : "no"),
            "curl_errno=" . (int) ($decision["curl_errno"] ?? 0),
            "error_backoff_remaining=" .
                (int) ($decision["error_backoff_remaining"] ?? 0),
        ];
        if (!empty($decision["size_key"])) {
            $log[] =
                $decision["size_key"] . "=" . (int) ($decision["size_value"] ?? 0);
        }
        $this->audit_log(implode(" | ", $log), false);
    }

    /**
     * Record request metrics, apply tuning decisions, and sleep if needed.
     */
    private function finalize_tuned_request(
        string $endpoint,
        float $wall_time,
        array $response_stats
    ): void {
        if (!$this->tuner instanceof AdaptiveTuner) {
            return;
        }

        $decision = $this->tuner->record_result($endpoint, [
            "wall_time" => $wall_time,
            "server_time" => $response_stats["server_time"] ?? null,
            "status" => $response_stats["status"] ?? null,
            "bytes_processed" => $response_stats["bytes_processed"] ?? null,
            "entries_processed" => $response_stats["entries_processed"] ?? null,
            "sql_bytes" => $response_stats["sql_bytes"] ?? null,
            "ttfb" => $response_stats["ttfb"] ?? null,
            "total_time" => $response_stats["total_time"] ?? null,
            "memory_used" => $response_stats["memory_used"] ?? null,
            "memory_limit" => $response_stats["memory_limit"] ?? null,
        ]);

        $log = [
            "TUNER RESULT",
            "endpoint={$endpoint}",
            "decision={$decision["decision"]}",
            "status=" . ($decision["status"] ?? "unknown"),
            "elapsed=" . sprintf("%.3f", $decision["elapsed"] ?? 0) . "s",
            "server_time=" .
                sprintf("%.3f", (float) ($decision["server_time"] ?? 0)) .
                "s",
            "wall_time=" .
                sprintf("%.3f", (float) ($decision["wall_time"] ?? 0)) .
                "s",
        ];

        if (isset($decision["work_done"]) && $decision["work_done"] !== null) {
            $log[] = "work=" . (int) $decision["work_done"];
        }
        if (isset($decision["throughput"]) && $decision["throughput"] !== null) {
            $log[] =
                "throughput=" . sprintf("%.2f", $decision["throughput"]);
        }
        if (isset($decision["throughput_ema"]) && $decision["throughput_ema"] !== null) {
            $log[] = "ema=" . sprintf("%.2f", $decision["throughput_ema"]);
        }
        if (isset($decision["throughput_ratio"]) && $decision["throughput_ratio"] !== null) {
            $log[] =
                "ratio=" . sprintf("%.2f", (float) $decision["throughput_ratio"]);
        }
        if (!empty($decision["size_key"])) {
            $log[] =
                $decision["size_key"] . "=" . (int) ($decision["size_value"] ?? 0);
        }
        if (isset($decision["error_backoff_remaining"])) {
            $log[] =
                "error_backoff=" . (int) $decision["error_backoff_remaining"];
        }
        $log[] = "duty=" . sprintf("%.2f", $decision["duty"] ?? 0);
        $log[] =
            "sleep=" .
            sprintf("%.2f", $decision["sleep_seconds"] ?? 0) .
            "s";
        $this->audit_log(implode(" | ", $log), false);

        $sleep = (float) ($decision["sleep_seconds"] ?? 0);
        if ($sleep > 0) {
            usleep((int) round($sleep * 1_000_000));
        }
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
        $checkpoint = $this->files_pull_checkpoint();
        $state_command = $this->state["command"] ?? null;
        $current_status =
            $state_command === "files-pull"
                ? $checkpoint->status ?? $this->state["status"] ?? null
                : null;
        $has_progress =
            $state_command === "files-pull" &&
            $current_status !== null &&
            $current_status !== "complete";

        $this->recover_index_updates();

        if ($current_status === "complete") {
            $this->handle_completed_files_sync($checkpoint);
            return;
        }

        if ($this->filter === "skipped-earlier") {
            throw new RuntimeException(
                "--filter=skipped-earlier was requested but there is no completed sync with skipped files. " .
                    "Run files-pull with --filter=essential-files first.",
            );
        }

        $is_delta = $this->has_local_file_index();

        if ($has_progress) {
            $this->report_files_sync_resume($checkpoint);
        } else {
            $this->start_files_sync($checkpoint, $is_delta);
        }

        $checkpoint->status = "in_progress";
        $this->save_files_pull_checkpoint($checkpoint);
        $this->record_command_status("files-pull", "in_progress");

        $this->run_files_sync_pipeline($checkpoint);

        if ($checkpoint->status === "partial") {
            $this->record_command_status("files-pull", "partial");
            return;
        }

        $this->complete_files_sync($checkpoint, $is_delta);
    }

    private function handle_completed_files_sync(FilesPullCheckpoint $checkpoint): void
    {
        $has_skipped = $this->has_skipped_download_list();

        if ($this->filter === "skipped-earlier") {
            $this->start_skipped_files_fetch($checkpoint, $has_skipped);
            return;
        }

        $this->report_files_sync_already_complete($has_skipped);
    }

    private function has_skipped_download_list(): bool
    {
        return
            file_exists($this->skipped_download_list_file) &&
            filesize($this->skipped_download_list_file) > 0;
    }

    private function start_skipped_files_fetch(
        FilesPullCheckpoint $checkpoint,
        bool $has_skipped
    ): void
    {
        if (!$has_skipped) {
            throw new RuntimeException(
                "--filter=skipped-earlier was requested but there is no skipped file list. " .
                    "Run files-pull with --filter=essential-files first.",
            );
        }

        $this->audit_log(
            "FETCH SKIPPED | files-pull was complete — downloading previously skipped files",
            true,
        );
        $this->output->show_lifecycle_line("Downloading previously skipped files\n");
        $this->output_progress([
            "type" => "lifecycle",
            "event" => "starting",
            "command" => "files-pull",
            "stage" => "fetch-skipped",
            "message" => "Downloading previously skipped files",
        ], true);
        $checkpoint->status = "in_progress";
        $checkpoint->stage = "fetch-skipped";
        $this->save_files_pull_checkpoint($checkpoint);
        $this->record_command_status("files-pull", "in_progress");

        $this->run_files_sync_pipeline($checkpoint);
        if ($checkpoint->status === "partial") {
            $this->record_command_status("files-pull", "partial");
            return;
        }

        $checkpoint->status = "complete";
        $checkpoint->stage = null;
        $this->save_files_pull_checkpoint($checkpoint);
        $this->record_command_status("files-pull", "complete");
    }

    private function report_files_sync_already_complete(bool $has_skipped): void
    {
        $index_size = $this->index_count();
        $this->output->clear_progress_line();

        $skipped_note = $has_skipped
            ? " (some files were skipped — re-run with --filter=skipped-earlier to download them)"
            : "";
        $this->audit_log(
            sprintf("files-pull already complete: %d files indexed%s", $index_size, $skipped_note),
            true,
        );

        $this->output->show_lifecycle_line("files-pull already complete: {$index_size} files indexed\n");
        if ($has_skipped) {
            $this->output->show_lifecycle_line("Some files were skipped. Re-run with --filter=skipped-earlier to download them.\n");
        } else {
            $this->output->show_lifecycle_line("To re-sync, run with --abort first to clear state.\n");
        }
        $this->output_progress([
            "type" => "lifecycle",
            "event" => "already_complete",
            "command" => "files-pull",
            "files_indexed" => $index_size,
            "has_skipped" => $has_skipped,
            "message" => "files-pull already complete: {$index_size} files indexed",
        ], true);
    }

    private function has_local_file_index(): bool
    {
        return
            file_exists($this->index_file) &&
            filesize($this->index_file) > 0;
    }

    private function is_fs_root_empty(): bool
    {
        return !is_dir($this->fs_root) || count(array_diff(
            scandir($this->fs_root) ?: [],
            [".", ".."]
        )) === 0;
    }

    private function report_files_sync_resume(FilesPullCheckpoint $checkpoint): void
    {
        $index_size = $this->index_count();
        $stage = $checkpoint->stage ?? "index";

        $this->audit_log(
            sprintf(
                "RESUME files-pull | stage=%s | indexed_files=%d",
                $stage,
                $index_size,
            ),
            true,
        );

        $this->output->show_lifecycle_line("Resuming files-pull\n");
        $this->output->show_lifecycle_line("  Stage: {$stage}\n");
        $this->output->show_lifecycle_line("  Already indexed: {$index_size} files\n");
        $this->output_progress([
            "type" => "lifecycle",
            "event" => "resuming",
            "command" => "files-pull",
            "stage" => $stage,
            "index_size" => $index_size,
            "message" => "Resuming files-pull (stage: {$stage}, indexed: {$index_size} files)",
        ], true);
    }

    private function start_files_sync(FilesPullCheckpoint $checkpoint, bool $is_delta): void
    {
        $is_empty = $this->is_fs_root_empty();
        if (!$is_empty && !$is_delta && $this->fs_root_nonempty_behavior === 'error') {
            throw new RuntimeException(
                "Target directory is not empty and no cursor found. " .
                    "Either clear the target directory, use --abort flag, or use --on-fs-root-nonempty=preserve-local to sync while preserving the existing content.",
            );
        }

        $checkpoint->reset_for_files_pull();
        $this->save_files_pull_checkpoint($checkpoint);
        $this->record_command_status("files-pull", "in_progress");

        if ($is_delta) {
            $this->report_files_sync_delta_start();
        } else {
            $this->report_files_sync_initial_start($is_empty);
        }
    }

    private function report_files_sync_delta_start(): void
    {
        $this->files_imported = 0;
        $index_size = $this->index_count();

        $this->audit_log(
            "START files-pull (delta) | index_files={$index_size}",
            true,
        );

        $this->output->show_lifecycle_line("Starting files-pull (delta)\n");
        $this->output->show_lifecycle_line("  Index contains: {$index_size} files\n");
        $this->output->show_lifecycle_line("  Stage: index\n");
        $this->output_progress([
            "type" => "lifecycle",
            "event" => "starting",
            "command" => "files-pull",
            "delta" => true,
            "index_size" => $index_size,
            "message" => "Starting files-pull (delta, {$index_size} files indexed)",
        ], true);
    }

    private function report_files_sync_initial_start(bool $is_empty): void
    {
        $this->audit_log(
            "START files-pull ({$this->fs_root_nonempty_behavior} mode, ".($is_empty ? 'empty directory' : 'non-empty directory').")",
            true,
        );

        $this->output->show_lifecycle_line("Starting files-pull\n");
        $this->output_progress([
            "type" => "lifecycle",
            "event" => "starting",
            "command" => "files-pull",
            "message" => "Starting files-pull",
        ], true);
    }

    private function complete_files_sync(
        FilesPullCheckpoint $checkpoint,
        bool $is_delta
    ): void
    {
        $checkpoint->status = "complete";
        $checkpoint->stage = null;
        $this->save_files_pull_checkpoint($checkpoint);
        $this->record_command_status("files-pull", "complete");

        $this->output->clear_progress_line();
        $index_size = $this->index_count();
        $label = $is_delta ? "files-pull (delta)" : "files-pull";

        $this->audit_log(
            sprintf("%s complete: %d files indexed", $label, $index_size),
            true,
        );

        $this->output->show_lifecycle_line("{$label} complete: {$index_size} files indexed\n");
        $this->output->show_lifecycle_line("Audit log: {$this->audit_log}\n");
        $this->output_progress([
            "type" => "lifecycle",
            "event" => "complete",
            "command" => "files-pull",
            "delta" => $is_delta,
            "files_indexed" => $index_size,
            "audit_log" => $this->audit_log,
            "message" => "{$label} complete: {$index_size} files indexed",
        ], true);

        $this->report_volatile_files();
    }

    private function run_files_sync_pipeline(FilesPullCheckpoint $checkpoint): void
    {
        $stage = $checkpoint->stage ?? "index";

        if ($stage === "index") {
            if (!$this->download_remote_index($checkpoint)) {
                $this->mark_files_sync_partial($checkpoint);
                return;
            }

            if ($this->follow_symlinks) {
                $this->discover_symlink_targets($checkpoint);
                if ($this->shutdown_requested) {
                    $this->mark_files_sync_partial($checkpoint);
                    return;
                }
            }

            $this->sort_index_file($this->remote_index_file);
            $checkpoint->stage = "diff";
            $checkpoint->reset_diff();
            $this->delete_file_if_exists(
                $this->download_list_file,
                "clearing before diff stage",
            );
            $this->delete_file_if_exists(
                $this->skipped_download_list_file,
                "clearing before diff stage",
            );
            $this->save_files_pull_checkpoint($checkpoint);
            $stage = "diff";
        }

        if ($stage === "diff") {
            if (!$this->diff_indexes_and_build_fetch_list($checkpoint)) {
                $this->mark_files_sync_partial($checkpoint);
                return;
            }

            $has_downloads = $this->file_has_entries($this->download_list_file);
            $has_skipped = $this->file_has_entries($this->skipped_download_list_file);

            if ($has_downloads) {
                $stage = "fetch";
            } elseif ($has_skipped) {
                $stage = "fetch-skipped";
            } else {
                $stage = null;
            }

            $checkpoint->stage = $stage;
            $this->save_files_pull_checkpoint($checkpoint);

            if ($has_downloads) {
                $this->show_download_progress_start(
                    $this->index_entries_counted,
                    $this->download_list_file,
                );
            }

            if (!$has_downloads) {
                $this->delete_file_if_exists(
                    $this->download_list_file,
                    "no files to fetch",
                );
            }
            if (!$has_skipped) {
                $this->delete_file_if_exists(
                    $this->skipped_download_list_file,
                    "no skipped files to fetch",
                );
            }
        }

        if ($stage === "fetch") {
            if (!$this->download_files_from_list(
                $checkpoint,
                $this->download_list_file,
                "fetch",
            )) {
                $this->mark_files_sync_partial($checkpoint);
                return;
            }

            $checkpoint->fetch->reset();
            $this->delete_file_if_exists($this->download_list_file, "fetch complete");

            $has_skipped = $this->file_has_entries($this->skipped_download_list_file);

            if ($has_skipped && $this->filter === "essential-files") {
                $checkpoint->stage = null;
                $this->save_files_pull_checkpoint($checkpoint);
                $this->audit_log(
                    "ESSENTIAL FILES COMPLETE | skipped files listed in {$this->skipped_download_list_file} - run with --filter=skipped-earlier to download them",
                    true,
                );
                $stage = null;
            } elseif ($has_skipped) {
                $checkpoint->stage = "fetch-skipped";
                $this->save_files_pull_checkpoint($checkpoint);
                $stage = "fetch-skipped";
                $this->audit_log(
                    "ESSENTIAL FILES COMPLETE | transitioning to skipped files",
                    true,
                );
                $this->write_status_file();
            } else {
                $checkpoint->stage = null;
                $this->save_files_pull_checkpoint($checkpoint);
                $stage = null;
            }
        }

        if ($stage === "fetch-skipped") {
            if (!$this->download_files_from_list(
                $checkpoint,
                $this->skipped_download_list_file,
                "fetch_skipped",
            )) {
                $this->mark_files_sync_partial($checkpoint);
                return;
            }

            $checkpoint->stage = null;
            $checkpoint->fetch_skipped->reset();
            $this->save_files_pull_checkpoint($checkpoint);

            $this->delete_file_if_exists(
                $this->skipped_download_list_file,
                "skipped files fetch complete",
            );
        }

        if ($this->follow_symlinks) {
            (new IntermediateSymlinkRecreator(
                $this->local_filesystem(),
                function (string $message, bool $to_console): void {
                    $this->audit_log($message, $to_console);
                },
            ))->recreate($this->remote_index_file);
        }
    }

    private function mark_files_sync_partial(FilesPullCheckpoint $checkpoint): void
    {
        $checkpoint->status = "partial";
        $this->save_files_pull_checkpoint($checkpoint);
    }

    private function file_has_entries(string $file): bool
    {
        return file_exists($file) && filesize($file) > 0;
    }

    private function delete_file_if_exists(string $file, string $reason): void
    {
        if (!file_exists($file)) {
            return;
        }

        @unlink($file);
        $this->audit_log("FILE DELETE | {$file} | {$reason}");
    }

    private function show_download_progress_start(
        int $scanned,
        string $download_list_file
    ): void {
        if (!$this->output->is_quiet_lifecycle()) {
            return;
        }

        $green = "\033[32m";
        $dim = "\033[2m";
        $r = "\033[0m";
        $this->output->clear_progress_line();
        $this->output->print_line(
            "  {$green}✓{$r} Scanned {$dim}— " .
            number_format($scanned) .
            " entries{$r}\n",
        );
        $this->output->set_active_label(null);
        $total = $this->count_newlines($download_list_file);
        $this->output->show_progress_line(
            "Downloading — 0 / " . number_format($total) . " files",
            0.0,
        );
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
        $checkpoint = $this->files_pull_checkpoint();
        $state_command = $this->state["command"] ?? null;
        $current_status =
            $state_command === "files-index"
                ? $this->state["status"] ?? null
                : null;

        if ($current_status === "complete") {
            throw new RuntimeException(
                "files-index already completed. Use --abort flag to start over.",
            );
        }

        if ($current_status === null) {
            $checkpoint->reset_for_files_pull();
            $this->save_files_pull_checkpoint($checkpoint);
            $this->record_command_status("files-index", "in_progress");
            $this->audit_log("START files-index", true);
            $this->output->show_lifecycle_line("Starting files-index\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "starting",
                "command" => "files-index",
                "message" => "Starting files-index",
            ], true);
        } else {
            $cursor = $checkpoint->index_cursor;
            $this->audit_log(
                sprintf(
                    "RESUME files-index | cursor=%s",
                    $cursor ? substr($cursor, 0, 20) . "..." : "none",
                ),
                true,
            );
            $this->output->show_lifecycle_line("Resuming files-index\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "resuming",
                "command" => "files-index",
                "message" => "Resuming files-index",
            ], true);
        }

        $this->record_command_status("files-index", "in_progress");

        $attempts = 0;
        $last_cursor = $checkpoint->index_cursor;
        while (true) {
            $complete = $this->download_remote_index($checkpoint);
            if ($complete) {
                break;
            }

            if ($this->shutdown_requested) {
                $checkpoint->status = "partial";
                $this->save_files_pull_checkpoint($checkpoint);
                $this->record_command_status("files-index", "partial");
                return;
            }

            $current_cursor = $checkpoint->index_cursor;
            if ($current_cursor === $last_cursor) {
                throw new RuntimeException(
                    "files-index made no progress (cursor unchanged)",
                );
            }
            $last_cursor = $current_cursor;

            $attempts++;
            if ($attempts > 100000) {
                throw new RuntimeException(
                    "files-index exceeded maximum attempts",
                );
            }
        }

        // Follow symlinks: discover symlink targets outside known roots and
        // index them as additional directories.  Repeats until no new targets
        // are found, with cycle detection via realpath.
        if ($this->follow_symlinks) {
            $this->discover_symlink_targets($checkpoint);
        }

        $this->sort_index_file($this->remote_index_file);
        $checkpoint->status = "complete";
        $checkpoint->stage = null;
        $this->save_files_pull_checkpoint($checkpoint);
        $this->record_command_status("files-index", "complete");

        $count = 0;
        if (file_exists($this->remote_index_file)) {
            $h = fopen($this->remote_index_file, "r");
            if ($h) {
                while (fgets($h) !== false) {
                    $count++;
                }
                fclose($h);
            }
        }
        $this->audit_log(
            sprintf("files-index complete: %d entries indexed", $count),
            true,
        );

        $this->output->show_lifecycle_line("files-index complete: {$count} entries indexed\n");
        $this->output->show_lifecycle_line("Remote index: {$this->remote_index_file}\n");
        $this->output->show_lifecycle_line("Audit log: {$this->audit_log}\n");
        $this->output_progress([
            "type" => "lifecycle",
            "event" => "complete",
            "command" => "files-index",
            "entries_indexed" => $count,
            "remote_index" => $this->remote_index_file,
            "audit_log" => $this->audit_log,
            "message" => "files-index complete: {$count} entries indexed",
        ], true);
    }

    /**
     * Recursively discover directories that need indexing beyond the primary
     * export roots.
     *
     * Scans the remote index for symlink entries with a "target" field,
     * resolves relative targets to absolute paths, and indexes each target
     * directory. Repeats until the queue is drained, with cycle detection.
     */
    private function discover_symlink_targets(FilesPullCheckpoint $checkpoint): void
    {
        (new SymlinkTargetIndexer(
            $this->remote_index_file,
            function (string $directory) use ($checkpoint): bool {
                return $this->download_remote_index($checkpoint, $directory);
            },
            function (FilesPullCheckpoint $checkpoint): void {
                $this->save_files_pull_checkpoint($checkpoint);
            },
            function (): bool {
                return $this->shutdown_requested;
            },
            function (string $message, bool $to_console): void {
                $this->audit_log($message, $to_console);
            },
            function (string $message): void {
                $this->output->show_lifecycle_line($message);
            },
            function (array $progress): void {
                $this->output_progress($progress, true);
            },
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
        return new DbPullWorkflow(
            $this,
            $this->state_dir,
            $this->audit_log,
            $this->sql_output_mode,
            $this->mysql_database,
            $this->output,
        );
    }

    private function load_db_pull_checkpoint(): DbPullCheckpoint
    {
        return DbPullCheckpoint::from_array(
            $this->json_state_store->load($this->paths->db_pull_checkpoint_file()) ?? [],
        );
    }

    public function save_db_pull_checkpoint(DbPullCheckpoint $checkpoint): void
    {
        $this->output->tick_spinner();
        $this->json_state_store->save(
            $this->paths->db_pull_checkpoint_file(),
            $checkpoint->to_array(),
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

    private function record_command_status(string $command, ?string $status): void
    {
        $this->state["command"] = $command;
        $this->state["status"] = $status;
        $this->save_state($this->state);
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
        // Load state to get preflight data and detected webhost.
        $entry = $this->state["preflight"] ?? null;
        if (!is_array($entry) || empty($entry["data"])) {
            throw new RuntimeException(
                "apply-runtime requires a prior preflight run. " .
                "Run 'preflight' first to capture the source site's environment."
            );
        }

        $preflight_data = $entry["data"];
        $webhost = $this->state["webhost"] ?? "other";

        $result = (new RuntimeConfigurationApplier())->apply(
            [
                "runtime" => $options["runtime"] ?? null,
                "output_dir" => $options["output_dir"] ?? null,
                "fs_root" => $this->fs_root,
                "flat_document_root" => $options["flat_document_root"] ?? null,
                "preflight_data" => $preflight_data,
                "webhost" => $webhost,
                "apply_state" => $this->state["apply"] ?? [],
                "host" => $options["host"] ?? null,
                "port" => $options["port"] ?? null,
                "enable_remote_upload_proxy" => $this->should_enable_remote_upload_proxy(),
                "state_dir" => $this->state_dir,
            ],
            function (string $message, bool $verbose = false): void {
                $this->audit_log($message, $verbose);
            },
        );

        // Persist which paths were removed so callers can inspect state.
        $this->state["apply"]["remote_paths_removed_from_local_site"] = $result["paths_removed"];
        $this->save_state($this->state);

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

        if (($this->state["command"] ?? null) !== "files-pull") {
            return false;
        }

        $status = $this->state["status"] ?? null;
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
        $preflight = $this->state["preflight"]["data"] ?? [];

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
        $checkpoint = (new DbApplyWorkflow(
            $this->state_dir,
            $this->remote_url,
            $this->local_filesystem(),
            $this->output,
            function (DbApplyCheckpoint $checkpoint): void {
                $this->save_db_apply_checkpoint($checkpoint);
            },
            function (string $message, bool $to_console = true): void {
                $this->audit_log($message, $to_console);
            },
            function (array $progress, bool $force = false): void {
                $this->output_progress($progress, $force);
            },
            function (): bool {
                return $this->shutdown_requested;
            },
        ))->run(
            $this->db_apply_checkpoint(),
            $this->state,
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

        $has_cursor =
            $this->state["command"] === "db-index" &&
            $checkpoint->cursor !== null;
        $current_status = $this->state["command"] === "db-index"
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
    private function download_file_fetch(
        FilesPullCheckpoint $checkpoint,
        ?array $post_data,
        ?string $cursor,
        string $state_key = "fetch"
    ): bool {
        $local_applier = $this->file_sync_local_applier($checkpoint);

        return (new FileFetchDownloader(
            function (string $endpoint, ?string $cursor, array $params): string {
                return $this->build_url($endpoint, $cursor, $params);
            },
            function (
                string $url,
                ?string $cursor,
                StreamingContext $context,
                ?array $post_data,
                string $phase
            ): void {
                $this->fetch_streaming($url, $cursor, $context, $post_data, $phase);
            },
            function (string $endpoint): array {
                return $this->get_tuned_params($endpoint);
            },
            function (): bool {
                return $this->shutdown_requested;
            },
            function (FilesPullCheckpoint $checkpoint): void {
                $this->save_files_pull_checkpoint($checkpoint);
            },
            function (array $chunk, StreamingContext $context) use ($local_applier): void {
                $local_applier->handle_metadata_chunk($chunk, $context);
            },
            function (array $chunk, StreamingContext $context) use ($local_applier): void {
                $local_applier->handle_file_chunk($chunk, $context);
                $this->files_imported = $local_applier->files_imported();
            },
            function (array $chunk) use ($local_applier): void {
                $local_applier->handle_directory_chunk($chunk);
            },
            function (array $chunk) use ($local_applier): void {
                $local_applier->handle_symlink_chunk($chunk);
            },
            function (
                array $chunk,
                string $phase,
                StreamingContext $context
            ) use ($local_applier): void {
                $local_applier->handle_error_chunk($chunk, $phase, $context);
            },
            function (array $chunk, string $phase) use ($local_applier): void {
                $local_applier->handle_progress($chunk, $phase);
            },
            function (array $progress): void {
                $this->output_progress($progress, true);
            },
            function (
                string $phase,
                ?string $cursor_before,
                ?string $cursor_after
            ) use ($checkpoint): void {
                $this->assert_can_retry_files_pull_timeout(
                    $checkpoint,
                    $phase,
                    $cursor_before,
                    $cursor_after,
                );
            },
            function (string $endpoint, float $wall_time, array $stats): void {
                $this->finalize_tuned_request($endpoint, $wall_time, $stats);
            },
            function (): void {
                $this->finalize_index_updates();
            },
            function (string $message, bool $to_console): void {
                $this->audit_log($message, $to_console);
            },
        ))->download(
            $checkpoint,
            [
                "post_data" => $post_data,
                "cursor" => $cursor,
                "state_key" => $state_key,
                "export_dirs" => $this->get_export_directories(),
                "save_every" => self::SAVE_STATE_EVERY_N_CHUNKS,
            ],
        );
    }

    /**
     * Download the remote index stream and write to disk.
     */
    private function download_remote_index(
        FilesPullCheckpoint $checkpoint,
        ?string $list_dir_override = null
    ): bool
    {
        $local_applier = $this->file_sync_local_applier($checkpoint);

        return (new RemoteIndexDownloader(
            function (string $endpoint, ?string $cursor, array $params): string {
                return $this->build_url($endpoint, $cursor, $params);
            },
            function (
                string $url,
                ?string $cursor,
                StreamingContext $context,
                ?array $post_data,
                string $phase
            ): void {
                $this->fetch_streaming($url, $cursor, $context, $post_data, $phase);
            },
            function (string $endpoint): array {
                return $this->get_tuned_params($endpoint);
            },
            function (): bool {
                return $this->shutdown_requested;
            },
            function (FilesPullCheckpoint $checkpoint): void {
                $this->save_files_pull_checkpoint($checkpoint);
            },
            function (array $chunk, StreamingContext $context) use ($local_applier): void {
                $local_applier->handle_metadata_chunk($chunk, $context);
            },
            function (
                array $chunk,
                string $phase,
                StreamingContext $context
            ) use ($local_applier): void {
                $local_applier->handle_error_chunk($chunk, $phase, $context);
            },
            function (array $chunk, string $phase) use ($local_applier): void {
                $local_applier->handle_progress($chunk, $phase);
            },
            function (int $entries_counted): void {
                $this->show_remote_index_progress($entries_counted);
            },
            function (
                string $phase,
                ?string $cursor_before,
                ?string $cursor_after
            ) use ($checkpoint): void {
                $this->assert_can_retry_files_pull_timeout(
                    $checkpoint,
                    $phase,
                    $cursor_before,
                    $cursor_after,
                );
            },
            function (string $endpoint, float $wall_time, array $stats): void {
                $this->finalize_tuned_request($endpoint, $wall_time, $stats);
            },
            function (string $path): int {
                return $this->count_newlines($path);
            },
            function (string $message, bool $to_console): void {
                $this->audit_log($message, $to_console);
            },
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

    private function show_remote_index_progress(int $entries_counted): void
    {
        if ($entries_counted > 0) {
            $this->output->show_progress_line(
                "Scanning remote files — " .
                number_format($entries_counted) . " scanned"
            );
            return;
        }

        $this->output->show_progress_line("Scanning remote files");
    }

    /**
     * Diff local index against remote index and build download list.
     */
    private function diff_indexes_and_build_fetch_list(FilesPullCheckpoint $checkpoint): bool
    {
        $local_applier = $this->file_sync_local_applier($checkpoint);

        $builder = new FetchListBuilder(
            $this->index_store,
            function (string $path) use ($local_applier): void {
                $local_applier->delete_local_file_path($path);
            },
            function (string $path) use ($local_applier): ?string {
                return $local_applier->should_skip_for_preserve_local($path);
            },
            function (string $path) use ($local_applier): void {
                $local_applier->emit_skip_progress($path);
            },
            function (array $diff) use ($checkpoint): void {
                $checkpoint->set_diff_state($diff);
                $this->save_files_pull_checkpoint($checkpoint);
            },
            function (): bool {
                return $this->shutdown_requested;
            },
            function (): void {
                $this->output->tick_spinner();
            },
            function (string $message, bool $to_console = true): void {
                $this->audit_log($message, $to_console);
            },
        );

        return $builder->build(
            $this->remote_index_file,
            $this->index_file,
            $this->download_list_file,
            $this->skipped_download_list_file,
            $checkpoint->diff_state(),
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
    private function count_newlines(string $file, int $up_to_byte = -1): int
    {
        return DownloadList::count_lines($file, $up_to_byte);
    }

    /**
     * Download files from a prepared list.
     *
     * @param string $list_file Path to the JSONL download list to process.
     * @param string $state_key Key in $this->state that holds fetch progress
     *                          (e.g. "fetch" or "fetch_skipped").
     */
    private function download_files_from_list(
        FilesPullCheckpoint $checkpoint,
        string $list_file,
        string $state_key
    ): bool {
        $fetch_checkpoint = $checkpoint->fetch_checkpoint($state_key);
        $executor = new FetchListExecutor(
            $this->download_list_total,
            $this->download_list_done,
            $this->files_imported,
            $this->get_max_request_bytes(),
            function (string $batch_file, $cursor, string $state_key) use ($checkpoint): bool {
                $post_data = [
                    "file_list" => new CURLFile(
                        $batch_file,
                        "application/json",
                        "file-list.json",
                    ),
                ];

                return $this->download_file_fetch(
                    $checkpoint,
                    $post_data,
                    $cursor,
                    $state_key,
                );
            },
            function (string $state_key, FetchCheckpoint $fetch_checkpoint) use ($checkpoint): void {
                $checkpoint->{$state_key} = $fetch_checkpoint;
                $this->save_files_pull_checkpoint($checkpoint);
            },
            function (string $message): void {
                $this->audit_log($message);
            },
        );

        try {
            return $executor->run(
                $list_file,
                $state_key,
                $fetch_checkpoint,
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
        $preflight = $this->state["preflight"]["data"]["limits"] ?? null;
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
        $paths_urls = $this->state["preflight"]["data"]["database"]["wp"]["paths_urls"] ?? null;
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
            function (string $message, bool $to_console): void {
                $this->audit_log($message, $to_console);
            },
        );

        return $this->local_filesystem;
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
    private function finalize_index_updates(): void
    {
        $this->index_store->finalize_updates();
    }

    /**
     * Download SQL from remote.
     */
    private function download_sql(DbPullCheckpoint $checkpoint): DbPullCheckpoint
    {
        $local_applier = $this->file_sync_local_applier();

        $checkpoint = (new SqlDownloader(
            function (string $endpoint, ?string $cursor, array $params): string {
                return $this->build_url($endpoint, $cursor, $params);
            },
            function (
                string $url,
                ?string $cursor,
                StreamingContext $context,
                ?array $post_data,
                string $phase
            ): void {
                $this->fetch_streaming($url, $cursor, $context, $post_data, $phase);
            },
            function (string $endpoint): array {
                return $this->get_tuned_params($endpoint);
            },
            function (): bool {
                return $this->shutdown_requested;
            },
            function (DbPullCheckpoint $checkpoint): void {
                $this->save_db_pull_checkpoint($checkpoint);
            },
            function (int $sql_bytes_written) use ($checkpoint): void {
                $db_bytes_est = $checkpoint->db_index->bytes;
                $est_is_useful = $db_bytes_est > $sql_bytes_written;
                $sql_fraction = $est_is_useful
                    ? $sql_bytes_written / $db_bytes_est
                    : null;
                $sql_progress = $this->format_bytes($sql_bytes_written);
                if ($est_is_useful) {
                    $sql_progress .= " / " . $this->format_bytes($db_bytes_est);
                }
                $this->output->show_progress_line($sql_progress, $sql_fraction);
            },
            function (array $chunk, string $phase) use ($local_applier): void {
                $local_applier->handle_progress($chunk, $phase);
            },
            function (
                array $chunk,
                string $phase,
                StreamingContext $context
            ) use ($local_applier): void {
                $local_applier->handle_error_chunk($chunk, $phase, $context);
            },
            function (array $progress): void {
                $this->output_progress($progress, true);
            },
            function (): void {
                $this->save_db_pull_checkpoint($checkpoint);
                exit(0);
            },
            function (
                string $phase,
                ?string $cursor_before,
                ?string $cursor_after
            ) use ($checkpoint): void {
                $this->assert_can_retry_db_pull_timeout(
                    $checkpoint,
                    $phase,
                    $cursor_before,
                    $cursor_after,
                );
            },
            function (string $endpoint, float $wall_time, array $stats): void {
                $this->finalize_tuned_request($endpoint, $wall_time, $stats);
            },
            function (string $message, bool $to_console): void {
                $this->audit_log($message, $to_console);
            },
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
        return HttpRequestBuilder::url($this->remote_url, $endpoint, $cursor, $params);
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

    public function assert_can_retry_stream_timeout(
        string $phase,
        ?string $cursor_before,
        ?string $cursor_after
    ): void {
        $this->assert_can_retry_consecutive_timeout(
            $phase,
            $cursor_before,
            $cursor_after,
        );
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
            $this->state["preflight"]["data"] ?? [],
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
            $this->state["preflight"]["data"] ?? [],
            $this->extra_directory,
            function (string $message): void {
                $this->audit_log($message);
            },
        );
    }

    /**
     * Sorts an index file by path and removes duplicate entries.
     */
    private function sort_index_file(string $path): void
    {
        $this->index_sorter->sort($path);
    }

    /**
     * User-Agent strings to try during preflight, in order of preference.
     * Some WAFs block browser UAs that carry custom auth headers, so we
     * start with an honest non-browser identity and fall back to common
     * browser strings.
     */
    public const USER_AGENTS = HttpRequestBuilder::USER_AGENTS;

    /**
     * Build the multipart chunk handler callback shared by both parser
     * creation sites inside fetch_streaming.
     *
     * File parts are forwarded as body data arrives so large files are written
     * to disk incrementally. Non-file parts are still accumulated until
     * complete because they are small metadata/progress JSON payloads.
     */
    private function make_chunk_handler(
        StreamingContext $context,
        &$current_chunk
    ): callable {
        return $this->http_transport()->make_chunk_handler($context, $current_chunk);
    }

    /**
     * Track consecutive cURL timeouts and decide whether to retry or give up.
     *
     * Compares the cursor before and after the request. If the cursor advanced
     * (we got some data before stalling), the counter resets — the stall was
     * transient and resuming makes sense. If the cursor didn't move, the
     * counter increments. After MAX_CONSECUTIVE_TIMEOUTS with no progress,
     * throws a RuntimeException so the runner sees exit code 1 and stops.
     *
     * @param string $phase   Human-readable phase name for logs (e.g. "sql_chunk")
     * @param ?string $cursor_before Cursor value at the start of the request
     * @param ?string $cursor_after  Cursor value when the timeout fired
     */
    protected function assert_can_retry_consecutive_timeout(
        string $phase,
        ?string $cursor_before,
        ?string $cursor_after
    ): void {
        if ($cursor_after !== null && $cursor_after !== $cursor_before) {
            // Progress was made — reset the counter.
            $this->state["consecutive_timeouts"] = 0;
        } else {
            $this->state["consecutive_timeouts"] =
                ($this->state["consecutive_timeouts"] ?? 0) + 1;
        }

        $count = $this->state["consecutive_timeouts"];

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

    /**
     * Fetch a JSON response for a lightweight request (non-streaming).
     */
    public function fetch_json(string $url): array
    {
        $this->audit_log("HTTP_REQUEST | GET | {$url}", false);

        $result = $this->http_transport()->fetch_json(
            $url,
            $this->json_request_headers(''),
            $this->has_hmac_secret(),
        );

        if (!empty($result["error_code"])) {
            $this->last_error_code = $result["error_code"];
        }

        return $result;
    }

    /**
     * Fetch URL with streaming multipart parsing.
     */
    protected function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data = null,
        ?string $endpoint = null
    ): void {
        $body_for_signing = ImportHttpTransport::body_for_signing($post_data);
        $transport = $this->http_transport();

        $this->audit_log(
            $this->streaming_request_log_message($url, $post_data),
            false,
        );
        $this->output_progress(["debug" => "Waiting for server response..."]);

        try {
            $transport->fetch_streaming(
                $url,
                $context,
                $this->streaming_request_headers($cursor, $body_for_signing),
                $post_data,
                $this->has_hmac_secret(),
            );
        } catch (RuntimeException $e) {
            $this->sync_transport_error_state($transport);
            if ($endpoint !== null) {
                $this->handle_transport_tuner_error($endpoint, $transport);
            }

            $http_code = $transport->last_http_code();
            if ($http_code !== null && $http_code !== 200) {
                $this->audit_log(
                    "HTTP error {$http_code} | error_body length: " .
                        (int) ($transport->last_error_body_length() ?? 0),
                    true,
                );
            }

            throw $e;
        }
    }

    private function json_request_headers(string $body): array
    {
        return [
            ...HttpRequestBuilder::base_headers(
                "application/json",
                $this->state["user_agent"] ?? null,
            ),
            ...$this->hmac_headers($body),
        ];
    }

    private function streaming_request_headers(
        ?string $cursor,
        string $body
    ): array {
        $headers = [
            ...HttpRequestBuilder::base_headers(
                "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8",
                $this->state["user_agent"] ?? null,
            ),
            "Upgrade-Insecure-Requests: 1",
            "Sec-Fetch-Dest: document",
            "Sec-Fetch-Mode: navigate",
            "Sec-Fetch-Site: none",
            "Sec-Fetch-User: ?1",
        ];

        if ($cursor) {
            $headers[] = "X-Export-Cursor: {$cursor}";
        }

        return [
            ...$headers,
            ...$this->hmac_headers($body),
        ];
    }

    private function hmac_headers(string $body): array
    {
        if ($this->hmac_client === null) {
            return [];
        }

        return $this->hmac_client->get_curl_headers($body);
    }

    private function has_hmac_secret(): bool
    {
        return $this->hmac_client !== null;
    }

    private function streaming_request_log_message(
        string $url,
        ?array $post_data
    ): string {
        $log_parts = ["HTTP_REQUEST", $post_data ? "POST" : "GET", $url];
        if ($post_data && isset($post_data["file_list"])) {
            $file_list_part = $post_data["file_list"];
            if ($file_list_part instanceof CURLFile) {
                $upload_path = $file_list_part->getFilename();
                $upload_size = is_string($upload_path)
                    ? filesize($upload_path)
                    : false;
                $upload_size = $upload_size === false ? 0 : $upload_size;
                $log_parts[] = "file_list_file=" . $upload_size . "b";
            } else {
                $log_parts[] =
                    "file_list=" . strlen((string) $file_list_part) . "b";
            }
        }

        return implode(" | ", $log_parts);
    }

    private function sync_transport_error_state(ImportHttpTransport $transport): void
    {
        if ($transport->last_error_code() !== null) {
            $this->last_error_code = $transport->last_error_code();
        }
    }

    private function handle_transport_tuner_error(
        string $endpoint,
        ImportHttpTransport $transport
    ): void {
        $http_code = $transport->last_http_code();
        $curl_errno = $transport->last_curl_errno();

        if (
            $curl_errno === null &&
            ($http_code === null || $http_code === 200)
        ) {
            return;
        }

        $this->handle_tuner_error($endpoint, [
            "http_code" => $http_code ?? 0,
            "timeout" => $transport->last_curl_timed_out(),
            "curl_errno" => $curl_errno ?? 0,
        ]);
    }

    public function default_state(): array
    {
        return ImportStateSchema::default_state();
    }

    /**
     * Normalize state array to the compact schema.
     */
    private function normalize_state(array $state): array
    {
        return ImportStateSchema::normalize($state);
    }

    /**
     * Encode state path fields as base64 to make JSON persistence byte-safe.
     */
    private function encode_state_paths(array $state): array
    {
        return $this->state_path_codec->encode_state_paths($state);
    }

    /**
     * Decode base64-encoded path fields in state after loading.
     *
     * Supports legacy plain-string fields for backward compatibility.
     */
    private function decode_state_paths(array $state): array
    {
        return $this->state_path_codec->decode_state_paths($state);
    }

    /**
     * Encode preflight path fields.
     */
    private function encode_preflight_data_paths(array $data): array
    {
        return $this->state_path_codec->encode_preflight_data_paths($data);
    }

    /**
     * Decode preflight path fields.
     */
    private function decode_preflight_data_paths(array $data): array
    {
        return $this->state_path_codec->decode_preflight_data_paths($data);
    }

    /**
     * Load import state from disk.
     */
    private function load_state(): array
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

        $state = $this->normalize_state($state);
        $state = $this->decode_state_paths($state);

        $state_cmd = $state["command"] ?? null;
        if (is_string($state_cmd)) {
            $state["command"] = ImportCommands::normalize_name($state_cmd);
        }

        return $state;
    }

    /**
     * Save import state to disk.
     *
     * Uses atomic write (temp file + rename) to prevent corruption if
     * the process is killed mid-write.
     */
    public function save_state(array $state): void
    {
        // Keep the spinner alive between curl requests. save_state is
        // called frequently during streaming operations, so this fills
        // the gaps where curl's progress callback doesn't fire.
        $this->output->tick_spinner();

        if ($this->tuner instanceof AdaptiveTuner) {
            $state["tuning"] = [
                "config" => $this->tuner->get_config(),
                "state" => $this->tuner->get_state(),
            ];
        }
        $state = $this->normalize_state($state);
        $state = $this->encode_state_paths($state);

        $this->json_state_store->save($this->state_file, $state);

        $indexed = $this->index_count();
        $files_imported = $this->files_imported; // Completed in this run
        $files_checkpoint = in_array($state["command"] ?? null, ["files-pull", "files-index"], true)
            ? $this->files_pull_checkpoint()
            : null;
        $has_cursor =
            !empty($state["cursor"] ?? null) ||
            !empty($state["index"]["cursor"] ?? null) ||
            !empty($state["fetch"]["cursor"] ?? null) ||
            ($files_checkpoint !== null && (
                !empty($files_checkpoint->index_cursor) ||
                !empty($files_checkpoint->fetch->cursor) ||
                !empty($files_checkpoint->fetch_skipped->cursor)
            ));
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
        $state = $this->state ?? [];
        $command = $state["command"] ?? null;
        $files_checkpoint = in_array($command, ["files-pull", "files-index"], true)
            ? $this->files_pull_checkpoint()
            : null;
        $status = $error !== null
            ? "error"
            : ($files_checkpoint->status ?? $state["status"] ?? "in_progress");

        // Derive phase from the state's stage field
        $phase = $files_checkpoint->stage ?? $state["stage"] ?? null;

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
        $current_command = $this->state["command"] ?? "unknown";

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
            $this->save_state($this->state);
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
            $this->save_state($this->state);
            exit(0);
        }
    }
}
