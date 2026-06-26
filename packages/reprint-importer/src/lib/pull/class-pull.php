<?php

namespace Reprint\Importer\Pull;

use InvalidArgumentException;
use RuntimeException;
use Reprint\Importer\Pull\Command\PullStageCommands;
use Reprint\Importer\Support\ByteFormatter;
use Reprint\Importer\TerminalProgress\TerminalProgress;
use const Reprint\Importer\TargetRuntime\VALID_TARGET_RUNTIMES;

/**
 * The `pull` command — orchestrates the lower-level commands into a
 * single resumable site clone pipeline.
 *
 * Each step retries automatically on server timeouts (exit code 2). If
 * the process is interrupted, re-running pull resumes from the last
 * completed step. Like `git pull` composes fetch + merge, this composes
 * preflight → files-pull → db-pull → db-apply → flat-docroot →
 * apply-runtime → start.
 *
 * The class owns pull orchestration only. It delegates environment-specific
 * work through PullRuntime so pipeline state, retry policy, and stage framing
 * stay independent from the importer composition root.
 */
class Pull
{
    private PullRuntime $client;
    private TerminalProgress $progress;

    public function __construct(PullRuntime $client, TerminalProgress $progress)
    {
        $this->client = $client;
        $this->progress = $progress;
    }

    public function runtime(): PullRuntime
    {
        return $this->client;
    }

    public function client(): PullRuntime
    {
        return $this->client;
    }

    /**
     * Determine the pipeline stages based on the provided options.
     *
     * Always: preflight → files-pull → db-pull.
     * Adds db-apply when a database target is configured, flat-docroot
     * when --flatten-to is set, apply-runtime when --runtime is set,
     * and start when the selected start runtime can be launched
     * in-process.
     */
    public function stages(array $options): array
    {
        $stages = ['preflight', 'files-pull', 'db-pull'];
        $has_db_target =
            !empty($options['target_db']) ||
            !empty($options['target_engine']) ||
            !empty($options['target_sqlite_path']) ||
            !empty($options['target_user']);
        if ($has_db_target) {
            $stages[] = 'db-apply';
        }
        if (!empty($options['flatten_to'])) {
            $stages[] = 'flat-docroot';
        }
        $runtime = $this->resolve_runtime($options);
        $start_runtime = $this->resolve_start_runtime($options, $runtime);
        if ($runtime !== null && $runtime !== 'none') {
            $stages[] = 'apply-runtime';
            if (
                $start_runtime !== 'none' &&
                $start_runtime === $runtime &&
                $this->can_start_runtime($start_runtime)
            ) {
                $stages[] = 'start';
            }
        }
        return $stages;
    }

    /** Human-readable label for a pipeline stage. */
    public function stage_label(string $stage): string
    {
        $command = PullStageCommands::get($stage);
        return $command ? $command->label() : $stage;
    }

    /**
     * Run the pull pipeline.
     */
    public function run(array $options): void
    {
        $this->client->ensure_site_export_api_url();
        $this->progress->enable_quiet_lifecycle();

        $options = $this->validate_and_default_options($options);

        $stages = $this->stages($options);
        $total = count($stages);
        $completed_stage = $this->checkpoint()->stage;

        // Older versions persisted "start" before the launcher actually
        // succeeded. Treat that ephemeral stage as retryable so rerunning pull
        // starts the generated server instead of no-oping.
        if ($completed_stage === 'start') {
            $completed_stage = 'apply-runtime';
        }

        // If the prior pull completed, prepare for a delta re-pull.
        if ($completed_stage === 'complete') {
            $this->prepare_repull();
            $completed_stage = null;
        }

        $start_index = 0;
        if ($completed_stage !== null) {
            $idx = array_search($completed_stage, $stages);
            if ($idx !== false) {
                $start_index = $idx + 1;
            }
        }

        $host = $this->client->remote_host();
        $bold = "\033[1m";
        $r = "\033[0m";
        $this->progress->print_line("\n{$bold}Pulling {$host}{$r}\n");

        $this->client->output_progress([
            "type" => "lifecycle",
            "event" => "starting",
            "command" => "pull",
            "stages" => $stages,
            "resume_from" => $start_index,
            "message" => "Starting pull",
        ], true);

        $this->client->audit_log(
            sprintf("PULL | stages=%s | resume_from=%d", implode(",", $stages), $start_index),
            true,
        );

        for ($i = 0; $i < $start_index; $i++) {
            $this->print_skipped($stages[$i]);
        }

        for ($i = $start_index; $i < $total; $i++) {
            $stage = $stages[$i];

            $this->print_stage_header($stage);

            try {
                $this->run_stage($stage, $options);
            } catch (\Exception $e) {
                $this->report_failure($stage, $stages, $i, $e);
                throw $e;
            }

            $this->mark_stage_complete($stage);
        }

        // The 'start' stage handles its own completion (it needs to save
        // state before blocking on the server process).
        if (!in_array('start', $stages, true)) {
            $this->mark_complete();
            $this->print_summary();
        }

        $this->client->output_progress([
            "type" => "lifecycle",
            "event" => "complete",
            "command" => "pull",
            "stages" => $stages,
            "message" => "Pull complete",
        ], true);
    }

