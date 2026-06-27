<?php
/**
 * The `pull` command — orchestrates the lower-level commands into a
 * single resumable site clone pipeline.
 *
 * Each step retries automatically on server timeouts (exit code 2). If
 * the process is interrupted, re-running pull resumes from the last
 * completed step. Like `git pull` composes fetch + merge, this composes
 * preflight → files-download → db-download → db-apply → flat-docroot →
 * apply-runtime → start.
 *
 * The class holds a reference to ImportClient because each stage
 * delegates to an ImportClient method (run_preflight, run_files_sync,
 * etc.). The orchestration logic (pipeline state, retry loop, stage
 * framing) lives here; the actual transfer logic stays in ImportClient.
 */
class Pull
{
    /**
     * Server timeouts report partial progress and should be retried, but
     * a stage that never reaches complete must fail rather than let the
     * orchestrator mark it complete.
     */
    private const MAX_STAGE_RETRIES = 1000;

    private ImportClient $client;
    private TerminalProgress $progress;

    public function __construct(ImportClient $client, TerminalProgress $progress)
    {
        $this->client = $client;
        $this->progress = $progress;
    }

    /**
     * Determine the pipeline stages based on the provided options.
     *
     * Always: preflight → files-download → db-download.
     * Adds db-apply when a database target is configured, flat-docroot
     * when --flatten-to is set, apply-runtime when --runtime is set,
     * and start when the selected start runtime can be launched
     * in-process.
     */
    public function stages(array $options): array
    {
        $stages = ['preflight', 'files-download', 'db-download'];
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
        switch ($stage) {
            case 'preflight':     return 'Connecting';
            case 'files-download':    return 'Pulling files';
            case 'db-download':       return 'Pulling database';
            case 'db-apply':      return 'Importing database';
            case 'flat-docroot':  return 'Flattening layout';
            case 'apply-runtime': return 'Preparing runtime';
            case 'start':         return 'Starting server';
            default:              return $stage;
        }
    }

