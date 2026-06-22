<?php

namespace Reprint\Importer\Sql\Port;

use Reprint\Importer\Protocol\StreamingContext;

interface SqlStreamObserver
{
    public function on_sql_progress(int $sql_bytes_written): void;

    public function on_progress_chunk(array $chunk, string $phase): void;

    public function on_error_chunk(array $chunk, string $phase, StreamingContext $context): void;

    /**
     * @param array<string, mixed> $progress
     */
    public function on_completion_progress(array $progress): void;

    public function on_stdout_write_failed(): void;
}
