<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\FileSync\Port\FileIndexGateway;
use Reprint\Importer\FileSync\Port\FileSyncStreamClient;
use Reprint\Importer\FileSync\Port\FileSyncStreamObserver;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\FileSync\Port\FilesPullTimeoutPolicy;
use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Protocol\CurlTimeoutException;
use Reprint\Importer\Protocol\StreamingContext;

final class FileFetchDownloader
{
    private FileSyncStreamClient $stream;
    private ShutdownToken $shutdown;
    private FilesPullCheckpointStore $checkpoints;
    private FileSyncStreamObserver $observer;
    private FilesPullTimeoutPolicy $timeout_policy;
    private FileIndexGateway $index;
    private AuditLogger $audit;

    public function __construct(
        FileSyncStreamClient $stream,
        ShutdownToken $shutdown,
        FilesPullCheckpointStore $checkpoints,
        FileSyncStreamObserver $observer,
        FilesPullTimeoutPolicy $timeout_policy,
        FileIndexGateway $index,
        AuditLogger $audit
    ) {
        $this->stream = $stream;
        $this->shutdown = $shutdown;
        $this->checkpoints = $checkpoints;
        $this->observer = $observer;
        $this->timeout_policy = $timeout_policy;
        $this->index = $index;
        $this->audit = $audit;
    }

    /**
     * Download file content for a prepared file list.
     *
     * @param array{
     *     post_data:?array,
     *     cursor:?string,
     *     state_key:string,
     *     export_dirs:array<int, string>,
     *     save_every:int
     * } $config
     */
    public function download(FilesPullCheckpoint $checkpoint, array $config): bool
    {
        $state_key = $config["state_key"];
        $cursor = $config["cursor"] ?? null;
        $fetch_checkpoint = $checkpoint->fetch_checkpoint($state_key);
        $cursor = $cursor ?? $fetch_checkpoint->cursor;

        $tracked_file = $checkpoint->current_file;
        $tracked_bytes = $checkpoint->current_file_bytes;
        if ($tracked_file !== null && $tracked_bytes !== null && file_exists($tracked_file)) {
            $actual_size = filesize($tracked_file);
            if ($actual_size > $tracked_bytes) {
                ($this->audit)(
                    sprintf(
                        "CRASH RECOVERY | Truncating %s from %d to %d bytes",
                        $tracked_file,
                        $actual_size,
                        $tracked_bytes,
                    ),
                    true,
                );
                $handle = fopen($tracked_file, "r+");
                if ($handle) {
                    ftruncate($handle, $tracked_bytes);
                    fclose($handle);
                }
            }
        }

        $params = $this->stream->tuned_params("file_fetch");
        $export_dirs = $config["export_dirs"];
        if (!empty($export_dirs)) {
            $params["directory"] = $export_dirs;
        }

        $url = $this->stream->build_url("file_fetch", $cursor, $params);
        $post_data = $config["post_data"];
        $this->audit->record("Downloading file fetch from {$url}", true);
        $this->audit->record("POST data: " . json_encode($post_data), true);

        $context = new StreamingContext();
        $context->file_handle = null;
        $context->file_path = null;
        $context->file_ctime = null;

        if ($tracked_file !== null && $tracked_bytes !== null && file_exists($tracked_file)) {
            $context->file_handle = fopen($tracked_file, "ab");
            if ($context->file_handle) {
                $context->file_path = $tracked_file;
                $context->file_bytes_written = $tracked_bytes;
                $this->audit->record(
                    sprintf(
                        "RESUME FILE | Re-opened %s at %d bytes for continued download",
                        $tracked_file,
                        $tracked_bytes,
                    ),
                    true,
                );
            }
        }

        $response_handler = new FileFetchResponseHandler(
            $cursor,
            $state_key,
            $context,
            $config["save_every"],
            $checkpoint,
            $this->shutdown,
            $this->checkpoints,
            $this->observer,
        );
        $context->on_chunk = [$response_handler, 'handle'];

        $cursor_before = $cursor;
        $request_start = microtime(true);
        try {
            $this->stream->fetch_streaming(
                $url,
                $cursor,
                $context,
                $post_data,
                "file_fetch",
            );
        } catch (CurlTimeoutException $e) {
            $cursor = $response_handler->cursor();
            $this->timeout_policy->assert_can_retry(
                $checkpoint,
                "file_fetch",
                $cursor_before,
                $cursor,
            );
            $fetch_checkpoint->cursor = $cursor;
            $this->index->finalize_updates();
            $this->track_current_file_if_active($checkpoint, $context);
            $checkpoint->status = "partial";
            $this->checkpoints->save($checkpoint);
            return false;
        }

        $cursor = $response_handler->cursor();
        $complete = $response_handler->complete();
        $checkpoint->consecutive_timeouts = 0;
        $wall_time = microtime(true) - $request_start;

        $this->stream->finalize_request(
            "file_fetch",
            $wall_time,
            $context->response_stats ?? [],
        );
        $fetch_checkpoint->cursor = $cursor;
        $this->index->finalize_updates();
        $this->track_current_file($checkpoint, $context);
        $this->checkpoints->save($checkpoint);

        return $complete;
    }

    private function track_current_file(
        FilesPullCheckpoint $checkpoint,
        StreamingContext $context
    ): void
    {
        if ($context->file_handle && $context->file_path) {
            fflush($context->file_handle);
            $checkpoint->current_file = $context->file_path;
            $checkpoint->current_file_bytes = $context->file_bytes_written;
            return;
        }

        $checkpoint->current_file = null;
        $checkpoint->current_file_bytes = null;
    }

    private function track_current_file_if_active(
        FilesPullCheckpoint $checkpoint,
        StreamingContext $context
    ): void
    {
        if ($context->file_handle && $context->file_path) {
            fflush($context->file_handle);
            $checkpoint->current_file = $context->file_path;
            $checkpoint->current_file_bytes = $context->file_bytes_written;
        }
    }
}
