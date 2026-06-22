<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\FileSync\Port\FileSyncStreamClient;
use Reprint\Importer\FileSync\Port\FileSyncStreamObserver;
use Reprint\Importer\FileSync\Port\FileSyncWorkspace;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\FileSync\Port\FilesPullTimeoutPolicy;
use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Protocol\CurlTimeoutException;
use Reprint\Importer\Protocol\StreamingContext;
use RuntimeException;

final class RemoteIndexDownloader
{
    private FileSyncStreamClient $stream;
    private ShutdownToken $shutdown;
    private FilesPullCheckpointStore $checkpoints;
    private FileSyncStreamObserver $observer;
    private FilesPullTimeoutPolicy $timeout_policy;
    private FileSyncWorkspace $workspace;
    private AuditLogger $audit;

    public function __construct(
        FileSyncStreamClient $stream,
        ShutdownToken $shutdown,
        FilesPullCheckpointStore $checkpoints,
        FileSyncStreamObserver $observer,
        FilesPullTimeoutPolicy $timeout_policy,
        FileSyncWorkspace $workspace,
        AuditLogger $audit
    ) {
        $this->stream = $stream;
        $this->shutdown = $shutdown;
        $this->checkpoints = $checkpoints;
        $this->observer = $observer;
        $this->timeout_policy = $timeout_policy;
        $this->workspace = $workspace;
        $this->audit = $audit;
    }

    /**
     * Download the remote file index stream and append/write it to disk.
     *
     * @param array{
     *     remote_index_file:string,
     *     roots:array<int, string>,
     *     export_dirs:array<int, string>,
     *     list_dir_override?:?string,
     *     follow_symlinks:bool,
     *     include_caches:bool,
     *     save_every:int
     * } $config
     */
    public function download(
        FilesPullCheckpoint $checkpoint,
        array $config,
        int &$entries_counted
    ): bool
    {
        $cursor = $checkpoint->index_cursor;

        $roots = $config["roots"];
        if (empty($roots)) {
            throw new RuntimeException(
                "No root directories found. Either add directory[]=... to the " .
                    "export URL, or run preflight first so directories can be auto-detected.",
            );
        }

        $remote_index_file = $config["remote_index_file"];
        $mode = file_exists($remote_index_file) ? "a" : "w";
        if ($mode === "a" && $entries_counted === 0) {
            $entries_counted = $this->workspace->count_lines($remote_index_file);
        }

        if ($mode === "w") {
            $this->audit->record(
                "FILE CREATE | {$remote_index_file} | downloading fresh remote index",
                true,
            );
        } else {
            $this->audit->record(
                "FILE APPEND | {$remote_index_file} | resuming remote index download",
                true,
            );
        }

        $handle = fopen($remote_index_file, $mode);
        if (!$handle) {
            throw new RuntimeException("Failed to open remote index file");
        }

        try {
            $params = $this->stream->tuned_params("file_index");
            if ($cursor === null) {
                $params["list_dir"] = $config["list_dir_override"] ?? $roots[0];
            }
            if ($config["follow_symlinks"]) {
                $params["follow_symlinks"] = "1";
            }
            if ($config["include_caches"]) {
                $params["include_caches"] = "1";
            }
            $export_dirs = $config["export_dirs"];
            if (!empty($export_dirs)) {
                $params["directory"] = $export_dirs;
            }

            $url = $this->stream->build_url("file_index", $cursor, $params);
            $context = new StreamingContext();

            $response_handler = new IndexResponseHandler(
                $handle,
                $checkpoint,
                $cursor,
                $context,
                $entries_counted,
                $config["save_every"],
                $this->shutdown,
                $this->checkpoints,
                $this->observer,
            );
            $context->on_chunk = [$response_handler, 'handle'];

            $cursor_before = $cursor;
            $request_start = microtime(true);
            try {
                $this->stream->fetch_streaming($url, $cursor, $context, null, "file_index");
            } catch (CurlTimeoutException $e) {
                $cursor = $response_handler->cursor();
                $entries_counted = $response_handler->entries_counted();
                $this->timeout_policy->assert_can_retry(
                    $checkpoint,
                    "file_index",
                    $cursor_before,
                    $cursor,
                );

                fclose($handle);
                $handle = null;

                $checkpoint->index_cursor = $cursor;
                $checkpoint->status = "partial";
                $this->checkpoints->save($checkpoint);
                return false;
            }

            $cursor = $response_handler->cursor();
            $complete = $response_handler->complete();
            $entries_counted = $response_handler->entries_counted();
            $checkpoint->consecutive_timeouts = 0;
            $wall_time = microtime(true) - $request_start;
            $this->stream->finalize_request(
                "file_index",
                $wall_time,
                $context->response_stats ?? [],
            );

            fclose($handle);
            $handle = null;

            $checkpoint->index_cursor = $complete ? null : $cursor;
            $this->checkpoints->save($checkpoint);

            return $complete;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
