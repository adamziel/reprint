<?php

namespace Reprint\Importer\Application;

use Reprint\Importer\Pull\PullCheckpoint;
use Reprint\Importer\Pull\PullRuntime;
use Reprint\Importer\Session\ImportPaths;
use Reprint\Importer\Sql\DbApplyCheckpoint;
use Reprint\Importer\Support\ByteFormatter;
use Reprint\Importer\Application\UseCase\DbApplyHandler;
use Reprint\Importer\Application\UseCase\DbPullHandler;
use Reprint\Importer\Application\UseCase\FlatDocrootHandler;
use Reprint\Importer\Application\UseCase\FilesPullHandler;
use Reprint\Importer\Application\UseCase\PreflightHandler;
use Reprint\Importer\Application\UseCase\RuntimeApplyHandler;

final class PullRuntimeAdapter implements PullRuntime
{
    private ImportContext $context;
    private ImportServices $services;

    public function __construct(ImportContext $context, ImportServices $services)
    {
        $this->context = $context;
        $this->services = $services;
    }

    public function ensure_site_export_api_url(): void
    {
        $this->context->ensure_site_export_api_url();
    }

    public function remote_host(): string
    {
        return $this->context->remote_host();
    }

    public function output_progress(array $data, bool $force = false): void
    {
        $this->context->output_progress($data, $force);
    }

    public function write_status_file(?string $error = null): void
    {
        $this->context->write_status_file($error);
    }

    public function audit_log(string $message, bool $to_console = true): void
    {
        $this->context->audit_log($message, $to_console);
    }

    public function paths(): ImportPaths
    {
        return $this->context->paths();
    }

    public function prepare_repull_run_state(): void
    {
        $this->context->prepare_repull_run_state();
    }

    public function delete_pull_checkpoint(): void
    {
        $this->context->delete_pull_checkpoint();
    }

    public function pull_checkpoint(): PullCheckpoint
    {
        return $this->context->pull_checkpoint();
    }

    public function save_pull_checkpoint(PullCheckpoint $checkpoint): void
    {
        $this->context->save_pull_checkpoint($checkpoint);
    }

    public function current_run_status(): ?string
    {
        return $this->context->current_run_status();
    }

    public function set_run_status(?string $status): void
    {
        $this->context->set_run_status($status);
    }

    public function set_exit_code(int $exit_code): void
    {
        $this->context->set_exit_code($exit_code);
    }

    public function last_error_code(): ?string
    {
        return $this->context->last_error_code();
    }

    public function preflight_entry(): ?array
    {
        return $this->context->preflight_entry();
    }

    public function preflight_data(): ?array
    {
        return $this->context->preflight_data();
    }

    public function default_runtime_output_dir(): string
    {
        return $this->context->default_runtime_output_dir();
    }

    public function current_filter(): string
    {
        return $this->context->filter();
    }

    public function has_skipped_files_pending(): bool
    {
        return $this->context->has_skipped_files_pending();
    }

    public function index_count(): int
    {
        return $this->context->index_count();
    }

    public function db_apply_checkpoint(): DbApplyCheckpoint
    {
        return $this->context->db_apply_checkpoint();
    }

    public function run_preflight(): void
    {
        (new PreflightHandler())->fetch($this->context, $this->services);
    }

    public function run_files_sync(): void
    {
        (new FilesPullHandler())->execute($this->context, $this->services, []);
    }

    public function run_db_sync(): void
    {
        (new DbPullHandler())->execute($this->context, $this->services, []);
    }

    public function run_db_apply(array $options): void
    {
        (new DbApplyHandler())->execute($this->context, $this->services, $options);
    }

    public function run_flat_document_root(array $options): void
    {
        (new FlatDocrootHandler())->execute($this->context, $this->services, $options);
    }

    public function run_apply_runtime(array $options): void
    {
        (new RuntimeApplyHandler())->execute($this->context, $this->services, $options);
    }

    public function fs_root(): string
    {
        return $this->context->fs_root();
    }

    public function format_bytes(int $bytes): string
    {
        return ByteFormatter::format($bytes);
    }
}
