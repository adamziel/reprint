<?php

namespace Reprint\Importer\Sql\Port;

interface DbApplyObserver
{
    public function on_workflow_starting(): void;

    public function on_workflow_resuming(int $statements_executed, int $bytes_read): void;

    /**
     * @param array<int, string> $domains
     * @param array<string, string> $url_mapping
     */
    public function on_domains_discovered(array $domains, array $url_mapping): void;

    public function on_lifecycle_line(string $message): void;

    public function on_apply_starting(?int $statements_total): void;

    public function on_apply_progress(
        int $statements_executed,
        ?int $statements_total,
        int $bytes_read,
        int $bytes_total
    ): void;

    public function on_apply_partial(int $statements_executed, ?int $statements_total): void;

    public function on_apply_complete(int $statements_executed, ?int $statements_total): void;

    public function on_fast_query_stream_fallback(int $byte_offset): void;
}
