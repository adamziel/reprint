<?php

namespace Reprint\Importer\Application;

use Exception;
use RuntimeException;
use Reprint\Importer\FileSync\FileSyncTransferProgress;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Infrastructure\ImportOutputProgressTicker;
use Reprint\Importer\FileSync\Infrastructure\JsonFilesPullCheckpointStore;
use Reprint\Importer\Filesystem\LocalImportFilesystem;
use Reprint\Importer\Index\IndexFileSorter;
use Reprint\Importer\Index\IndexLineParser;
use Reprint\Importer\Index\IndexStore;
use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\Output\NullImportOutput;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Pull\PullCheckpoint;
use Reprint\Importer\Session\ImportAbortHandler;
use Reprint\Importer\Session\ImportPaths;
use Reprint\Importer\Session\ImportRunState;
use Reprint\Importer\Session\JsonStateStore;
use Reprint\Importer\Session\PreflightCheckpoint;
use Reprint\Importer\Session\RuntimeLifecycle;
use Reprint\Importer\Session\RunStateRepository;
use Reprint\Importer\Session\ShutdownState;
use Reprint\Importer\Session\StatePathCodec;
use Reprint\Importer\Sql\DbApplyCheckpoint;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\Infrastructure\JsonDbApplyCheckpointStore;
use Reprint\Importer\Sql\Infrastructure\JsonDbPullCheckpointStore;
use Reprint\Importer\TargetRuntime\RuntimeCheckpoint;
use Reprint\Importer\Transport\ImportHttpSession;

final class ImportContext
{
    public const SAVE_STATE_EVERY_N_CHUNKS = 50;
    public const MAX_CONSECUTIVE_TIMEOUTS = 3;
    public const USER_AGENTS = ImportHttpSession::USER_AGENTS;

    private string $remote_url;
    private string $state_dir;
    private string $fs_root;
    private ImportIo $io;
    private ImportPaths $paths;
    private JsonStateStore $store;
    private StatePathCodec $path_codec;
    private RunStateRepository $run_states;
    private RuntimeLifecycle $lifecycle;
    private ShutdownState $shutdown;
    private ImportOptions $options;
    private FileSyncTransferProgress $file_sync_progress;
    private IndexStore $index_store;
    private IndexFileSorter $index_sorter;
    private ?ImportRunState $state = null;
    private ?ImportHttpSession $http_session = null;
    private ?LocalImportFilesystem $local_filesystem = null;
    private ?PreflightCheckpoint $preflight_checkpoint = null;
    private ?PullCheckpoint $pull_checkpoint = null;
    private int $exit_code = 0;
    private ?string $last_error_code = null;

