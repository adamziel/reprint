<?php

namespace Reprint\Importer\Command;

use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Session\ImportPaths;
use Reprint\Importer\Session\PreflightCheckpoint;
use Reprint\Importer\Sql\DbPullCheckpoint;

interface ImportRuntime
{
    public function abort_command(string $command): void;

    public function abort_pull(): void;

    public function run_apply_runtime(array $options): void;

    public function run_db_apply(array $options): void;

    public function run_db_index(): void;

    public function run_db_sync(): void;

    public function run_files_index(): void;

    public function run_files_sync(): void;

    public function run_flat_document_root(array $options): void;

    public function run_pull(array $options): void;

    public function paths(): ImportPaths;

    public function audit_logger(): AuditLogger;

    public function remote_index_file(): string;

    public function download_list_file(): string;

    public function skipped_download_list_file(): string;

    public function files_pull_checkpoint(): FilesPullCheckpoint;

    public function load_db_pull_checkpoint(): DbPullCheckpoint;

    public function preflight_checkpoint(): PreflightCheckpoint;

    public function build_url(string $endpoint, ?string $cursor, array $params = []): string;

    public function audit_log(string $message, bool $to_console = true): void;

    public function set_request_user_agent(string $user_agent): void;

    /**
     * @return array<int, string>
     */
    public function user_agents(): array;

    /**
     * @return array<string, mixed>
     */
    public function fetch_json(string $url): array;

    public function has_wpcloud_docroot_link(): bool;

    public function save_preflight_checkpoint(PreflightCheckpoint $checkpoint): void;

    public function write_status_file(?string $error = null): void;

    public function download_runtime_files(): void;

    /**
     * @return array<string, mixed>|null
     */
    public function parse_index_line(string $line): ?array;
}
