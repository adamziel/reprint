<?php

namespace Reprint\Importer;

use CURLFile;
use Exception;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Reprint\Importer\Command\ImportCommands;
use Reprint\Importer\Command\ImportCommandResult;
use Reprint\Importer\Command\PreflightCommand;
use Reprint\Importer\FileSync\DirectoryChunkApplier;
use Reprint\Importer\FileSync\DownloadList;
use Reprint\Importer\FileSync\FetchListBuilder;
use Reprint\Importer\FileSync\FetchListExecutor;
use Reprint\Importer\FileSync\FileChunkApplier;
use Reprint\Importer\FileSync\FileFetchDownloader;
use Reprint\Importer\FileSync\IntermediateSymlinkRecreator;
use Reprint\Importer\FileSync\RemoteIndexDownloader;
use Reprint\Importer\FileSync\RuntimeFilesDownloader;
use Reprint\Importer\FileSync\SymlinkChunkApplier;
use Reprint\Importer\Filesystem\FlatDocumentRootBuilder;
use Reprint\Importer\Filesystem\LocalImportFilesystem;
use Reprint\Importer\Filesystem\PathUtils;
use Reprint\Importer\Index\IndexFileSorter;
use Reprint\Importer\Index\IndexLineParser;
use Reprint\Importer\Index\IndexPathPrefixMatcher;
use Reprint\Importer\Index\IndexStore;
use Reprint\Importer\Input\ImportRunRequest;
use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\Output\NullImportOutput;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Pull\Pull;
use Reprint\Importer\Session\ExportDirectoryResolver;
use Reprint\Importer\Session\ImportPaths;
use Reprint\Importer\Session\ImportStateSchema;
use Reprint\Importer\Session\StatePathCodec;
use Reprint\Importer\Session\VolatileFileTracker;
use Reprint\Importer\Sql\ActivePluginDeactivator;
use Reprint\Importer\Sql\DbApplyQueryExecutor;
use Reprint\Importer\Sql\DbIndexDownloader;
use Reprint\Importer\Sql\SqlDumpApplier;
use Reprint\Importer\Sql\SqlDownloader;
use Reprint\Importer\Sql\TargetDatabaseConnectionFactory;
use Reprint\Importer\Support\ByteFormatter;
use Reprint\Importer\Support\PathDisplayFormatter;
use Reprint\Importer\TargetRuntime\RuntimeConfigurationApplier;
use Reprint\Importer\Transport\HttpErrorDiagnoser;
use Reprint\Importer\Transport\ImportHttpTransport;
use Reprint\Importer\Transport\HttpRequestBuilder;
use Reprint\Importer\Tuning\AdaptiveTuner;
use Reprint\Importer\UrlRewrite\NewSiteUrlResolver;
use Reprint\Importer\UrlRewrite\SqlStatementRewriter;
use Reprint\Importer\UrlRewrite\StructuredDataUrlRewriter;
use function Reprint\Exporter\normalize_path;
use function Reprint\Exporter\path_is_within_root;

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

    /** @var string Directory for import state files (.import-state.json, db.sql, etc.). */
    public $state_dir;

    /** @var string Directory where downloaded site files are written (no filesystem-root/ wrapper). */
    public $fs_root;

    /** @var ImportPaths Derived filesystem paths for this import session. */
    private ImportPaths $paths;

    /** @var StatePathCodec Encodes byte-sensitive state paths for JSON persistence. */
    private StatePathCodec $state_path_codec;

    /** @var string Path to .import-state.json — persists command, cursor, stage across invocations. */
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
     * @var array Persistent import state loaded from / saved to $state_file.
     * Keys: command, status, cursor, stage, preflight, version, follow_symlinks,
     * max_allowed_packet, db_index, file_index.
     * @var array|null
     */
    public $state;

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

    /** @var string|null Machine-readable error code from the last diagnose_http_error() call. */
    public $last_error_code = null;

    /** @var ImportOutput Reports progress, status, and human-readable output. */
    private ImportOutput $output;

    /** @var ImportHttpTransport|null HTTP and streaming multipart transport. */
    private ?ImportHttpTransport $http_transport = null;

    /** @var LocalImportFilesystem|null Filesystem operations scoped to the import root. */
    private ?LocalImportFilesystem $local_filesystem = null;

    /** @var Pull Orchestrates the pull command pipeline. */
    private Pull $pull;

    /** @var int Cumulative count of index entries written (survives retries). */
    private $index_entries_counted = 0;

    /** @var IndexPathPrefixMatcher|null Memoized remote index path-prefix matcher. */
    private ?IndexPathPrefixMatcher $remote_index_prefix_matcher = null;

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

        // Register signal handlers for graceful shutdown
        if (function_exists("pcntl_signal")) {
            // Enable async signals (PHP 7.1+) so signals work during blocking operations
            if (function_exists("pcntl_async_signals")) {
                pcntl_async_signals(true);
            }
            pcntl_signal(SIGINT, [$this, "handle_shutdown"]);
            pcntl_signal(SIGTERM, [$this, "handle_shutdown"]);
        }

        // Create directories
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

    private function http_transport(): ImportHttpTransport
    {
        if ($this->http_transport instanceof ImportHttpTransport) {
            return $this->http_transport;
        }

        $this->http_transport = new ImportHttpTransport(
            $this->output,
            function (string $message, bool $to_console): void {
                $this->audit_log($message, $to_console);
            },
            function (array $data, bool $force = false): void {
                $this->output_progress($data, $force);
            },
            function (): ?string {
                return $this->state["user_agent"] ?? null;
            },
            function (string $body): array {
                if ($this->hmac_client === null) {
                    return [];
                }

                return $this->hmac_client->get_curl_headers($body);
            },
            function (): bool {
                return $this->hmac_client !== null;
            },
            function (string $code): void {
                $this->last_error_code = $code;
            },
            function (string $endpoint, array $error): void {
                $this->handle_tuner_error($endpoint, $error);
            },
            function (): array {
                if ($this->download_list_total === null) {
                    return [];
                }

                return [
                    "files_done" =>
                        ($this->download_list_done ?? 0) + $this->files_imported,
                    "files_total" => $this->download_list_total,
                ];
            },
        );

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
     * Upsert a file entry in the index.
     */
    private function upsert_index_entry(
        string $path,
        int $ctime,
        int $size,
        string $type
    ): void {
        $this->index_store->upsert($path, $ctime, $size, $type);
    }

    /**
     * Delete a file entry from the index.
     */
    private function delete_index_entry(string $path): void
    {
        $this->index_store->delete($path);
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

    /** Mark a pull pipeline stage as completed in state. */
    public function mark_pull_stage_complete(string $stage): void
    {
        $this->state['pull']['stage'] = $stage;
        $this->save_state($this->state);
    }

    /** Mark the pull pipeline as fully complete in state. */
    public function mark_pull_complete(): void
    {
        $this->state['pull']['stage'] = 'complete';
        $this->state['status'] = 'complete';
        $this->save_state($this->state);
    }

    /** Record the pull's file filter and whether deferred files remain. */
    public function set_pull_files_state(string $filter, bool $skipped_pending): void
    {
        $this->state['pull']['files_filter'] = $filter;
        $this->state['pull']['skipped_pending'] = $skipped_pending;
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

    /**
     * Record that a file changed during streaming.
     * Increments the change counter for the given path.
     */
    private function record_volatile_file(string $path): void
    {
        $this->volatile_file_tracker()->record($path);
    }

    /**
     * Clear a file from the volatile tracker after a successful download.
     */
    private function clear_volatile_file(string $path): void
    {
        $this->volatile_file_tracker()->clear($path);
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
     * Emit a preserve-local skip event to both TTY progress line and JSONL.
     */
    private function emit_skip_progress(string $path): void
    {
        $this->output->show_progress_line("[skip] " . PathDisplayFormatter::short_path($path));
        $this->output_progress([
            "type" => "skip",
            "path" => $path,
            "message" => "[skip] " . $path,
        ], true);
    }

    /**
     * Run the import process with explicit command validation.
     *
     * @param array $options Options:
     *   - command: Required. One of the commands registered in ImportCommands.
     *   - abort: Optional. Clear state for the command and exit immediately
     *   - verbose: Optional. Enable verbose output
     *
     * @return ImportCommandResult|null Structured command result for the caller to format.
     */
    public function run(array $options = []): ?ImportCommandResult
    {
        return $this->run_request(ImportRunRequest::from_options($options));
    }

    /**
     * Run the import process using a validated request.
     */
    public function run_request(ImportRunRequest $request): ?ImportCommandResult
    {
        $options = $request->options();
        $command = $request->command();
        $command_runner = $request->command_runner();

        $this->output->set_verbose_mode($request->verbose());
        $this->follow_symlinks = $request->value("follow_symlinks", true);
        $this->include_caches = $request->value("include_caches", false);
        $this->extra_directory = $request->value("extra_directory");
        if ($request->has("fs_root_nonempty_behavior")) {
            $this->fs_root_nonempty_behavior = $request->value("fs_root_nonempty_behavior");
        }

        $abort = $request->abort();
        $this->pipeline_step = $request->value("pipeline_step");
        $this->pipeline_steps = $request->value("pipeline_steps");

        $this->state = $this->load_state();

        // Persist follow_symlinks in state so it survives across invocations.
        // If explicitly set on CLI, store it.  Otherwise, restore from persisted state.
        if ($request->has("follow_symlinks")) {
            $this->state["follow_symlinks"] = $this->follow_symlinks;
            $this->save_state($this->state);
        } elseif (isset($this->state["follow_symlinks"])) {
            $this->follow_symlinks = $this->state["follow_symlinks"];
        }

        // Persist fs_root_nonempty_behavior in state so it survives across invocations.
        // 'preserve-local' preserves existing local files instead of overwriting
        // them, and gracefully skips non-writable directories.
        if ($request->has("fs_root_nonempty_behavior")) {
            $this->state["fs_root_nonempty_behavior"] = $this->fs_root_nonempty_behavior;
            $this->save_state($this->state);
        } else {
            $this->fs_root_nonempty_behavior = $this->state["fs_root_nonempty_behavior"] ?? 'error';
        }
        $this->local_filesystem = null;

        // Persist filter in state so it survives across resume cycles.
        //
        //   --filter=none             download everything (default)
        //   --filter=essential-files   skip uploads, download code/config/themes/plugins
        //   --filter=skipped-earlier   download only files skipped by a prior essential-files run
        //
        // Changing the filter mid-flight is not allowed.  The user must either
        // start fresh (--abort) or finish the current sync before switching.
        // The one valid transition is: essential-files (complete) → skipped-earlier.
        if ($request->has("filter")) {
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
            $this->save_state($this->state);
        } elseif (isset($this->state["filter"])) {
            $this->filter = $this->state["filter"];
        }

        // Persist max_allowed_packet in state so it survives across invocations.
        // The client sends this to the server so SQL statements are capped to a
        // size the client's MySQL instance can actually import.
        if ($request->has("max_allowed_packet")) {
            $this->max_allowed_packet = (int) $request->value("max_allowed_packet");
            $this->state["max_allowed_packet"] = $this->max_allowed_packet;
            $this->save_state($this->state);
        } elseif (isset($this->state["max_allowed_packet"])) {
            $this->max_allowed_packet = (int) $this->state["max_allowed_packet"];
        }

        // Persist sql_output_mode in state so it survives across resume invocations.
        // The password is NOT persisted — it must be supplied on every run (or via
        // the MYSQL_PASSWORD environment variable).
        if ($request->has("sql_output")) {
            $mode = $request->value("sql_output");
            $this->sql_output_mode = $mode;
            $this->state["sql_output"] = $mode;
        } elseif (isset($this->state["sql_output"])) {
            $this->sql_output_mode = $this->state["sql_output"];
        }

        // In stdout mode, SQL goes to STDOUT, so progress/status output must
        // go to STDERR to keep the streams separate.
        if ($this->sql_output_mode === "stdout") {
            $this->output->use_error_stream();
        }

        // MySQL connection parameters for --sql-output=mysql.
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

        $this->save_state($this->state);

        // Password is never persisted — must be supplied each run or via env.
        if ($request->has("mysql_password")) {
            $this->mysql_password = $request->value("mysql_password");
        } elseif (getenv("MYSQL_PASSWORD") !== false) {
            $this->mysql_password = getenv("MYSQL_PASSWORD");
        }

        // Validate mysql mode requirements.
        if ($this->sql_output_mode === "mysql" && empty($this->mysql_database)) {
            throw new InvalidArgumentException(
                "--mysql-database is required when using --sql-output=mysql",
            );
        }

        $this->initialize_tuner($options);

        // Initialize HMAC authentication if a shared secret was provided.
        // When set, every outgoing HTTP request will include X-Auth-Signature,
        // X-Auth-Nonce, and X-Auth-Timestamp headers so the export API can verify
        // the caller without a SECRET_KEY in the URL.
        if (!empty($request->value("secret"))) {
            if (!class_exists(\Reprint\Exporter\Site_Export_HMAC_Client::class)) {
                throw new RuntimeException(
                    'Streaming exporter runtime not found. Run composer install before using --secret.'
                );
            }
            $this->hmac_client = new \Reprint\Exporter\Site_Export_HMAC_Client($request->value("secret"));
        }

        if ($abort) {
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
        switch ($command) {
            case "files-pull":
                // Clear sync progress (cursor, stage, status) and transient
                // files, but keep the local index and downloaded files intact.
                // This way the next `files-pull` sees a completed local index
                // and runs a delta sync rather than re-downloading everything.
                $this->audit_log(
                    "RESTART | Clearing files-pull progress (keeping local index and files)",
                    true,
                );
                $this->reset_state();

                // Merge any pending index updates into the main index before
                // clearing transient state so we don't lose work.
                $this->recover_index_updates();
                $this->index_store->delete_updates_file();
                $this->index_store->clear_updates_state();

                if (file_exists($this->remote_index_file)) {
                    @unlink($this->remote_index_file);
                    $this->audit_log("FILE DELETE | {$this->remote_index_file}");
                }
                if (file_exists($this->download_list_file)) {
                    @unlink($this->download_list_file);
                    $this->audit_log("FILE DELETE | {$this->download_list_file}");
                }
                if (file_exists($this->skipped_download_list_file)) {
                    @unlink($this->skipped_download_list_file);
                    $this->audit_log("FILE DELETE | {$this->skipped_download_list_file}");
                }
                if (file_exists($this->volatile_files_file)) {
                    @unlink($this->volatile_files_file);
                    $this->audit_log("FILE DELETE | {$this->volatile_files_file}");
                }
                $this->state["index"] = $this->default_state()["index"];
                $this->state["fetch"] = $this->default_state()["fetch"];
                $this->state["fetch_skipped"] = $this->default_state()["fetch_skipped"];

                $this->save_state($this->state);
                break;

            case "files-index":
                $this->audit_log(
                    "RESTART | Clearing files-index state",
                    true,
                );
                $this->state["command"] = "files-index";
                $this->state["status"] = null;
                $this->state["stage"] = null;
                $this->state["index"] = $this->default_state()["index"];
                if (file_exists($this->remote_index_file)) {
                    @unlink($this->remote_index_file);
                    $this->audit_log("FILE DELETE | {$this->remote_index_file}");
                }
                $this->save_state($this->state);
                break;

            case "db-pull":
                $this->audit_log(
                    "RESTART | Clearing db-pull state",
                    true,
                );
                $this->reset_state();
                $this->save_state($this->state);

                if ($this->sql_output_mode === "file") {
                    $sql_file = $this->state_dir . "/db.sql";
                    if (file_exists($sql_file)) {
                        unlink($sql_file);
                        $this->audit_log(
                            "FILE DELETE | {$sql_file} | abort db-pull",
                        );
                    }
                }
                $tables_file = $this->state_dir . "/db-tables.jsonl";
                if (file_exists($tables_file)) {
                    unlink($tables_file);
                    $this->audit_log(
                        "FILE DELETE | {$tables_file} | abort db-pull",
                    );
                }
                $domains_file = $this->state_dir . "/.import-domains.json";
                if (file_exists($domains_file)) {
                    unlink($domains_file);
                    $this->audit_log(
                        "FILE DELETE | {$domains_file} | abort db-pull",
                    );
                }
                break;

            case "db-index":
                $this->audit_log(
                    "RESTART | Clearing db-index state",
                    true,
                );
                $this->reset_state();
                $this->save_state($this->state);

                $tables_file = $this->state_dir . "/db-tables.jsonl";
                if (file_exists($tables_file)) {
                    unlink($tables_file);
                    $this->audit_log(
                        "FILE DELETE | {$tables_file} | abort db-index",
                    );
                }
                break;

            case "db-apply":
                $this->audit_log(
                    "RESTART | Clearing db-apply state",
                    true,
                );
                $this->reset_state();
                $this->save_state($this->state);
                break;
        }

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
        $state_command = $this->state["command"] ?? null;
        $current_status =
            $state_command === "files-pull"
                ? $this->state["status"] ?? null
                : null;
        $has_progress =
            $state_command === "files-pull" &&
            $current_status !== null &&
            $current_status !== "complete";

        $this->recover_index_updates();

        // Already completed.
        if ($current_status === "complete") {
            $has_skipped =
                file_exists($this->skipped_download_list_file) &&
                filesize($this->skipped_download_list_file) > 0;

            // --filter=skipped-earlier: download only the files that a prior
            // --filter=essential-files run skipped.  This is the only way to
            // resume downloading those files — no implicit behavior.
            if ($this->filter === "skipped-earlier") {
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
                $this->state["status"] = "in_progress";
                $this->state["stage"] = "fetch-skipped";
                $this->save_state($this->state);
                $this->run_files_sync_pipeline();
                return;
            }

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
            return;
        }

        // --filter=skipped-earlier is only valid after a completed
        // --filter=essential-files run.  It doesn't make sense as a fresh
        // start or resume of an in-progress sync.
        if ($this->filter === "skipped-earlier") {
            throw new RuntimeException(
                "--filter=skipped-earlier was requested but there is no completed sync with skipped files. " .
                    "Run files-pull with --filter=essential-files first.",
            );
        }

        // Filter out "." and ".." explicitly: standard PHP scandir() returns them,
        // but WASM PHP (WordPress Playground) does not, so a `count <= 2` shortcut
        // would mis-classify directories with one or two real entries as empty.
        $is_empty = !is_dir($this->fs_root) || count(array_diff(
            scandir($this->fs_root) ?: [],
            [".", ".."]
        )) === 0;

        // A local index from a prior completed sync means the next run is a
        // delta: re-index the remote, diff against local, fetch only changes.
        $is_delta =
            file_exists($this->index_file) &&
            filesize($this->index_file) > 0;

        // Resuming an in-progress sync
        if ($has_progress) {
            // Don't reset files_imported here — it counts files within
            // the current batch and is only reset when a batch completes
            // (in download_files_from_list). Resetting it on entry would
            // cause the progress counter to dip between pull retries.
            $index_size = $this->index_count();


            $stage = $this->state["stage"] ?? "index";
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
        } else {
            // Starting fresh — validate that target directory is empty.
            // A delta sync ($is_delta) naturally has a non-empty fs root
            // because we put those files there during the initial sync.
            if (!$is_empty && !$is_delta && $this->fs_root_nonempty_behavior === 'error') {
                throw new RuntimeException(
                    "Target directory is not empty and no cursor found. " .
                        "Either clear the target directory, use --abort flag, or use --on-fs-root-nonempty=preserve-local to sync while preserving the existing content.",
                );
            }

            $this->state["command"] = "files-pull";
            $this->state["status"] = "in_progress";
            $this->state["stage"] = "index";
            $this->state["diff"] = $this->default_state()["diff"];
            $this->state["index"] = $this->default_state()["index"];
            $this->state["fetch"] = $this->default_state()["fetch"];
            $this->state["fetch_skipped"] = $this->default_state()["fetch_skipped"];
            $this->save_state($this->state);

            if ($is_delta) {
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
            } else {
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
        }

        $this->state["command"] = "files-pull";
        $this->state["status"] = "in_progress";
        $this->save_state($this->state);

        $this->run_files_sync_pipeline();

        // Pipeline returns early with partial status if interrupted
        if (($this->state["status"] ?? null) === "partial") {
            return;
        }

        $this->state["status"] = "complete";
        $this->save_state($this->state);

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

    /**
     * Shared index → diff → fetch pipeline used by both initial and delta syncs.
     *
     * Reads the current stage from state and runs each stage in sequence.
     * Returns early (with partial status) if any stage doesn't complete.
     */
    private function run_files_sync_pipeline(): void
    {
        $stage = $this->state["stage"] ?? "index";

        if ($stage === "index") {
            $complete = $this->download_remote_index();
            if (!$complete) {
                $this->state["status"] = "partial";
                $this->save_state($this->state);
                return;
            }
            if ($this->follow_symlinks) {
                $this->discover_symlink_targets();
                if ($this->shutdown_requested) {
                    $this->state["status"] = "partial";
                    $this->save_state($this->state);
                    return;
                }
            }
            $this->sort_index_file($this->remote_index_file);
            $this->state["stage"] = "diff";
            $this->state["diff"] = $this->default_state()["diff"];
            if (file_exists($this->download_list_file)) {
                @unlink($this->download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->download_list_file} | clearing before diff stage",
                );
            }
            if (file_exists($this->skipped_download_list_file)) {
                @unlink($this->skipped_download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->skipped_download_list_file} | clearing before diff stage",
                );
            }
            $this->save_state($this->state);
            $stage = "diff";
        }

        if ($stage === "diff") {
            $complete = $this->diff_indexes_and_build_fetch_list();
            if (!$complete) {
                $this->state["status"] = "partial";
                $this->save_state($this->state);
                return;
            }

            $has_downloads =
                file_exists($this->download_list_file) &&
                filesize($this->download_list_file) > 0;
            $has_skipped =
                file_exists($this->skipped_download_list_file) &&
                filesize($this->skipped_download_list_file) > 0;

            // Determine the first fetch stage to run.
            if ($has_downloads) {
                $stage = "fetch";
            } elseif ($has_skipped) {
                $stage = "fetch-skipped";
            } else {
                $stage = null;
            }
            $this->state["stage"] = $stage;
            $this->save_state($this->state);

            // In pull mode, finalize the scanning line with a checkmark
            // and start the download progress on a fresh line.
            if ($has_downloads && $this->output->is_quiet_lifecycle()) {
                $green = "\033[32m";
                $dim = "\033[2m";
                $r = "\033[0m";
                $scanned = number_format($this->index_entries_counted);
                $this->output->clear_progress_line();
                $this->output->print_line("  {$green}✓{$r} Scanned {$dim}— {$scanned} entries{$r}\n");
                $total = $this->count_newlines($this->download_list_file);
                $this->output->set_active_label(null);
                $this->output->show_progress_line(
                    "Downloading — 0 / " . number_format($total) . " files",
                    0.0
                );
            }

            if (!$has_downloads && file_exists($this->download_list_file)) {
                @unlink($this->download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->download_list_file} | no files to fetch",
                );
            }
            if (!$has_skipped && file_exists($this->skipped_download_list_file)) {
                @unlink($this->skipped_download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->skipped_download_list_file} | no skipped files to fetch",
                );
            }
        }

        if ($stage === "fetch") {
            $complete = $this->download_files_from_list(
                $this->download_list_file,
                "fetch",
            );
            if (!$complete) {
                $this->state["status"] = "partial";
                $this->save_state($this->state);
                return;
            }
            $this->state["fetch"] = $this->default_state()["fetch"];

            if (file_exists($this->download_list_file)) {
                @unlink($this->download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->download_list_file} | fetch complete",
                );
            }

            $has_skipped =
                file_exists($this->skipped_download_list_file) &&
                filesize($this->skipped_download_list_file) > 0;

            if ($has_skipped && $this->filter === "essential-files") {
                // Essential files are done — mark the sync as complete.
                // The skipped list stays on disk for a later
                // --filter=skipped-earlier run.
                $this->state["stage"] = null;
                $this->save_state($this->state);
                $this->audit_log(
                    "ESSENTIAL FILES COMPLETE | skipped files listed in {$this->skipped_download_list_file} — run with --filter=skipped-earlier to download them",
                    true,
                );
                $stage = null;
            } elseif ($has_skipped) {
                // Skipped list exists but filter is "none" — download now.
                $this->state["stage"] = "fetch-skipped";
                $this->save_state($this->state);
                $stage = "fetch-skipped";
                $this->audit_log(
                    "ESSENTIAL FILES COMPLETE | transitioning to skipped files",
                    true,
                );
                $this->write_status_file();
            } else {
                $this->state["stage"] = null;
                $this->save_state($this->state);
                $stage = null;
            }
        }

        if ($stage === "fetch-skipped") {
            $complete = $this->download_files_from_list(
                $this->skipped_download_list_file,
                "fetch_skipped",
            );
            if (!$complete) {
                $this->state["status"] = "partial";
                $this->save_state($this->state);
                return;
            }
            $this->state["stage"] = null;
            $this->state["fetch_skipped"] = $this->default_state()["fetch_skipped"];
            $this->save_state($this->state);

            if (file_exists($this->skipped_download_list_file)) {
                @unlink($this->skipped_download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->skipped_download_list_file} | skipped files fetch complete",
                );
            }
        }

        // Recreate intermediate path symlinks so the full symlink chain
        // works locally.  The server discovers these (e.g. /srv/wordpress
        // -> /wordpress) and includes them in the remote index.
        if ($this->follow_symlinks) {
            (new IntermediateSymlinkRecreator(
                $this->local_filesystem(),
                function (string $message, bool $to_console): void {
                    $this->audit_log($message, $to_console);
                },
            ))->recreate($this->remote_index_file);
        }
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
            $this->state["command"] = "files-index";
            $this->state["status"] = "in_progress";
            $this->state["stage"] = "index";
            $this->save_state($this->state);
            $this->audit_log("START files-index", true);
            $this->output->show_lifecycle_line("Starting files-index\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "starting",
                "command" => "files-index",
                "message" => "Starting files-index",
            ], true);
        } else {
            $cursor = $this->state["index"]["cursor"] ?? null;
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

        $this->state["command"] = "files-index";
        $this->save_state($this->state);

        $attempts = 0;
        $last_cursor = $this->state["index"]["cursor"] ?? null;
        while (true) {
            $complete = $this->download_remote_index();
            if ($complete) {
                break;
            }

            if ($this->shutdown_requested) {
                $this->state["status"] = "partial";
                $this->save_state($this->state);
                return;
            }

            $current_cursor = $this->state["index"]["cursor"] ?? null;
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
            $this->discover_symlink_targets();
        }

        $this->sort_index_file($this->remote_index_file);
        $this->state["status"] = "complete";
        $this->state["stage"] = null;
        $this->save_state($this->state);

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
    private function discover_symlink_targets(): void
    {
        $roots = $this->get_root_directories_from_preflight();

        // Collect all indexed directory real paths for containment checks
        $visited = [];
        foreach ($roots as $root) {
            $visited[$root] = true;
        }

        $queue = $this->extract_symlink_dirs_from_index($visited);

        while (!empty($queue)) {
            $dir = array_shift($queue);
            if (isset($visited[$dir])) {
                continue;
            }
            // Skip if this directory is a subdirectory of an already-visited path,
            // since those files were already included in the parent's index.
            $already_covered = false;
            foreach ($visited as $v => $_) {
                if (str_starts_with($dir, $v . "/")) {
                    $already_covered = true;
                    break;
                }
            }
            if ($already_covered) {
                $this->audit_log(
                    "FOLLOW SYMLINK SKIP | {$dir} already covered by a visited parent",
                    true,
                );
                continue;
            }
            $visited[$dir] = true;

            $this->audit_log(
                "FOLLOW SYMLINK | indexing target directory: {$dir}",
                true,
            );
            $this->output->show_lifecycle_line("Following symlink target: {$dir}\n");
            $this->output_progress([
                "type" => "symlink_follow",
                "directory" => $dir,
                "message" => "Following symlink target: {$dir}",
            ], true);

            // Reset the index cursor so download_remote_index starts fresh
            // for this directory, but appends to the existing index file.
            // Note we are not losing the previous cursor position. This code
            // runs only after the previous directory was fully indexed so
            // we won't need any prior cursor information again.
            $this->state["index"]["cursor"] = null;
            $this->save_state($this->state);

            $attempts = 0;
            $last_cursor = null;
            while (true) {
                try {
                    $complete = $this->download_remote_index($dir);
                } catch (RuntimeException $e) {
                    // We won't be able to follow every symlink. If
                    // the response seems like the remote server rejecting
                    // our attempt to index this directory, log a warning
                    // and skip to the next directory instead of crashing.
                    $msg = $e->getMessage();
                    if (
                        strpos($msg, "HTTP error 4") !== false ||
                        strpos($msg, "dir_outside_root") !== false ||
                        strpos($msg, "outside of allowed roots") !== false
                    ) {
                        $this->audit_log(
                            "FOLLOW SYMLINK SKIP | server rejected {$dir}: " .
                                substr($msg, 0, 200),
                            true,
                        );
                        $this->output->show_lifecycle_line("  Skipped (server rejected): {$dir}\n");
                        $this->output_progress([
                            "type" => "symlink_follow_rejected",
                            "directory" => $dir,
                            "message" => "Skipped (server rejected): {$dir}",
                        ], true);
                        continue 2;
                    }

                    // Still throw all the other errors.
                    throw $e;
                }
                if ($complete) {
                    break;
                }

                if ($this->shutdown_requested) {
                    return;
                }

                $current_cursor = $this->state["index"]["cursor"] ?? null;
                if ($current_cursor === $last_cursor) {
                    throw new RuntimeException(
                        "files-index (symlink follow) made no progress (cursor unchanged)",
                    );
                }
                $last_cursor = $current_cursor;

                $attempts++;
                if ($attempts > 10_000) {
                    // @TODO: Consider a configurable maximum attempts for really large sites that
                    //        require more than 10,000 requests to index.
                    throw new RuntimeException(
                        "files-index (symlink follow) exceeded maximum attempts",
                    );
                }
            }

            // Scan newly added entries for more symlink targets
            $new_targets = $this->extract_symlink_dirs_from_index($visited);
            foreach ($new_targets as $target) {
                if (!isset($visited[$target])) {
                    $queue[] = $target;
                }
            }
        }
    }

    /**
     * Scan the remote index file for symlink entries whose targets are
     * directories not already in $visited.  Returns an array of real paths.
     *
     * Skips entries marked as "intermediate" — those are path-component
     * symlinks (e.g. /srv/wordpress -> /wordpress) emitted by the server's
     * discover_path_symlinks() for local recreation only, not for indexing.
     */
    private function extract_symlink_dirs_from_index(array $visited): array
    {
        $targets = [];
        if (!file_exists($this->remote_index_file)) {
            return $targets;
        }

        $handle = fopen($this->remote_index_file, "r");
        if (!$handle) {
            return $targets;
        }

        while (($line = fgets($handle)) !== false) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }
            if (($entry["type"] ?? "") !== "link") {
                continue;
            }
            if (!empty($entry["intermediate"])) {
                continue;
            }
            $target_encoded = $entry["target"] ?? null;
            if (!is_string($target_encoded) || $target_encoded === "") {
                continue;
            }
            $target = base64_decode($target_encoded);
            if ($target === false || $target === "") {
                continue;
            }

            // If we've seen this target already, we can move on
            // to the next one.
            if (isset($visited[$target])) {
                continue;
            }

            // Check containment: skip if already under a visited root
            $contained = false;
            foreach ($visited as $root => $_) {
                if (str_starts_with($target, $root . "/")) {
                    $contained = true;
                    break;
                }
            }
            if ($contained) {
                continue;
            }

            $targets[] = $target;
        }
        fclose($handle);

        return array_values(array_unique($targets));
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
        $state_command = $this->state["command"] ?? null;
        $sql_file = $this->state_dir . "/db.sql";

        $has_progress =
            $state_command === "db-pull" &&
            ($this->state["status"] ?? null) === "in_progress";
        $current_status =
            $state_command === "db-pull"
                ? $this->state["status"] ?? null
                : null;

        // Check if already completed
        if ($current_status === "complete") {
            if ($this->sql_output_mode === "file") {
                $sql_exists = file_exists($sql_file);
                if ($sql_exists) {
                    throw new RuntimeException(
                        "db-pull already completed and db.sql exists. Use --abort flag to start over.",
                    );
                } else {
                    throw new RuntimeException(
                        "db-pull marked complete but db.sql is missing. Use --abort flag to re-sync.",
                    );
                }
            } else {
                throw new RuntimeException(
                    "db-pull already completed. Use --abort flag to start over.",
                );
            }
        }

        if ($has_progress) {
            $stage = $this->state["stage"] ?? "db-index";
            $this->audit_log(
                sprintf(
                    "RESUME db-pull | stage=%s | cursor=%s",
                    $stage,
                    !empty($this->state["cursor"])
                        ? substr($this->state["cursor"], 0, 20) . "..."
                        : "none",
                ),
                true,
            );

            $this->output->show_lifecycle_line("Resuming db-pull (stage: {$stage})\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "resuming",
                "command" => "db-pull",
                "stage" => $stage,
                "message" => "Resuming db-pull (stage: {$stage})",
            ], true);
        } else {
            // Starting fresh
            $this->state["command"] = "db-pull";
            $this->state["status"] = "in_progress";
            $this->state["cursor"] = null;
            $this->state["stage"] = "db-index";
            $this->state["diff"] = $this->default_state()["diff"];
            $this->state["db_index"] = $this->default_state()["db_index"];
            $this->save_state($this->state);

            $this->audit_log("START db-pull", true);

            $this->output->show_lifecycle_line("Starting db-pull\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "starting",
                "command" => "db-pull",
                "message" => "Starting db-pull",
            ], true);
        }

        $this->state["command"] = "db-pull";
        $this->save_state($this->state);

        // Stage 1: db-index (table metadata for progress estimation)
        $stage = $this->state["stage"] ?? "db-index";
        if ($stage === "db-index") {
            $this->output_progress([
                "status" => "starting",
                "phase" => "db-index",
                "message" => "Downloading table metadata",
            ]);

            $this->download_db_index();

            // Timeout during db-index — state already saved, exit partial.
            if (($this->state["status"] ?? null) === "partial") {
                return;
            }

            $tables = (int) ($this->state["db_index"]["tables"] ?? 0);
            $this->audit_log(
                sprintf("db-pull db-index stage complete: %d tables", $tables),
            );

            // Transition to sql stage
            $this->state["stage"] = "sql";
            $this->state["cursor"] = null;
            $this->save_state($this->state);
        }

        // Stage 2: SQL dump download
        $this->output_progress([
            "status" => "starting",
            "phase" => "sql",
            "message" => "Downloading SQL dump",
        ]);

        $this->download_sql();

        // Timeout during SQL download — state already saved, exit partial.
        if (($this->state["status"] ?? null) === "partial") {
            return;
        }

        // Mark as complete
        $this->state["status"] = "complete";
        $this->save_state($this->state);

        $this->audit_log("db-pull complete", true);

        $this->output->show_lifecycle_line("db-pull complete\n");
        if ($this->sql_output_mode === "file") {
            $this->output->show_lifecycle_line("SQL file: {$sql_file}\n");
        } elseif ($this->sql_output_mode === "stdout") {
            $this->output->show_lifecycle_line("SQL written to stdout\n");
        } elseif ($this->sql_output_mode === "mysql") {
            $this->output->show_lifecycle_line("SQL imported into {$this->mysql_database}\n");
        }
        $this->output->show_lifecycle_line("Audit log: {$this->audit_log}\n");
        $db_sync_complete = [
            "type" => "lifecycle",
            "event" => "complete",
            "command" => "db-pull",
            "sql_output_mode" => $this->sql_output_mode,
            "audit_log" => $this->audit_log,
            "message" => "db-pull complete",
        ];
        if ($this->sql_output_mode === "file") {
            $db_sync_complete["sql_file"] = $sql_file;
        }
        $this->output_progress($db_sync_complete, true);
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

    /**
     * If --new-site-url is set, derive the source origin from the export URL
     * and append implicit --rewrite-url mappings for both HTTP and HTTPS
     * variants of the old URL to $options. The new URL is used verbatim.
     */
    private function resolve_new_site_url_option(array &$options): void
    {
        $options = NewSiteUrlResolver::resolve_options($options, $this->remote_url);
    }

    private function create_target_db_apply_connection(array $options): array
    {
        $target_engine = strtolower((string) ($options["target_engine"] ?? "mysql"));
        if (!in_array($target_engine, ["mysql", "sqlite"], true)) {
            throw new InvalidArgumentException(
                "Invalid --target-engine value: {$target_engine}. Valid engines: mysql, sqlite.",
            );
        }

        if ($target_engine === "sqlite") {
            $target_path = $options["target_sqlite_path"] ?? null;
            $target_db = $options["target_db"] ?? "sqlite_database";

            if (!$target_path) {
                $content_dir = rtrim(
                    $this->state["preflight"]["data"]["database"]["wp"]["paths_urls"]["content_dir"] ?? "",
                    "/",
                );
                if (!$content_dir) {
                    throw new InvalidArgumentException(
                        "--target-sqlite-path option is required but was missing.",
                    );
                }
                $target_path = $this->get_filesystem_root_path() . $content_dir . '/database/.ht.sqlite';
                $this->audit_log("DB-APPLY | defaulting SQLite path to: {$target_path}");
                $this->output->show_lifecycle_line("SQLite path: {$target_path}\n");
            }

            // Persist target database configuration for apply-runtime.
            $this->state["apply"]["target_engine"] = "sqlite";
            $this->state["apply"]["target_db"] = $target_db;
            $this->state["apply"]["target_sqlite_path"] = $target_path;

            return [
                TargetDatabaseConnectionFactory::sqlite($target_path, $target_db),
                sprintf(
                    "engine=sqlite path=%s db=%s",
                    $target_path,
                    $target_db,
                ),
            ];
        }

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

        // Persist target database configuration for apply-runtime.
        $this->state["apply"]["target_engine"] = "mysql";
        $this->state["apply"]["target_db"] = $target_db;
        $this->state["apply"]["target_host"] = $target_host;
        $this->state["apply"]["target_port"] = $target_port;
        $this->state["apply"]["target_user"] = $target_user;
        $this->state["apply"]["target_pass"] = $target_pass;

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

    // =========================================================================
    // db-apply: Apply SQL dump to a target MySQL database with URL rewriting
    // =========================================================================

    public function run_db_apply(array $options): void
    {
        $sql_file = $this->state_dir . "/db.sql";
        if (!file_exists($sql_file)) {
            throw new RuntimeException(
                "db.sql not found in {$this->state_dir}. Run db-pull first.",
            );
        }

        // If --new-site-url is provided, derive the source origin from the
        // export URL and add an implicit --rewrite-url mapping.
        $this->resolve_new_site_url_option($options);

        // Parse URL mapping
        $url_mapping = [];
        if (!empty($options["rewrite_url"])) {
            foreach ($options["rewrite_url"] as $pair) {
                $url_mapping[$pair[0]] = $pair[1];
            }
        }

        // Show discovered domains if available
        $domains_file = $this->state_dir . "/.import-domains.json";
        if (file_exists($domains_file)) {
            $domains = json_decode(file_get_contents($domains_file), true);
            if (is_array($domains) && !empty($domains)) {
                $this->audit_log(
                    sprintf("DISCOVERED DOMAINS | %s", implode(", ", $domains)),
                    false,
                );
                $this->output->show_lifecycle_line("Discovered domains in SQL dump:\n");
                foreach ($domains as $domain) {
                    $mapped = isset($url_mapping[$domain]) ? " => {$url_mapping[$domain]}" : " (not mapped)";
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
        }

        // Check state for resume
        $state_command = $this->state["command"] ?? null;
        $current_status = $state_command === "db-apply" ? ($this->state["status"] ?? null) : null;

        if ($current_status === "complete") {
            throw new RuntimeException(
                "db-apply already completed. Use --abort flag to re-run.",
            );
        }

        $apply_state = $this->state["apply"] ?? $this->default_state()["apply"];
        $statements_executed = (int) ($apply_state["statements_executed"] ?? 0);
        $bytes_read = (int) ($apply_state["bytes_read"] ?? 0);
        $is_resume = $current_status === "in_progress" && $statements_executed > 0;

        if ($is_resume) {
            $this->audit_log(
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
            $this->state["command"] = "db-apply";
            $this->state["status"] = "in_progress";
            $this->state["apply"] = $this->default_state()["apply"];
            if (!empty($url_mapping)) {
                $this->state["apply"]["rewrite_url"] = $url_mapping;
            }
            $this->save_state($this->state);
            $statements_executed = 0;
            $bytes_read = 0;

            $this->audit_log("START db-apply", true);
            $this->output->show_lifecycle_line("Starting db-apply\n");
            $this->output_progress([
                "type" => "lifecycle",
                "event" => "starting",
                "command" => "db-apply",
                "message" => "Starting db-apply",
            ], true);
        }

        // On resume, use the persisted URL mapping if none provided on CLI
        if (empty($url_mapping) && !empty($apply_state["rewrite_url"])) {
            $url_mapping = $apply_state["rewrite_url"];
        }

        // Set up SQL statement rewriter if we have URL mappings
        $stmt_rewriter = null;
        if (!empty($url_mapping)) {
            $table_prefix = $this->state["preflight"]["data"]["database"]["wp"]["table_prefix"] ?? 'wp_';
            $stmt_rewriter = new SqlStatementRewriter(
                new StructuredDataUrlRewriter($url_mapping),
                $table_prefix,
            );
            $this->audit_log(
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
        }

        [$pdo, $connection_label] = $this->create_target_db_apply_connection($options);
        $sqlite_prepared_pdo = null;
        if (
            strtolower((string) ($options["target_engine"] ?? "mysql")) === "sqlite"
            && method_exists($pdo, 'get_connection')
        ) {
            $sqlite_prepared_pdo = $pdo->get_connection()->get_pdo();
            // These are connection-local import hints. Avoid journal/sync/locking
            // PRAGMAs because they alter durability or observable database state.
            $sqlite_prepared_pdo->exec('PRAGMA temp_store = MEMORY');
            $sqlite_prepared_pdo->exec('PRAGMA cache_size = -32768');
            $this->audit_log(
                'SQLite db-apply PRAGMAs | temp_store=MEMORY | cache_size=32768 KiB',
                false,
            );
        }
        $query_executor = new DbApplyQueryExecutor($pdo, $stmt_rewriter, $sqlite_prepared_pdo);

        $this->audit_log(
            "CONNECTED | {$connection_label}",
            false,
        );

        (new SqlDumpApplier(
            function (): bool {
                return $this->shutdown_requested;
            },
            function (array $state): void {
                $this->save_state($state);
            },
            function (string $message, bool $to_console): void {
                $this->audit_log($message, $to_console);
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
            function (PDO $pdo): array {
                return $this->deactivate_host_plugins($pdo);
            },
            function (PDO $pdo, string $new_site_url): array {
                return $this->deactivate_path_incompatible_plugins($pdo, $new_site_url);
            },
        ))->apply(
            $this->state,
            [
                "sql_file" => $sql_file,
                "state_dir" => $this->state_dir,
                "statements_executed" => $statements_executed,
                "bytes_read" => $bytes_read,
                "new_site_url" => (string) ($options["new_site_url"] ?? ""),
            ],
            $query_executor,
            $pdo,
        );
    }

    /**
     * Deactivate host-specific plugins in the target database.
     *
     * Looks at the detected webhost's paths_to_remove for entries under
     * wp-content/plugins/ and removes matching basenames from the
     * active_plugins option. Runs at the end of db-apply while the PDO
     * connection is still open.
     *
     * @return string[]  Plugin basenames actually removed.
     */
    private function deactivate_host_plugins(PDO $pdo): array
    {
        $webhost = $this->state["webhost"] ?? "other";
        $analyzer = host_analyzer_for($webhost);
        $preflight_data = $this->state["preflight"]["data"] ?? [];
        $manifest = $analyzer->analyze($preflight_data);

        return ActivePluginDeactivator::deactivate_for_removed_paths(
            $pdo,
            $manifest->paths_to_remove,
            $this->db_apply_table_prefix(),
            function (string $message): void {
                $this->audit_log($message);
            },
        );
    }

    /**
     * Deactivate plugins whose URL builders break when the new site URL
     * has a non-/ path segment.
     *
     * page-optimize's concat-css/js builds asset URLs by concatenating
     * `$siteurl . $path`, which produces doubled prefixes (e.g.
     * `/scope:abc/scope:abc/wp-content/...`) when `$siteurl` already
     * carries a path component like WordPress Playground's
     * `/scope:<slug>/` iframe scope.
     *
     * wpcomsh has the same shape but lives on WP Cloud, where
     * WpcloudHostAnalyzer's paths_to_remove already feeds it through
     * deactivate_host_plugins().
     *
     * Skipped when the new site URL is empty or has no path beyond `/`.
     *
     * @return string[]  Plugin basenames actually removed.
     */
    private function deactivate_path_incompatible_plugins(PDO $pdo, string $new_site_url): array
    {
        return ActivePluginDeactivator::deactivate_path_incompatible(
            $pdo,
            $new_site_url,
            $this->db_apply_table_prefix(),
            function (string $message): void {
                $this->audit_log($message);
            },
        );
    }

    private function db_apply_table_prefix(): string
    {
        $preflight_data = $this->state["preflight"]["data"] ?? [];
        return $preflight_data["database"]["wp"]["table_prefix"] ?? 'wp_';
    }

    /**
     * Command: db-index
     *
     * Streams table metadata (name/rows/size) for planning and diagnostics.
     */
    public function run_db_index(): void
    {
        $state_command = $this->state["command"] ?? null;
        $tables_file = $this->state_dir . "/db-tables.jsonl";

        $has_cursor =
            $state_command === "db-index" &&
            !empty($this->state["cursor"] ?? null);
        $current_status =
            $state_command === "db-index"
                ? $this->state["status"] ?? null
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
            $this->state["command"] = "db-index";
            $this->state["status"] = "in_progress";
            $this->state["cursor"] = null;
            $this->state["stage"] = null;
            $this->state["diff"] = $this->default_state()["diff"];
            $this->state["db_index"] = $this->default_state()["db_index"];
            $this->save_state($this->state);

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
                    substr($this->state["cursor"], 0, 20) . "...",
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

        $this->state["command"] = "db-index";
        $this->save_state($this->state);

        $this->download_db_index();

        $this->state["status"] = "complete";
        $this->save_state($this->state);

        $tables = (int) ($this->state["db_index"]["tables"] ?? 0);
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
        ?array $post_data,
        ?string $cursor,
        string $state_key = "fetch"
    ): bool {
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
            function (array $state): void {
                $this->save_state($state);
            },
            function (array $chunk, StreamingContext $context): void {
                $this->handle_metadata_chunk($chunk, $context);
            },
            function (array $chunk, StreamingContext $context): void {
                $this->handle_file_chunk($chunk, $context);
            },
            function (array $chunk): void {
                $this->handle_directory_chunk($chunk);
            },
            function (array $chunk): void {
                $this->handle_symlink_chunk($chunk);
            },
            function (
                array $chunk,
                string $phase,
                StreamingContext $context
            ): void {
                $this->handle_error_chunk($chunk, $phase, $context);
            },
            function (array $chunk, string $phase): void {
                $this->handle_progress($chunk, $phase);
            },
            function (array $progress): void {
                $this->output_progress($progress, true);
            },
            function (
                string $phase,
                ?string $cursor_before,
                ?string $cursor_after
            ): void {
                $this->assert_can_retry_consecutive_timeout(
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
            $this->state,
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
    private function download_remote_index(?string $list_dir_override = null): bool
    {
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
            function (array $state): void {
                $this->save_state($state);
            },
            function (array $chunk, StreamingContext $context): void {
                $this->handle_metadata_chunk($chunk, $context);
            },
            function (
                array $chunk,
                string $phase,
                StreamingContext $context
            ): void {
                $this->handle_error_chunk($chunk, $phase, $context);
            },
            function (array $chunk, string $phase): void {
                $this->handle_progress($chunk, $phase);
            },
            function (int $entries_counted): void {
                $this->show_remote_index_progress($entries_counted);
            },
            function (
                string $phase,
                ?string $cursor_before,
                ?string $cursor_after
            ): void {
                $this->assert_can_retry_consecutive_timeout(
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
            $this->state,
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
    private function diff_indexes_and_build_fetch_list(): bool
    {
        $builder = new FetchListBuilder(
            $this->index_store,
            function (string $path): void {
                $this->delete_local_file_path($path);
            },
            function (string $path): ?string {
                return $this->should_skip_for_preserve_local($path);
            },
            function (string $path): void {
                $this->emit_skip_progress($path);
            },
            function (array $diff): void {
                $this->state["diff"] = $diff;
                $this->save_state($this->state);
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
            $this->state["diff"] ?? [],
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
        string $list_file,
        string $state_key
    ): bool {
        $fetch_state = $this->state[$state_key] ?? $this->default_state()[$state_key];
        $executor = new FetchListExecutor(
            $this->download_list_total,
            $this->download_list_done,
            $this->files_imported,
            $this->get_max_request_bytes(),
            function (string $batch_file, $cursor, string $state_key): bool {
                $post_data = [
                    "file_list" => new CURLFile(
                        $batch_file,
                        "application/json",
                        "file-list.json",
                    ),
                ];

                return $this->download_file_fetch($post_data, $cursor, $state_key);
            },
            function (string $state_key, array $fetch_state): void {
                $this->state[$state_key] = $fetch_state;
                $this->save_state($this->state);
            },
            function (string $message): void {
                $this->audit_log($message);
            },
        );

        try {
            return $executor->run(
                $list_file,
                $state_key,
                $fetch_state,
                $this->default_state()[$state_key],
            );
        } finally {
            $this->download_list_total = $executor->download_list_total();
            $this->download_list_done = $executor->download_list_done();
            $this->files_imported = $executor->files_imported();
        }
    }

    /**
     * Builds a JSON batch file listing the next set of paths to download.
     *
     * Reads from the download list (.import-download-list.jsonl) starting at
     * $offset, accumulating paths into a JSON array until the batch approaches
     * 80% of the server's max request size.  Always includes at least one path,
     * even if it alone exceeds the limit.
     *
     * The batch file is written to a temp file and intended to be uploaded as
     * the request body for the file_fetch endpoint.
     *
     * @param string $list_file Path to the JSONL download list.
     * @param int    $offset    Byte offset into the download list file.
     * @return array{file: string, offset: int, next_offset: int, entries: int}|null
     *         The temp file path, byte offsets, and entry count, or null if
     *         no paths remain.
     */
    private function prepare_fetch_batch(string $list_file, int $offset): ?array
    {
        return DownloadList::prepare_batch(
            $list_file,
            $offset,
            $this->get_max_request_bytes(),
        );
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

    /**
     * Delete a local file path safely under the fs root.
     */
    private function delete_local_file_path(string $path): void
    {
        if ($path === "") {
            return;
        }
        try {
            $local_path = $this->remote_path_to_local_path_within_import_root($path);
        } catch (RuntimeException $e) {
            $this->audit_log(
                "Security: refusing to delete invalid path '{$path}': " . $e->getMessage(),
                true,
            );
            return;
        }
        if (!file_exists($local_path) && !is_link($local_path)) {
            return;
        }

        if ($this->remove_local_path_without_following_symlinks($local_path)) {
            $this->audit_log("Deleted: {$path}", false);
            return;
        }

        $this->audit_log("Failed to delete: {$path}", true);
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
     * Remove a local path recursively without traversing symlink targets.
     *
     * Symlinks are always unlinked as links. Directories are traversed
     * depth-first.
     */
    private function remove_local_path_without_following_symlinks(
        string $local_path
    ): bool {
        return $this->local_filesystem()->remove_path_without_following_symlinks($local_path);
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
    private function download_sql(): void
    {
        (new SqlDownloader(
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
            function (array $state): void {
                $this->save_state($state);
            },
            function (int $sql_bytes_written): void {
                $db_bytes_est = (int) ($this->state["db_index"]["bytes"] ?? 0);
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
            function (array $chunk, string $phase): void {
                $this->handle_progress($chunk, $phase);
            },
            function (
                array $chunk,
                string $phase,
                StreamingContext $context
            ): void {
                $this->handle_error_chunk($chunk, $phase, $context);
            },
            function (array $progress): void {
                $this->output_progress($progress, true);
            },
            function (): void {
                $this->save_state($this->state);
                exit(0);
            },
            function (
                string $phase,
                ?string $cursor_before,
                ?string $cursor_after
            ): void {
                $this->assert_can_retry_consecutive_timeout(
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
            $this->state,
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
    }

    /**
     * Download table stats from the db_index endpoint.
     */
    private function download_db_index(): void
    {
        $tables_file = $this->state_dir . "/db-tables.jsonl";

        (new DbIndexDownloader(
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
            function (): bool {
                return $this->shutdown_requested;
            },
            function (array $chunk, string $phase): void {
                $this->handle_progress($chunk, $phase);
            },
            function (
                array $chunk,
                string $phase,
                StreamingContext $context
            ): void {
                $this->handle_error_chunk($chunk, $phase, $context);
            },
            function (array $progress): void {
                $this->output_progress($progress, true);
            },
            function (
                string $phase,
                ?string $cursor_before,
                ?string $cursor_after
            ): void {
                $this->assert_can_retry_consecutive_timeout(
                    $phase,
                    $cursor_before,
                    $cursor_after,
                );
            },
            function (string $endpoint, float $wall_time, array $stats): void {
                $this->finalize_tuned_request($endpoint, $wall_time, $stats);
            },
            function (array $state): void {
                $this->save_state($state);
            },
            function (string $message, bool $to_console): void {
                $this->audit_log($message, $to_console);
            },
        ))->download($this->state, $tables_file);
    }


    /**
     * Map an absolute remote symlink target to the local fs-root mirror when possible.
     *
     * Example:
     *
     * Source site:
     *
     *   /srv/source-site/
     *   `-- wp-content/
     *       `-- themes/
     *           `-- indice -> /tmp/e2e-shared-themes/pub/indice
     *
     *   /tmp/e2e-shared-themes/pub/indice/
     *   |-- style.css
     *   `-- index.php
     *
     * Local import state:
     *
     *   <state-dir>/fs-root/
     *   |-- tmp/e2e-shared-themes/pub/indice/
     *   |   |-- style.css
     *   |   `-- index.php
     *   `-- srv/source-site/
     *       `-- wp-content/themes/
     *
     * Without this mapping, the symlink would point at /tmp/e2e-shared-themes/pub/indice
     * (which does not exist on the local machine, or worse, exists with unrelated content).
     * With this mapping, the symlink is rewritten to a relative path that resolves to the
     * mirrored local copy under fs-root.
     */
    private function map_absolute_symlink_target_for_local_mirror(
        string $path,
        string $local_path,
        string $target
    ): string {
        if (!str_starts_with($target, "/")) {
            return $target;
        }

        $root = $this->get_filesystem_root_path();
        $normalized_target = normalize_path($target);

        // Already points inside fs-root, keep as-is.
        if (path_is_within_root($normalized_target, $root)) {
            return $target;
        }

        // Only rewrite when symlink-following is enabled and the target path
        // was actually indexed from the source.
        if (
            !$this->follow_symlinks ||
            !$this->remote_index_contains_path_prefix($normalized_target)
        ) {
            return $target;
        }

        $mapped_absolute = $root . $normalized_target;
        $mapped_relative = PathUtils::relative_path(
            dirname($local_path),
            $mapped_absolute
        );

        $this->audit_log(
            "SYMLINK TARGET REMAP | {$path}: {$target} -> {$mapped_relative}",
            false,
        );

        return $mapped_relative;
    }

    private function remote_index_contains_path_prefix(string $path): bool
    {
        if ($this->remote_index_prefix_matcher === null) {
            $this->remote_index_prefix_matcher = new IndexPathPrefixMatcher($this->remote_index_file);
        }

        return $this->remote_index_prefix_matcher->contains($path);
    }

    /**
     * Return canonical fs root path, creating it if it doesn't exist.
     */
    private function get_filesystem_root_path(): string
    {
        return $this->local_filesystem()->filesystem_root_path();
    }


    /**
     * Resolve a remote absolute path into a local path under the fs root.
     *
     * Maps a remote absolute path (e.g. "/wp-content/uploads/photo.jpg") to a
     * local path under the import fs root. Performs symlink traversal security
     * checks to prevent directory traversal attacks that could write files
     * outside the import root.
     */
    private function remote_path_to_local_path_within_import_root(
        string $path
    ): string {
        return $this->local_filesystem()->local_path_for_remote_path($path);
    }

    /**
     * Handle a metadata chunk from multipart response.
     */
    private function handle_metadata_chunk(
        array $chunk,
        StreamingContext $context
    ): void {
        $headers = $chunk["headers"];
        $filesystem_root = base64_decode($headers["x-filesystem-root"] ?? "", true);

        if ($filesystem_root) {
            $context->filesystem_root = $filesystem_root;
            $this->audit_log("Filesystem root: {$filesystem_root}", false);
        }
    }

    /**
     * Handle a file chunk from multipart response.
     */
    private function handle_file_chunk(
        array $chunk,
        StreamingContext $context
    ): void {
        $applier = new FileChunkApplier(
            $this->files_imported,
            function (string $path): string {
                return $this->remote_path_to_local_path_within_import_root($path);
            },
            function (string $local_path): bool {
                return $this->remove_local_path_without_following_symlinks($local_path);
            },
            function (string $dir): void {
                $this->ensure_directory_path($dir);
            },
            function (string $message, bool $to_console): void {
                $this->audit_log($message, $to_console);
            },
            function (string $path, int $file_size): void {
                $this->show_file_fetch_progress($path, $file_size);
            },
            function (string $path): void {
                $this->emit_skip_progress($path);
            },
            function (string $path, int $ctime, int $size, string $type): void {
                $this->upsert_index_entry($path, $ctime, $size, $type);
            },
            function (string $path): void {
                $this->clear_volatile_file($path);
            },
            function (?string $path, ?int $bytes): void {
                $this->state["current_file"] = $path;
                $this->state["current_file_bytes"] = $bytes;
            },
        );

        try {
            $applier->handle($chunk, $context);
        } finally {
            $this->files_imported = $applier->files_imported();
        }
    }

    private function show_file_fetch_progress(string $path, int $file_size): void
    {
        $files_done = ($this->download_list_done ?? 0) + $this->files_imported;
        $files_total = $this->download_list_total;
        $file_fraction = ($files_total !== null && $files_total > 0)
            ? $files_done / $files_total
            : null;
        $file_progress_message = $files_total !== null
            ? sprintf("Downloading — %s / %s files", number_format($files_done), number_format($files_total))
            : sprintf("Downloading — %s files", number_format($files_done));
        $this->output->show_progress_line($file_progress_message, $file_fraction);
        $progress_record = [
            "type" => "file_progress",
            "files_done" => $files_done,
            "path" => $path,
            "size" => $file_size,
            "message" => $file_progress_message,
        ];
        if ($this->download_list_total !== null) {
            $progress_record["files_total"] = $this->download_list_total;
        }
        $this->output_progress($progress_record);
    }

    /**
     * Check whether any component of the path (between the filesystem root
     * and the target) is a symlink.  In preserve-local mode this is used
     * to prevent creating new content through symlinked directories — their
     * contents belong to shared hosting infrastructure and must not be
     * modified.
     */
    private function should_skip_for_preserve_local(string $path): ?string
    {
        if ($this->fs_root_nonempty_behavior !== 'preserve-local') {
            return null;
        }

        $local_path = $this->remote_path_to_local_path_within_import_root($path);

        // Skip if anything already exists at this path — regular file, symlink
        // (even to a file), or directory.  This preserves hosting symlinks like
        // wp-load.php -> __wp__/wp-load.php and drop-in symlinks like
        // object-cache.php -> ../../wordpress/drop-ins/...
        if (file_exists($local_path) || is_link($local_path)) {
            return "PRESERVE-LOCAL skip file (exists): {$path}";
        }

        // Skip if parent directory is not writable or if any directory component
        // in the path is a symlink.  We never create new files through symlinks —
        // the symlink and its target contents are shared hosting infrastructure.
        $dir = dirname($local_path);
        if (is_dir($dir) && !is_writable($dir)) {
            return "PRESERVE-LOCAL skip file (dir not writable): {$path}";
        }
        if ($this->path_traverses_symlink($dir)) {
            return "PRESERVE-LOCAL skip file (symlink in path): {$path}";
        }

        return null;
    }

    private function path_traverses_symlink(string $path): bool
    {
        return $this->local_filesystem()->path_traverses_symlink($path);
    }

    /**
     * Ensure a directory path exists, removing any files that block it.
     *
     * @param string $dir Directory path to ensure
     * @throws RuntimeException if directory cannot be created or is outside allowed path
     */
    private function ensure_directory_path(string $dir): void
    {
        $this->local_filesystem()->ensure_directory_path($dir);
    }

    /**
     * Handle a directory chunk (create empty directory).
     */
    private function handle_directory_chunk(array $chunk): void
    {
        $applier = new DirectoryChunkApplier(
            $this->fs_root_nonempty_behavior === 'preserve-local',
            function (string $path): string {
                return $this->remote_path_to_local_path_within_import_root($path);
            },
            function (string $path): bool {
                return $this->path_traverses_symlink($path);
            },
            function (string $local_path): bool {
                return $this->remove_local_path_without_following_symlinks($local_path);
            },
            function (string $dir): void {
                $this->ensure_directory_path($dir);
            },
            function (string $message, bool $to_console): void {
                $this->audit_log($message, $to_console);
            },
            function (string $path): void {
                $this->emit_skip_progress($path);
            },
            function (string $path, int $ctime, int $size, string $type): void {
                $this->upsert_index_entry($path, $ctime, $size, $type);
            },
        );

        $applier->handle($chunk);
    }

    /**
     * Recreates a symlink from the export stream in the local filesystem.
     *
     * Decodes the base64-encoded path and target from the chunk headers,
     * validates that the target stays within the filesystem root (preventing
     * directory traversal), then creates the symlink.  Failures are logged
     * to the audit log and reported as symlink_error progress events — they
     * do not halt the import.
     *
     * @param array $chunk Multipart chunk with x-symlink-path, x-symlink-target,
     *                     and x-symlink-ctime headers (all base64-encoded).
     */
    private function handle_symlink_chunk(array $chunk): void
    {
        $applier = new SymlinkChunkApplier(
            $this->fs_root_nonempty_behavior === 'preserve-local',
            function (string $path): string {
                return $this->remote_path_to_local_path_within_import_root($path);
            },
            function (string $path, string $local_path, string $target): string {
                return $this->map_absolute_symlink_target_for_local_mirror(
                    $path,
                    $local_path,
                    $target,
                );
            },
            function (): string {
                return $this->get_filesystem_root_path();
            },
            function (string $path): bool {
                return $this->path_traverses_symlink($path);
            },
            function (string $local_path): bool {
                return $this->remove_local_path_without_following_symlinks($local_path);
            },
            function (string $dir): void {
                $this->ensure_directory_path($dir);
            },
            function (string $message, bool $to_console): void {
                $this->audit_log($message, $to_console);
            },
            function (string $path): void {
                $this->emit_skip_progress($path);
            },
            function (string $path, int $ctime, int $size, string $type): void {
                $this->upsert_index_entry($path, $ctime, $size, $type);
            },
            function (array $progress): void {
                $this->output_progress($progress);
            },
        );

        $applier->handle($chunk);
    }

    /**
     * Handle an error chunk from the server.
     */
    private function handle_error_chunk(
        array $chunk,
        string $phase,
        StreamingContext $context
    ): void {
        $body = $chunk["body"] ?? "";
        $data = json_decode($body, true);
        if (!$data) {
            $this->audit_log(
                "REMOTE ERROR | phase={$phase} | raw (JSON decode failed): " .
                    substr($body, 0, 500),
                true,
            );
            return;
        }

        $error_type = $data["error_type"] ?? "unknown";
        $path = $data["path"] ?? "";
        $message = $data["message"] ?? "Error";

        $this->audit_log(
            "REMOTE ERROR | phase={$phase} | type={$error_type} | path={$path} | message={$message}",
            true,
        );

        $is_file_error = in_array(
            $error_type,
            ["file_changed", "file_missing", "file_open", "file_read"],
            true,
        );
        if ($path !== "" && $is_file_error) {
            $local_path = $this->fs_root . $path;
            if ($context->file_handle && $context->file_path === $local_path) {
                fclose($context->file_handle);
                $context->file_handle = null;
                $context->file_path = null;
                $context->file_ctime = null;
                $context->file_bytes_written = 0;
            }

            if (file_exists($local_path)) {
                @unlink($local_path);
            }
            $this->delete_index_entry($path);

            if ($error_type === "file_changed") {
                $this->record_volatile_file($path);
            }
        }

        $error_progress_message = "Remote error: {$error_type} " . ($path !== "" ? $path : "");
        $this->output->show_progress_line($error_progress_message);
        $this->output_progress(
            [
                "type" => "error",
                "phase" => $phase,
                "error_type" => $error_type,
                "path" => $path,
                "error_message" => $message,
                "message" => $error_progress_message,
            ],
            true,
        );
    }

    /**
     * Handle progress chunk.
     */
    private function handle_progress(array $chunk, string $phase): void
    {
        $body = $chunk["body"] ?? "";
        $data = json_decode($body, true);
        if (!$data) {
            return;
        }

        $this->output_progress(array_merge(["phase" => $phase], $data));
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
     * Diagnose an HTTP error and return a user-friendly message with
     * actionable advice. Used by fetch_json() and fetch_streaming() to
     * turn opaque "HTTP 403" messages into something a non-expert can
     * act on.
     *
     * Returns ['message' => ..., 'code' => ...].
     *
     * @param int         $http_code    HTTP status code (0 for connection failures).
     * @param string|null $body         Response body (may be HTML, JSON, or empty).
     * @param string|null $redirect_url The Location header / CURLINFO_REDIRECT_URL for 3xx responses.
     */
    private function diagnose_http_error(int $http_code, ?string $body, ?string $redirect_url = null): array
    {
        return HttpErrorDiagnoser::diagnose(
            $http_code,
            $body,
            $redirect_url,
            $this->hmac_client !== null,
        );
    }

    /**
     * Format a diagnosed error as a single string for display.
     * Also stores the error code on the instance for output_progress
     * and write_status_file to pick up.
     */
    private function format_diagnosed_error(array $diagnosis): string
    {
        $this->last_error_code = $diagnosis['code'];
        return $diagnosis['message'];
    }

    /**
     * Fetch a JSON response for a lightweight request (non-streaming).
     */
    public function fetch_json(string $url): array
    {
        return $this->http_transport()->fetch_json($url);
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
        $this->http_transport()->fetch_streaming(
            $url,
            $cursor,
            $context,
            $post_data,
            $endpoint,
        );
    }

    /**
     * Return the default compact state structure.
     */
    /**
     * Reset state to defaults while preserving cross-command data like
     * preflight results, version, and follow_symlinks.
     */
    private function reset_state(): void
    {
        $preflight = $this->state["preflight"] ?? null;
        $version = $this->state["version"] ?? null;
        $webhost = $this->state["webhost"] ?? null;
        $follow = $this->state["follow_symlinks"] ?? false;
        $nonempty = $this->state["fs_root_nonempty_behavior"] ?? "error";
        $max_packet = $this->state["max_allowed_packet"] ?? null;
        $pull = $this->state["pull"] ?? null;
        $this->state = $this->default_state();
        $this->state["preflight"] = $preflight;
        $this->state["version"] = $version;
        $this->state["webhost"] = $webhost;
        $this->state["follow_symlinks"] = $follow;
        $this->state["fs_root_nonempty_behavior"] = $nonempty;
        $this->state["max_allowed_packet"] = $max_packet;
        if ($pull !== null) {
            $this->state["pull"] = $pull;
        }
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
     * @param mixed $value
     * @return mixed
     */
    private function encode_state_path_value($value)
    {
        return $this->state_path_codec->encode_value($value);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function decode_state_path_value($value)
    {
        return $this->state_path_codec->decode_value($value);
    }

    /**
     * Load import state from disk.
     */
    private function load_state(): array
    {
        if (!file_exists($this->state_file)) {
            return $this->default_state();
        }

        $contents = file_get_contents($this->state_file);
        if ($contents === false) {
            return $this->default_state();
        }

        $state = json_decode($contents, true);
        if (!is_array($state)) {
            $this->audit_log(
                "Warning: corrupt state file detected, renaming and starting fresh",
                true,
            );
            $corrupt_name = $this->state_file . ".corrupt." . time();
            @rename($this->state_file, $corrupt_name);
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

        // Write to temp file first, then atomic rename
        $json = json_encode($state, JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException("Failed to encode state: " . json_last_error_msg());
        }
        $tmp_file = $this->state_file . '.tmp';
        $bytes = file_put_contents($tmp_file, $json);
        if ($bytes === false) {
            throw new RuntimeException("Failed to write state file: $tmp_file (disk full?)");
        }
        if (!rename($tmp_file, $this->state_file)) {
            throw new RuntimeException("Failed to rename state file: $tmp_file -> {$this->state_file}");
        }

        $indexed = $this->index_count();
        $files_imported = $this->files_imported; // Completed in this run
        $has_cursor =
            !empty($state["cursor"] ?? null) ||
            !empty($state["index"]["cursor"] ?? null) ||
            !empty($state["fetch"]["cursor"] ?? null);
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
        $status = $error !== null ? "error" : ($state["status"] ?? "in_progress");

        // Derive phase from the state's stage field
        $phase = $state["stage"] ?? null;

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
