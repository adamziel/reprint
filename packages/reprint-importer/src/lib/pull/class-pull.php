<?php
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
 * The class holds a reference to ImportClient because each stage
 * delegates to an ImportClient method (run_preflight, run_files_sync,
 * etc.). The orchestration logic (pipeline state, retry loop, stage
 * framing) lives here; the actual transfer logic stays in ImportClient.
 */
class Pull
{
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
     * Always: preflight → files-pull → db-pull.
     * Adds db-apply when a database target is configured, flat-docroot
     * when --flatten-to is set, apply-runtime when --runtime is set,
     * and start when the runtime can be launched in-process.
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
        if (!empty($options['runtime'])) {
            $stages[] = 'apply-runtime';
            // php-builtin and playground-cli both generate a start.sh
            // that can be launched directly from the CLI.
            if (in_array($options['runtime'], ['php-builtin', 'playground-cli'], true)) {
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
            case 'files-pull':    return 'Pulling files';
            case 'db-pull':       return 'Pulling database';
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

        $options = $this->validate_and_default_options($options);

        $stages = $this->stages($options);
        $total = count($stages);
        $state = $this->client->state;
        $completed_stage = $state['pull']['stage'] ?? null;

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

        $host = parse_url($this->client->remote_url, PHP_URL_HOST) ?? $this->client->remote_url;
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
            $step = $i + 1;

            $this->print_stage_header($stage);

            try {
                $this->run_stage($stage, $options, $step, $total);
            } catch (\Exception $e) {
                $this->report_failure($stage, $stages, $i, $e);
                throw $e;
            }

            $this->client->mark_pull_stage_complete($stage);
        }

        // The 'start' stage handles its own completion (it needs to save
        // state before blocking on the server process).
        if (!in_array('start', $stages, true)) {
            $this->client->mark_pull_complete();
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

    private function run_stage(string $stage, array $options, int $step, int $total): void
    {
        switch ($stage) {
            case 'preflight':
                $this->client->run_preflight();
                if ($this->check_plugin_installed()) {
                    $this->client->exit_code = 1;
                    return;
                }
                $this->print_done($stage, $this->preflight_summary());
                break;

            case 'files-pull':
                $this->run_until_complete(function () {
                    $this->client->run_files_sync();
                });
                $skipped_pending =
                    $options['filter'] === 'essential-files' &&
                    $this->client->has_skipped_files_pending();
                $this->client->set_pull_files_state($options['filter'], $skipped_pending);
                $count = $this->client->index_count();
                $summary = $count > 0 ? number_format($count) . " files" : null;
                if ($skipped_pending) {
                    $summary = $summary !== null
                        ? $summary . ", deferred files pending"
                        : "deferred files pending";
                }
                $this->print_done($stage, $summary);
                break;

            case 'db-pull':
                $this->run_until_complete(function () {
                    $this->client->run_db_sync();
                });
                $sql_file = $this->client->state_dir . "/db.sql";
                $size = file_exists($sql_file) ? $this->format_bytes(filesize($sql_file)) : null;
                $this->print_done($stage, $size);
                break;

            case 'db-apply':
                $this->run_until_complete(function () use ($options) {
                    $this->client->run_db_apply($options);
                });
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
    }

    /**
     * Validate user-provided options and apply defaults.
     */
    private function validate_and_default_options(array $options): array
    {
        // Default --runtime to php-builtin so pull always ends with a
        // running local server. Users can override with --runtime=nginx-fpm,
        // --runtime=playground-cli, or --runtime=none to skip runtime
        // generation entirely.
        if (empty($options['runtime'])) {
            $options['runtime'] = 'php-builtin';
        }
        $valid_runtimes = ['nginx-fpm', 'php-builtin', 'playground-cli', 'none'];
        if (!in_array($options['runtime'], $valid_runtimes, true)) {
            throw new InvalidArgumentException(
                "Invalid --runtime value: {$options['runtime']}. " .
                "Valid runtimes: " . implode(', ', $valid_runtimes)
            );
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

        if (!isset($options['filter'])) {
            $options['filter'] = $this->client->state['filter'] ?? 'none';
        }
        if (!in_array($options['filter'], ['none', 'essential-files'], true)) {
            throw new InvalidArgumentException(
                "Invalid --filter value for pull: {$options['filter']}. " .
                "Valid values: none, essential-files"
            );
        }

        return $options;
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

    /**
     * Reset sub-command state for a delta re-pull.
     *
     * Keeps the local file index (so files-pull runs in delta mode) and
     * preflight data, but clears everything else and deletes db.sql /
     * the remote index so the next pull re-fetches them.
     */
    private function prepare_repull(): void
    {
        $state_dir = $this->client->state_dir;
        $defaults = $this->client->default_state();
        $this->client->mutate_state(function (array $state) use ($defaults) {
            $state['pull']['stage'] = null;
            $state['pull']['files_filter'] = null;
            $state['pull']['skipped_pending'] = false;
            $state['command'] = null;
            $state['status'] = null;
            $state['cursor'] = null;
            $state['stage'] = null;
            $state['consecutive_timeouts'] = 0;
            $state['sql_bytes'] = null;
            $state['current_file'] = null;
            $state['current_file_bytes'] = null;
            $state['db_index'] = $defaults['db_index'];
            $state['diff'] = $defaults['diff'];
            $state['fetch'] = $defaults['fetch'];
            $state['fetch_skipped'] = $defaults['fetch_skipped'];
            $state['apply'] = $defaults['apply'];
            $state['sql_output'] = null;
            return $state;
        });

        foreach ([
            $state_dir . "/db.sql",
            $state_dir . "/.import-domains.json",
            $state_dir . "/.import-remote-index.jsonl",
            $state_dir . "/.import-download-list.jsonl",
            $state_dir . "/.import-download-list-skipped.jsonl",
        ] as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        $this->client->audit_log("PULL | prepared for delta re-pull", true);
    }

    /**
     * Sub-commands return with state["status"]="partial" when a server
     * timeout drops the connection. This loop retries automatically,
     * resetting the status to "in_progress" so the handler enters its
     * resume path (it specifically checks for that value).
     */
    private function run_until_complete(callable $handler): void
    {
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $handler();
            $state = $this->client->state;
            if (($state['status'] ?? null) !== 'partial') {
                break;
            }
            $this->client->mutate_state(function (array $state) {
                $state['status'] = 'in_progress';
                return $state;
            });
            $this->client->exit_code = 0;
            $this->progress->tick_spinner();
        }
    }

    /**
     * Check whether preflight detected that the exporter plugin is not
     * installed. Returns true if so (caller should abort the pipeline).
     */
    private function check_plugin_installed(): bool
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

    private function report_failure(string $stage, array $stages, int $i, \Exception $e): void
    {
        $this->client->output_progress([
            "status" => "error",
            "command" => "pull",
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
