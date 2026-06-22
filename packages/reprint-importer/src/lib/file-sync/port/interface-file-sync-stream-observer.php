<?php

namespace Reprint\Importer\FileSync\Port;

use Reprint\Importer\Protocol\StreamingContext;

interface FileSyncStreamObserver
{
    public function on_metadata_chunk(array $chunk, StreamingContext $context): void;

    public function on_file_chunk(array $chunk, StreamingContext $context): void;

    public function on_directory_chunk(array $chunk): void;

    public function on_symlink_chunk(array $chunk): void;

    public function on_missing_path(string $path): void;

    public function on_error_chunk(array $chunk, string $phase, StreamingContext $context): void;

    public function on_progress_chunk(array $chunk, string $phase): void;

    /**
     * @param array<string, mixed> $progress
     */
    public function on_completion_progress(array $progress): void;

    public function on_index_progress(int $entries_counted): void;
}
