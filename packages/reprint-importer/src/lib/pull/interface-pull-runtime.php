<?php

namespace Reprint\Importer\Pull;

use Reprint\Importer\Session\ImportPaths;
use Reprint\Importer\Sql\DbApplyCheckpoint;

interface PullRuntime
{
    public function ensure_site_export_api_url(): void;

    public function remote_host(): string;

    public function output_progress(array $data, bool $force = false): void;

    public function audit_log(string $message, bool $to_console = true): void;

    public function paths(): ImportPaths;

    public function prepare_repull_run_state(): void;

    public function delete_pull_checkpoint(): void;

    public function pull_checkpoint(): PullCheckpoint;

    public function save_pull_checkpoint(PullCheckpoint $checkpoint): void;

    public function current_run_status(): ?string;

    public function set_run_status(?string $status): void;

    public function set_exit_code(int $exit_code): void;

    public function last_error_code(): ?string;

    public function preflight_entry(): ?array;

    public function preflight_data(): ?array;

    public function default_runtime_output_dir(): string;

    public function current_filter(): string;

    public function has_skipped_files_pending(): bool;

    public function index_count(): int;

    public function db_apply_checkpoint(): DbApplyCheckpoint;

    public function run_preflight(): void;

    public function run_files_sync(): void;

    public function run_db_sync(): void;

    public function run_db_apply(array $options): void;

    public function run_flat_document_root(array $options): void;

    public function run_apply_runtime(array $options): void;
}
