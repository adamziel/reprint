<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Protocol\CurlTimeoutException;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Sql\DbIndexCheckpoint;
use Reprint\Importer\Sql\DbIndexResponseHandler;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\Port\DbIndexDownloader;
use Reprint\Importer\Sql\Port\DbIndexTableSinkFactory;
use Reprint\Importer\Sql\Port\DbPullCheckpointStore;
use Reprint\Importer\Sql\Port\DbPullTimeoutPolicy;
use Reprint\Importer\Sql\Port\SqlShutdownToken;
use Reprint\Importer\Sql\Port\SqlStreamClient;
use RuntimeException;

final class RemoteDbIndexDownloader implements DbIndexDownloader
{
    private SqlStreamClient $stream;
    private SqlShutdownToken $shutdown;
    private DbPullCheckpointStore $checkpoints;
    private DbPullTimeoutPolicy $timeout_policy;
    private DbIndexTableSinkFactory $sink_factory;
    private string $default_tables_file;

    public function __construct(
        SqlStreamClient $stream,
        SqlShutdownToken $shutdown,
        DbPullCheckpointStore $checkpoints,
        DbPullTimeoutPolicy $timeout_policy,
        DbIndexTableSinkFactory $sink_factory,
        string $default_tables_file
    ) {
        $this->stream = $stream;
        $this->shutdown = $shutdown;
        $this->checkpoints = $checkpoints;
        $this->timeout_policy = $timeout_policy;
        $this->sink_factory = $sink_factory;
        $this->default_tables_file = $default_tables_file;
    }

    public function download(DbPullCheckpoint $checkpoint, ?string $tables_file = null): DbPullCheckpoint
    {
        $tables_file = $tables_file ?? $this->default_tables_file;
        $cursor = $checkpoint->cursor;
        $complete = false;
        $sink = $this->sink_factory->create($tables_file, $cursor, $checkpoint->db_index);

        try {
            while (!$complete) {
                if ($this->shutdown->is_shutdown_requested()) {
                    throw new RuntimeException("Shutdown requested");
                }

                $context = new StreamingContext();
                $response_handler = new DbIndexResponseHandler(
                    $sink,
                    $cursor,
                    $context,
                );
                $context->on_chunk = [$response_handler, "handle"];

                $cursor_before = $cursor;
                $request_start = microtime(true);
                try {
                    $params = ["tables_per_batch" => 1000];
                    $this->stream->fetch_streaming(
                        $this->stream->build_url("db_index", $cursor, $params),
                        $cursor,
                        $context,
                        null,
                        "db_index",
                    );
                } catch (CurlTimeoutException $e) {
                    $cursor = $response_handler->cursor();
                    $complete = $response_handler->complete();
                    $this->timeout_policy->assert_can_retry(
                        $checkpoint,
                        "db_index",
                        $cursor_before,
                        $cursor,
                    );
                    $sink->flush();
                    $checkpoint->cursor = $cursor;
                    $checkpoint->db_index = $this->state_entry($tables_file, $sink);
                    $checkpoint->status = "partial";
                    $this->checkpoints->save($checkpoint);
                    return $checkpoint;
                }

                $cursor = $response_handler->cursor();
                $complete = $response_handler->complete();

                $checkpoint->consecutive_timeouts = 0;
                $this->stream->finalize_request(
                    "db_index",
                    microtime(true) - $request_start,
                    $context->response_stats ?? [],
                );

                $sink->flush();
                $checkpoint->cursor = $cursor;
                $checkpoint->db_index = $this->state_entry($tables_file, $sink);
                $this->checkpoints->save($checkpoint);
            }
        } finally {
            $sink->close();
        }

        return $checkpoint;
    }

    private function state_entry(string $tables_file, $sink): DbIndexCheckpoint
    {
        return new DbIndexCheckpoint(
            $tables_file,
            $sink->tables_written(),
            $sink->rows_estimated(),
            $sink->bytes_written(),
            time(),
        );
    }
}