    /**
     * Run the pull pipeline.
     */
    public function run(array $options): void
    {
        $this->normalize_url();
        $this->progress->enable_quiet_lifecycle();

        $options = $this->validate_and_default_command_options('pull', $options);

        $this->run_resumable_pipeline(
            'pull',
            $this->stages($options),
            $options,
            'pull',
            'Pulling',
        );
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

    /**
     * Run the file-only pull pipeline.
     */
    public function run_pull_files(array $options): void
    {
        $this->normalize_url();
        $this->progress->enable_quiet_lifecycle();

        $options = $this->validate_and_default_command_options('pull-files', $options);
        $this->run_resumable_pipeline(
            'pull-files',
            ['preflight', 'files-download'],
            $options,
            'pull_files',
            'Pull files from',
        );
    }

    /**
     * Run the database-only pull pipeline.
     */
    public function run_pull_db(array $options): void
    {
        $this->normalize_url();
        $this->progress->enable_quiet_lifecycle();

        $options = $this->validate_and_default_command_options('pull-db', $options);
        $this->run_resumable_pipeline(
            'pull-db',
            ['preflight', 'db-download', 'db-apply'],
            $options,
            'pull_db',
            'Pull database from',
        );
    }

    public function validate_and_default_command_options(string $command, array $options): array
    {
        switch ($command) {
            case 'pull':
                return $this->validate_and_default_options($options);
            case 'pull-files':
                return $this->validate_and_default_pull_files_options($options, 'pull-files');
            case 'pull-db':
                return $this->validate_and_default_pull_db_options($options);
        }

        throw new InvalidArgumentException("Unknown pull pipeline: {$command}");
    }

    public function clear_pipeline_state(string $command): void
    {
        $state_key = $this->pipeline_state_key($command);
        $this->client->mutate_state(function (array $state) use ($state_key) {
            $state[$state_key]['stage'] = null;
            $state[$state_key]['has_completed_once'] = true;
            if ($state_key === 'pull_files') {
                $state[$state_key]['files_filter'] = null;
                $state[$state_key]['skipped_pending'] = false;
            }
            return $state;
        });
    }

    private function run_resumable_pipeline(
        string $command,
        array $stages,
        array $options,
        string $state_key,
        string $title
    ): void {
        $completed_stage = $this->completed_pipeline_stage($state_key);

        // A completed high-level command should behave like `git pull`:
        // reset its orchestration cursor and run a fresh delta against the
        // current remote state.
        if ($completed_stage === 'complete') {
            $this->prepare_completed_pipeline_for_rerun($command, $state_key);
            $completed_stage = null;
        }

        $start_index = 0;
        if ($completed_stage !== null) {
            $idx = array_search($completed_stage, $stages, true);
            if ($idx !== false) {
                $start_index = $idx + 1;
            }
        }

        $this->run_pipeline($command, $stages, $options, $start_index, $title, $state_key);
    }

    private function run_pipeline(
        string $command,
        array $stages,
        array $options,
        int $start_index,
        string $title,
        string $state_key
    ): void {
        $total = count($stages);
        $host = parse_url($this->client->remote_url, PHP_URL_HOST) ?? $this->client->remote_url;
        $bold = "\033[1m";
        $r = "\033[0m";
        $this->progress->print_line("\n{$bold}{$title} {$host}{$r}\n");

        $this->client->output_progress([
            "type" => "lifecycle",
            "event" => "starting",
            "command" => $command,
            "stages" => $stages,
            "resume_from" => $start_index,
            "message" => "Starting {$command}",
        ], true);

        $this->client->audit_log(
            sprintf("%s | stages=%s | resume_from=%d", strtoupper($command), implode(",", $stages), $start_index),
            true,
        );

        for ($i = 0; $i < $start_index; $i++) {
            $this->print_skipped($stages[$i]);
        }

        for ($i = $start_index; $i < $total; $i++) {
            $stage = $stages[$i];
            $this->print_stage_header($stage);

            try {
                if (!$this->run_stage($command, $stage, $options, $i + 1, $total)) {
                    return;
                }
            } catch (\Exception $e) {
                $this->report_failure($command, $stage, $stages, $i, $e);
                throw $e;
            }

            $this->mark_pipeline_stage_complete($state_key, $stage);
        }

        // The 'start' stage handles its own completion (it needs to save
        // state before blocking on the server process).
        if (!in_array('start', $stages, true)) {
            $this->mark_pipeline_complete($command, $state_key);
            if ($command === 'pull') {
                $this->print_summary();
            }
        }

        $complete_message = $command === 'pull' ? 'Pull complete' : "{$command} complete";
        $this->client->output_progress([
            "type" => "lifecycle",
            "event" => "complete",
            "command" => $command,
            "stages" => $stages,
            "message" => $complete_message,
        ], true);
    }

    private function completed_pipeline_stage(string $state_key): ?string
    {
        $stage = $this->client->state[$state_key]['stage'] ?? null;
        return $this->normalize_stage_name($stage);
    }

    private function mark_pipeline_stage_complete(string $state_key, string $stage): void
    {
        if ($state_key === 'pull') {
            $this->client->mark_pull_stage_complete($stage);
            return;
        }

        $this->client->mutate_state(function (array $state) use ($state_key, $stage) {
            $state[$state_key]['stage'] = $stage;
            return $state;
        });
    }

    private function mark_pipeline_complete(string $command, string $state_key): void
    {
        if ($state_key === 'pull') {
            $this->client->mark_pull_complete();
            return;
        }

        $this->client->mutate_state(function (array $state) use ($state_key) {
            $state[$state_key]['stage'] = 'complete';
            $state[$state_key]['has_completed_once'] = true;
            $state['status'] = 'complete';
            return $state;
        });
        $this->clear_completed_atomic_command($command);
    }

    private function set_pipeline_files_state(string $command, string $filter, bool $skipped_pending): void
    {
        if ($command === 'pull') {
            $this->client->set_pull_files_state($filter, $skipped_pending);
            return;
        }

        $this->client->mutate_state(function (array $state) use ($filter, $skipped_pending) {
            $state['pull_files']['files_filter'] = $filter;
            $state['pull_files']['skipped_pending'] = $skipped_pending;
            return $state;
        });
    }

    private function clear_completed_atomic_command(string $command): void
    {
        if (!in_array($command, ['pull-files', 'pull-db'], true)) {
            return;
        }

        $this->client->mutate_state(function (array $state) {
            $state['command'] = null;
            $state['stage'] = null;
            $state['cursor'] = null;
            return $state;
        });
    }

    private function run_stage(string $command, string $stage, array $options, int $step, int $total): bool
    {
        switch ($stage) {
            case 'preflight':
                $this->client->run_preflight();
                if ($this->check_plugin_installed($command)) {
                    $this->client->exit_code = 1;
                    return false;
                }
                $this->print_done($stage, $this->preflight_summary());
                break;

            case 'files-download':
                $this->prepare_files_stage_for_pipeline($command);
                $options = $this->validate_and_default_pull_files_options($options, 'files-download');
                $this->client->prepare_files_download_options($options);

                $this->run_until_complete(function () {
                    $this->client->run_files_sync();
                });
                $skipped_pending =
                    $options['filter'] === 'essential-files' &&
                    $this->client->has_skipped_files_pending();
                $this->set_pipeline_files_state($command, $options['filter'], $skipped_pending);
                $count = $this->client->index_count();
                $summary = $count > 0 ? number_format($count) . " files" : null;
                if ($skipped_pending) {
                    $summary = $summary !== null
                        ? $summary . ", deferred files pending"
                        : "deferred files pending";
                }
                $this->print_done($stage, $summary);
                break;

            case 'db-download':
                $this->prepare_db_stage_for_pipeline($command);
                if (!$this->database_stage_already_complete($command, 'db-download')) {
                    $this->run_until_complete(function () {
                        $this->client->run_db_sync();
                    });
                }
                $sql_file = $this->client->state_dir . "/db.sql";
                $size = file_exists($sql_file) ? $this->format_bytes(filesize($sql_file)) : null;
                $this->print_done($stage, $size);
                break;

            case 'db-apply':
                if (!$this->database_stage_already_complete($command, 'db-apply')) {
                    $this->run_until_complete(function () use ($options) {
                        $this->client->run_db_apply($options);
                    });
                }
                $state = $this->client->state;
                $stmts = (int) ($state["apply"]["statements_executed"] ?? 0);
                $this->print_done($stage, $stmts > 0 ? number_format($stmts) . " statements" : null);
                break;

            case 'flat-docroot':
                $this->client->run_flat_document_root($options);
                $this->print_done($stage);
                break;

            case 'apply-runtime':
                $this->client->run_apply_runtime($options);
                $this->print_done($stage);
                break;

            case 'start':
                $this->start_server($options);
                break;
        }

        return true;
    }

    private function prepare_files_stage_for_pipeline(string $command): void
    {
        $owner = $this->client->state['files_pipeline_owner'] ?? null;
        $state_command = $this->client->state['command'] ?? null;
        $state_status = $this->client->state['status'] ?? null;
        $needs_reset =
            ($owner !== null && $owner !== $command) ||
            ($owner === null && $state_command === 'files-download' && $state_status === 'complete');

        if ($needs_reset) {
            $defaults = $this->client->default_state();
            $this->client->mutate_state(function (array $state) use ($defaults, $command) {
                $state = $this->reset_files_download_state($state, $defaults);
                $state['files_pipeline_owner'] = $command;
                return $state;
            });
            $this->delete_state_files($this->files_download_state_files());
            return;
        }

        if ($owner !== $command) {
            $this->client->mutate_state(function (array $state) use ($command) {
                $state['files_pipeline_owner'] = $command;
                return $state;
            });
        }
    }

    private function prepare_db_stage_for_pipeline(string $command): void
    {
        $owner = $this->client->state['db_pipeline_owner'] ?? null;
        $state_command = $this->client->state['command'] ?? null;
        $state_status = $this->client->state['status'] ?? null;
        $needs_reset =
            ($owner !== null && $owner !== $command) ||
            ($owner === null && in_array($state_command, ['db-download', 'db-apply'], true) && $state_status === 'complete');

        if ($needs_reset) {
            $defaults = $this->client->default_state();
            $this->client->mutate_state(function (array $state) use ($defaults, $command) {
                $state = $this->reset_database_download_state($state, $defaults);
                $state['db_pipeline_owner'] = $command;
                return $state;
            });
            $this->delete_state_files($this->database_download_state_files());
            return;
        }

        if ($owner !== $command) {
            $this->client->mutate_state(function (array $state) use ($command) {
                $state['db_pipeline_owner'] = $command;
                return $state;
            });
        }
    }

    private function database_stage_already_complete(string $command, string $stage): bool
    {
        if (
            ($this->client->state['db_pipeline_owner'] ?? null) !== $command ||
            ($this->client->state['command'] ?? null) !== $stage ||
            ($this->client->state['status'] ?? null) !== 'complete'
        ) {
            return false;
        }

        return $stage !== 'db-download' || file_exists($this->client->state_dir . '/db.sql');
    }

    private function normalize_stage_name(?string $stage): ?string
    {
        static $legacy_stages = [
            'files-pull' => 'files-download',
            'db-pull' => 'db-download',
        ];
        return $stage !== null ? ($legacy_stages[$stage] ?? $stage) : null;
    }

    /**
     * Validate user-provided options and apply defaults.
     */
    private function validate_and_default_options(array $options): array
    {
        // --runtime and --start-runtime values are validated at CLI parse
        // time from VALID_TARGET_RUNTIMES. Re-check here for programmatic
        // callers (e.g. ImportClient::run invoked directly) that bypass
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
            $options['output_dir'] = $this->client->state_dir . '/runtime';
        }

        $options = $this->validate_and_default_pull_files_options($options, 'pull');

        // When --flatten-to produces a flattened document root, the
        // apply-runtime stage must target that flattened directory rather
        // than its default (the raw download tree + remote document_root).
        // Derive flat_document_root from flatten_to so a single
        // `pull --flatten-to=X --runtime=...` generates a runtime rooted at
        // the flattened layout.
        if (!empty($options['flatten_to']) && empty($options['flat_document_root'])) {
            $options['flat_document_root'] = $options['flatten_to'];
        }

        return $options;
    }

