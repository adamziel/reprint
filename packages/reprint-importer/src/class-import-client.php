<?php

namespace Reprint\Importer;

use CURLFile;
use Exception;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Reprint\Importer\Command\ImportCommands;
use Reprint\Importer\Command\ImportCommandResult;
use Reprint\Importer\Command\PreflightCommand;
use Reprint\Importer\FileSync\DirectoryChunkApplier;
use Reprint\Importer\FileSync\DownloadList;
use Reprint\Importer\FileSync\FetchListBuilder;
use Reprint\Importer\FileSync\FetchListExecutor;
use Reprint\Importer\FileSync\FileChunkApplier;
use Reprint\Importer\FileSync\FileFetchResponseHandler;
use Reprint\Importer\FileSync\IndexResponseHandler;
use Reprint\Importer\FileSync\SymlinkChunkApplier;
use Reprint\Importer\Filesystem\LocalImportFilesystem;
use Reprint\Importer\Filesystem\PathUtils;
use Reprint\Importer\Host\RuntimeManifest;
use Reprint\Importer\Index\IndexFileSorter;
use Reprint\Importer\Index\IndexLineParser;
use Reprint\Importer\Index\IndexStore;
use Reprint\Importer\Protocol\CurlTimeoutException;
use Reprint\Importer\Protocol\MultipartStreamParser;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Pull\Pull;
use Reprint\Importer\QueryStream\WP_MySQL_FastQueryStream;
use Reprint\Importer\QueryStream\WP_MySQL_Naive_Query_Stream;
use Reprint\Importer\Session\ImportPaths;
use Reprint\Importer\Session\ImportStateSchema;
use Reprint\Importer\Session\StatePathCodec;
use Reprint\Importer\Sql\DbIndexResponseHandler;
use Reprint\Importer\Sql\SqlResponseHandler;
use Reprint\Importer\Sql\SqlStatementInspector;
use Reprint\Importer\Support\ByteFormatter;
use Reprint\Importer\TerminalProgress\TerminalProgress;
use Reprint\Importer\Tuning\AdaptiveTuner;
use Reprint\Importer\UrlRewrite\Base64ValueScanner;
use Reprint\Importer\UrlRewrite\DomainCollector;
use Reprint\Importer\UrlRewrite\PhpSerializationProcessor;
use Reprint\Importer\UrlRewrite\SQLitePreparedInsertBuilder;
use Reprint\Importer\UrlRewrite\SqlStatementRewriter;
use Reprint\Importer\UrlRewrite\StructuredDataUrlRewriter;
use function Reprint\Exporter\normalize_path;
use function Reprint\Exporter\path_is_within_root;

class ImportClient
{

    private const SAVE_STATE_EVERY_N_CHUNKS = 50;
    private const SQLITE_PREPARED_INSERT_CACHE_MAX = 128;

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
     * @var float Monotonic timestamp of last progress JSON line emitted.
     * Used with $progress_throttle to rate-limit stdout progress output.
     */
    private $last_progress_output = 0;

    /** @var float Minimum seconds between progress output lines. */
    private $progress_throttle = 1.0;

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

    /** @var bool When true, emit detailed operation logs to stdout. Set via --verbose. */
    private $verbose_mode = false;

    /** @var bool Whether stdout is a TTY (enables interactive progress display). */
    private $is_tty;

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

    /** @var int|null Last curl error number, for retry/diagnostic logic. */
    private $last_curl_errno = null;

    /** @var bool Whether the last curl request timed out. */
    private $last_curl_timeout = false;

    /** @var string|null Machine-readable error code from the last diagnose_http_error() call. */
    public $last_error_code = null;

    /** @var TerminalProgress Renders progress and lifecycle output to the terminal. */
    private TerminalProgress $progress;

    /** @var Pull Orchestrates the pull command pipeline. */
    private Pull $pull;

    /** @var int Cumulative count of index entries written (survives retries). */
    private $index_entries_counted = 0;

    /**
     * Memoized lookups for "does remote index contain this path or any descendant path?"
     * keyed by normalized absolute path.
     *
     * @var array<string,bool>
     */
    private $remote_index_prefix_cache = [];

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

    /** @var resource File descriptor for progress output — STDOUT normally, STDERR in stdout mode. */
    private $progress_fd;

    /**
     * @var int Process exit code. 0 = import complete, 2 = partial progress
     * (caller should invoke again to continue).
     */
    public $exit_code = 0;