    /**
     * Handle --abort for the pull command: clear pipeline state but
     * leave downloaded files in place.
     */
    public function abort(): void
    {
        $this->prepare_repull();
        $this->progress->show_lifecycle_line("Pull state cleared. Downloaded files are preserved.\n");
        $this->client->output_progress([
            "type" => "lifecycle",
            "event" => "aborted",
            "command" => "pull",
            "message" => "Pull state cleared",
        ], true);
    }

    private function run_stage(string $stage, array $options): void
    {
        $command = PullStageCommands::get($stage);
        if ($command === null) {
            throw new InvalidArgumentException("Invalid pull stage: {$stage}");
        }

        $command->execute($this, $options);
    }

    public function record_files_state(string $filter, bool $skipped_pending): void
    {
        $checkpoint = $this->checkpoint();
        $checkpoint->files_filter = $filter;
        $checkpoint->skipped_pending = $skipped_pending;
        $this->client->save_pull_checkpoint($checkpoint);
    }

    private function checkpoint(): PullCheckpoint
    {
        return $this->client->pull_checkpoint();
    }

    private function mark_stage_complete(string $stage): void
    {
        $checkpoint = $this->checkpoint();
        $checkpoint->stage = $stage;
        $this->client->save_pull_checkpoint($checkpoint);
    }

    private function mark_complete(): void
    {
        $checkpoint = $this->checkpoint();
        $checkpoint->stage = 'complete';
        $this->client->save_pull_checkpoint($checkpoint);
        $this->client->set_run_status('complete');
    }