    private function validate_and_default_pull_files_options(array $options, string $command): array
    {
        if (!isset($options['filter'])) {
            $options['filter'] = $this->client->state['filter'] ?? 'none';
        }
        if (!in_array($options['filter'], ['none', 'essential-files'], true)) {
            throw new InvalidArgumentException(
                "Invalid --filter value for {$command}: {$options['filter']}. " .
                "Valid values: none, essential-files"
            );
        }
        return $options;
    }

    private function validate_and_default_pull_db_options(array $options): array
    {
        $options['sql_output'] = 'file';

        if (empty($options['target_engine'])) {
            $has_mysql_target =
                !empty($options['target_host']) ||
                !empty($options['target_port']) ||
                !empty($options['target_user']) ||
                !empty($options['target_pass']);
            $has_sqlite_target = !empty($options['target_sqlite_path']);
            if ($has_sqlite_target || (!$has_mysql_target && empty($options['target_db']))) {
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
            $options['target_engine'] = $engine;
        }

        if (($options['target_engine'] ?? 'mysql') === 'mysql') {
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
     * Append ?site-export-api to bare site URLs so users can pass
     * https://example.com instead of https://example.com/?site-export-api.
     */
    private function normalize_url(): void
    {
        $url = $this->client->remote_url;
        if (strpos($url, 'site-export-api') === false) {
            $separator = strpos($url, '?') === false ? '?' : '&';
            $this->client->remote_url = $url . $separator . 'site-export-api';
        }
    }

    private function pipeline_state_key(string $command): string
    {
        switch ($command) {
            case 'pull': return 'pull';
            case 'pull-files': return 'pull_files';
            case 'pull-db': return 'pull_db';
        }
        throw new InvalidArgumentException("Unknown pull pipeline: {$command}");
    }

    private function prepare_completed_pipeline_for_rerun(string $command, string $state_key): void
    {
        switch ($command) {
            case 'pull':
                $this->prepare_repull();
                return;
            case 'pull-files':
                $this->prepare_pull_files_repull($state_key);
                return;
            case 'pull-db':
                $this->prepare_pull_db_repull($state_key);
                return;
        }
    }

    /**
     * Reset sub-command state for a delta re-pull.
     *
     * Keeps preflight data and the durable local file index on disk, but
     * clears the transient stage cursors and batch files so each atomic
     * stage starts against the current remote state.
     */
    private function prepare_repull(): void
    {
        $defaults = $this->client->default_state();
        $this->client->mutate_state(function (array $state) use ($defaults) {
            $state['pull']['stage'] = null;
            $state['pull']['files_filter'] = null;
            $state['pull']['skipped_pending'] = false;
            $state['pull']['has_completed_once'] = true;
            $state = $this->reset_files_download_state($state, $defaults);
            $state = $this->reset_database_download_state($state, $defaults);
            return $state;
        });

        $this->delete_state_files(array_merge(
            $this->files_download_state_files(),
            $this->database_download_state_files(),
        ));

        $this->client->audit_log("PULL | prepared for delta re-pull", true);
    }

    private function prepare_pull_files_repull(string $state_key): void
    {
        $defaults = $this->client->default_state();
        $this->client->mutate_state(function (array $state) use ($defaults, $state_key) {
            $state[$state_key]['stage'] = null;
            $state[$state_key]['files_filter'] = null;
            $state[$state_key]['skipped_pending'] = false;
            $state[$state_key]['has_completed_once'] = true;
            return $this->reset_files_download_state($state, $defaults);
        });

        $this->delete_state_files($this->files_download_state_files());

        $this->client->audit_log("PULL-FILES | prepared for delta re-pull", true);
    }

    private function prepare_pull_db_repull(string $state_key): void
    {
        $defaults = $this->client->default_state();
        $this->client->mutate_state(function (array $state) use ($defaults, $state_key) {
            $state[$state_key]['stage'] = null;
            $state[$state_key]['has_completed_once'] = true;
            return $this->reset_database_download_state($state, $defaults);
        });

        $this->delete_state_files($this->database_download_state_files());

        $this->client->audit_log("PULL-DB | prepared for delta re-pull", true);
    }

    private function reset_files_download_state(array $state, array $defaults): array
    {
        $state['command'] = null;
        $state['status'] = null;
        $state['cursor'] = null;
        $state['stage'] = null;
        $state['current_file'] = null;
        $state['current_file_bytes'] = null;
        $state['diff'] = $defaults['diff'];
        $state['index'] = $defaults['index'];
        $state['fetch'] = $defaults['fetch'];
        $state['fetch_skipped'] = $defaults['fetch_skipped'];
        return $state;
    }

    private function reset_database_download_state(array $state, array $defaults): array
    {
        $state['command'] = null;
        $state['status'] = null;
        $state['cursor'] = null;
        $state['stage'] = null;
        $state['consecutive_timeouts'] = 0;
        $state['sql_bytes'] = null;
        $state['db_index'] = $defaults['db_index'];
        $state['apply'] = $defaults['apply'];
        $state['sql_output'] = null;
        return $state;
    }

    private function files_download_state_files(): array
    {
        $state_dir = $this->client->state_dir;
        return [
            $state_dir . "/.import-remote-index.jsonl",
            $state_dir . "/.import-download-list.jsonl",
            $state_dir . "/.import-download-list-skipped.jsonl",
        ];
    }

    private function database_download_state_files(): array
    {
        $state_dir = $this->client->state_dir;
        return [
            $state_dir . "/db.sql",
            $state_dir . "/db-tables.jsonl",
            $state_dir . "/.import-domains.json",
        ];
    }

    private function delete_state_files(array $paths): void
    {
        foreach ($paths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Sub-commands return with state["status"]="partial" when a server
     * timeout drops the connection. This loop retries automatically,
     * resetting the status to "in_progress" so the handler enters its
     * resume path (it specifically checks for that value).
     */
    private function run_until_complete(callable $handler): void
    {
        for ($attempt = 0; $attempt < self::MAX_STAGE_RETRIES; $attempt++) {
            $handler();
            $state = $this->client->state;
            $status = $state['status'] ?? null;
            if ($status === 'complete') {
                return;
            }
            if ($status !== 'partial') {
                throw new RuntimeException(
                    "Stage stopped with unexpected status " . var_export($status, true) .
                    "; aborting."
                );
            }
            $this->client->mutate_state(function (array $state) {
                $state['status'] = 'in_progress';
                return $state;
            });
            $this->client->exit_code = 0;
            $this->progress->tick_spinner();
        }

        throw new RuntimeException(
            'Stage kept reporting partial progress after ' . self::MAX_STAGE_RETRIES .
            ' retry attempts; aborting.'
        );
    }

    /**
     * Check whether preflight detected that the exporter plugin is not
     * installed. Returns true if so (caller should abort the pipeline).
     */
    private function check_plugin_installed(string $command): bool
    {
        $state = $this->client->state;
        $preflight = $state["preflight"] ?? null;
        $ok = ($preflight["http_code"] ?? 0) === 200 && !empty($preflight["data"]["ok"]);
        if ($ok) {
            return false;
        }

        $error = $preflight["error"] ?? null;
        $error_code = $this->client->last_error_code;
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
            "command" => $command,
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
    private function preflight_summary(): ?string
    {
        $state = $this->client->state;
        $data = $state["preflight"]["data"] ?? null;
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
    private function start_server(array $options): void
    {
        $output_dir = $options['output_dir'] ?? $this->client->state_dir . '/runtime';
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

        // Mark pull complete BEFORE the server blocks so killing the
        // server (Ctrl-C) doesn't leave the pipeline mid-flight.
        $this->client->mutate_state(function (array $state) {
            $state['pull']['stage'] = 'start';
            $state['status'] = 'complete';
            return $state;
        });

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
        $this->client->exit_code = $exit_code;
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

    private function print_done(string $stage, ?string $summary = null): void
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
        $fs_root = $this->client->fs_root;
        $this->progress->print_line(
            "\n{$green}{$bold}Done.{$r} {$dim}Files in {$fs_root}{$r}\n"
        );
        if (!empty($this->client->state['pull']['skipped_pending'])) {
            $this->progress->print_line(
                "{$dim}Deferred files remain. The skipped download list was preserved on disk for a follow-up sync.{$r}\n"
            );
        }
    }

    private function report_failure(string $command, string $stage, array $stages, int $i, \Exception $e): void
    {
        $this->client->output_progress([
            "status" => "error",
            "command" => $command,
            "failed_stage" => $stage,
            "completed_stages" => array_slice($stages, 0, $i),
            "error_code" => $this->client->last_error_code,
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

    private function format_bytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf("%.1f GB", $bytes / 1073741824);
        }
        if ($bytes >= 1048576) {
            return sprintf("%.1f MB", $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf("%.1f KB", $bytes / 1024);
        }
        return "{$bytes} B";
    }
}