    public function __construct(string $remote_url, string $state_dir, string $fs_root)
    {
        $this->remote_url = rtrim($remote_url, "?&");
        $this->state_dir = rtrim($state_dir, "/");
        $this->fs_root = rtrim($fs_root, "/");
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

        // Detect TTY for progress display. In stdout mode this is re-evaluated
        // against STDERR in run() once we know the output mode.
        $this->is_tty = function_exists("posix_isatty") && posix_isatty(STDOUT);
        $this->progress_fd = STDOUT;
        $this->progress = new TerminalProgress($this->is_tty, $this->progress_fd);
        $this->pull = new Pull($this, $this->progress);
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
                $this->progress->tick_spinner();
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
        if ($to_console && $this->verbose_mode) {
            fwrite($this->progress_fd, $log_line);
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

    /**
     * Load the volatile files tracker from disk.
     *
     * @return array<string, int> Map of path => change count
     */
    private function load_volatile_files(): array
    {
        if (!file_exists($this->volatile_files_file)) {
            return [];
        }
        $json = file_get_contents($this->volatile_files_file);
        if ($json === false) {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save the volatile files tracker to disk.
     * Deletes the file if the array is empty.
     */
    private function save_volatile_files(array $files): void
    {
        if (empty($files)) {
            if (file_exists($this->volatile_files_file)) {
                @unlink($this->volatile_files_file);
            }
            return;
        }
        $json = json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return; // Don't corrupt the file
        }
        file_put_contents($this->volatile_files_file, $json . "\n");
    }

    /**
     * Record that a file changed during streaming.
     * Increments the change counter for the given path.
     */
    private function record_volatile_file(string $path): void
    {
        $files = $this->load_volatile_files();
        $count = ($files[$path] ?? 0) + 1;
        $files[$path] = $count;
        $this->save_volatile_files($files);
        $this->audit_log("VOLATILE | path={$path} | count={$count}");
    }

    /**
     * Clear a file from the volatile tracker after a successful download.
     */
    private function clear_volatile_file(string $path): void
    {
        $files = $this->load_volatile_files();
        if (!isset($files[$path])) {
            return;
        }
        unset($files[$path]);
        $this->save_volatile_files($files);
        $this->audit_log("VOLATILE CLEARED | path={$path}");
    }

    /**
     * Report volatile files to the user at sync completion.
     */
    private function report_volatile_files(): void
    {
        $files = $this->load_volatile_files();
        if (empty($files)) {
            return;
        }

        $count = count($files);
        $this->audit_log(
            sprintf("VOLATILE SUMMARY | %d file(s) changed during sync", $count),
            true,
        );

        $this->progress->show_lifecycle_line("{$count} file(s) changed during sync and need re-syncing (run files-pull again):\n");

        foreach ($files as $path => $changes) {
            $suffix = $changes >= 3
                ? " (changed {$changes} times — may be too volatile to sync)"
                : " (changed {$changes} time" . ($changes > 1 ? "s" : "") . ")";
            $this->audit_log("  VOLATILE FILE | path={$path} | count={$changes}");
            $this->progress->show_lifecycle_line("  {$path}{$suffix}\n");
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
        $this->progress->show_progress_line("[skip] " . $this->display_path($path));
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
        $this->verbose_mode = $options["verbose"] ?? false;
        $this->progress->set_verbose_mode($this->verbose_mode);
        $this->follow_symlinks = $options["follow_symlinks"] ?? true;
        $this->include_caches = $options["include_caches"] ?? false;
        $this->extra_directory = $options["extra_directory"] ?? null;
        if (isset($options["fs_root_nonempty_behavior"])) {
            $this->fs_root_nonempty_behavior = $options["fs_root_nonempty_behavior"];
            if (!in_array($this->fs_root_nonempty_behavior, ['error', 'preserve-local'])) {
                throw new InvalidArgumentException(
                    "Invalid --on-fs-root-nonempty value: {$this->fs_root_nonempty_behavior}. " .
                        "Valid values: error, preserve-local",
                );
            }
        }
        $command = ImportCommands::normalize_name($options["command"] ?? null);

        $abort = $options["abort"] ?? false;
        $this->pipeline_step = $options["pipeline_step"] ?? null;
        $this->pipeline_steps = $options["pipeline_steps"] ?? null;

        if (!$command) {
            throw new InvalidArgumentException(
                "Command is required. Valid commands: " . ImportCommands::valid_names_message(),
            );
        }

        $command_runner = ImportCommands::get($command);
        if ($command_runner === null) {
            throw new InvalidArgumentException(
                "Invalid command: {$command}. Valid commands: " . ImportCommands::valid_names_message(),
            );
        }

        $this->state = $this->load_state();

        // Persist follow_symlinks in state so it survives across invocations.
        // If explicitly set on CLI, store it.  Otherwise, restore from persisted state.
        if (isset($options["follow_symlinks"])) {
            $this->state["follow_symlinks"] = $this->follow_symlinks;
            $this->save_state($this->state);
        } elseif (isset($this->state["follow_symlinks"])) {
            $this->follow_symlinks = $this->state["follow_symlinks"];
        }

        // Persist fs_root_nonempty_behavior in state so it survives across invocations.
        // 'preserve-local' preserves existing local files instead of overwriting
        // them, and gracefully skips non-writable directories.
        if (isset($options["fs_root_nonempty_behavior"])) {
            $this->state["fs_root_nonempty_behavior"] = $this->fs_root_nonempty_behavior;
            $this->save_state($this->state);
        } else {
            $this->fs_root_nonempty_behavior = $this->state["fs_root_nonempty_behavior"] ?? 'error';
        }

        // Persist filter in state so it survives across resume cycles.
        //
        //   --filter=none             download everything (default)
        //   --filter=essential-files   skip uploads, download code/config/themes/plugins
        //   --filter=skipped-earlier   download only files skipped by a prior essential-files run
        //
        // Changing the filter mid-flight is not allowed.  The user must either
        // start fresh (--abort) or finish the current sync before switching.
        // The one valid transition is: essential-files (complete) → skipped-earlier.
        if (isset($options["filter"])) {
            $next = $options["filter"];
            if (
                $command === "pull" &&
                !in_array($next, ["none", "essential-files"], true)
            ) {
                throw new InvalidArgumentException(
                    "Invalid --filter value for pull: {$next}. " .
                        "Valid values: none, essential-files",
                );
            }
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
        if (isset($options["max_allowed_packet"])) {
            $this->max_allowed_packet = (int) $options["max_allowed_packet"];
            $this->state["max_allowed_packet"] = $this->max_allowed_packet;
            $this->save_state($this->state);
        } elseif (isset($this->state["max_allowed_packet"])) {
            $this->max_allowed_packet = (int) $this->state["max_allowed_packet"];
        }

        // Persist sql_output_mode in state so it survives across resume invocations.
        // The password is NOT persisted — it must be supplied on every run (or via
        // the MYSQL_PASSWORD environment variable).
        if (isset($options["sql_output"])) {
            $mode = $options["sql_output"];
            if (!in_array($mode, ["file", "stdout", "mysql"])) {
                throw new InvalidArgumentException(
                    "Invalid --sql-output mode: {$mode}. Valid modes: file, stdout, mysql",
                );
            }
            $this->sql_output_mode = $mode;
            $this->state["sql_output"] = $mode;
        } elseif (isset($this->state["sql_output"])) {
            $this->sql_output_mode = $this->state["sql_output"];
        }

        // In stdout mode, SQL goes to STDOUT, so progress/status output must
        // go to STDERR to keep the streams separate.
        if ($this->sql_output_mode === "stdout") {
            $this->progress_fd = STDERR;
            $this->is_tty = function_exists("posix_isatty") && posix_isatty(STDERR);
            $this->progress->set_progress_fd($this->progress_fd);
            $this->progress->set_is_tty($this->is_tty);
        }

        // MySQL connection parameters for --sql-output=mysql.
        if (isset($options["mysql_host"])) {
            $this->mysql_host = $options["mysql_host"];
            $this->state["mysql_host"] = $this->mysql_host;
        } elseif (isset($this->state["mysql_host"])) {
            $this->mysql_host = $this->state["mysql_host"];
        }

        if (isset($options["mysql_port"])) {
            $this->mysql_port = (int) $options["mysql_port"];
            $this->state["mysql_port"] = $this->mysql_port;
        } elseif (isset($this->state["mysql_port"])) {
            $this->mysql_port = (int) $this->state["mysql_port"];
        }

        if (isset($options["mysql_user"])) {
            $this->mysql_user = $options["mysql_user"];
            $this->state["mysql_user"] = $this->mysql_user;
        } elseif (isset($this->state["mysql_user"])) {
            $this->mysql_user = $this->state["mysql_user"];
        }

        if (isset($options["mysql_database"])) {
            $this->mysql_database = $options["mysql_database"];
            $this->state["mysql_database"] = $this->mysql_database;
        } elseif (isset($this->state["mysql_database"])) {
            $this->mysql_database = $this->state["mysql_database"];
        }

        $this->save_state($this->state);

        // Password is never persisted — must be supplied each run or via env.
        if (isset($options["mysql_password"])) {
            $this->mysql_password = $options["mysql_password"];
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
        if (!empty($options["secret"])) {
            if (!class_exists(\Reprint\Exporter\Site_Export_HMAC_Client::class)) {
                throw new RuntimeException(
                    'Streaming exporter runtime not found. Run composer install before using --secret.'
                );
            }
            $this->hmac_client = new \Reprint\Exporter\Site_Export_HMAC_Client($options["secret"]);
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

        $this->progress->show_lifecycle_line("State cleared for {$command}.\n");

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
        $runtime_dir = $this->state_dir . "/runtime_files";

        // Always wipe and recreate so the directory reflects current state.
        if (is_dir($runtime_dir)) {
            self::rmdir_recursive($runtime_dir);
            $this->audit_log("RUNTIME FILES | deleted {$runtime_dir}");
        }

        $ini_all = $this->state["preflight"]["data"]["runtime"]["ini_get_all"] ?? [];
        $files = [];
        foreach (["auto_prepend_file", "auto_append_file"] as $key) {
            $path = $ini_all[$key] ?? "";
            if (is_string($path) && $path !== "") {
                $files[] = $path;
            }
        }
        $files = array_values(array_unique($files));

        if (empty($files)) {
            $this->audit_log("RUNTIME FILES | no prepend/append scripts to download");
            return;
        }

        mkdir($runtime_dir, 0755, true);

        $this->audit_log(
            "RUNTIME FILES | downloading " . count($files) . " script(s): " .
                implode(", ", $files),
        );

        $downloaded = $this->fetch_files_into($runtime_dir, $files);
        $this->audit_log("RUNTIME FILES | downloaded {$downloaded}/" . count($files) . " script(s)");
    }

    /**
     * Download a list of absolute remote paths into $target_dir,
     * preserving their directory structure.
     *
     * Issues one file_fetch request per parent directory so that an
     * inaccessible directory doesn't block the others.  All errors
     * are caught and logged as non-fatal.
     *
     * @return int Number of files successfully downloaded.
     */
    private function fetch_files_into(string $target_dir, array $files): int
    {
        $by_dir = [];
        foreach ($files as $f) {
            $parent = dirname($f);
            if ($parent !== "" && $parent !== ".") {
                $by_dir[rtrim($parent, "/")][] = $f;
            }
        }

        $downloaded = 0;

        foreach ($by_dir as $directory => $dir_files) {
            $tmp = tempnam(sys_get_temp_dir(), "fetch-into-");
            if ($tmp === false) {
                continue;
            }
            file_put_contents($tmp, json_encode($dir_files, JSON_UNESCAPED_SLASHES));

            $post_data = [
                "file_list" => new CURLFile($tmp, "application/json", "file_list"),
            ];
            $url = $this->build_url("file_fetch", null, ["directory" => [$directory]]);

            $context = new StreamingContext();
            $context->file_handle = null;
            $context->file_path = null;
            $context->file_ctime = null;

            $context->on_chunk = function ($chunk) use ($target_dir, $context, &$downloaded) {
                $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

                if ($chunk_type === "file") {
                    $raw = $chunk["headers"]["x-file-path"] ?? "";
                    $path = base64_decode($raw, true);
                    if ($path === false || $path === "") {
                        return;
                    }

                    $is_first = ($chunk["headers"]["x-first-chunk"] ?? "0") === "1";
                    $is_last = ($chunk["headers"]["x-last-chunk"] ?? "0") === "1";
                    $local_path = $target_dir . $path;

                    if ($is_first) {
                        if ($context->file_handle) {
                            fclose($context->file_handle);
                            $context->file_handle = null;
                        }
                        $dir = dirname($local_path);
                        if (!is_dir($dir)) {
                            @mkdir($dir, 0755, true);
                        }
                        $context->file_handle = @fopen($local_path, "wb");
                        $context->file_path = $local_path;
                    }

                    if ($context->file_handle && isset($chunk["body"])) {
                        fwrite($context->file_handle, $chunk["body"]);
                    }

                    if ($is_last && $context->file_handle) {
                        fclose($context->file_handle);
                        $context->file_handle = null;
                        $downloaded++;
                        $this->audit_log("Saved {$path} → {$local_path}");
                    }
                } elseif ($chunk_type === "error") {
                    $body = json_decode($chunk["body"] ?? "{}", true);
                    $error_path = isset($body["path"]) ? base64_decode($body["path"]) : "unknown";
                    $this->audit_log("Fetch error for {$error_path}: " . ($body["message"] ?? "unknown"));
                } elseif ($chunk_type === "completion") {
                    $context->saw_completion = true;
                }
            };

            try {
                $this->fetch_streaming($url, null, $context, $post_data, "file_fetch");
            } catch (\RuntimeException $e) {
                $this->audit_log(
                    "Fetch failed for directory {$directory} (non-fatal): " .
                        substr($e->getMessage(), 0, 200),
                );
            }

            @unlink($tmp);

            if ($context->file_handle) {
                fclose($context->file_handle);
            }
        }

        return $downloaded;
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private static function rmdir_recursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            $path = $dir . "/" . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::rmdir_recursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
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
                $this->progress->show_lifecycle_line("Downloading previously skipped files\n");
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
            $this->progress->clear_progress_line();

            $skipped_note = $has_skipped
                ? " (some files were skipped — re-run with --filter=skipped-earlier to download them)"
                : "";
            $this->audit_log(
                sprintf("files-pull already complete: %d files indexed%s", $index_size, $skipped_note),
                true,
            );

            $this->progress->show_lifecycle_line("files-pull already complete: {$index_size} files indexed\n");
            if ($has_skipped) {
                $this->progress->show_lifecycle_line("Some files were skipped. Re-run with --filter=skipped-earlier to download them.\n");
            } else {
                $this->progress->show_lifecycle_line("To re-sync, run with --abort first to clear state.\n");
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

            $this->progress->show_lifecycle_line("Resuming files-pull\n");
            $this->progress->show_lifecycle_line("  Stage: {$stage}\n");
            $this->progress->show_lifecycle_line("  Already indexed: {$index_size} files\n");
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

                $this->progress->show_lifecycle_line("Starting files-pull (delta)\n");
                $this->progress->show_lifecycle_line("  Index contains: {$index_size} files\n");
                $this->progress->show_lifecycle_line("  Stage: index\n");
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

                $this->progress->show_lifecycle_line("Starting files-pull\n");
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

        $this->progress->clear_progress_line();
        $index_size = $this->index_count();
        $label = $is_delta ? "files-pull (delta)" : "files-pull";

        $this->audit_log(
            sprintf("%s complete: %d files indexed", $label, $index_size),
            true,
        );

        $this->progress->show_lifecycle_line("{$label} complete: {$index_size} files indexed\n");
        $this->progress->show_lifecycle_line("Audit log: {$this->audit_log}\n");
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
            if ($has_downloads && $this->progress->is_quiet_lifecycle()) {
                $green = "\033[32m";
                $dim = "\033[2m";
                $r = "\033[0m";
                $scanned = number_format($this->index_entries_counted);
                $this->progress->clear_progress_line();
                $this->progress->print_line("  {$green}✓{$r} Scanned {$dim}— {$scanned} entries{$r}\n");
                $total = $this->count_newlines($this->download_list_file);
                $this->progress->set_active_label(null);
                $this->progress->show_progress_line(
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
            $this->recreate_intermediate_symlinks();
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
            $this->progress->show_lifecycle_line("Starting files-index\n");
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
            $this->progress->show_lifecycle_line("Resuming files-index\n");
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

        $this->progress->show_lifecycle_line("files-index complete: {$count} entries indexed\n");
        $this->progress->show_lifecycle_line("Remote index: {$this->remote_index_file}\n");
        $this->progress->show_lifecycle_line("Audit log: {$this->audit_log}\n");
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
            $this->progress->show_lifecycle_line("Following symlink target: {$dir}\n");
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
                        $this->progress->show_lifecycle_line("  Skipped (server rejected): {$dir}\n");
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
     * Recreate intermediate symlinks discovered by the server's
     * discover_path_symlinks() function.
     *
     * When following symlinks, the server walks each target path component by
     * component and emits index entries for any intermediate symlinks it finds.
     * For example, if /srv/wordpress is a symlink to /wordpress, the server
     * emits an index entry with path=/srv/wordpress, target=/wordpress,
     * type=link, intermediate=true.
     *
     * Since the server indexes everything under realpath()-resolved paths,
     * the files are already downloaded to the target location (e.g.
     * fs-root/wordpress/...).  We just need to create the symlink
     * (e.g. fs-root/srv/wordpress -> /wordpress) so the directory
     * layout matches the server.
     */
    private function recreate_intermediate_symlinks(): void
    {
        if (!file_exists($this->remote_index_file)) {
            return;
        }

        $h = fopen($this->remote_index_file, "r");
        if (!$h) {
            return;
        }

        $created = 0;
        while (($line = fgets($h)) !== false) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }
            if (($entry["type"] ?? "") !== "link") {
                continue;
            }
            if (empty($entry["intermediate"])) {
                continue;
            }
            $target_encoded = $entry["target"] ?? null;
            if (!is_string($target_encoded) || $target_encoded === "") {
                continue;
            }
            $path_encoded = $entry["path"] ?? null;
            if (!is_string($path_encoded) || $path_encoded === "") {
                continue;
            }

            /**
             * base64_decode second parameter is a `strict` flag. It rejects the entire
             * input if it contains any bytes that are not produced by base64_encode().
             *
             * @see https://www.php.net/base64_decode
             */
            $path = base64_decode($path_encoded, true);
            $target = base64_decode($target_encoded, true);
            if ($path === false || $path === "" || $target === false || $target === "") {
                continue;
            }

            try {
                $local_path = $this->remote_path_to_local_path_within_import_root($path);
            } catch (RuntimeException $e) {
                $this->audit_log(
                    "INTERMEDIATE SYMLINK SKIP: invalid path {$path}: " . $e->getMessage(),
                    true,
                );
                continue;
            }

            // Already correct — skip
            if (is_link($local_path) && readlink($local_path) === $target) {
                continue;
            }

            // Create parent directory
            $parent = dirname($local_path);
            if (!is_dir($parent)) {
                try {
                    $this->ensure_directory_path($parent);
                } catch (RuntimeException $e) {
                    $this->audit_log(
                        "INTERMEDIATE SYMLINK SKIP: failed to prepare parent for {$path}: " .
                            $e->getMessage(),
                        true,
                    );
                    continue;
                }
            }

            // Remove stale symlink if present
            if (is_link($local_path)) {
                @unlink($local_path);
            }

            // Don't overwrite a real directory — that shouldn't exist for
            // an intermediate symlink path, and if it does something else
            // is wrong.
            if (file_exists($local_path)) {
                $this->audit_log(
                    "INTERMEDIATE SYMLINK SKIP: {$path} already exists as a real file/dir",
                    true,
                );
                continue;
            }

            // Validate that the symlink target doesn't escape the filesystem root.
            $root = $this->get_filesystem_root_path();
            try {
                $this->assert_symlink_target_within_root(
                    dirname($local_path),
                    $target,
                    $root
                );
            } catch (RuntimeException $e) {
                $this->audit_log(
                    "INTERMEDIATE SYMLINK SKIP: " . $e->getMessage(),
                    true,
                );
                continue;
            }

            if (@symlink($target, $local_path)) {
                $created++;
                $this->audit_log(
                    "INTERMEDIATE SYMLINK: {$path} -> {$target}",
                    false,
                );
            } else {
                $this->audit_log(
                    "Failed to create intermediate symlink: {$path} -> {$target}",
                    true,
                );
            }
        }
        fclose($h);

        if ($created > 0) {
            $this->audit_log(
                "Recreated {$created} intermediate symlink(s)",
                false,
            );
        }
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

            $this->progress->show_lifecycle_line("Resuming db-pull (stage: {$stage})\n");
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

            $this->progress->show_lifecycle_line("Starting db-pull\n");
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

        $this->progress->show_lifecycle_line("db-pull complete\n");
        if ($this->sql_output_mode === "file") {
            $this->progress->show_lifecycle_line("SQL file: {$sql_file}\n");
        } elseif ($this->sql_output_mode === "stdout") {
            $this->progress->show_lifecycle_line("SQL written to stdout\n");
        } elseif ($this->sql_output_mode === "mysql") {
            $this->progress->show_lifecycle_line("SQL imported into {$this->mysql_database}\n");
        }
        $this->progress->show_lifecycle_line("Audit log: {$this->audit_log}\n");
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
        $runtime = $options["runtime"] ?? null;
        if (empty($runtime)) {
            throw new InvalidArgumentException(
                "apply-runtime requires --runtime=RUNTIME."
            );
        }

        $output_dir = $options["output_dir"] ?? null;
        if (empty($output_dir)) {
            throw new InvalidArgumentException(
                "apply-runtime requires --output-dir=DIR to write runtime configuration files"
            );
        }

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

        // Resolve the effective fs root from either --flat-document-root
        // (used as-is) or --fs-root (prefixed with the remote document_root).
        // Mutual exclusion is already enforced at the CLI level.
        $flat_document_root = $options["flat_document_root"] ?? null;

        if (!empty($flat_document_root)) {
            // --flat-document-root: used directly as the web root.
            $effective_fs_root = rtrim($flat_document_root, "/");
        } else {
            // --fs-root: the raw download directory. The remote site's
            // document_root tells us where the web root lived on the
            // source server. Files are downloaded preserving the full
            // remote path, so the effective fs root is --fs-root +
            // document_root.
            $remote_doc_root = $preflight_data["runtime"]["document_root"] ?? "";
            if (is_string($remote_doc_root)) {
                $remote_doc_root = rtrim($remote_doc_root, "/");
            } else {
                $remote_doc_root = "";
            }

            if ($remote_doc_root !== "") {
                $effective_fs_root = $this->fs_root . $remote_doc_root;
            } else {
                $effective_fs_root = $this->fs_root;
            }

            if (!is_dir($effective_fs_root)) {
                throw new RuntimeException(
                    "Effective fs root does not exist: {$effective_fs_root}\n" .
                    "The remote document_root was: {$remote_doc_root}\n" .
                    "If you used flat-docroot, pass the flattened directory " .
                    "with --flat-document-root instead of --fs-root."
                );
            }
        }

        // Resolve to absolute paths so generated files work from any cwd.
        $abs_output_dir = realpath($output_dir) ?: $output_dir;
        $abs_fs_root = realpath($effective_fs_root) ?: $effective_fs_root;

        if (!is_dir($abs_output_dir)) {
            if (!mkdir($abs_output_dir, 0755, true)) {
                throw new RuntimeException(
                    "Failed to create output directory: {$abs_output_dir}"
                );
            }
            $abs_output_dir = realpath($abs_output_dir);
        }

        // Step 1: Host analyzer produces a manifest from preflight data.
        $analyzer = host_analyzer_for($webhost);
        $manifest = $analyzer->analyze($preflight_data);
        $this->maybe_enable_remote_upload_proxy($manifest, $preflight_data);

        // Step 1b: Merge target database configuration from db-apply state.
        // db-apply persists the target engine and connection details so that
        // apply-runtime can generate the matching DB_* constants and, for
        // SQLite targets, set up the database integration plugin.
        $apply_state = $this->state["apply"] ?? [];
        $target_engine = $apply_state["target_engine"] ?? null;
        if ($target_engine === "mysql") {
            $manifest->constants["DB_NAME"] = $apply_state["target_db"] ?? "";
            $manifest->constants["DB_USER"] = $apply_state["target_user"] ?? "";
            $manifest->constants["DB_PASSWORD"] = $apply_state["target_pass"] ?? "";
            $host_value = $apply_state["target_host"] ?? "127.0.0.1";
            $port_value = (int) ($apply_state["target_port"] ?? 3306);
            if ($port_value !== 3306) {
                $host_value .= ":" . $port_value;
            }
            $manifest->constants["DB_HOST"] = $host_value;
            // runtime.php defines DB_* before wp-config.php loads, which
            // causes "Constant already defined" warnings. Flag this so the
            // generated runtime.php installs a handler to suppress them.
            $manifest->has_db_constants = true;
        } elseif ($target_engine === "sqlite") {
            $sqlite_path = $apply_state["target_sqlite_path"] ?? null;
            if ($sqlite_path !== null && $sqlite_path !== '') {
                $db_dir = rtrim(dirname($sqlite_path), '/') . '/';
                $db_file = basename($sqlite_path);
            } else {
                $db_dir = '{fs-root}/wp-content/database/';
                $db_file = '.ht.sqlite';
            }
            $manifest->sqlite = [
                'plugin_source' => resolve_sqlite_integration_plugin_path(),
                'plugin_dir' => '',  // resolved after copy_sqlite_plugin()
                'db_dir' => $db_dir,
                'db_file' => $db_file,
            ];
        }

        $this->audit_log("APPLY-RUNTIME | analyzed preflight (source={$manifest->source}, webhost={$webhost})");

        // Resolve host and port for the target server. If not provided on
        // the CLI, derive from the first URL rewrite target (saved by
        // db-apply). This way the dev server listens on the same address
        // the database was rewritten to.
        $host = $options["host"] ?? null;
        $port = $options["port"] ?? null;
        if ($host === null || $port === null) {
            $rewrite_map = $this->state["apply"]["rewrite_url"] ?? [];
            $first_target = !empty($rewrite_map) ? reset($rewrite_map) : null;
            if (is_string($first_target)) {
                $parsed = parse_url($first_target);
                if ($host === null) {
                    $host = $parsed["host"] ?? null;
                }
                if ($port === null && isset($parsed["port"])) {
                    $port = $parsed["port"];
                }
            }
        }

        // Resolve the path to WordPress's index.php. On standard hosts it
        // lives in the fs root. On WPCloud the ABSPATH is a different
        // directory (e.g. /wordpress/core/X.Y.Z) which maps to
        // download_root + abspath when using --fs-root.
        $paths_urls = $preflight_data["database"]["wp"]["paths_urls"] ?? [];
        $abspath = rtrim($paths_urls["abspath"] ?? "", "/");
        if (!empty($flat_document_root)) {
            // Flattened layout: index.php is at the top level.
            $wordpress_index = $abs_fs_root . '/index.php';
        } elseif ($abspath !== "") {
            // Raw download: ABSPATH is relative to the download root,
            // not the effective fs root (which is download_root + document_root).
            $wordpress_index = realpath($this->fs_root . $abspath . '/index.php') ?: '';
        } else {
            $wordpress_index = $abs_fs_root . '/index.php';
        }

        // Step 2: Runtime applier writes server-specific config files.
        $applier = runtime_applier_for($runtime);
        $applier_options = [];
        if ($wordpress_index !== '') {
            $applier_options['wordpress_index'] = $wordpress_index;
        }
        if ($host !== null) {
            $applier_options['host'] = $host;
        }
        if ($port !== null) {
            $applier_options['port'] = (int) $port;
        }
        // Step 2b: For SQLite targets, copy the integration plugin into the
        // output directory BEFORE the applier runs, so generate_runtime_php()
        // can embed the resolved plugin path in the lazy-loader code.
        if ($manifest->sqlite !== null) {
            $copied_plugin = copy_sqlite_plugin(
                $manifest->sqlite['plugin_source'],
                $abs_output_dir,
            );
            // Replace the source path with the copied-to path so the
            // generated runtime.php points to the output directory.
            $manifest->sqlite['plugin_dir'] = $copied_plugin;
            // Resolve {fs-root} in db_dir now that we have the real path.
            $manifest->sqlite['db_dir'] = resolve_runtime_placeholders(
                $manifest->sqlite['db_dir'],
                $abs_fs_root,
            );
        }

        $summary = $applier->apply($manifest, $abs_fs_root, $abs_output_dir, $applier_options);

        if ($manifest->sqlite !== null) {
            $summary[] = "Copied sqlite-database-integration to {$abs_output_dir}/sqlite-database-integration";
        }

        // Remove production drop-ins and mu-plugins that would crash
        // the local site.  The host analyzer declares these — they
        // depend on infrastructure (Memcached servers, multisite APIs)
        // not available outside the original hosting environment.
        foreach ($manifest->paths_to_remove as $rel_path) {
            $full_path = $abs_fs_root . '/' . ltrim($rel_path, '/');
            if (!file_exists($full_path) && !is_link($full_path)) {
                continue;
            }
            if (is_dir($full_path) && !is_link($full_path)) {
                self::rmdir_recursive($full_path);
            } else {
                unlink($full_path);
            }
            $summary[] = "Removed production drop-in: {$rel_path}";
            $this->audit_log("APPLY-RUNTIME | removed {$rel_path} (production-only)");
        }

        foreach ($summary as $line) {
            $this->audit_log("APPLY-RUNTIME | {$line}");
        }

        // Persist which paths were removed so callers can inspect state.
        $this->state["apply"]["remote_paths_removed_from_local_site"] = $manifest->paths_to_remove;
        $this->save_state($this->state);

        // Read the structured start config if the applier wrote one.
        // Playground CLI writes start.json with mount paths as seen by
        // this PHP process — callers (e.g. Studio) map them to host paths.
        $start_config_path = $abs_output_dir . '/start.json';
        $start_config = null;
        if (file_exists($start_config_path)) {
            $start_config = json_decode(file_get_contents($start_config_path), true);
        }

        // Output the summary and manifest as structured JSON for callers,
        // and print the human-readable summary to stderr.
        $this->output_progress([
            "status" => "complete",
            "command" => "apply-runtime",
            "runtime" => $runtime,
            "webhost" => $webhost,
            "webhost_source" => $manifest->source,
            "target_engine" => $target_engine,
            "paths_removed" => $manifest->paths_to_remove,
            "extra_directories" => $manifest->extra_directories,
            "start_config" => $start_config,
            "message" => "apply-runtime complete (runtime: {$runtime})",
        ]);

        if (!$this->progress->is_quiet_lifecycle()) {
            fwrite(STDERR, "\n");
            fwrite(STDERR, "Runtime: {$runtime}\n");
            fwrite(STDERR, "Source host: {$webhost}\n");
            if ($target_engine !== null) {
                fwrite(STDERR, "Target database: {$target_engine}\n");
            }
            fwrite(STDERR, "\n");
            foreach ($summary as $line) {
                fwrite(STDERR, "{$line}\n");
            }
        }
    }

    /**
     * Enable the temporary remote upload proxy when uploads may still be
     * missing locally.
     *
     * The proxy is active in two cases:
     * - files-pull is still incomplete
     * - a prior --filter=essential-files run left skipped uploads on disk
     */
    private function maybe_enable_remote_upload_proxy(RuntimeManifest $manifest, array $preflight_data): void
    {
        if (!$this->should_enable_remote_upload_proxy()) {
            return;
        }

        $base_url = $this->get_remote_upload_proxy_base_url($preflight_data);
        if ($base_url === null) {
            $this->audit_log(
                "APPLY-RUNTIME | remote upload proxy skipped (no source uploads URL available)",
                true,
            );
            return;
        }

        $manifest->constants["STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_BASEURL"] = $base_url;
        $state_dir = realpath($this->state_dir) ?: $this->state_dir;
        $manifest->constants["STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_STATE_FILE"] =
            rtrim($state_dir, "/") . "/.import-state.json";
        $manifest->constants["STREAMING_SITE_MIGRATION_REMOTE_UPLOAD_PROXY_SKIPPED_FILE"] =
            rtrim($state_dir, "/") . "/.import-download-list-skipped.jsonl";
        $manifest->routes[] = [
            "handler" => "remote-upload-proxy",
            "path_pattern" => "/wp-content/uploads/.*",
            "condition" => "file_not_found",
            "description" => "Proxy missing uploads from the source site until files-pull completes",
        ];
        $this->audit_log(
            "APPLY-RUNTIME | enabled remote upload proxy ({$base_url})",
            true,
        );
    }

    /**
     * Decide whether runtime should proxy missing uploads from the source.
     *
     * Once files-pull is fully complete and no skipped uploads remain, the
     * proxy is disabled so requests are served only from local files.
     */
    private function should_enable_remote_upload_proxy(): bool
    {
        if (
            file_exists($this->skipped_download_list_file) &&
            filesize($this->skipped_download_list_file) > 0
        ) {
            return true;
        }

        if (($this->state["command"] ?? null) !== "files-pull") {
            return false;
        }

        $status = $this->state["status"] ?? null;
        return $status !== null && $status !== "complete";
    }

    /**
     * Resolve the source uploads base URL used by the temporary runtime proxy.
     */
    private function get_remote_upload_proxy_base_url(array $preflight_data): ?string
    {
        $paths_urls = $preflight_data["database"]["wp"]["paths_urls"] ?? [];
        $uploads_baseurl = $paths_urls["uploads"]["baseurl"] ?? null;
        if (is_string($uploads_baseurl) && $uploads_baseurl !== "") {
            return rtrim($uploads_baseurl, "/");
        }

        $site_urls = [
            $paths_urls["home_url"] ?? null,
            $paths_urls["site_url"] ?? null,
            $preflight_data["database"]["wp"]["home"] ?? null,
            $preflight_data["database"]["wp"]["siteurl"] ?? null,
        ];
        foreach ($site_urls as $site_url) {
            if (is_string($site_url) && $site_url !== "") {
                return rtrim($site_url, "/") . "/wp-content/uploads";
            }
        }

        return null;
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
        $force = $options["force"] ?? false;

        // Ensure the fs root exists
        if (!is_dir($this->fs_root)) {
            throw new RuntimeException(
                "Fs root does not exist: {$this->fs_root}",
            );
        }

        // Require preflight data so we know where WP components live
        $this->require_preflight();
        $preflight = $this->state["preflight"]["data"] ?? [];

        // Extract WordPress directory paths from preflight
        $paths_urls = $preflight["database"]["wp"]["paths_urls"] ?? null;
        $abspath = null;
        $wp_admin_path = null;
        $wp_includes_path = null;
        $content_dir = null;
        $plugins_dir = null;
        $mu_plugins_dir = null;
        $uploads_basedir = null;

        if (is_array($paths_urls)) {
            $abspath = $this->flatten_clean_path($paths_urls["abspath"] ?? null);
            $wp_admin_path = $this->flatten_clean_path($paths_urls["wp_admin_path"] ?? null);
            $wp_includes_path = $this->flatten_clean_path($paths_urls["wp_includes_path"] ?? null);
            $content_dir = $this->flatten_clean_path($paths_urls["content_dir"] ?? null);
            $plugins_dir = $this->flatten_clean_path($paths_urls["plugins_dir"] ?? null);
            $mu_plugins_dir = $this->flatten_clean_path($paths_urls["mu_plugins_dir"] ?? null);
            $uploads_basedir = $this->flatten_clean_path(
                $paths_urls["uploads"]["basedir"] ?? null,
            );
        }

        // Fall back to wp_detect roots if abspath not available
        if ($abspath === null) {
            $roots = $preflight["wp_detect"]["roots"] ?? [];
            if (!empty($roots)) {
                $abspath = $this->flatten_clean_path($roots[0]["path"] ?? null);
            }
        }

        if ($abspath === null) {
            throw new RuntimeException(
                "Cannot determine WordPress ABSPATH from preflight data. " .
                    "Run preflight first to detect the WordPress installation.",
            );
        }

        // Map remote paths to local paths within fs root
        $local_abspath = $this->fs_root . $abspath;
        if (!is_dir($local_abspath)) {
            throw new RuntimeException(
                "WordPress ABSPATH directory not found in fs root: {$local_abspath} " .
                    "(remote ABSPATH: {$abspath}). Has the file sync completed?",
            );
        }

        $local_wp_admin = $wp_admin_path !== null
            ? $this->fs_root . $wp_admin_path
            : null;
        $local_wp_includes = $wp_includes_path !== null
            ? $this->fs_root . $wp_includes_path
            : null;
        $local_content_dir = $content_dir !== null
            ? $this->fs_root . $content_dir
            : null;
        $local_plugins_dir = $plugins_dir !== null
            ? $this->fs_root . $plugins_dir
            : null;
        $local_mu_plugins_dir = $mu_plugins_dir !== null
            ? $this->fs_root . $mu_plugins_dir
            : null;
        $local_uploads_basedir = $uploads_basedir !== null
            ? $this->fs_root . $uploads_basedir
            : null;

        // Determine which components are "detached" — located outside
        // their conventional parent directory on the source server.
        // wp-admin and wp-includes are detached when their resolved path
        // differs from the ABSPATH/wp-admin or ABSPATH/wp-includes path
        // (e.g. WP Cloud where they live behind __wp__/).
        $wp_admin_detached = $wp_admin_path !== null
            && $wp_admin_path !== $abspath . "/wp-admin";
        $wp_includes_detached = $wp_includes_path !== null
            && $wp_includes_path !== $abspath . "/wp-includes";
        $content_detached = $content_dir !== null
            && strpos($content_dir, $abspath . "/") !== 0;
        $plugins_detached = $plugins_dir !== null
            && $content_dir !== null
            && strpos($plugins_dir, $content_dir . "/") !== 0;
        $mu_plugins_detached = $mu_plugins_dir !== null
            && $content_dir !== null
            && strpos($mu_plugins_dir, $content_dir . "/") !== 0;
        $uploads_detached = $uploads_basedir !== null
            && $content_dir !== null
            && strpos($uploads_basedir, $content_dir . "/") !== 0;

        // If any sub-component is detached from content_dir, we need to
        // "explode" wp-content into a real directory with individual symlinks
        // rather than symlinking the content_dir wholesale.
        $need_exploded_content =
            $plugins_detached || $mu_plugins_detached || $uploads_detached;

        // Create the target directory if it doesn't exist
        if (!is_dir($flatten_to)) {
            if (!mkdir($flatten_to, 0755, true)) {
                throw new RuntimeException(
                    "Failed to create flatten-to directory: {$flatten_to}",
                );
            }
            $this->audit_log(
                "FLAT-DOCUMENT-ROOT | Created directory: {$flatten_to}",
            );
        }

        $this->audit_log(
            sprintf(
                "FLAT-DOCUMENT-ROOT | abspath=%s wp_admin=%s wp_includes=%s " .
                    "content_dir=%s content_detached=%s " .
                    "plugins_detached=%s mu_plugins_detached=%s uploads_detached=%s",
                $abspath,
                $wp_admin_path ?? "(from abspath)",
                $wp_includes_path ?? "(from abspath)",
                $content_dir ?? "(not set)",
                $content_detached ? "yes" : "no",
                $plugins_detached ? "yes" : "no",
                $mu_plugins_detached ? "yes" : "no",
                $uploads_detached ? "yes" : "no",
            ),
        );

        $created = 0;
        $refreshed = 0;
        $forced = 0;

        // Determine what to skip from ABSPATH enumeration.
        // Components with known detached locations are handled separately.
        $skip_from_abspath = [];
        if ($content_detached || $need_exploded_content) {
            $skip_from_abspath["wp-content"] = true;
        }
        if ($wp_admin_detached) {
            $skip_from_abspath["wp-admin"] = true;
        }
        if ($wp_includes_detached) {
            $skip_from_abspath["wp-includes"] = true;
        }

        // Phase 1: Symlink all entries from ABSPATH into flatten-to.
        // This covers core files (index.php, wp-load.php, wp-config.php, etc.)
        // and wp-admin/wp-includes when they're directly under ABSPATH.
        $entries = @scandir($local_abspath);
        if ($entries === false) {
            throw new RuntimeException(
                "Failed to scan ABSPATH directory: {$local_abspath}",
            );
        }

        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            if (isset($skip_from_abspath[$entry])) {
                $this->audit_log(
                    "FLAT-DOCUMENT-ROOT | Skipping '{$entry}' from ABSPATH " .
                        "(will be sourced from resolved location)",
                );
                continue;
            }

            $source = $local_abspath . "/" . $entry;
            $target = $flatten_to . "/" . $entry;
            $this->flatten_place_symlink(
                $source,
                $target,
                $force,
                $created,
                $refreshed,
                $forced,
            );
        }

        // Phase 1b: Symlink detached wp-admin and wp-includes from their
        // resolved physical locations (e.g. /wordpress/wp-admin on WP Cloud).
        if ($wp_admin_detached && $local_wp_admin !== null && is_dir($local_wp_admin)) {
            $this->flatten_place_symlink(
                $local_wp_admin,
                $flatten_to . "/wp-admin",
                $force,
                $created,
                $refreshed,
                $forced,
            );
        }
        if ($wp_includes_detached && $local_wp_includes !== null && is_dir($local_wp_includes)) {
            $this->flatten_place_symlink(
                $local_wp_includes,
                $flatten_to . "/wp-includes",
                $force,
                $created,
                $refreshed,
                $forced,
            );
        }

        // Phase 1c: Symlink wp-config.php from ABSPATH's parent directory.
        // WordPress allows wp-config.php one directory above ABSPATH —
        // wp-load.php checks dirname(ABSPATH) as a fallback. On WP Cloud
        // the typical layout is /srv/htdocs/wp-config.php with ABSPATH at
        // /srv/htdocs/wordpress/, so Phase 1's ABSPATH scan won't find it.
        $wp_config_in_flatten = $flatten_to . "/wp-config.php";
        if (!file_exists($wp_config_in_flatten)) {
            $parent_of_abspath = dirname($abspath);
            $local_parent_wp_config = $this->fs_root . $parent_of_abspath . "/wp-config.php";
            if (file_exists($local_parent_wp_config)) {
                $this->flatten_place_symlink(
                    $local_parent_wp_config,
                    $wp_config_in_flatten,
                    $force,
                    $created,
                    $refreshed,
                    $forced,
                );
                $this->audit_log(
                    "FLAT-DOCUMENT-ROOT | Symlinked wp-config.php from ABSPATH parent: " .
                        "{$parent_of_abspath}/wp-config.php",
                );
            }
        }


        // Phase 2: Handle wp-content when it's outside ABSPATH
        if ($need_exploded_content && $local_content_dir !== null) {
            // wp-content must be a real directory because some sub-components
            // (plugins, mu-plugins, or uploads) live outside content_dir.
            $wp_content_target = $flatten_to . "/wp-content";
            $this->flatten_ensure_real_directory(
                $wp_content_target,
                $force,
                $forced,
            );

            // Symlink all entries from content_dir into the real wp-content dir
            if (is_dir($local_content_dir)) {
                $content_entries = @scandir($local_content_dir) ?: [];
                // Determine which sub-entries to skip (will be overridden)
                $skip_from_content = [];
                if ($plugins_detached) {
                    $skip_from_content["plugins"] = true;
                }
                if ($mu_plugins_detached) {
                    $skip_from_content["mu-plugins"] = true;
                }
                if ($uploads_detached) {
                    $skip_from_content["uploads"] = true;
                }

                foreach ($content_entries as $entry) {
                    if ($entry === "." || $entry === "..") {
                        continue;
                    }
                    if (isset($skip_from_content[$entry])) {
                        continue;
                    }
                    $source = $local_content_dir . "/" . $entry;
                    $target = $wp_content_target . "/" . $entry;
                    $this->flatten_place_symlink(
                        $source,
                        $target,
                        $force,
                        $created,
                        $refreshed,
                        $forced,
                    );
                }
            }

            // Symlink detached sub-components into wp-content
            if ($plugins_detached && is_dir($local_plugins_dir)) {
                $target = $wp_content_target . "/plugins";
                $this->flatten_place_symlink(
                    $local_plugins_dir,
                    $target,
                    $force,
                    $created,
                    $refreshed,
                    $forced,
                );
            }
            if ($mu_plugins_detached && is_dir($local_mu_plugins_dir)) {
                $target = $wp_content_target . "/mu-plugins";
                $this->flatten_place_symlink(
                    $local_mu_plugins_dir,
                    $target,
                    $force,
                    $created,
                    $refreshed,
                    $forced,
                );
            }
            if ($uploads_detached && is_dir($local_uploads_basedir)) {
                $target = $wp_content_target . "/uploads";
                $this->flatten_place_symlink(
                    $local_uploads_basedir,
                    $target,
                    $force,
                    $created,
                    $refreshed,
                    $forced,
                );
            }
        } elseif ($content_detached && $local_content_dir !== null) {
            // Content dir is outside ABSPATH but sub-components are inside it.
            // Simple case: just symlink the whole content_dir as wp-content.
            if (is_dir($local_content_dir)) {
                $target = $flatten_to . "/wp-content";
                $this->flatten_place_symlink(
                    $local_content_dir,
                    $target,
                    $force,
                    $created,
                    $refreshed,
                    $forced,
                );
            } else {
                $this->audit_log(
                    "FLAT-DOCUMENT-ROOT | Warning: content_dir not found in fs root: " .
                        "{$local_content_dir} (remote: {$content_dir})",
                    true,
                );
            }
        }

        $this->audit_log(
            sprintf(
                "FLAT-DOCUMENT-ROOT | Complete: %d created, %d refreshed, %d force-replaced",
                $created,
                $refreshed,
                $forced,
            ),
            true,
        );

        $result = [
            "status" => "complete",
            "flatten_to" => $flatten_to,
            "fs_root" => $this->fs_root,
            "abspath" => $abspath,
            "wp_admin_path" => $wp_admin_path,
            "wp_includes_path" => $wp_includes_path,
            "content_dir" => $content_dir,
            "content_detached" => $content_detached,
            "created" => $created,
            "refreshed" => $refreshed,
            "force_replaced" => $forced,
        ];
        if (!$this->progress->is_quiet_lifecycle()) {
            fwrite($this->progress_fd, json_encode($result) . "\n");
        }
        $this->output_progress(array_merge(["type" => "flat_docroot_complete"], $result));
    }

    /**
     * Clean a path value from preflight data: trim, strip trailing slash.
     * Returns null if the value is not a non-empty string.
     */
    private function flatten_clean_path($value): ?string
    {
        return PathUtils::clean_path_value($value);
    }

    /**
     * Compute a relative path from $from to $to.
     *
     * Both paths must be absolute. Returns a relative path such that
     * a symlink at $from/$name pointing to the result will resolve to $to.
     *
     * Example: relative_path('/a/b/c', '/a/d/e') => '../../d/e'
     */
    private static function compute_relative_path(
        string $from,
        string $to
    ): string {
        return PathUtils::relative_path($from, $to);
    }

    /**
     * Create or refresh a symlink at $target pointing to $source.
     * Handles conflicts (existing non-symlinks) based on --force flag.
     *
     * The symlink value is computed as a relative path from the symlink's
     * parent directory to the source, so it works regardless of CWD and
     * survives directory moves.
     */
    private function flatten_place_symlink(
        string $source,
        string $target,
        bool $force,
        int &$created,
        int &$refreshed,
        int &$forced
    ): void {
        // Resolve both paths to absolute so we can compute a correct
        // relative symlink value.  The source may not have a realpath()
        // (e.g. broken symlink), but its parent directory should exist.
        $abs_source = realpath($source);
        if ($abs_source === false) {
            // Source itself may be a symlink or not exist yet — try
            // resolving the parent and appending the basename.
            $parent_real = realpath(dirname($source));
            if ($parent_real === false) {
                throw new RuntimeException(
                    "Cannot resolve source path for symlink: {$source}",
                );
            }
            $abs_source = $parent_real . "/" . basename($source);
        }

        // The target's parent must exist (we create flatten-to before calling this).
        $target_parent_real = realpath(dirname($target));
        if ($target_parent_real === false) {
            throw new RuntimeException(
                "Cannot resolve target parent directory: " . dirname($target),
            );
        }

        $link_value = self::compute_relative_path($target_parent_real, $abs_source);

        // If the target is already a symlink, check if it resolves to the
        // same place. Refresh if not, skip if already correct.
        if (is_link($target)) {
            $current_link_target = readlink($target);
            if ($current_link_target === $link_value) {
                $refreshed++;
                return;
            }
            // Points elsewhere — remove and recreate
            unlink($target);
            $this->audit_log(
                "FLAT-DOCUMENT-ROOT | Refreshed symlink: {$target} (was -> {$current_link_target})",
            );
            if (!symlink($link_value, $target)) {
                throw new RuntimeException(
                    "Failed to create symlink: {$target} -> {$link_value}",
                );
            }
            $refreshed++;
            return;
        }

        // If something exists at the target path that is not a symlink,
        // this is a conflict.
        if (file_exists($target)) {
            if (!$force) {
                throw new RuntimeException(
                    "Cannot create symlink at {$target}: a non-symlink " .
                        (is_dir($target) ? "directory" : "file") .
                        " already exists. Use --force to remove it and replace with a symlink.",
                );
            }

            $type = is_dir($target) ? "directory" : "file";
            $this->audit_log(
                "FLAT-DOCUMENT-ROOT FORCE | Removing conflicting {$type}: {$target}",
                true,
            );

            // At this point, we know $target is not a symlink (symlinks
            // are handled above and return early). So we only need to
            // distinguish between directories and regular files.
            if (is_dir($target)) {
                $this->remove_directory_recursive($target);
            } else {
                unlink($target);
            }
            $forced++;
        }

        // Create the symlink
        if (!symlink($link_value, $target)) {
            throw new RuntimeException(
                "Failed to create symlink: {$target} -> {$link_value}",
            );
        }
        $this->audit_log(
            "FLAT-DOCUMENT-ROOT | Created symlink: {$target} -> {$link_value}",
        );
        $created++;
    }

    /**
     * Ensure a path is a real directory (not a symlink).
     * If it's a symlink, remove it (or error without --force).
     * If it doesn't exist, create it.
     */
    private function flatten_ensure_real_directory(
        string $path,
        bool $force,
        int &$forced
    ): void {
        if (is_link($path)) {
            if (!$force) {
                throw new RuntimeException(
                    "Cannot create real directory at {$path}: a symlink already " .
                        "exists. Use --force to remove it.",
                );
            }
            $this->audit_log(
                "FLAT-DOCUMENT-ROOT FORCE | Replacing symlink with real directory: {$path}",
                true,
            );
            unlink($path);
            $forced++;
        }

        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true)) {
                throw new RuntimeException(
                    "Failed to create directory: {$path}",
                );
            }
            $this->audit_log(
                "FLAT-DOCUMENT-ROOT | Created directory: {$path}",
            );
        }
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private function remove_directory_recursive(string $dir): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            throw new RuntimeException("Failed to scan directory for removal: {$dir}");
        }
        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            $path = $dir . "/" . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->remove_directory_recursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * If --new-site-url is set, derive the source origin from the export URL
     * and append implicit --rewrite-url mappings for both HTTP and HTTPS
     * variants of the old URL to $options. The new URL is used verbatim.
     */
    private function resolve_new_site_url_option(array &$options): void
    {
        if (empty($options["new_site_url"])) {
            return;
        }

        $parsed_url = parse_url($this->remote_url);
        if (!$parsed_url || !isset($parsed_url['scheme'], $parsed_url['host'])) {
            throw new InvalidArgumentException(
                "--new-site-url requires a valid export URL to derive the source site origin.",
            );
        }

        $host_with_port = $parsed_url['host'];
        if (!empty($parsed_url['port'])) {
            $host_with_port .= ':' . $parsed_url['port'];
        }

        if (!isset($options["rewrite_url"])) {
            $options["rewrite_url"] = [];
        }

        // Rewrite both http:// and https:// variants of the old origin
        // to the new URL verbatim, so we catch references stored with
        // either scheme in the database.
        $new_url = $options["new_site_url"];
        $options["rewrite_url"][] = ['https://' . $host_with_port, $new_url];
        $options["rewrite_url"][] = ['http://' . $host_with_port, $new_url];
    }

    private function escape_pdo_dsn_value(string $value): string
    {
        return str_replace(';', ';;', $value);
    }

    private function create_sqlite_target_pdo(string $target_path, string $target_db): PDO
    {
        if (!extension_loaded("pdo_sqlite")) {
            throw new RuntimeException(
                "SQLite target support requires the pdo_sqlite extension.",
            );
        }

        // The bundled loader require_onces a fixed set of class files
        // relative to its own dirname. When the host already loaded a
        // different copy of those same classes (notably WordPress
        // Playground's auto_prepend), each class declaration would throw
        // a fatal "name already in use". Skip the loader entirely when the
        // host's copy is already in memory — both trees expose the same
        // public class names, so the existing instance is fine.
        $driver_loader = resolve_sqlite_integration_path("/wp-pdo-mysql-on-sqlite.php");
        if (
            class_exists(\WP_PDO_MySQL_On_SQLite::class, false) &&
            class_exists(\WP_Parser_Grammar::class, false)
        ) {
            $driver_loader = null;
        }

        if ($target_path !== ':memory:') {
            $target_dir = dirname($target_path);
            if ($target_dir !== '' && $target_dir !== '.' && !is_dir($target_dir)) {
                if (!mkdir($target_dir, 0777, true) && !is_dir($target_dir)) {
                    throw new RuntimeException(
                        "Cannot create SQLite directory: {$target_dir}",
                    );
                }
            }
        }

        if ($driver_loader !== null) { require_once $driver_loader; }

        $dsn = sprintf(
            "mysql-on-sqlite:path=%s;dbname=%s",
            $this->escape_pdo_dsn_value($target_path),
            $this->escape_pdo_dsn_value($target_db),
        );

        try {
            $pdo = new \WP_PDO_MySQL_On_SQLite($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Cannot connect to target SQLite database: " . $e->getMessage(),
                0,
                $e,
            );
        }

        // SQL dumps from MySQLDumpProducer encode every value as
        // FROM_BASE64('...'), and deactivate_host_plugins() reuses the same
        // encoding for its UPDATE — so the SQLite connection needs both.
        $sqlite_pdo = $pdo->get_connection()->get_pdo();
        register_sqlite_function($sqlite_pdo, 'FROM_BASE64', function ($data) {
            if ($data === null) {
                return null;
            }
            return base64_decode($data);
        });
        register_sqlite_function($sqlite_pdo, 'TO_BASE64', function ($data) {
            if ($data === null) {
                return null;
            }
            return base64_encode($data);
        });

        return $pdo;
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
                if(!$content_dir) {
                    throw new InvalidArgumentException(
                        "--target-sqlite-path option is required but was missing.",
                    );
                }
                $target_path = $this->get_filesystem_root_path() . $content_dir . '/database/.ht.sqlite';
                $this->audit_log("DB-APPLY | defaulting SQLite path to: {$target_path}");
                $this->progress->show_lifecycle_line("SQLite path: {$target_path}\n");
            }

            // Persist target database configuration for apply-runtime.
            $this->state["apply"]["target_engine"] = "sqlite";
            $this->state["apply"]["target_db"] = $target_db;
            $this->state["apply"]["target_sqlite_path"] = $target_path;

            return [
                $this->create_sqlite_target_pdo($target_path, $target_db),
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

        $dsn = "mysql:host={$target_host};port={$target_port};dbname={$target_db};charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, $target_user, $target_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_LOCAL_INFILE => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Cannot connect to target MySQL database: " . $e->getMessage(),
                0,
                $e,
            );
        }

        return [
            $pdo,
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
                $this->progress->show_lifecycle_line("Discovered domains in SQL dump:\n");
                foreach ($domains as $domain) {
                    $mapped = isset($url_mapping[$domain]) ? " => {$url_mapping[$domain]}" : " (not mapped)";
                    $this->progress->show_lifecycle_line("  {$domain}{$mapped}\n");
                }
                $this->progress->show_lifecycle_line("\n");
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
            $this->progress->show_lifecycle_line("Resuming db-apply (executed: {$statements_executed} statements)\n");
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
            $this->progress->show_lifecycle_line("Starting db-apply\n");
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
        $sqlite_prepared_statement_cache = [];
        $sqlite_prepared_statement_cache_order = [];
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

        $this->audit_log(
            "CONNECTED | {$connection_label}",
            false,
        );

        // Stream db.sql through the query stream and execute. Use the
        // fast strcspn-based parser by default; it self-falls-back to
        // WP_MySQL_Naive_Query_Stream if it ever fails to make progress
        // (buffer overflow without a top-level semicolon, or input drained
        // mid-string/comment), so the slow path is still available for
        // any input the fast scanner doesn't handle.
        $query_stream = new WP_MySQL_FastQueryStream();
        $query_stream->set_error_logger(function (array $err) use (&$stmt_count) {
            $this->audit_log(
                sprintf(
                    "FAST QUERY STREAM fallback | reason=%s | byte_offset=%d | stmt=%d | %s | context=%.200s",
                    $err['reason'] ?? '?',
                    $err['byte_offset'] ?? 0,
                    $stmt_count,
                    $err['message'] ?? '',
                    $err['context'] ?? ''
                ),
                true
            );
            $this->progress->show_lifecycle_line(
                "Fast query stream fell back to lexer-based parser at byte offset "
                . ($err['byte_offset'] ?? 0) . "; see audit log for details\n"
            );
        });
        $sql_handle = fopen($sql_file, "r");
        if (!$sql_handle) {
            throw new RuntimeException("Cannot open SQL file: {$sql_file}");
        }

        $sql_file_size = filesize($sql_file);
        $total_bytes_read = 0;
        $stmt_count = 0;
        $skipped = 0;
        $save_every = 100;
        $stmts_since_save = 0;

        // Load pre-computed statement count from db-pull for progress reporting
        $sql_stats_file = $this->state_dir . "/.import-sql-stats.json";
        $statements_total = null;
        if (file_exists($sql_stats_file)) {
            $stats = json_decode(file_get_contents($sql_stats_file), true);
            if (is_array($stats) && isset($stats["statements_total"])) {
                $statements_total = (int) $stats["statements_total"];
            }
        }

        // If resuming, seek to saved position. bytes_read is the byte offset
        // right after the last successfully executed query (tracked via
        // query_stream->get_bytes_consumed()), so no statement skipping is
        // needed after seeking — we're exactly at the next un-executed query.
        $seek_offset = 0;
        $stmts_to_skip = 0;
        if ($bytes_read > 0 && $bytes_read < $sql_file_size) {
            fseek($sql_handle, $bytes_read);
            $total_bytes_read = $bytes_read;
            $seek_offset = $bytes_read;
        } elseif ($statements_executed > 0) {
            // Can't seek — need to scan from beginning and skip statements
            $stmts_to_skip = $statements_executed;
        }

        $this->output_progress([
            "status" => "starting",
            "phase" => "db-apply",
            "statements_total" => $statements_total,
            "message" => "Applying SQL" . ($statements_total !== null ? " ({$statements_total} statements)" : ""),
        ]);

        try {
            $chunk_size = 64 * 1024; // 64KB read chunks

            while (!feof($sql_handle)) {
                // Check shutdown
                if ($this->shutdown_requested) {
                    $this->audit_log("SHUTDOWN REQUESTED | saving state", true);
                    break;
                }
                if (function_exists("pcntl_signal_dispatch")) {
                    pcntl_signal_dispatch();
                }

                $data = fread($sql_handle, $chunk_size);
                if ($data === false || $data === '') {
                    break;
                }
                $total_bytes_read += strlen($data);
                $query_stream->append_sql($data);

                while ($query_stream->next_query()) {
                    $query = $query_stream->get_query();
                    $stmt_count++;

                    // Skip already-executed statements on resume
                    if ($stmts_to_skip > 0) {
                        $stmts_to_skip--;
                        continue;
                    }

                    // Execute against target database
                    $executed_query = $query;
                    try {
                        $this->execute_db_apply_query(
                            $pdo,
                            $query,
                            $stmt_rewriter,
                            $sqlite_prepared_pdo,
                            $sqlite_prepared_statement_cache,
                            $sqlite_prepared_statement_cache_order,
                            $executed_query,
                        );
                    } catch (PDOException $e) {
                        $this->audit_log(
                            sprintf(
                                "SQL ERROR | stmt=%d | %s | query=%.200s",
                                $stmt_count,
                                $e->getMessage(),
                                $executed_query,
                            ),
                            true,
                        );
                        throw new RuntimeException(
                            "SQL execution error at statement {$stmt_count}: " .
                            $e->getMessage(),
                        );
                    }

                    $statements_executed++;
                    $stmts_since_save++;

                    // Save state periodically. bytes_read is the file offset
                    // right after the last extracted query — NOT total_bytes_read,
                    // which includes bytes buffered in the query stream that haven't
                    // formed a complete query yet. This ensures resumption starts at
                    // the exact boundary between executed and un-executed queries.
                    if ($stmts_since_save >= $save_every) {
                        $this->state["apply"]["statements_executed"] = $statements_executed;
                        $this->state["apply"]["bytes_read"] = $seek_offset + $query_stream->get_bytes_consumed();
                        $this->save_state($this->state);
                        $stmts_since_save = 0;

                        // Progress output
                        $apply_fraction = $sql_file_size > 0
                            ? $total_bytes_read / $sql_file_size
                            : null;
                        $pct = $apply_fraction !== null ? round($apply_fraction * 100, 1) : 0;

                        $progress_message = sprintf(
                            "%s statements",
                            $statements_total === null
                                ? number_format($statements_executed)
                                : number_format($statements_executed) . " / " . number_format($statements_total),
                        );

                        $this->output_progress([
                            "phase" => "db-apply",
                            "statements_executed" => $statements_executed,
                            "bytes_read" => $total_bytes_read,
                            "bytes_total" => $sql_file_size,
                            "pct" => $pct,
                            "statements_total" => $statements_total,
                            "message" => $progress_message,
                        ]);

                        $this->progress->show_progress_line($progress_message, $apply_fraction);
                    }
                }
            }

            // Drain any remaining buffered query
            $query_stream->mark_input_complete();
            while ($query_stream->next_query()) {
                $query = $query_stream->get_query();
                $stmt_count++;

                if ($stmts_to_skip > 0) {
                    $stmts_to_skip--;
                    continue;
                }

                $executed_query = $query;
                try {
                    $this->execute_db_apply_query(
                        $pdo,
                        $query,
                        $stmt_rewriter,
                        $sqlite_prepared_pdo,
                        $sqlite_prepared_statement_cache,
                        $sqlite_prepared_statement_cache_order,
                        $executed_query,
                    );
                } catch (PDOException $e) {
                    $this->audit_log(
                        sprintf(
                            "SQL ERROR | stmt=%d | %s | query=%.200s",
                            $stmt_count,
                            $e->getMessage(),
                            $executed_query,
                        ),
                        true,
                    );
                    throw new RuntimeException(
                        "SQL execution error at statement {$stmt_count}: " .
                        $e->getMessage(),
                    );
                }

                $statements_executed++;
            }

            if ($this->shutdown_requested) {
                // Save partial progress
                $this->state["apply"]["statements_executed"] = $statements_executed;
                $this->state["apply"]["bytes_read"] = $seek_offset + $query_stream->get_bytes_consumed();
                $this->state["status"] = "partial";
                $this->save_state($this->state);
                $this->audit_log(
                    sprintf(
                        "PARTIAL db-apply | %d statements executed",
                        $statements_executed,
                    ),
                    true,
                );
                $this->output_progress([
                    "status" => "partial",
                    "phase" => "db-apply",
                    "statements_executed" => $statements_executed,
                    "statements_total" => $statements_total,
                    "message" => "db-apply partial: {$statements_executed} statements executed",
                ], true);
            } else {
                // Deactivate host-specific plugins before marking complete.
                // The host analyzer declares paths_to_remove; any entry under
                // wp-content/plugins/ means that plugin will be deleted from
                // disk during apply-runtime. We remove it from active_plugins
                // now, while the database connection is still open, so
                // WordPress won't complain about missing plugin files.
                // We skip deactivate_plugins() because the plugin files will
                // be gone by the time WordPress boots — firing deactivation
                // hooks into absent code is pointless.
                $deactivated = $this->deactivate_host_plugins($pdo);
                foreach ($deactivated as $basename) {
                    $this->audit_log("DB-APPLY | deactivated plugin {$basename} (host-specific)");
                }

                // Drop plugins whose URL builders break when the site
                // URL has a non-/ path segment (e.g. WordPress Playground's
                // /scope:<slug>/ iframe scope).
                $deactivated = $this->deactivate_path_incompatible_plugins(
                    $pdo,
                    (string) ($options["new_site_url"] ?? ""),
                );
                foreach ($deactivated as $basename) {
                    $this->audit_log("DB-APPLY | deactivated plugin {$basename} (path-incompatible siteurl)");
                }

                // Mark complete
                $this->state["apply"]["statements_executed"] = $statements_executed;
                $this->state["apply"]["bytes_read"] = $seek_offset + $query_stream->get_bytes_consumed();
                $this->state["status"] = "complete";
                $this->save_state($this->state);

                $this->audit_log(
                    sprintf(
                        "db-apply complete | %d statements executed",
                        $statements_executed,
                    ),
                    true,
                );

                $this->output_progress([
                    "status" => "complete",
                    "phase" => "db-apply",
                    "statements_executed" => $statements_executed,
                    "statements_total" => $statements_total,
                    "message" => "db-apply complete ({$statements_executed} statements executed)",
                ]);

                if (!$this->progress->is_quiet_lifecycle()) {
                    // Clear the progress line before printing the final message
                    $this->progress->clear_progress_line();
                }
                $this->progress->show_lifecycle_line("db-apply complete ({$statements_executed} statements executed)\n");
            }
        } finally {
            fclose($sql_handle);
        }
    }

    private function execute_db_apply_query(
        PDO $pdo,
        string $query,
        ?SqlStatementRewriter $stmt_rewriter,
        ?PDO $sqlite_prepared_pdo,
        array &$sqlite_prepared_statement_cache,
        array &$sqlite_prepared_statement_cache_order,
        string &$executed_query
    ): void {
        $executed_query = $query;

        if ($sqlite_prepared_pdo !== null) {
            $prepared_insert = $stmt_rewriter !== null
                ? $stmt_rewriter->build_sqlite_prepared_insert($query)
                : SQLitePreparedInsertBuilder::build($query);

            if ($prepared_insert !== null) {
                $executed_query = $prepared_insert['sql'];
                $statement = $sqlite_prepared_statement_cache[$prepared_insert['sql']] ?? null;
                if (!$statement instanceof PDOStatement) {
                    $statement = $sqlite_prepared_pdo->prepare($prepared_insert['sql']);
                    if ($statement === false) {
                        throw new PDOException('Failed to prepare SQLite INSERT statement.');
                    }

                    $sqlite_prepared_statement_cache[$prepared_insert['sql']] = $statement;
                    $sqlite_prepared_statement_cache_order[] = $prepared_insert['sql'];
                    if (count($sqlite_prepared_statement_cache_order) > self::SQLITE_PREPARED_INSERT_CACHE_MAX) {
                        $oldest_sql = array_shift($sqlite_prepared_statement_cache_order);
                        if (is_string($oldest_sql)) {
                            unset($sqlite_prepared_statement_cache[$oldest_sql]);
                        }
                    }
                } else {
                    $statement->closeCursor();
                }

                foreach ($prepared_insert['params'] as $index => $value) {
                    $statement->bindValue(
                        $index + 1,
                        $value,
                        $prepared_insert['param_types'][$index] ?? PDO::PARAM_STR
                    );
                }

                if ($statement->execute() === false) {
                    throw new PDOException('Failed to execute SQLite INSERT statement.');
                }
                return;
            }
        }

        if ($stmt_rewriter !== null) {
            $executed_query = $stmt_rewriter->rewrite($query);
        }

        $pdo->exec($executed_query);
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

        $plugin_dirs = [];
        foreach ($manifest->paths_to_remove as $rel_path) {
            if (preg_match('#^wp-content/plugins/([^/]+)$#', $rel_path, $m)) {
                $plugin_dirs[] = $m[1];
            }
        }

        return $this->deactivate_plugins_by_dir($pdo, $plugin_dirs, "host-specific");
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
        if ($new_site_url === "") {
            return [];
        }
        $path = parse_url($new_site_url, PHP_URL_PATH);
        if ($path === null || $path === "" || $path === "/") {
            return [];
        }

        return $this->deactivate_plugins_by_dir(
            $pdo,
            ['page-optimize'],
            "path-incompatible siteurl",
        );
    }

    /**
     * Remove plugin entries whose basename starts with one of $plugin_dirs
     * from the `active_plugins` option in the target database.
     *
     * Requires `$pdo` to support `FROM_BASE64()` — native on MySQL 5.6+,
     * registered on SQLite by create_sqlite_target_pdo().
     *
     * @param string[] $plugin_dirs  Plugin directory names to match against
     *                               each `active_plugins` entry's basename.
     * @param string   $reason       Short label used in audit log messages.
     * @return string[]              Plugin basenames actually removed.
     */
    private function deactivate_plugins_by_dir(PDO $pdo, array $plugin_dirs, string $reason): array
    {
        if (empty($plugin_dirs)) {
            return [];
        }

        $preflight_data = $this->state["preflight"]["data"] ?? [];
        $table_prefix = $preflight_data["database"]["wp"]["table_prefix"] ?? 'wp_';
        // Quote the table name to prevent SQL injection from a crafted prefix.
        $options_table = '`' . str_replace('`', '``', $table_prefix . 'options') . '`';

        // Stick to query()/exec() — WP_PDO_MySQL_On_SQLite overrides those
        // but not prepare(), and prepare() throws "object is uninitialized"
        // on the wrapper.
        $row = $pdo->query(
            "SELECT option_value FROM {$options_table} WHERE option_name = 'active_plugins'"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row || !isset($row['option_value'])) {
            return [];
        }

        // Use PhpSerializationProcessor to iterate string values safely —
        // no unserialize(), no risk of arbitrary object instantiation.
        $serialized = $row['option_value'];
        $processor = new PhpSerializationProcessor($serialized);
        if ($processor->is_malformed()) {
            return [];
        }

        // Partition active_plugins entries against the directory list.
        $deactivated_plugins = [];
        $retained_plugins = [];
        while ($processor->next_value()) {
            $basename = $processor->get_value();
            $is_match = false;
            foreach ($plugin_dirs as $dir) {
                if (strpos($basename, $dir . '/') === 0) {
                    $is_match = true;
                    break;
                }
            }
            if ($is_match) {
                $deactivated_plugins[] = $basename;
            } else {
                $retained_plugins[] = $basename;
            }
        }

        if (empty($deactivated_plugins)) {
            $this->audit_log("DB-APPLY | no {$reason} plugins found in active_plugins");
            return [];
        }

        // FROM_BASE64 carries the new value into SQL — base64 is
        // [A-Za-z0-9+/=], so the literal can't carry SQL-special characters
        // regardless of what a plugin basename contains.
        $encoded_value = base64_encode(serialize(array_values($retained_plugins)));
        $pdo->exec(
            "UPDATE {$options_table} SET option_value = FROM_BASE64('{$encoded_value}') WHERE option_name = 'active_plugins'"
        );
        // The SQL dump runs with AUTOCOMMIT=0 and issues a final COMMIT,
        // but autocommit stays off. Our UPDATE needs an explicit COMMIT.
        $pdo->exec('COMMIT');

        $this->audit_log(
            "DB-APPLY | updated active_plugins (" .
            count($deactivated_plugins) . " {$reason} plugin(s) removed)",
        );

        return $deactivated_plugins;
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
            $this->progress->show_lifecycle_line("Starting db-index\n");
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
            $this->progress->show_lifecycle_line("Resuming db-index\n");
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

        $this->progress->show_lifecycle_line("db-index complete: {$tables} tables\n");
        $this->progress->show_lifecycle_line("Table stats: {$tables_file}\n");
        $this->progress->show_lifecycle_line("Audit log: {$this->audit_log}\n");
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
        $cursor = $cursor ?? ($this->state[$state_key]["cursor"] ?? null);

        // Crash recovery: if we have a tracked file that's larger than expected,
        // truncate it. This happens if we crashed after writing but before saving
        // the new cursor, so we'll re-fetch the same data.
        $tracked_file = $this->state["current_file"] ?? null;
        $tracked_bytes = $this->state["current_file_bytes"] ?? null;
        if ($tracked_file !== null && $tracked_bytes !== null && file_exists($tracked_file)) {
            $actual_size = filesize($tracked_file);
            if ($actual_size > $tracked_bytes) {
                $this->audit_log(
                    sprintf(
                        "CRASH RECOVERY | Truncating %s from %d to %d bytes",
                        $tracked_file,
                        $actual_size,
                        $tracked_bytes,
                    ),
                    true,
                );
                $handle = fopen($tracked_file, "r+");
                if ($handle) {
                    ftruncate($handle, $tracked_bytes);
                    fclose($handle);
                }
            }
        }

        $params = $this->get_tuned_params("file_fetch");
        // Always send directory[] – see comment in download_remote_index().
        $export_dirs = $this->get_export_directories();
        if (!empty($export_dirs)) {
            $params["directory"] = $export_dirs;
        }
        $url = $this->build_url("file_fetch", $cursor, $params);
        $this->audit_log("Downloading file fetch from {$url}");
        $this->audit_log("POST data: " . json_encode($post_data));

        $context = new StreamingContext();
        $context->file_handle = null;
        $context->file_path = null;
        $context->file_ctime = null;

        // Resume recovery: if a file was partially downloaded in a previous
        // request, re-open it in append mode so continuation chunks (where
        // is_first=false) can still be written.  Without this, the context
        // starts with file_handle=null and non-first chunks are silently dropped.
        if ($tracked_file !== null && $tracked_bytes !== null && file_exists($tracked_file)) {
            $context->file_handle = fopen($tracked_file, "ab");
            if ($context->file_handle) {
                $context->file_path = $tracked_file;
                $context->file_bytes_written = $tracked_bytes;
                $this->audit_log(
                    sprintf(
                        "RESUME FILE | Re-opened %s at %d bytes for continued download",
                        $tracked_file,
                        $tracked_bytes,
                    ),
                    true,
                );
            }
        }

        $response_handler = new FileFetchResponseHandler(
            $cursor,
            $state_key,
            $context,
            self::SAVE_STATE_EVERY_N_CHUNKS,
            function (): bool {
                return $this->shutdown_requested;
            },
            function (string $state_key, ?string $cursor, StreamingContext $context): void {
                $this->save_file_fetch_checkpoint($state_key, $cursor, $context);
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
            function (string $path): void {
                $this->audit_log("Missing on server: {$path}", true);
            },
            function (array $chunk, string $phase, StreamingContext $context): void {
                $this->handle_error_chunk($chunk, $phase, $context);
            },
            function (array $chunk, string $phase): void {
                $this->handle_progress($chunk, $phase);
            },
            function (array $progress): void {
                $this->output_progress($progress, true);
            },
        );
        $context->on_chunk = [$response_handler, 'handle'];

        $cursor_before = $cursor;
        $request_start = microtime(true);
        try {
            $this->fetch_streaming(
                $url,
                $cursor,
                $context,
                $post_data,
                "file_fetch",
            );
        } catch (CurlTimeoutException $e) {
            $cursor = $response_handler->cursor();
            // Throws RuntimeException after MAX_CONSECUTIVE_TIMEOUTS
            // with no progress, so we don't retry forever.
            $this->assert_can_retry_consecutive_timeout("file_fetch", $cursor_before, $cursor);
            // Save state so the next invocation resumes from the
            // last cursor instead of crashing with exit code 1.
            $this->state[$state_key]["cursor"] = $cursor;
            $this->finalize_index_updates();
            if ($context->file_handle && $context->file_path) {
                fflush($context->file_handle);
                $this->state["current_file"] = $context->file_path;
                $this->state["current_file_bytes"] = $context->file_bytes_written;
            }
            $this->state["status"] = "partial";
            $this->save_state($this->state);
            return false;
        }
        $cursor = $response_handler->cursor();
        $complete = $response_handler->complete();
        $this->state["consecutive_timeouts"] = 0;
        $wall_time = microtime(true) - $request_start;

        $this->finalize_tuned_request(
            "file_fetch",
            $wall_time,
            $context->response_stats ?? [],
        );
        $this->state[$state_key]["cursor"] = $cursor;
        $this->finalize_index_updates();
        // Update file tracking: track in-progress file, or clear if complete/no active file
        if ($context->file_handle && $context->file_path) {
            fflush($context->file_handle);
            $this->state["current_file"] = $context->file_path;
            $this->state["current_file_bytes"] = $context->file_bytes_written;
        } else {
            $this->state["current_file"] = null;
            $this->state["current_file_bytes"] = null;
        }
        $this->save_state($this->state);

        return $complete;
    }

    private function save_file_fetch_checkpoint(
        string $state_key,
        ?string $cursor,
        StreamingContext $context
    ): void {
        $this->state[$state_key]["cursor"] = $cursor;
        if ($context->file_handle && $context->file_path) {
            fflush($context->file_handle);
            $this->state["current_file"] = $context->file_path;
            $this->state["current_file_bytes"] = $context->file_bytes_written;
        } else {
            $this->state["current_file"] = null;
            $this->state["current_file_bytes"] = null;
        }
        $this->save_state($this->state);
    }

    /**
     * Download the remote index stream and write to disk.
     */
    private function download_remote_index(?string $list_dir_override = null): bool
    {
        $index_state = $this->state["index"] ?? $this->default_state()["index"];
        $cursor = $index_state["cursor"] ?? null;

        $roots = $this->get_root_directories_from_preflight();
        if (empty($roots)) {
            throw new RuntimeException(
                "No root directories found. Either add directory[]=... to the " .
                    "export URL, or run preflight first so directories can be auto-detected.",
            );
        }

        $mode = file_exists($this->remote_index_file) ? "a" : "w";
        // Initialize the index counter from the existing file so resume
        // shows a monotonically increasing count.
        if ($mode === "a" && $this->index_entries_counted === 0) {
            $this->index_entries_counted = $this->count_newlines($this->remote_index_file);
        }
        if ($mode === "w") {
            $this->audit_log(
                "FILE CREATE | {$this->remote_index_file} | downloading fresh remote index",
            );
        } else {
            $this->audit_log(
                "FILE APPEND | {$this->remote_index_file} | resuming remote index download",
            );
        }
        $handle = fopen($this->remote_index_file, $mode);
        if (!$handle) {
            throw new RuntimeException("Failed to open remote index file");
        }

        $params = $this->get_tuned_params("file_index");
        if ($cursor === null) {
            $params["list_dir"] = $list_dir_override ?? $roots[0];
        }
        if ($this->follow_symlinks) {
            $params["follow_symlinks"] = "1";
        }
        if ($this->include_caches) {
            // Server defaults to skipping caches/VCS metadata/OS junk.
            // Opt in to include them when the consumer explicitly asks.
            $params["include_caches"] = "1";
        }
        // Always send directory[] to the server when we have export dirs.
        // Without this parameter, the server falls back to ABSPATH as the
        // scan root. On managed hosts like wp.com Atomic, ABSPATH points to
        // a shared WordPress core directory (e.g. /wordpress/core/6.9.4/)
        // rather than the site's document root, so the scan would miss
        // wp-content entirely (no plugins, themes, or uploads).
        $export_dirs = $this->get_export_directories();
        if (!empty($export_dirs)) {
            $params["directory"] = $export_dirs;
        }
        $url = $this->build_url("file_index", $cursor, $params);
        $context = new StreamingContext();

        $response_handler = new IndexResponseHandler(
            $handle,
            $cursor,
            $context,
            $this->index_entries_counted,
            self::SAVE_STATE_EVERY_N_CHUNKS,
            function (): bool {
                return $this->shutdown_requested;
            },
            function (?string $cursor): void {
                $this->state["index"] = [
                    "cursor" => $cursor,
                ];
                $this->save_state($this->state);
            },
            function (array $chunk, StreamingContext $context): void {
                $this->handle_metadata_chunk($chunk, $context);
            },
            function (array $chunk, string $phase, StreamingContext $context): void {
                $this->handle_error_chunk($chunk, $phase, $context);
            },
            function (array $chunk, string $phase): void {
                $this->handle_progress($chunk, $phase);
            },
            function (int $entries_counted): void {
                $this->show_remote_index_progress($entries_counted);
            },
        );
        $context->on_chunk = [$response_handler, 'handle'];

        $cursor_before = $cursor;
        $request_start = microtime(true);
        try {
            $this->fetch_streaming($url, $cursor, $context, null, "file_index");
        } catch (CurlTimeoutException $e) {
            $cursor = $response_handler->cursor();
            $this->index_entries_counted = $response_handler->entries_counted();
            // Throws RuntimeException after MAX_CONSECUTIVE_TIMEOUTS
            // with no progress, so we don't retry forever.
            $this->assert_can_retry_consecutive_timeout("file_index", $cursor_before, $cursor);
            fclose($handle);
            $this->state["index"] = ["cursor" => $cursor];
            $this->state["status"] = "partial";
            $this->save_state($this->state);
            return false;
        }
        $cursor = $response_handler->cursor();
        $complete = $response_handler->complete();
        $this->index_entries_counted = $response_handler->entries_counted();
        $this->state["consecutive_timeouts"] = 0;
        $wall_time = microtime(true) - $request_start;
        $this->finalize_tuned_request(
            "file_index",
            $wall_time,
            $context->response_stats ?? [],
        );
        fclose($handle);

        $this->state["index"] = [
            "cursor" => $complete ? null : $cursor,
        ];
        $this->save_state($this->state);

        return $complete;
    }

    private function show_remote_index_progress(int $entries_counted): void
    {
        if ($entries_counted > 0) {
            $this->progress->show_progress_line(
                "Scanning remote files — " .
                number_format($entries_counted) . " scanned"
            );
            return;
        }

        $this->progress->show_progress_line("Scanning remote files");
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
                $this->progress->tick_spinner();
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
        return new LocalImportFilesystem(
            $this->fs_root,
            $this->fs_root_nonempty_behavior,
            function (string $message, bool $to_console): void {
                $this->audit_log($message, $to_console);
            },
        );
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
        $cursor = $this->state["cursor"] ?? null;
        $complete = false;
        $mode = $this->sql_output_mode;

        // ── Set up write strategy based on output mode ──────────────

        $sql_handle = null;
        $mysql_conn = null;
        $buffer_handle = null;
        $sql_bytes_written = 0;
        $sql_buffer = "";

        if ($mode === "file") {
            $sql_file = $this->state_dir . "/db.sql";

            // Crash recovery: if SQL file is larger than expected, truncate it.
            // This happens if we crashed after writing but before saving the new cursor.
            $tracked_bytes = $this->state["sql_bytes"] ?? null;
            if ($tracked_bytes !== null && file_exists($sql_file)) {
                $actual_size = filesize($sql_file);
                if ($actual_size > $tracked_bytes) {
                    $this->audit_log(
                        sprintf(
                            "CRASH RECOVERY | Truncating db.sql from %d to %d bytes",
                            $actual_size,
                            $tracked_bytes,
                        ),
                        true,
                    );
                    $handle = fopen($sql_file, "r+");
                    if ($handle) {
                        ftruncate($handle, $tracked_bytes);
                        fclose($handle);
                    }
                }
            }

            $sql_bytes_written = file_exists($sql_file) ? filesize($sql_file) : 0;

            // Open in write mode if no cursor (starting fresh), append mode if resuming
            $sql_handle = fopen($sql_file, $cursor ? "a" : "w");
            if (!$sql_handle) {
                throw new RuntimeException("Cannot open SQL file: {$sql_file}");
            }

        } elseif ($mode === "stdout") {
            $sql_bytes_written = $this->state["sql_bytes"] ?? 0;

        } elseif ($mode === "mysql") {
            $sql_bytes_written = $this->state["sql_bytes"] ?? 0;

            $host = $this->mysql_host ?? "127.0.0.1";
            $user = $this->mysql_user ?? "root";
            $pass = $this->mysql_password ?? "";
            $name = $this->mysql_database;

            // Parse host for port/socket (same format as WordPress DB_HOST).
            // An explicit --mysql-port takes precedence over a port embedded
            // in the host string.
            $port = $this->mysql_port ?? 3306;
            $socket = null;
            if (strpos($host, ":") !== false) {
                list($host, $port_or_socket) = explode(":", $host, 2);
                if ($port_or_socket[0] === "/") {
                    $socket = $port_or_socket;
                } elseif ($this->mysql_port === null) {
                    $port = (int) $port_or_socket;
                }
            }

            $mysql_conn = new \mysqli($host, $user, $pass, $name, $port, $socket);
            if ($mysql_conn->connect_error) {
                throw new RuntimeException("MySQL connection failed: " . $mysql_conn->connect_error);
            }
            $mysql_conn->set_charset("utf8mb4");

            $this->audit_log(
                "SQL OUTPUT mysql | connected via multi_query(): {$user}@{$host}:{$port}/{$name}",
                true,
            );

            // Open a persistent buffer file so partial queries survive crashes.
            // Each SQL chunk is appended to this file as it arrives; when the
            // query completes and executes, the file is truncated. If the process
            // dies at any point, the next run reloads whatever was accumulated.
            $buffer_file = $this->state_dir . "/.sql-buffer";
            if (file_exists($buffer_file)) {
                $sql_buffer = file_get_contents($buffer_file);
                $this->audit_log(
                    sprintf("CRASH RECOVERY | Restored %d bytes from .sql-buffer", strlen($sql_buffer)),
                    true,
                );
            }
            // Open in write mode (truncate) if we loaded nothing, append if we
            // have a partial query to continue accumulating into.
            $buffer_handle = fopen($buffer_file, $sql_buffer !== "" ? "a" : "w");
            if (!$buffer_handle) {
                throw new RuntimeException("Cannot open SQL buffer file: {$buffer_file}");
            }
        }

        // Domain discovery and statement counting: scan SQL for URLs during download
        $query_stream = class_exists(WP_MySQL_Naive_Query_Stream::class)
            ? new WP_MySQL_Naive_Query_Stream()
            : null;
        $domain_collector = class_exists(DomainCollector::class)
            ? new DomainCollector()
            : null;
        $domains_file = $this->state_dir . "/.import-domains.json";
        $sql_stats_file = $this->state_dir . "/.import-sql-stats.json";
        $sql_statements_counted = (int) ($this->state["sql_statements_counted"] ?? 0);

        // Auto-detect the source site domain from the export URL so it
        // always appears in .import-domains.json even if the SQL dump
        // hasn't been fully scanned yet.
        if ($domain_collector) {
            $parsed_url = parse_url($this->remote_url);
            if ($parsed_url && isset($parsed_url['scheme'], $parsed_url['host'])) {
                $source_origin = $parsed_url['scheme'] . '://' . $parsed_url['host'];
                if (!empty($parsed_url['port'])) {
                    $source_origin .= ':' . $parsed_url['port'];
                }
                $domain_collector->merge([$source_origin]);
            }
        }

        // Load previously discovered domains (from earlier partial downloads)
        if ($domain_collector && file_exists($domains_file)) {
            $prev = json_decode(file_get_contents($domains_file), true);
            if (is_array($prev)) {
                $domain_collector->merge($prev);
            }
        }

        // Log current progress at start of request
        $has_cursor = $cursor !== null;
        $this->audit_log(
            sprintf(
                "START SQL REQUEST | mode=%s | cursor=%s | bytes_written=%s",
                $mode,
                $has_cursor ? "YES" : "NO",
                number_format($sql_bytes_written) . " bytes",
            ),
            false,
        );

        $curl_timed_out = false;
        $caught_exception = null;
        $buffer_not_flushed = "";
        $chunks_since_save = 0;
        $sync_sql_response_state = function (
            SqlResponseHandler $response_handler
        ) use (
            &$cursor,
            &$complete,
            &$sql_bytes_written,
            &$sql_buffer,
            &$sql_statements_counted,
            &$chunks_since_save
        ): void {
            $cursor = $response_handler->cursor();
            $complete = $response_handler->complete();
            $sql_bytes_written = $response_handler->sql_bytes_written();
            $sql_buffer = $response_handler->sql_buffer();
            $sql_statements_counted = $response_handler->sql_statements_counted();
            $chunks_since_save = $response_handler->chunks_since_save();
        };
        try {
            while (!$complete) {
                $params = $this->get_tuned_params("sql_chunk");
                $url = $this->build_url("sql_chunk", $cursor, $params);

                $context = new StreamingContext();
                $context->chunk_fingerprints = [];
                $response_handler = new SqlResponseHandler(
                    $mode,
                    $cursor,
                    $context,
                    $sql_handle,
                    $mysql_conn,
                    $buffer_handle,
                    $sql_buffer,
                    $sql_bytes_written,
                    $query_stream,
                    $domain_collector,
                    $sql_statements_counted,
                    $chunks_since_save,
                    self::SAVE_STATE_EVERY_N_CHUNKS,
                    function (): bool {
                        return $this->shutdown_requested;
                    },
                    function (
                        ?string $cursor,
                        int $sql_bytes_written,
                        int $sql_statements_counted
                    ): void {
                        $this->state["cursor"] = $cursor;
                        $this->state["sql_bytes"] = $sql_bytes_written;
                        $this->state["sql_statements_counted"] = $sql_statements_counted;
                        $this->save_state($this->state);
                    },
                    function (array $domains) use ($domains_file): void {
                        file_put_contents(
                            $domains_file,
                            json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                        );
                    },
                    function (
                        WP_MySQL_Naive_Query_Stream $query_stream,
                        DomainCollector $domain_collector,
                        int $sql_statements_counted
                    ): int {
                        $this->drain_query_stream_for_domains(
                            $query_stream,
                            $domain_collector,
                            $sql_statements_counted,
                        );
                        return $sql_statements_counted;
                    },
                    function (int $sql_bytes_written): void {
                        // Show download progress on the TTY progress line.
                        // The bytes accumulate across chunks and requests.
                        // Include estimated total from db-index when available,
                        // but only if the estimate is larger than what we've
                        // already downloaded — INFORMATION_SCHEMA estimates
                        // can be wildly off (e.g. 7 KB for a 22 MB dump).
                        $db_bytes_est = (int) ($this->state["db_index"]["bytes"] ?? 0);
                        $est_is_useful = $db_bytes_est > $sql_bytes_written;
                        $sql_fraction = $est_is_useful
                            ? $sql_bytes_written / $db_bytes_est
                            : null;
                        $sql_progress = $this->format_bytes($sql_bytes_written);
                        if ($est_is_useful) {
                            $sql_progress .= " / " . $this->format_bytes($db_bytes_est);
                        }
                        $this->progress->show_progress_line($sql_progress, $sql_fraction);
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
                        // Broken pipe — save state and exit cleanly so the
                        // pipe reader (e.g. `mysql`) can finish on its own.
                        $this->save_state($this->state);
                        exit(0);
                    },
                );
                $context->on_chunk = [$response_handler, "handle"];

                $cursor_before = $cursor;
                $request_start = microtime(true);
                try {
                    $this->fetch_streaming($url, $cursor, $context, null, "sql_chunk");
                } catch (CurlTimeoutException $e) {
                    $sync_sql_response_state($response_handler);

                    // Throws RuntimeException after MAX_CONSECUTIVE_TIMEOUTS
                    // with no progress, so we don't retry forever.
                    $this->assert_can_retry_consecutive_timeout("sql_chunk", $cursor_before, $cursor);
                    // Save state so the next invocation resumes from the
                    // last cursor instead of crashing with exit code 1.
                    if ($sql_handle) {
                        fflush($sql_handle);
                    }
                    $this->state["cursor"] = $cursor;
                    $this->state["sql_bytes"] = $sql_bytes_written;
                    $this->state["sql_statements_counted"] = $sql_statements_counted;
                    $this->state["status"] = "partial";
                    $this->save_state($this->state);
                    // Discard any pending SQL buffer — it's incomplete and
                    // will be re-fetched on the next invocation. Setting
                    // this to "" also prevents the finally block from
                    // throwing about un-executed buffered SQL.
                    $sql_buffer = "";
                    $curl_timed_out = true;
                    break;
                } catch (RuntimeException $e) {
                    $sync_sql_response_state($response_handler);

                    // The server may crash mid-response (max_execution_time,
                    // OOM, fatal error). This surfaces as either:
                    //  - "missing completion chunk" (response ended without it)
                    //  - cURL error 18/52/56 (partial transfer / recv error)
                    //  - "missing multipart boundary" (proxy error page)
                    // Treat these as a retryable partial response: save state
                    // so the next invocation resumes from the cursor. Unlike
                    // a timeout (where the buffer is discarded and re-fetched),
                    // we keep $sql_buffer intact here so the .sql-buffer file
                    // is preserved — the next run reloads it and continues
                    // accumulating from where the server left off.
                    $msg = $e->getMessage();
                    // Only retry connection-level curl errors that indicate
                    // the server crashed or the connection was interrupted.
                    // Do NOT retry content-encoding errors (e.g. gzip
                    // corruption, CURLE_BAD_CONTENT_ENCODING=61) — those
                    // will fail identically on every retry.
                    //   18 = CURLE_PARTIAL_FILE (transfer closed mid-stream)
                    //   52 = CURLE_GOT_NOTHING (empty response)
                    //   56 = CURLE_RECV_ERROR (connection reset / recv failure)
                    $is_retryable_curl = preg_match(
                        '/cURL error \((\d+)\):/', $msg, $curl_match
                    ) && in_array((int) $curl_match[1], [18, 52, 56]);
                    $is_retryable =
                        strpos($msg, "missing completion chunk") !== false ||
                        $is_retryable_curl ||
                        strpos($msg, "missing multipart boundary") !== false;
                    if ($is_retryable) {
                        $this->audit_log(
                            "INCOMPLETE RESPONSE | " . $msg .
                            " | buffered_sql=" . strlen($sql_buffer) . " bytes" .
                            " — will save state for retry",
                            true,
                        );
                        $this->assert_can_retry_consecutive_timeout("sql_chunk", $cursor_before, $cursor);
                        if ($sql_handle) {
                            fflush($sql_handle);
                        }
                        $this->state["cursor"] = $cursor;
                        $this->state["sql_bytes"] = $sql_bytes_written;
                        $this->state["sql_statements_counted"] = $sql_statements_counted;
                        $this->state["status"] = "partial";
                        $this->save_state($this->state);
                        $curl_timed_out = true;
                        break;
                    }
                    throw $e;
                }
                $sync_sql_response_state($response_handler);

                $this->state["consecutive_timeouts"] = 0;
                $wall_time = microtime(true) - $request_start;
                $this->finalize_tuned_request(
                    "sql_chunk",
                    $wall_time,
                    $context->response_stats ?? [],
                );

                // Save cursor for resumption (keep it even when complete for reference)
                if ($sql_handle) {
                    fflush($sql_handle);
                }

                $this->state["cursor"] = $cursor;
                // Clear sql_bytes when complete, otherwise save current position
                $this->state["sql_bytes"] = $complete ? null : $sql_bytes_written;
                $this->save_state($this->state);
            }

            // Drain any remaining statements after download completes
            if ($query_stream && $domain_collector) {
                $query_stream->mark_input_complete();
                $this->drain_query_stream_for_domains(
                    $query_stream,
                    $domain_collector,
                    $sql_statements_counted,
                );

                // Save discovered domains
                $domains = $domain_collector->get_domains();
                if (!empty($domains)) {
                    file_put_contents(
                        $domains_file,
                        json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                    );
                    $this->audit_log(
                        sprintf(
                            "DOMAINS DISCOVERED | %d unique domains saved to .import-domains.json",
                            count($domains),
                        ),
                        false,
                    );
                }

                // Save statement count for db-apply progress reporting
                if ($sql_statements_counted > 0) {
                    file_put_contents(
                        $sql_stats_file,
                        json_encode(["statements_total" => $sql_statements_counted]) . "\n",
                    );
                    $this->audit_log(
                        sprintf(
                            "SQL STATS | %d statements counted during download",
                            $sql_statements_counted,
                        ),
                        false,
                    );
                }
            }
        } catch (\Throwable $e) {
            $caught_exception = $e;
            throw $e;
        } finally {
            if ($sql_handle) {
                fclose($sql_handle);
            }
            if ($buffer_handle) {
                fclose($buffer_handle);
                $buffer_handle = null;
            }
            if ($mysql_conn) {
                $pending = $sql_buffer;
                $mysql_conn->close();
                $mysql_conn = null;
                // Clean up buffer file — if we got here with an empty buffer,
                // all queries were executed successfully.
                $buffer_file = $this->state_dir . "/.sql-buffer";
                if ($pending === "" && file_exists($buffer_file)) {
                    unlink($buffer_file);
                }
                if ($pending !== "") {
                    if ($caught_exception !== null) {
                        // An exception is already in flight (e.g. curl error,
                        // MySQL error). Don't mask it by throwing about the
                        // buffer — the buffer data is safely persisted in
                        // .sql-buffer and will be recovered on the next run.
                        $this->audit_log(
                            "BUFFER NOT FLUSHED | " . strlen($pending) .
                            " bytes in SQL buffer during exception unwind" .
                            " (original error: " . $caught_exception->getMessage() . ")",
                            true,
                        );
                    } elseif ($curl_timed_out) {
                        // Crash recovery — the buffer file is preserved on
                        // disk so the next invocation reloads it and continues
                        // accumulating from where the server left off.
                        $this->audit_log(
                            "BUFFER PRESERVED | " . strlen($pending) .
                            " bytes in SQL buffer saved for crash recovery",
                            true,
                        );
                    } else {
                        $buffer_not_flushed = $pending;
                    }
                }
            }
        }

        if ($buffer_not_flushed !== "") {
            throw new RuntimeException(
                "Buffered SQL was never executed (" . strlen($buffer_not_flushed) .
                " bytes) — incomplete export?"
            );
        }

        if ($curl_timed_out) {
            return;
        }
    }

    /**
     * Drain complete SQL statements from a query stream and scan their
     * base64-decoded values for URL domains.
     */
    public function drain_query_stream_for_domains(
        WP_MySQL_Naive_Query_Stream $query_stream,
        DomainCollector $domain_collector,
        ?int &$statements_counted = null
    ): void {
        while ($query_stream->next_query()) {
            $query = $query_stream->get_query();
            if ($statements_counted !== null) {
                $statements_counted++;
            }
            // Only scan INSERT statements (they contain data values).
            if (!self::sql_starts_with_token($query, \WP_MySQL_Lexer::INSERT_SYMBOL)) {
                continue;
            }
            // Only scan statements with base64 values
            if (strpos($query, "FROM_BASE64(") === false) {
                continue;
            }

            $table = self::extract_insert_table($query);
            $is_options_table = substr($table, -8) === '_options';

            $scanner = new Base64ValueScanner($query);
            while ($scanner->next_value()) {
                // For _options tables, extract the option_name (second column)
                // and skip transients — they contain ephemeral cached data
                // that would pollute the domain list.
                $option_name = null;
                $match_offset = $scanner->get_match_offset();
                if ($is_options_table) {
                    $option_name = self::extract_option_name($query, $match_offset);
                    if ($option_name !== null && (
                        strpos($option_name, '_transient') === 0 ||
                        strpos($option_name, '_site_transient') === 0
                    )) {
                        continue;
                    }
                }

                $new_domains = $domain_collector->scan($scanner->get_value());
                if (!empty($new_domains)) {
                    $row_id = self::extract_row_identifier($query, $match_offset);

                    $option_ctx = '';
                    if ($option_name !== null) {
                        $option_ctx = ' option=' . $option_name;
                    }

                    foreach ($new_domains as $domain) {
                        $this->audit_log(
                            sprintf(
                                "NEW DOMAIN | %s | table=%s %s%s",
                                $domain,
                                $table,
                                $row_id,
                                $option_ctx,
                            ),
                            false,
                        );
                    }
                }
            }
        }
    }

    /**
     * Extract the table name from an INSERT INTO statement.
     */
    private static function extract_insert_table(string $query): string
    {
        return SqlStatementInspector::extract_insert_table($query);
    }

    /**
     * Extract a row identifier (PK value or offset) from the INSERT row
     * containing the base64 expression at $offset.
     *
     * Scans backwards from $offset to find the row-opening parenthesis,
     * then reads the first column value — typically the primary key.
     */
    private static function extract_row_identifier(string $query, int $offset): string
    {
        return SqlStatementInspector::extract_row_identifier($query, $offset);
    }

    /**
     * Extract the option_name (second column) from a wp_options INSERT row.
     *
     * WordPress options tables have columns: option_id, option_name, option_value, autoload.
     * Given an offset inside the row, this finds the row-opening '(' and reads
     * past the first column (option_id) to extract the second column (option_name).
     */
    private static function extract_option_name(string $query, int $offset): ?string
    {
        return SqlStatementInspector::extract_option_name($query, $offset);
    }

    /**
     * Check whether a SQL statement's first keyword token matches a given token ID.
     * Skips leading whitespace and comments before the first SQL keyword.
     */
    private static function sql_starts_with_token(string $sql, int $expected_token_id): bool
    {
        return SqlStatementInspector::starts_with_token($sql, $expected_token_id);
    }

    /**
     * Download table stats from the db_index endpoint.
     */
    private function download_db_index(): void
    {
        $cursor = $this->state["cursor"] ?? null;
        $complete = false;
        $tables_file = $this->state_dir . "/db-tables.jsonl";

        $stats = $this->state["db_index"] ?? [];
        $tables_written = (int) ($stats["tables"] ?? 0);
        $rows_estimated = (int) ($stats["rows_estimated"] ?? 0);
        $bytes_written = (int) ($stats["bytes"] ?? 0);

        if ($bytes_written > 0 && file_exists($tables_file)) {
            $actual_size = filesize($tables_file);
            if ($actual_size > $bytes_written) {
                $this->audit_log(
                    sprintf(
                        "CRASH RECOVERY | Truncating db-tables.jsonl from %d to %d bytes",
                        $actual_size,
                        $bytes_written,
                    ),
                    true,
                );
                $handle = fopen($tables_file, "r+");
                if ($handle) {
                    ftruncate($handle, $bytes_written);
                    fclose($handle);
                }
            }
        }

        $handle = fopen($tables_file, $cursor ? "a" : "w");
        if (!$handle) {
            throw new RuntimeException("Cannot open table stats file: {$tables_file}");
        }

        try {
            while (!$complete) {
                $params = [
                    "tables_per_batch" => 1000,
                ];
                $url = $this->build_url("db_index", $cursor, $params);

                $context = new StreamingContext();
                $response_handler = new DbIndexResponseHandler(
                    $handle,
                    $cursor,
                    $context,
                    $tables_written,
                    $rows_estimated,
                    $bytes_written,
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
                );
                $context->on_chunk = [$response_handler, "handle"];

                $cursor_before = $cursor;
                $request_start = microtime(true);
                try {
                    $this->fetch_streaming(
                        $url,
                        $cursor,
                        $context,
                        null,
                        "db_index",
                    );
                } catch (CurlTimeoutException $e) {
                    $cursor = $response_handler->cursor();
                    $complete = $response_handler->complete();
                    $tables_written = $response_handler->tables_written();
                    $rows_estimated = $response_handler->rows_estimated();
                    $bytes_written = $response_handler->bytes_written();

                    // Throws RuntimeException after MAX_CONSECUTIVE_TIMEOUTS
                    // with no progress, so we don't retry forever.
                    $this->assert_can_retry_consecutive_timeout("db_index", $cursor_before, $cursor);
                    fflush($handle);
                    $this->state["cursor"] = $cursor;
                    $this->state["db_index"] = [
                        "file" => $tables_file,
                        "tables" => $tables_written,
                        "rows_estimated" => $rows_estimated,
                        "bytes" => $bytes_written,
                        "updated_at" => time(),
                    ];
                    $this->state["status"] = "partial";
                    $this->save_state($this->state);
                    return;
                }
                $cursor = $response_handler->cursor();
                $complete = $response_handler->complete();
                $tables_written = $response_handler->tables_written();
                $rows_estimated = $response_handler->rows_estimated();
                $bytes_written = $response_handler->bytes_written();

                $this->state["consecutive_timeouts"] = 0;
                $wall_time = microtime(true) - $request_start;
                $this->finalize_tuned_request(
                    "db_index",
                    $wall_time,
                    $context->response_stats ?? [],
                );

                fflush($handle);
                $this->state["cursor"] = $cursor;
                $this->state["db_index"] = [
                    "file" => $tables_file,
                    "tables" => $tables_written,
                    "rows_estimated" => $rows_estimated,
                    "bytes" => $bytes_written,
                    "updated_at" => time(),
                ];
                $this->save_state($this->state);
            }
        } finally {
            fclose($handle);
        }
    }


    /**
     * Assert that a symlink target resolves to a path within $root.
     *
     * For absolute targets, the target itself must be under $root.
     * For relative targets, the resolved path (parent dir + target) must be
     * under $root. We normalize ".." segments without touching the filesystem,
     * since the target may not exist yet.
     *
     * @throws RuntimeException if the target escapes the root.
     */
    private function assert_symlink_target_within_root(
        string $symlink_parent_dir,
        string $target,
        string $root
    ): void {
        $this->local_filesystem()->assert_symlink_target_within_root(
            $symlink_parent_dir,
            $target,
            $root,
        );
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
        $mapped_relative = self::compute_relative_path(
            dirname($local_path),
            $mapped_absolute
        );

        $this->audit_log(
            "SYMLINK TARGET REMAP | {$path}: {$target} -> {$mapped_relative}",
            false,
        );

        return $mapped_relative;
    }

    /**
     * Checks if the remote index contains $path or any descendant under it.
     * Runs a memoized O(N) scan of .import-remote-index.jsonl.
     */
    private function remote_index_contains_path_prefix(string $path): bool
    {
        $path = rtrim(normalize_path($path), "/");
        if ($path === "") {
            return false;
        }

        if (isset($this->remote_index_prefix_cache[$path])) {
            return $this->remote_index_prefix_cache[$path];
        }

        if (!file_exists($this->remote_index_file)) {
            $this->remote_index_prefix_cache[$path] = false;
            return false;
        }

        $h = fopen($this->remote_index_file, "r");
        if (!$h) {
            $this->remote_index_prefix_cache[$path] = false;
            return false;
        }

        $prefix = $path . "/";
        $found = false;
        while (($line = fgets($h)) !== false) {
            try {
                $entry = $this->parse_index_line($line);
            } catch (RuntimeException $e) {
                continue;
            }
            if ($entry === null) {
                continue;
            }
            $entry_path = $entry["path"];
            if ($entry_path === $path || str_starts_with($entry_path, $prefix)) {
                $found = true;
                break;
            }
        }
        fclose($h);

        $this->remote_index_prefix_cache[$path] = $found;
        return $found;
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
        $this->progress->show_progress_line($file_progress_message, $file_fraction);
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
     * Build a short display path for progress messages: strip leading slash,
     * truncate from the left when too long.
     */
    private function display_path(string $path): string
    {
        $rel = ltrim($path, "/");
        $max = 60;
        if (strlen($rel) > $max) {
            $rel = "..." . substr($rel, -($max - 3));
        }
        return $rel;
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
        $this->progress->show_progress_line($error_progress_message);
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
        $url = $this->remote_url;
        $separator = strpos($url, "?") === false ? "?" : "&";

        $params["endpoint"] = $endpoint;
        if ($cursor) {
            // Also include cursor in query params as a fallback when headers are stripped.
            $params["cursor"] = $cursor;
        }
        $params["_cache_bust"] = time() . "-" . rand(0, 999999);

        return $url . $separator . http_build_query($params);
    }

    /**
     * Extract root directories from preflight wp_detect data.
     * Falls back to this when the URL doesn't contain directory[] params.
     */
    private function get_root_directories_from_preflight(): array
    {
        $roots = $this->state["preflight"]["data"]["wp_detect"]["roots"] ?? [];
        if (!is_array($roots) || empty($roots)) {
            return [];
        }
        $dirs = [];
        foreach ($roots as $root) {
            $path = $root["path"] ?? null;
            if (is_string($path) && $path !== "") {
                $dirs[] = rtrim($path, "/");
            }
        }
        $dirs = array_values(array_unique($dirs));
        if (!empty($dirs)) {
            $this->audit_log(
                "DIRECTORY AUTO-DETECT | from preflight wp_detect.roots: " .
                    implode(", ", $dirs),
            );
        }
        return $dirs;
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
        $dirs = $this->get_root_directories_from_preflight();
        if (empty($dirs)) {
            return [];
        }

        $preflight = $this->state["preflight"]["data"] ?? [];

        // Collect extra paths that may live outside the wp_detect roots.
        $extra_paths = [
            "document_root" => rtrim($preflight["runtime"]["document_root"] ?? "", "/"),
            "content_dir" => rtrim($preflight["database"]["wp"]["paths_urls"]["content_dir"] ?? "", "/"),
        ];

        if ($this->extra_directory !== null && $this->extra_directory !== "") {
            $extra_paths["extra_directory"] = rtrim($this->extra_directory, "/");
        }

        // auto_prepend_file / auto_append_file may point to directories
        // outside the WordPress roots (e.g. /scripts/env.php on Atomic).
        // Include those directories so the remote exporter traverses them.
        $ini_all = $preflight["runtime"]["ini_get_all"] ?? [];
        foreach (["auto_prepend_file", "auto_append_file"] as $ini_key) {
            $ini_path = $ini_all[$ini_key] ?? "";
            if (is_string($ini_path) && $ini_path !== "" && $ini_path[0] === "/") {
                $ini_dir = rtrim(dirname($ini_path), "/");
                if ($ini_dir !== "" && $ini_dir !== "/") {
                    $extra_paths[$ini_key] = $ini_dir;
                }
            }
        }

        foreach ($extra_paths as $label => $path) {
            if ($path === "") {
                continue;
            }
            // Check if this path is already covered by an existing dir.
            $covered = false;
            foreach ($dirs as $root) {
                if (
                    $path === $root ||
                    str_starts_with($path, $root . "/")
                ) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $dirs[] = $path;
                $this->audit_log(
                    "DIRECTORY AUTO-DETECT | adding {$label} outside roots: " .
                        $path,
                );
            }
        }

        return $dirs;
    }

    /**
     * Sorts an index file by path and removes duplicate entries.
     */
    private function sort_index_file(string $path): void
    {
        $this->index_sorter->sort($path);
    }

    /**
     * Return HMAC authentication headers formatted for curl ("Name: value"),
     * or an empty array if no secret was configured.
     *
     * @param string $body The request body content whose SHA-256 hash will
     *                     be included in the HMAC signature.  For CURLFile
     *                     uploads, pass the raw file content (not the
     *                     multipart envelope); for form-encoded POST, pass
     *                     the http_build_query() output; for GET, omit or
     *                     pass empty string.
     */
    private function get_hmac_headers(string $body = ''): array
    {
        if ($this->hmac_client === null) {
            return [];
        }
        return $this->hmac_client->get_curl_headers($body);
    }

    /**
     * Reset curl-related state at the start of each HTTP request.
     */
    private function reset_curl_state(): void
    {
        $this->last_curl_errno = null;
        $this->last_curl_timeout = false;
    }

    /**
     * User-Agent strings to try during preflight, in order of preference.
     * Some WAFs block browser UAs that carry custom auth headers, so we
     * start with an honest non-browser identity and fall back to common
     * browser strings.
     */
    public const USER_AGENTS = [
        "Reprint/1.0",
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0",
    ];

    private function get_base_headers(string $accept): array
    {
        $ua = $this->state["user_agent"] ?? self::USER_AGENTS[0];
        return [
            "User-Agent: {$ua}",
            "Accept: {$accept}",
            "Accept-Language: en-US,en;q=0.9",
            "Accept-Encoding: gzip, deflate",
            "Cache-Control: no-cache",
            "Pragma: no-cache",
            "Connection: keep-alive",
        ];
    }

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
        return function ($event) use ($context, &$current_chunk) {
            if ($event["type"] === "body") {
                $headers = $event["headers"];
                $chunk_type = $headers["x-chunk-type"] ?? "";
                if ($chunk_type === "file") {
                    if (!$current_chunk) {
                        $current_chunk = [
                            "headers" => $headers,
                            "body_streamed" => true,
                            "started" => false,
                        ];
                    }

                    if ($context->on_chunk) {
                        $stream_headers = $headers;
                        if (!empty($current_chunk["started"])) {
                            $stream_headers["x-first-chunk"] = "0";
                        }
                        // The parser emits a separate complete event after the
                        // last body bytes, so close/index the file from there.
                        $stream_headers["x-last-chunk"] = "0";
                        ($context->on_chunk)([
                            "headers" => $stream_headers,
                            "body" => $event["data"],
                            // Suppresses state saves while a streamed file
                            // part body is still being written.
                            "is_streaming_body" => true,
                        ]);
                    }
                    $current_chunk["started"] = true;
                    return;
                }

                if (!$current_chunk) {
                    $current_chunk = [
                        "headers" => $headers,
                        "body" => $event["data"],
                    ];
                } else {
                    $current_chunk["body"] =
                        ($current_chunk["body"] ?? "") .
                        $event["data"];
                }
            } elseif ($event["type"] === "complete") {
                $headers = $event["headers"];
                $chunk_type = $headers["x-chunk-type"] ?? "";
                if ($chunk_type === "file" && !empty($current_chunk["body_streamed"])) {
                    if ($context->on_chunk) {
                        $close_headers = $headers;
                        $close_headers["x-first-chunk"] = "0";
                        ($context->on_chunk)([
                            "headers" => $close_headers,
                            "body" => "",
                            // Forces a save at every streamed file-part
                            // boundary, even if the periodic counter has not
                            // reached SAVE_STATE_EVERY_N_CHUNKS.
                            "is_streaming_close" => true,
                        ]);
                    }
                } elseif ($current_chunk) {
                    // Chunk complete - emit to handler
                    if ($context->on_chunk) {
                        ($context->on_chunk)(
                            $current_chunk,
                        );
                    }
                } elseif ($headers) {
                    // No body data - emit just headers
                    if ($context->on_chunk) {
                        ($context->on_chunk)([
                            "headers" =>
                                $headers,
                            "body" => "",
                        ]);
                    }
                }
                $current_chunk = null;
            }
        };
    }

    /**
     * Check for curl errors after curl_exec and record timeout state.
     * Throws RuntimeException on any curl error.
     */
    private function check_curl_error($ch): void
    {
        if (!curl_errno($ch)) {
            return;
        }
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $timeout_errno = defined("CURLE_OPERATION_TIMEDOUT")
            ? CURLE_OPERATION_TIMEDOUT
            : 28;
        $this->last_curl_errno = $errno;
        $this->last_curl_timeout = $errno === $timeout_errno;
        if ($this->last_curl_timeout) {
            throw new CurlTimeoutException("cURL error: {$error}");
        }
        throw new RuntimeException("cURL error ($errno): {$error}");
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
        $body = ($body !== null && $body !== false) ? $body : '';

        $decoded = json_decode($body, true);
        $server_msg = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        $looks_like_html = !is_array($decoded) && $body !== '' && (
            stripos($body, '<html') !== false ||
            stripos($body, '<!doctype') !== false ||
            str_starts_with($body, '<')
        );

        // ── Redirects ────────────────────────────────────────────
        if ($http_code >= 300 && $http_code < 400) {
            $msg = $redirect_url
                ? "Wrong URL. The server redirected to {$redirect_url} " .
                  "(HTTP {$http_code}).\n\n" .
                  "Reprint does not follow redirects to avoid silently " .
                  "connecting to the wrong server. Retry with the target " .
                  "URL above."
                : "Wrong URL. The server returned a redirect (HTTP {$http_code}) " .
                  "instead of the export API.\n\n" .
                  "Reprint does not follow redirects. Check whether the site " .
                  "uses http vs https or www vs non-www and retry with the " .
                  "canonical URL.";
            return ['code' => 'REDIRECT', 'message' => $msg];
        }

        // ── Authentication / authorization ───────────────────────
        if ($http_code === 401 || $http_code === 403) {
            if ($this->hmac_client === null) {
                return [
                    'code' => 'AUTH_NO_SECRET',
                    'message' =>
                        "No --secret was provided. The remote site requires " .
                        "authentication.\n\n" .
                        "Pass --secret=YOUR_SECRET using the same secret " .
                        "configured in the Site Export plugin on the remote site.",
                ];
            }

            if ($server_msg === null) {
                return [
                    'code' => 'AUTH_FAILED',
                    'message' =>
                        "The request was blocked (HTTP {$http_code}) but the " .
                        "server did not say why. The exporter plugin always " .
                        "explains authentication failures, so something " .
                        "upstream is blocking the request — a server-level " .
                        "firewall, .htaccess rule, or security plugin.",
                ];
            }

            // The server tells us exactly what went wrong. Map each known
            // HMAC error to a targeted message.

            if (str_contains($server_msg, 'HMAC signature verification failed')) {
                return [
                    'code' => 'AUTH_SECRET_MISMATCH',
                    'message' =>
                        "Wrong shared secret. The --secret value does not match " .
                        "the one configured in the Site Export plugin settings " .
                        "(wp-admin → Site Export).",
                ];
            }

            if (str_contains($server_msg, 'timestamp expired')) {
                return [
                    'code' => 'AUTH_CLOCK_SKEW',
                    'message' =>
                        "Clock out of sync. {$server_msg}\n\n" .
                        "Check this machine's clock (run `date`) and compare " .
                        "it with the server's time.",
                ];
            }

            if (str_contains($server_msg, 'Content hash mismatch')) {
                return [
                    'code' => 'AUTH_CONTENT_TAMPERED',
                    'message' =>
                        "Request body was modified in transit. A proxy, CDN, " .
                        "or firewall between this machine and the server is " .
                        "altering the request content.",
                ];
            }

            if (str_contains($server_msg, 'Missing X-Auth-')) {
                return [
                    'code' => 'AUTH_HEADERS_STRIPPED',
                    'message' =>
                        "Authentication headers were stripped. The server " .
                        "reported: {$server_msg}\n\n" .
                        "A proxy, CDN, or security plugin is removing custom " .
                        "HTTP headers before they reach WordPress.",
                ];
            }

            return [
                'code' => 'AUTH_FAILED',
                'message' => "Authentication failed: {$server_msg}",
            ];
        }

        // ── Export not configured (503 from exporter) ────────────
        if ($http_code === 503 && $server_msg !== null) {
            return [
                'code' => 'EXPORT_NOT_CONFIGURED',
                'message' =>
                    "The exporter plugin is installed but not configured. " .
                    "The server reported: {$server_msg}",
            ];
        }

        // ── Not found ────────────────────────────────────────────
        if ($http_code === 404) {
            $msg = "The exporter plugin is not installed on the remote site.";
            if ($looks_like_html) {
                $msg .= " The server returned an HTML 404 page instead of " .
                         "the export API.";
            } else {
                $msg .= " The server returned HTTP 404.";
            }
            $msg .= "\n\nRun `php reprint.phar install-exporter` for setup " .
                     "instructions.";
            return ['code' => 'NOT_FOUND', 'message' => $msg];
        }

        // ── Server errors ────────────────────────────────────────
        if ($http_code >= 500) {
            $msg = $server_msg
                ? "The remote server crashed: {$server_msg}"
                : "The remote server crashed (HTTP {$http_code}).";
            $msg .= "\n\nThis is a problem on the remote server. " .
                     "Check its PHP error log for details.";
            return ['code' => 'SERVER_ERROR', 'message' => $msg];
        }

        // ── HTML response (plugin not installed / wrong URL) ─────
        if ($looks_like_html) {
            return [
                'code' => 'HTML_RESPONSE',
                'http_code' => $http_code,
                'message' =>
                    "The exporter plugin is not installed on the remote site. " .
                    "The server returned an HTML page (HTTP {$http_code}) " .
                    "instead of a JSON API response.\n\n" .
                    "Run `php reprint.phar install-exporter` for setup " .
                    "instructions.",
            ];
        }

        // ── Fallback ─────────────────────────────────────────────
        return [
            'code' => 'HTTP_ERROR',
            'message' => $server_msg
                ? "HTTP error {$http_code}: {$server_msg}"
                : "Unexpected HTTP status {$http_code}.",
        ];
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
        $this->reset_curl_state();

        $this->audit_log("HTTP_REQUEST | GET | {$url}", false);

        $ch = curl_init($url);
        reprint_apply_curl_proxy_from_env($ch);
        reprint_apply_curl_ca_bundle($ch);

        $headers = [
            ...$this->get_base_headers("application/json"),
            ...($this->get_hmac_headers()),
        ];

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => "gzip, deflate",
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION =>
                function ($ch, $dl_total, $dl_now, $ul_total, $ul_now) {
                    $this->progress->tick_spinner();
                    return 0;
                },
        ]);

        $start = microtime(true);
        $body = curl_exec($ch);
        $elapsed = microtime(true) - $start;

        try {
            $this->check_curl_error($ch);
        } catch (RuntimeException $e) {
            @curl_close($ch);
            return [
                "ok" => false,
                "http_code" => 0,
                "elapsed" => $elapsed,
                "body" => null,
                "json" => null,
                "error" => $e->getMessage(),
                "curl_errno" => $this->last_curl_errno,
                "timeout" => $this->last_curl_timeout,
            ];
        }

        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
        @curl_close($ch);

        if ($http_code !== 200) {
            $diagnosis = $this->diagnose_http_error($http_code, $body, $redirect_url);
            return [
                "ok" => false,
                "http_code" => $http_code,
                "elapsed" => $elapsed,
                "body" => $body,
                "json" => null,
                "error" => $this->format_diagnosed_error($diagnosis),
                "error_code" => $diagnosis['code'],
            ];
        }

        $json = null;
        $json_error = null;
        $error_code = null;
        if ($body !== false && $body !== "") {
            $json = json_decode($body, true);
            if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
                // HTTP 200 but body isn't valid JSON — likely an HTML page
                // from a site that doesn't have the exporter installed.
                $diagnosis = $this->diagnose_http_error(200, $body);
                if ($diagnosis['code'] === 'HTML_RESPONSE') {
                    $json_error = $this->format_diagnosed_error($diagnosis);
                    $error_code = $diagnosis['code'];
                } else {
                    $json_error = "Invalid JSON: " . json_last_error_msg();
                    $error_code = 'INVALID_JSON';
                }
            }
        }

        return [
            "ok" => $json_error === null,
            "http_code" => $http_code,
            "elapsed" => $elapsed,
            "body" => $body,
            "json" => $json,
            "error" => $json_error,
            "error_code" => $error_code,
        ];
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
        $this->reset_curl_state();

        // Log HTTP request details
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

        $this->audit_log(implode(" | ", $log_parts), false);

        $ch = curl_init($url);
        reprint_apply_curl_proxy_from_env($ch);
        reprint_apply_curl_ca_bundle($ch);

        $parser = null;
        $current_chunk = null;
        $bytes_received = 0;
        $last_heartbeat = microtime(true);
        $last_progress_check = microtime(true);
        $last_bytes_received = 0;
        $error_body = "";

        // Build headers to look like a real browser
        $headers = [
            ...$this->get_base_headers("text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8"),
            "Upgrade-Insecure-Requests: 1",
            "Sec-Fetch-Dest: document",
            "Sec-Fetch-Mode: navigate",
            "Sec-Fetch-Site: none",
            "Sec-Fetch-User: ?1",
        ];

        if ($cursor) {
            $headers[] = "X-Export-Cursor: {$cursor}";
        }

        // Configure POST data if provided.  We need to know the body
        // content BEFORE generating HMAC headers so the content hash
        // can be included in the signature.
        $body_for_signing = '';
        if ($post_data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            $has_file = false;
            foreach ($post_data as $value) {
                if ($value instanceof CURLFile) {
                    $has_file = true;
                    break;
                }
            }
            if ($has_file) {
                // For CURLFile uploads, sign the raw file content — this
                // is the logical payload the server will receive, even
                // though curl wraps it in multipart framing.
                foreach ($post_data as $value) {
                    if ($value instanceof CURLFile) {
                        $body_for_signing .= file_get_contents(
                            $value->getFilename(),
                        );
                    }
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            } else {
                $body_for_signing = http_build_query($post_data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body_for_signing);
            }
        }

        // Append HMAC auth headers now that we know the body content
        array_push($headers, ...($this->get_hmac_headers($body_for_signing)));

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            // Don't cap total transfer time — streaming responses can
            // legitimately run for 20+ minutes. Instead, detect stalled
            // connections: timeout only when fewer than 1 byte/sec is
            // received for 300 consecutive seconds.
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 300,
            CURLOPT_ENCODING => "gzip, deflate",
            // Tick the spinner during transfers. curl calls this roughly
            // once per second even when no data is flowing, which keeps
            // the Braille spinner rotating so it looks alive.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION =>
                function ($ch, $dl_total, $dl_now, $ul_total, $ul_now) {
                    $this->progress->tick_spinner();
                    return 0; // 0 = continue, non-zero = abort
                },
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $header_line) use (
                &$parser,
                $context,
                &$current_chunk
            ) {
                $len = strlen($header_line);

                // Parse Content-Type to extract boundary
                if (stripos($header_line, "Content-Type:") === 0) {
                    // Find boundary parameter
                    $pos = stripos($header_line, "boundary=");
                    if ($pos !== false) {
                        $boundary_start = $pos + 9; // length of 'boundary='
                        $boundary_value = substr($header_line, $boundary_start);
                        $boundary_value = trim($boundary_value);

                        // Remove quotes if present
                        if ($boundary_value[0] === '"') {
                            $quote_end = strpos($boundary_value, '"', 1);
                            if ($quote_end !== false) {
                                $boundary_value = substr(
                                    $boundary_value,
                                    1,
                                    $quote_end - 1,
                                );
                            }
                        } else {
                            // Find end (semicolon, comma, or whitespace)
                            $end_pos = strcspn($boundary_value, ";,\r\n \t");
                            $boundary_value = substr(
                                $boundary_value,
                                0,
                                $end_pos,
                            );
                        }

                        if ($boundary_value !== "") {
                            $this->audit_log(
                                "Creating multipart parser with boundary: $boundary_value",
                                false,
                            );
                            $parser = new MultipartStreamParser(
                                $boundary_value,
                                $this->make_chunk_handler($context, $current_chunk),
                            );
                        }
                    }
                }

                return $len;
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (
                &$parser,
                &$current_chunk,
                $context,
                &$bytes_received,
                &$last_heartbeat,
                &$last_progress_check,
                &$last_bytes_received,
                &$error_body
            ) {
                // If no parser yet, we might be receiving an error response
                if (!$parser) {
                    $error_body .= $data;
                    if (strlen($error_body) > 65536) {
                        $error_body = substr($error_body, -65536);
                    }

                    // Strict fallback: if body starts with a boundary line, parse it.
                    if (strncmp($error_body, "--boundary-", 11) === 0) {
                        $line_end = strpos($error_body, "\n");
                        if ($line_end !== false) {
                            $line = rtrim(substr($error_body, 0, $line_end), "\r\n");
                            if (strncmp($line, "--boundary-", 11) === 0) {
                                $boundary = substr($line, 2);
                                if ($boundary !== "") {
                                    $this->audit_log(
                                        "Detected boundary in body (no Content-Type): {$boundary}",
                                        false,
                                    );
                                    $parser = new MultipartStreamParser(
                                        $boundary,
                                        $this->make_chunk_handler($context, $current_chunk),
                                    );
                                    $parser->feed($error_body);
                                    $error_body = "";
                                }
                            }
                        }
                    }

                    static $logged_no_parser = false;
                    if (!$logged_no_parser && strlen($error_body) > 0) {
                        $this->audit_log(
                            "No parser, accumulating error body (first 500 chars): " .
                                substr($error_body, 0, 500),
                            false,
                        );
                        $logged_no_parser = true;
                    }
                }

                if ($parser) {
                    $parser->feed($data);
                }

                $bytes_received += strlen($data);

                // Check for stuck/slow transfer every 5 seconds
                $now = microtime(true);
                if ($now - $last_progress_check >= 5.0) {
                    $bytes_since_check = $bytes_received - $last_bytes_received;
                    $rate = $bytes_since_check / 5.0; // bytes per second

                    // Only output progress_check in verbose mode or non-TTY
                    if ($this->verbose_mode || !$this->is_tty) {
                        fwrite($this->progress_fd, json_encode([
                            "progress_check" => true,
                            "bytes_received" => $bytes_received,
                            "bytes_last_5s" => $bytes_since_check,
                            "rate_bps" => round($rate),
                        ]) . "\n");
                    }

                    // If we're receiving less than 1KB/s for 5 seconds, something is wrong
                    if ($bytes_since_check < 1024 && $bytes_received > 0) {
                        $this->audit_log(
                            "Warning: Slow transfer detected - {$bytes_since_check} bytes in 5 seconds",
                            false,
                        );
                    }

                    $last_progress_check = $now;
                    $last_bytes_received = $bytes_received;
                }

                // Output heartbeat every second (only in verbose/non-TTY mode)
                if ($now - $last_heartbeat >= 1.0) {
                    if ($this->verbose_mode || !$this->is_tty) {
                        $heartbeat = [
                            "heartbeat" => true,
                            "bytes_received" => $bytes_received,
                        ];
                        // Only emit file counters when the download list has
                        // been counted (fetch phase).  During indexing the
                        // list doesn't exist yet and emitting files_done:0
                        // without files_total confuses consumers.
                        if ($this->download_list_total !== null) {
                            $heartbeat["files_done"] =
                                ($this->download_list_done ?? 0) + $this->files_imported;
                            $heartbeat["files_total"] = $this->download_list_total;
                        }
                        fwrite($this->progress_fd, json_encode($heartbeat) . "\n");
                    }
                    $last_heartbeat = $now;
                }

                return strlen($data);
            },
        ]);

        $this->audit_log("Executing curl request...", false);
        $this->output_progress(["debug" => "Waiting for server response..."]);
        $result = curl_exec($ch);
        $this->audit_log(
            "curl_exec completed, result=" .
                ($result === false ? "false" : "true"),
            false,
        );

        try {
            try {
                $this->check_curl_error($ch);
            } catch (RuntimeException $curl_error) {
                if ($endpoint !== null) {
                    $this->handle_tuner_error($endpoint, [
                        "http_code" => 0,
                        "timeout" => $this->last_curl_timeout,
                        "curl_errno" => $this->last_curl_errno,
                    ]);
                }
                throw $curl_error;
            }

            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
            $ttfb = (float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
            $total_time = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        } finally {
            @curl_close($ch);
        }

        if (!isset($context->response_stats) || !is_array($context->response_stats)) {
            $context->response_stats = [];
        }
        $context->response_stats["ttfb"] = $ttfb;
        $context->response_stats["total_time"] = $total_time;

        if ($http_code !== 200) {
            if ($endpoint !== null) {
                $this->handle_tuner_error($endpoint, [
                    "http_code" => $http_code,
                    "timeout" => false,
                    "curl_errno" => 0,
                ]);
            }

            // Log what we received
            $this->audit_log(
                "HTTP error {$http_code} | error_body length: " .
                    strlen($error_body),
                true,
            );

            $diagnosis = $this->diagnose_http_error($http_code, $error_body, $redirect_url);
            $error_msg = $this->format_diagnosed_error($diagnosis);

            // Append stack trace from the server if available.
            if ($error_body) {
                $error_data = json_decode($error_body, true);
                if (is_array($error_data) && isset($error_data["trace"])) {
                    $error_msg .= "\n\nServer stack trace:\n" . $error_data["trace"];
                }
            }

            throw new RuntimeException($error_msg);
        }

        if (!$parser) {
            $snippet = $error_body ? substr($error_body, 0, 500) : "";
            throw new RuntimeException(
                "Invalid response: missing multipart boundary. " .
                    ($snippet !== "" ? "Body: {$snippet}" : ""),
            );
        }

        if (!$context->saw_completion) {
            throw new RuntimeException(
                "Invalid response: missing completion chunk from server.",
            );
        }
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
        $this->progress->tick_spinner();

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
        $this->progress->clear_progress_line();

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

        $this->progress->show_lifecycle_line("\nInterrupted - saving state...\n");
        $this->progress->show_lifecycle_line("  Command: {$current_command}\n");
        $this->progress->show_lifecycle_line("  Total files indexed: {$indexed}\n");
        $this->progress->show_lifecycle_line("  Files completed in this run: {$files_imported}\n");
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
            $this->progress->show_lifecycle_line("✓ State saved successfully\n");
            $this->output_progress([
                "type" => "state_saved",
                "message" => "State saved successfully",
            ], true);
        } catch (Exception $e) {
            fwrite($this->progress_fd, "Warning: Failed to save state: " . $e->getMessage() . "\n");
        }

        $this->progress->show_lifecycle_line("Exiting...\n");

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
        // In TTY non-verbose mode, suppress JSON output (use show_progress_line instead)
        if ($this->is_tty && !$this->verbose_mode) {
            return;
        }

        $now = microtime(true);

        // Always output status changes
        $is_status_change =
            isset($data["status"]) &&
            in_array($data["status"], ["starting", "complete", "error"]);

        // Output if forced, status change, or throttle time passed
        if (
            $force ||
            $is_status_change ||
            $now - $this->last_progress_output >= $this->progress_throttle
        ) {
            $written = @fwrite($this->progress_fd, json_encode($data) . "\n");
            if ($written === false) {
                // Broken pipe — save state and exit cleanly
                $this->save_state($this->state);
                exit(0);
            }
            @flush();
            $this->last_progress_output = $now;
        }
    }
}