    /**
     * Validate user-provided options and apply defaults.
     */
    private function validate_and_default_options(array $options): array
    {
        // --runtime and --start-runtime values are validated at CLI parse
        // time from VALID_TARGET_RUNTIMES. Re-check here for programmatic
        // callers (e.g. Importer::run invoked directly) that bypass
        // the CLI parser.
        foreach (['runtime', 'start_runtime'] as $key) {
            if (!empty($options[$key]) && !in_array($options[$key], VALID_TARGET_RUNTIMES, true)) {
                $flag = str_replace('_', '-', $key);
                throw new InvalidArgumentException(
                    "Invalid --{$flag} value: {$options[$key]}. " .
                    "Valid runtimes: " . implode(', ', VALID_TARGET_RUNTIMES)
                );
            }
        }

        // Default --runtime to php-builtin so pull always ends with a
        // running local server. Users can override with --runtime=nginx-fpm,
        // --runtime=playground-cli, or --runtime=none to skip runtime
        // generation entirely.
        if (empty($options['runtime'])) {
            if (!empty($options['start_runtime']) && $options['start_runtime'] !== 'none') {
                $options['runtime'] = $options['start_runtime'];
            } else {
                $options['runtime'] = 'php-builtin';
            }
        }

        if (empty($options['start_runtime'])) {
            $options['start_runtime'] = $this->default_start_runtime($options['runtime']);
        }
        if ($options['start_runtime'] !== 'none') {
            if (!$this->can_start_runtime($options['start_runtime'])) {
                throw new InvalidArgumentException(
                    "Starting runtime {$options['start_runtime']} is not supported yet. " .
                    "Supported start runtimes: php-builtin, playground-cli, none"
                );
            }
            if ($options['start_runtime'] !== $options['runtime']) {
                throw new InvalidArgumentException(
                    "--start-runtime={$options['start_runtime']} requires matching --runtime={$options['start_runtime']}, " .
                    "or omit --runtime to use {$options['start_runtime']} for both."
                );
            }
        }

        if ($options['runtime'] === 'none') {
            unset($options['runtime']);
        }

        // Default --target-engine to sqlite for php-builtin and
        // playground-cli (no MySQL server needed). nginx-fpm users
        // probably have a server stack — require explicit DB config.
        if (empty($options['target_engine']) && empty($options['target_user']) && empty($options['target_db'])) {
            if (($options['runtime'] ?? null) !== 'nginx-fpm') {
                $options['target_engine'] = 'sqlite';
            }
        }

        if (!empty($options['target_engine'])) {
            $engine = strtolower($options['target_engine']);
            if (!in_array($engine, ['mysql', 'sqlite'], true)) {
                throw new InvalidArgumentException(
                    "Invalid --target-engine value: {$options['target_engine']}. " .
                    "Valid engines: mysql, sqlite"
                );
            }
        }

        $engine = strtolower($options['target_engine'] ?? '');
        if ($engine === 'mysql') {
            if (empty($options['target_user'])) {
                throw new InvalidArgumentException(
                    "--target-user is required for MySQL database import."
                );
            }
            if (empty($options['target_db'])) {
                throw new InvalidArgumentException(
                    "--target-db is required for MySQL database import."
                );
            }
        }

        if (empty($options['output_dir'])) {
            $options['output_dir'] = $this->client->default_runtime_output_dir();
        }

        if (!isset($options['filter'])) {
            $options['filter'] = $this->client->current_filter();
        }
        if (!in_array($options['filter'], ['none', 'essential-files'], true)) {
            throw new InvalidArgumentException(
                "Invalid --filter value for pull: {$options['filter']}. " .
                "Valid values: none, essential-files"
            );
        }

        return $options;
    }

    private function resolve_runtime(array $options): ?string
    {
        if (!empty($options['runtime'])) {
            return $options['runtime'];
        }
        if (!empty($options['start_runtime']) && $options['start_runtime'] !== 'none') {
            return $options['start_runtime'];
        }
        return null;
    }

    private function resolve_start_runtime(array $options, ?string $runtime): string
    {
        if (!empty($options['start_runtime'])) {
            return $options['start_runtime'];
        }
        return $this->default_start_runtime($runtime);
    }

    private function default_start_runtime(?string $runtime): string
    {
        return $this->can_start_runtime($runtime) ? $runtime : 'none';
    }

    private function can_start_runtime(?string $runtime): bool
    {
        return in_array($runtime, ['php-builtin', 'playground-cli'], true);
    }

    /**
     * Reset sub-command state for a delta re-pull.
     *
     * Keeps the local file index (so files-pull runs in delta mode) and
     * preflight data, but clears everything else and deletes db.sql /
     * the remote index so the next pull re-fetches them.
     */
    private function prepare_repull(): void
    {
        $paths = $this->client->paths();
        $this->client->prepare_repull_run_state();

        foreach ([
            $paths->sql_file(),
            $paths->table_stats_file(),
            $paths->domains_file(),
            $paths->sql_stats_file(),
            $paths->remote_index_file(),
            $paths->download_list_file(),
            $paths->skipped_download_list_file(),
            $paths->files_pull_checkpoint_file(),
            $paths->db_pull_checkpoint_file(),
            $paths->db_apply_checkpoint_file(),
            $paths->runtime_checkpoint_file(),
        ] as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        $this->client->delete_pull_checkpoint();
        $this->client->audit_log("PULL | prepared for delta re-pull", true);
    }

    /**
     * Sub-commands return with run status "partial" when a server timeout
     * drops the connection. This loop retries automatically, resetting the
     * status to "in_progress" so the handler enters its resume path.
     */
    public function run_resumable_stage(string $stage, array $options): void
    {
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $this->run_runtime_stage($stage, $options);
            if ($this->client->current_run_status() !== 'partial') {
                break;
            }
            $this->client->set_run_status('in_progress');
            $this->client->set_exit_code(0);
            $this->progress->tick_spinner();
        }
    }