    public function __construct(
        string $remote_url,
        string $state_dir,
        string $fs_root,
        ?ImportOutput $output = null
    ) {
        $this->remote_url = rtrim($remote_url, "?&");
        $this->state_dir = rtrim($state_dir, "/");
        $this->fs_root = rtrim($fs_root, "/");
        $this->paths = new ImportPaths($this->state_dir);
        $this->io = new ImportIo($this->paths, $output ?? new NullImportOutput());
        $this->store = new JsonStateStore();
        $this->path_codec = new StatePathCodec($this->audit_logger());
        $this->run_states = new RunStateRepository(
            $this->store,
            $this->paths,
            $this->audit_logger(),
        );
        $this->shutdown = new ShutdownState();
        $this->options = new ImportOptions();
        $this->file_sync_progress = new FileSyncTransferProgress();
        $this->lifecycle = new RuntimeLifecycle(
            $this->state_dir,
            $this->fs_root,
            [$this, "handle_shutdown"],
        );
        $this->index_store = new IndexStore(
            $this->paths->index_file(),
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
                $this->output()->tick_spinner();
            },
        );
    }

    public function prepare_runtime(): void
    {
        $this->lifecycle->prepare();
    }

    public function cleanup_runtime(): void
    {
        $this->lifecycle->cleanup();
    }

    public function output(): ImportOutput
    {
        return $this->io->output();
    }

    public function paths(): ImportPaths
    {
        return $this->paths;
    }

    public function state_dir(): string
    {
        return $this->state_dir;
    }

    public function fs_root(): string
    {
        return $this->fs_root;
    }

    public function remote_url(): string
    {
        return $this->remote_url;
    }

    public function remote_host(): string
    {
        return parse_url($this->remote_url, PHP_URL_HOST) ?? $this->remote_url;
    }

    public function ensure_site_export_api_url(): void
    {
        $this->http_session()->ensure_site_export_api_url();
        $this->remote_url = $this->http_session()->remote_url();
    }

    public function default_runtime_output_dir(): string
    {
        return $this->state_dir . "/runtime";
    }

    public function has_wpcloud_docroot_link(): bool
    {
        return is_link($this->fs_root . "/__wp__");
    }

    public function store(): JsonStateStore
    {
        return $this->store;
    }

    public function path_codec(): StatePathCodec
    {
        return $this->path_codec;
    }

    public function shutdown(): ShutdownState
    {
        return $this->shutdown;
    }

    public function file_sync_progress(): FileSyncTransferProgress
    {
        return $this->file_sync_progress;
    }

    public function index_store(): IndexStore
    {
        return $this->index_store;
    }

    public function index_sorter(): IndexFileSorter
    {
        return $this->index_sorter;
    }

    public function audit_logger(): AuditLogger
    {
        return $this->io->audit_logger();
    }

    public function audit_log(string $message, bool $to_console = true): void
    {
        $this->io->audit_log($message, $to_console);
    }

    public function audit_log_argv(string $command, array $argv): void
    {
        $this->io->audit_log_argv($command, $argv);
    }

    public function http_session(): ImportHttpSession
    {
        if ($this->http_session instanceof ImportHttpSession) {
            return $this->http_session;
        }

        $this->http_session = new ImportHttpSession(
            $this->remote_url,
            $this->output(),
            function (string $message, bool $to_console = true): void {
                $this->audit_log($message, $to_console);
            },
            function (array $event): void {
                $this->output_progress($event);
            },
        );

        return $this->http_session;
    }

    public function set_request_user_agent(string $user_agent): void
    {
        $this->state()->user_agent = $user_agent;
        $this->http_session()->set_user_agent($user_agent);
    }

    public function sync_http_error_code(): void
    {
        if (
            $this->http_session instanceof ImportHttpSession &&
            $this->http_session->last_error_code() !== null
        ) {
            $this->last_error_code = $this->http_session->last_error_code();
        }
    }

    public function last_error_code(): ?string
    {
        return $this->last_error_code;
    }

    public function exit_code(): int
    {
        return $this->exit_code;
    }

    public function set_exit_code(int $exit_code): void
    {
        $this->exit_code = $exit_code;
    }

    public function state(): ImportRunState
    {
        if (!$this->state instanceof ImportRunState) {
            $this->state = $this->run_states->load();
        }

        return $this->state;
    }

    public function replace_state(ImportRunState $state): void
    {
        $this->state = $state;
    }

    public function fresh_state(): ImportRunState
    {
        return $this->run_states->fresh();
    }

    public function save_state(?ImportRunState $state = null): void
    {
        $this->output()->tick_spinner();
        $state = $state ?? $this->state();

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
        $this->run_states->save($state);
        $this->write_status_file();
    }

    public function current_command(): ?string
    {
        return $this->state()->command;
    }

    public function current_run_status(): ?string
    {
        return $this->state()->status;
    }

    public function set_run_status(?string $status): void
    {
        $state = $this->state();
        $state->status = $status;
        $this->save_state($state);
    }

    public function record_command_status(string $command, ?string $status): void
    {
        $state = $this->state();
        $state->set_command_status($command, $status);
        $this->save_state($state);
    }

    public function prepare_repull_run_state(): void
    {
        $state = $this->state()->reset_for_restart();
        $state->command = "pull";
        $state->status = "in_progress";
        $this->save_state($state);
    }

    public function abort_command(string $command): void
    {
        $state = (new ImportAbortHandler(
            $this->paths,
            $this->index_store,
            $this->audit_logger(),
        ))->abort(
            $this->state(),
            $command,
            $this->options->sql_output_mode(),
        );

        $this->preflight_checkpoint = null;
        $this->save_state($state);
        $this->output()->show_lifecycle_line("State cleared for {$command}.\n");
    }

    public function finish_command_status(string $command): void
    {
        $final_status = $this->state()->status ?? "complete";
        $this->output_progress(["status" => $final_status, "message" => "{$command} {$final_status}"]);
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

    public function output_progress(array $data, bool $force = false): void
    {
        if (!$this->io->emit_event($data, $force)) {
            if ($this->state instanceof ImportRunState) {
                $this->save_state($this->state);
            }
            throw new ImportOutputClosedException("Import output stream closed.");
        }
    }

    public function build_url(string $endpoint, ?string $cursor, array $params = []): string
    {
        return $this->http_session()->build_url($endpoint, $cursor, $params);
    }

    public function fetch_json(string $url): array
    {
        $result = $this->http_session()->fetch_json($url);
        $this->sync_http_error_code();
        return $result;
    }

    public function fetch_streaming(
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
            $this->sync_http_error_code();
        }
    }

    public function preflight_checkpoint(): PreflightCheckpoint
    {
        if ($this->preflight_checkpoint instanceof PreflightCheckpoint) {
            return $this->preflight_checkpoint;
        }

        $this->preflight_checkpoint = PreflightCheckpoint::from_persisted_array(
            $this->store->load($this->paths->preflight_checkpoint_file()) ?? [],
            [$this->path_codec, "decode_preflight_data_paths"],
        );

        return $this->preflight_checkpoint;
    }

    public function save_preflight_checkpoint(PreflightCheckpoint $checkpoint): void
    {
        $this->output()->tick_spinner();
        $this->preflight_checkpoint = $checkpoint;
        $this->store->save(
            $this->paths->preflight_checkpoint_file(),
            $checkpoint->to_persisted_array([$this->path_codec, "encode_preflight_data_paths"]),
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

    public function require_preflight(): void
    {
        if ($this->preflight_data() === null) {
            throw new RuntimeException(
                "No preflight data found. Run 'preflight' or 'preflight-assert' first.",
            );
        }
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
            $this->store->load($this->paths->pull_checkpoint_file()) ?? [],
        );

        return $this->pull_checkpoint;
    }

    public function save_pull_checkpoint(PullCheckpoint $checkpoint): void
    {
        $this->output()->tick_spinner();
        $this->pull_checkpoint = $checkpoint;
        $this->store->save($this->paths->pull_checkpoint_file(), $checkpoint->to_array());
        $this->write_status_file();
    }

    public function delete_pull_checkpoint(): void
    {
        $this->pull_checkpoint = null;
        $this->store->delete($this->paths->pull_checkpoint_file());
    }

    public function db_pull_checkpoint_store(): JsonDbPullCheckpointStore
    {
        return new JsonDbPullCheckpointStore(
            $this->store,
            $this->paths,
            $this->path_codec,
            $this->output(),
        );
    }

    public function db_pull_checkpoint(): DbPullCheckpoint
    {
        return $this->db_pull_checkpoint_store()->get();
    }

    public function save_db_pull_checkpoint(DbPullCheckpoint $checkpoint): void
    {
        $this->db_pull_checkpoint_store()->save($checkpoint);
    }

    public function db_apply_checkpoint_store(): JsonDbApplyCheckpointStore
    {
        return new JsonDbApplyCheckpointStore(
            $this->store,
            $this->paths,
            $this->output(),
        );
    }

    public function db_apply_checkpoint(): DbApplyCheckpoint
    {
        return $this->db_apply_checkpoint_store()->get();
    }

    public function runtime_checkpoint(): RuntimeCheckpoint
    {
        return RuntimeCheckpoint::from_array(
            $this->store->load($this->paths->runtime_checkpoint_file()) ?? [],
        );
    }

    public function save_runtime_checkpoint(RuntimeCheckpoint $checkpoint): void
    {
        $this->output()->tick_spinner();
        $this->store->save($this->paths->runtime_checkpoint_file(), $checkpoint->to_array());
    }

    public function files_pull_checkpoint_store(): JsonFilesPullCheckpointStore
    {
        return new JsonFilesPullCheckpointStore(
            $this->store,
            $this->paths,
            $this->path_codec,
            new ImportOutputProgressTicker($this->output()),
        );
    }

    public function files_pull_checkpoint(): FilesPullCheckpoint
    {
        return $this->files_pull_checkpoint_store()->get();
    }

    public function save_files_pull_checkpoint(FilesPullCheckpoint $checkpoint): void
    {
        $this->files_pull_checkpoint_store()->save($checkpoint);
        $this->write_status_file();
    }

    public function local_filesystem(): LocalImportFilesystem
    {
        if ($this->local_filesystem instanceof LocalImportFilesystem) {
            return $this->local_filesystem;
        }

        $this->local_filesystem = new LocalImportFilesystem(
            $this->fs_root,
            $this->options->fs_root_nonempty_behavior(),
            $this->audit_logger(),
        );

        return $this->local_filesystem;
    }

    public function reset_local_filesystem(): void
    {
        $this->local_filesystem = null;
    }

    public function index_count(): int
    {
        return $this->index_store->count();
    }

    public function has_skipped_files_pending(): bool
    {
        return is_file($this->paths->skipped_download_list_file()) &&
            filesize($this->paths->skipped_download_list_file()) > 0;
    }

    public function parse_index_line(string $line): ?array
    {
        return IndexLineParser::parse($line);
    }

    public function set_file_sync_progress(
        int $files_imported,
        ?int $download_list_done,
        ?int $download_list_total
    ): void {
        $this->file_sync_progress->set_transfer_counts(
            $files_imported,
            $download_list_done,
            $download_list_total,
        );
    }

    public function finalize_index_updates(): void
    {
        $this->index_store->finalize_updates();
    }

    public function write_status_file(?string $error = null): void
    {
        $command = $this->state instanceof ImportRunState ? $this->state->command : null;
        $files_checkpoint = in_array($command, ["files-pull", "files-index"], true)
            ? $this->files_pull_checkpoint()
            : null;
        $db_pull_checkpoint = in_array($command, ["db-pull", "db-index"], true)
            ? $this->db_pull_checkpoint()
            : null;
        $status = $error !== null
            ? "error"
            : (
                $files_checkpoint->status ??
                $db_pull_checkpoint->status ??
                ($this->state instanceof ImportRunState ? $this->state->status : null) ??
                "in_progress"
        );

        $payload = [
            "step" => $this->options->pipeline_step(),
            "steps" => $this->options->pipeline_steps(),
            "command" => $command,
            "status" => $status,
            "phase" => $files_checkpoint->stage ?? $db_pull_checkpoint->stage ?? null,
            "error" => $error,
            "error_code" => $error !== null ? $this->last_error_code : null,
            "ts" => microtime(true),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT);
        if ($json === false) {
            return;
        }

        $tmp = $this->paths->status_file() . ".tmp";
        if (file_put_contents($tmp, $json) !== false) {
            rename($tmp, $this->paths->status_file());
        }
    }

    public function handle_shutdown(int $signal): void
    {
        static $already_shutting_down = false;
        if ($already_shutting_down) {
            throw new ImportShutdownRequestedException('Forced importer shutdown requested.', $signal, true);
        }
        $already_shutting_down = true;

        $this->shutdown->request();
        $this->output()->clear_progress_line();

        try {
            $this->finalize_index_updates();
        } catch (Exception $e) {
            $this->audit_log("Failed to finalize index updates on shutdown: " . $e->getMessage(), true);
        }

        if ($this->state instanceof ImportRunState) {
            $this->save_state($this->state);
        }

        throw new ImportShutdownRequestedException('Importer shutdown requested.', $signal);
    }

    public function follow_symlinks(): bool
    {
        return $this->options->follow_symlinks();
    }

    public function set_follow_symlinks(bool $value): void
    {
        $this->options->set_follow_symlinks($value);
    }

    public function include_caches(): bool
    {
        return $this->options->include_caches();
    }

    public function set_include_caches(bool $value): void
    {
        $this->options->set_include_caches($value);
    }

    public function fs_root_nonempty_behavior(): string
    {
        return $this->options->fs_root_nonempty_behavior();
    }

    public function set_fs_root_nonempty_behavior(string $value): void
    {
        $this->options->set_fs_root_nonempty_behavior($value);
        $this->reset_local_filesystem();
    }

    public function filter(): string
    {
        return $this->options->filter();
    }

    public function set_filter(string $value): void
    {
        $this->options->set_filter($value);
    }

    public function extra_directory(): ?string
    {
        return $this->options->extra_directory();
    }

    public function set_extra_directory(?string $value): void
    {
        $this->options->set_extra_directory($value);
    }

    public function max_allowed_packet(): ?int
    {
        return $this->options->max_allowed_packet();
    }

    public function set_max_allowed_packet(?int $value): void
    {
        $this->options->set_max_allowed_packet($value);
    }

    public function sql_output_mode(): string
    {
        return $this->options->sql_output_mode();
    }

    public function set_sql_output_mode(string $value): void
    {
        $this->options->set_sql_output_mode($value);
    }

    public function set_pipeline(?int $step, ?int $steps): void
    {
        $this->options->set_pipeline($step, $steps);
    }

    public function mysql_host(): ?string
    {
        return $this->options->mysql_host();
    }

    public function set_mysql_host(?string $value): void
    {
        $this->options->set_mysql_host($value);
    }

    public function mysql_port(): ?int
    {
        return $this->options->mysql_port();
    }

    public function set_mysql_port(?int $value): void
    {
        $this->options->set_mysql_port($value);
    }

    public function mysql_user(): ?string
    {
        return $this->options->mysql_user();
    }

    public function set_mysql_user(?string $value): void
    {
        $this->options->set_mysql_user($value);
    }

    public function mysql_password(): ?string
    {
        return $this->options->mysql_password();
    }

    public function set_mysql_password(?string $value): void
    {
        $this->options->set_mysql_password($value);
    }

    public function mysql_database(): ?string
    {
        return $this->options->mysql_database();
    }

    public function set_mysql_database(?string $value): void
    {
        $this->options->set_mysql_database($value);
    }

    public function validate_sql_output_options(): void
    {
        $this->options->validate_sql_output_options();
    }
}
