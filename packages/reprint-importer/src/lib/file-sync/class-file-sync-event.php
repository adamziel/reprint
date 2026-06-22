<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\Observability\ImportEvent;

final class FileSyncEvent
{
    public const AUDIT = 'file_sync.audit';
    public const FILES_PULL_STARTING = 'file_sync.files_pull.starting';
    public const FILES_PULL_RESUMING = 'file_sync.files_pull.resuming';
    public const FILES_PULL_ALREADY_COMPLETE = 'file_sync.files_pull.already_complete';
    public const FILES_PULL_COMPLETE = 'file_sync.files_pull.complete';
    public const FILES_PULL_FETCH_SKIPPED_STARTING = 'file_sync.files_pull.fetch_skipped.starting';
    public const FILES_INDEX_STARTING = 'file_sync.files_index.starting';
    public const FILES_INDEX_RESUMING = 'file_sync.files_index.resuming';
    public const FILES_INDEX_COMPLETE = 'file_sync.files_index.complete';
    public const DOWNLOAD_PROGRESS_STARTING = 'file_sync.download_progress.starting';
    public const FILE_DELETED = 'file_sync.file.deleted';

    /**
     * @param array<string, mixed> $payload
     */
    public static function named(string $name, array $payload = []): ImportEvent
    {
        return ImportEvent::named($name, $payload);
    }

    public static function audit(string $message, bool $to_console = true): ImportEvent
    {
        return self::named(self::AUDIT, [
            'message' => $message,
            'to_console' => $to_console,
        ]);
    }
}