    public function run_runtime_stage(string $stage, array $options): void
    {
        if ($stage === 'preflight') {
            $this->client->run_preflight();
            return;
        }

        if ($stage === 'files-pull') {
            $this->client->run_files_sync();
            return;
        }

        if ($stage === 'db-pull') {
            $this->client->run_db_sync();
            return;
        }

        if ($stage === 'db-apply') {
            $this->client->run_db_apply($options);
            return;
        }

        if ($stage === 'flat-docroot') {
            $this->client->run_flat_document_root($options);
            return;
        }

        if ($stage === 'apply-runtime') {
            $this->client->run_apply_runtime($options);
            return;
        }

        throw new InvalidArgumentException("Invalid runtime stage: {$stage}");
    }

    /**
     * Check whether preflight detected that the exporter plugin is not
     * installed. Returns true if so (caller should abort the pipeline).
     */
    public function check_plugin_installed(): bool
    {
        $preflight = $this->client->preflight_entry();
        $ok = ($preflight["http_code"] ?? 0) === 200 && !empty($preflight["data"]["ok"]);
        if ($ok) {
            return false;
        }

        $error = $preflight["error"] ?? null;
        $error_code = $this->client->last_error_code();
        $is_not_installed =
            $error_code === 'NOT_FOUND' ||
            $error_code === 'HTML_RESPONSE';

        if ($is_not_installed) {
            $red = "\033[31m";
            $bold = "\033[1m";
            $dim = "\033[2m";
            $cyan = "\033[36m";
            $r = "\033[0m";
            $this->progress->print_line("\n{$red}  ✗ The exporter plugin is not installed on this site.{$r}\n\n");
            $this->progress->print_line("  To set it up, run:\n\n");
            $this->progress->print_line("    {$cyan}php reprint.phar install-exporter{$r}\n\n");
            $this->progress->print_line("  {$dim}This will show the download URL and step-by-step instructions.{$r}\n");
        } else {
            $red = "\033[31m";
            $dim = "\033[2m";
            $r = "\033[0m";
            $this->progress->print_line("\n{$red}  ✗ Preflight failed{$r}\n");
            if ($error) {
                $indented = implode("\n  ", explode("\n", $error));
                $this->progress->print_line("  {$indented}\n");
            }
        }

        $this->client->output_progress([
            "status" => "error",
            "command" => "pull",
            "failed_stage" => "preflight",
            "error_code" => $error_code,
            "error" => $error ?? "Preflight check failed",
            "message" => $error ?? "Preflight check failed",
        ]);

        return true;
    }

    /**
     * Build a one-line summary of preflight results for the checkmark.
     */
    public function preflight_summary(): ?string
    {
        $data = $this->client->preflight_data();
        if (!is_array($data)) {
            return null;
        }
        $parts = [];
        $wp = $data["database"]["wp"]["wp_version"] ?? null;
        if ($wp) {
            $parts[] = "WordPress {$wp}";
        }
        $php = $data["runtime"]["phpversion"] ?? null;
        if ($php) {
            $parts[] = "PHP {$php}";
        }
        return $parts ? implode(", ", $parts) : null;
    }

    /**
     * Start the local server. For php-builtin / playground-cli this
     * runs start.sh via passthru and blocks until the user hits Ctrl-C.
     */
    public function start_server(array $options): void
    {
        $output_dir = $options['output_dir'] ?? $this->client->default_runtime_output_dir();
        $start_sh = $output_dir . '/start.sh';

        if (!file_exists($start_sh)) {
            throw new RuntimeException(
                "start.sh not found at {$start_sh}. " .
                "apply-runtime may have failed to generate it."
            );
        }

        $host = $options['host'] ?? 'localhost';
        $port = (int) ($options['port'] ?? 8881);
        $url = "http://{$host}:{$port}";

        // Mark the import itself complete BEFORE the server blocks, but keep
        // the durable pull stage at apply-runtime. Starting the server is
        // ephemeral; if the child process exits or the user reruns pull, the
        // correct behavior is to try launching start.sh again.
        $checkpoint = $this->checkpoint();
        $checkpoint->stage = 'apply-runtime';
        $this->client->set_run_status('complete');
        $this->client->save_pull_checkpoint($checkpoint);

        $green = "\033[32m";
        $bold = "\033[1m";
        $dim = "\033[2m";
        $cyan = "\033[36m";
        $r = "\033[0m";
        $this->progress->clear_progress_line();
        $this->progress->print_line("  {$green}✓{$r} Ready at {$cyan}{$bold}{$url}{$r}\n");
        $this->progress->print_line("    {$dim}Press Ctrl-C to stop.{$r}\n\n");

        $this->client->output_progress([
            "type" => "lifecycle",
            "event" => "server_starting",
            "command" => "pull",
            "url" => $url,
            "start_sh" => $start_sh,
            "message" => "Starting server at {$url}",
        ], true);

        passthru("bash " . escapeshellarg($start_sh), $exit_code);

        if ($exit_code === 0 || $exit_code === 130 || $exit_code === 143) {
            $this->client->set_exit_code($exit_code);
            return;
        }

        $this->client->set_exit_code($exit_code);
        throw new RuntimeException(
            "Local server exited with status {$exit_code}. " .
            "Run `bash {$start_sh}` to see the server output and retry."
        );
    }

    private function print_stage_header(string $stage): void
    {
        $this->progress->clear_progress_line();
        $label = $this->stage_label($stage);
        $this->progress->set_active_label($label);
        $cyan = "\033[36m";
        $r = "\033[0m";
        // \n claims a fresh line, then \r\033[K positions us at column 0.
        // Subsequent show_progress_line calls overwrite this in place.
        $this->progress->print_line("\n\r\033[K  {$cyan}⠋{$r} {$label}");
    }

    public function print_done(string $stage, ?string $summary = null): void
    {
        $this->progress->clear_progress_line();
        $green = "\033[32m";
        $dim = "\033[2m";
        $r = "\033[0m";
        $label = $this->stage_label($stage);
        $extra = $summary ? " {$dim}— {$summary}{$r}" : "";
        $this->progress->print_line("  {$green}✓{$r} {$label}{$extra}\n");
        $this->progress->set_active_label(null);
    }

    private function print_skipped(string $stage): void
    {
        $dim = "\033[2m";
        $r = "\033[0m";
        $label = $this->stage_label($stage);
        $this->progress->print_line("  {$dim}✓ {$label}{$r}\n");
    }

    private function print_summary(): void
    {
        $green = "\033[32m";
        $bold = "\033[1m";
        $dim = "\033[2m";
        $r = "\033[0m";
        $fs_root = $this->client->fs_root();
        $this->progress->print_line(
            "\n{$green}{$bold}Done.{$r} {$dim}Files in {$fs_root}{$r}\n"
        );
        if ($this->checkpoint()->skipped_pending) {
            $this->progress->print_line(
                "{$dim}Deferred files remain. The skipped download list was preserved on disk for a follow-up sync.{$r}\n"
            );
        }
    }

    private function report_failure(string $stage, array $stages, int $i, \Exception $e): void
    {
        $this->client->output_progress([
            "status" => "error",
            "command" => "pull",
            "failed_stage" => $stage,
            "completed_stages" => array_slice($stages, 0, $i),
            "error_code" => $this->client->last_error_code(),
            "error" => $e->getMessage(),
            "message" => "Pull failed at {$stage}: " . $e->getMessage(),
        ]);
        $this->client->write_status_file("Pull failed at {$stage}: " . $e->getMessage());

        $red = "\033[31m";
        $dim = "\033[2m";
        $r = "\033[0m";
        $this->progress->clear_progress_line();
        $this->progress->print_line("  {$red}✗ " . $this->stage_label($stage) . "{$r}\n");
        $this->progress->print_line("    {$dim}" . $e->getMessage() . "{$r}\n\n");
        $this->progress->print_line("  Re-run the same command to resume.\n");
    }

    public function format_bytes(int $bytes): string
    {
        return ByteFormatter::format($bytes);
    }
}
