<?php

namespace Reprint\Importer\Sql;

use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Protocol\CurlTimeoutException;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\QueryStream\WP_MySQL_Naive_Query_Stream;
use Reprint\Importer\Sql\Port\DbPullCheckpointStore;
use Reprint\Importer\Sql\Port\DbPullTimeoutPolicy;
use Reprint\Importer\Sql\Port\SqlDomainStore;
use Reprint\Importer\Sql\Port\SqlOutputSinkFactory;
use Reprint\Importer\Sql\Port\SqlShutdownToken;
use Reprint\Importer\Sql\Port\SqlStatementStatsStore;
use Reprint\Importer\Sql\Port\SqlStreamClient;
use Reprint\Importer\Sql\Port\SqlStreamObserver;
use Reprint\Importer\UrlRewrite\DomainCollector;
use RuntimeException;
use Throwable;

final class SqlDownloader
{
    private SqlStreamClient $stream;
    private SqlShutdownToken $shutdown;
    private DbPullCheckpointStore $checkpoints;
    private DbPullTimeoutPolicy $timeout_policy;
    private SqlOutputSinkFactory $output_sinks;
    private SqlDomainStore $domain_store;
    private SqlStatementStatsStore $stats_store;
    private SqlStreamObserver $observer;
    private AuditLogger $audit;

    public function __construct(
        SqlStreamClient $stream,
        SqlShutdownToken $shutdown,
        DbPullCheckpointStore $checkpoints,
        DbPullTimeoutPolicy $timeout_policy,
        SqlOutputSinkFactory $output_sinks,
        SqlDomainStore $domain_store,
        SqlStatementStatsStore $stats_store,
        SqlStreamObserver $observer,
        AuditLogger $audit
    ) {
        $this->stream = $stream;
        $this->shutdown = $shutdown;
        $this->checkpoints = $checkpoints;
        $this->timeout_policy = $timeout_policy;
        $this->output_sinks = $output_sinks;
        $this->domain_store = $domain_store;
        $this->stats_store = $stats_store;
        $this->observer = $observer;
        $this->audit = $audit;
    }

    /**
     * Download SQL from the remote exporter.
     *
     * @param array{
     *     mode:string,
     *     state_dir:string,
     *     remote_url:string,
     *     mysql_host?:?string,
     *     mysql_port?:?int,
     *     mysql_user?:?string,
     *     mysql_password?:?string,
     *     mysql_database?:?string,
     *     save_every:int
     * } $config
     */
    public function download(DbPullCheckpoint $checkpoint, array $config): DbPullCheckpoint
    {
        $cursor = $checkpoint->cursor;
        $complete = false;
        $mode = $config["mode"];
        $output = $this->output_sinks->create($checkpoint, $config);

        $query_stream = new WP_MySQL_Naive_Query_Stream();
        $domain_collector = new DomainCollector();
        $domain_scanner = new SqlDomainScanner($this->audit);
        $sql_statements_counted = $checkpoint->sql_statements_counted;

        $parsed_url = parse_url($config["remote_url"]);
        if ($parsed_url && isset($parsed_url['scheme'], $parsed_url['host'])) {
            $source_origin = $parsed_url['scheme'] . '://' . $parsed_url['host'];
            if (!empty($parsed_url['port'])) {
                $source_origin .= ':' . $parsed_url['port'];
            }
            $domain_collector->merge([$source_origin]);
        }
        $domain_collector->merge($this->domain_store->load());

        $this->audit->record(
            sprintf(
                "START SQL REQUEST | mode=%s | cursor=%s | bytes_written=%s",
                $mode,
                $cursor !== null ? "YES" : "NO",
                number_format($output->bytes_written()) . " bytes",
            ),
            false,
        );

        $curl_timed_out = false;
        $caught_exception = null;
        $buffer_not_flushed = "";
        $chunks_since_save = 0;

        try {
            while (!$complete) {
                if ($this->shutdown->is_shutdown_requested()) {
                    throw new RuntimeException("Shutdown requested");
                }

                $params = $this->stream->tuned_params("sql_chunk");
                $url = $this->stream->build_url("sql_chunk", $cursor, $params);

                $context = new StreamingContext();
                $context->chunk_fingerprints = [];
                $response_handler = new SqlResponseHandler(
                    $cursor,
                    $context,
                    $output,
                    $query_stream,
                    $domain_collector,
                    $domain_scanner,
                    $sql_statements_counted,
                    $chunks_since_save,
                    $config["save_every"],
                    $checkpoint,
                    $this->shutdown,
                    $this->checkpoints,
                    $this->domain_store,
                    $this->observer,
                );
                $context->on_chunk = [$response_handler, "handle"];

                $cursor_before = $cursor;
                $request_start = microtime(true);
                try {
                    $this->stream->fetch_streaming($url, $cursor, $context, null, "sql_chunk");
                } catch (CurlTimeoutException $e) {
                    [$cursor, $complete, $sql_statements_counted, $chunks_since_save] =
                        $this->response_state($response_handler);
                    $this->timeout_policy->assert_can_retry(
                        $checkpoint,
                        "sql_chunk",
                        $cursor_before,
                        $cursor,
                    );
                    $this->checkpoint_for_retry(
                        $checkpoint,
                        $output->bytes_written(),
                        $sql_statements_counted,
                        $cursor,
                    );
                    $output->flush();
                    $curl_timed_out = true;
                    break;
                } catch (RuntimeException $e) {
                    [$cursor, $complete, $sql_statements_counted, $chunks_since_save] =
                        $this->response_state($response_handler);

                    if ($this->is_retryable_incomplete_response($e)) {
                        $this->audit->record(
                            "INCOMPLETE RESPONSE | " . $e->getMessage() .
                            " | buffered_sql=" . strlen($output->pending_buffer()) . " bytes" .
                            " - will save state for retry",
                            true,
                        );
                        $this->timeout_policy->assert_can_retry(
                            $checkpoint,
                            "sql_chunk",
                            $cursor_before,
                            $cursor,
                        );
                        $this->checkpoint_for_retry(
                            $checkpoint,
                            $output->bytes_written(),
                            $sql_statements_counted,
                            $cursor,
                        );
                        $output->flush();
                        $curl_timed_out = true;
                        break;
                    }

                    throw $e;
                }

                [$cursor, $complete, $sql_statements_counted, $chunks_since_save] =
                    $this->response_state($response_handler);

                $checkpoint->consecutive_timeouts = 0;
                $this->stream->finalize_request(
                    "sql_chunk",
                    microtime(true) - $request_start,
                    $context->response_stats ?? [],
                );

                $output->flush();
                $checkpoint->cursor = $cursor;
                $checkpoint->sql_bytes = $complete ? null : $output->bytes_written();
                $checkpoint->sql_statements_counted = $sql_statements_counted;
                $this->checkpoints->save($checkpoint);
            }

            $query_stream->mark_input_complete();
            $sql_statements_counted = $domain_scanner->drain_query_stream(
                $query_stream,
                $domain_collector,
                $sql_statements_counted,
            );

            $domains = $domain_collector->get_domains();
            if (!empty($domains)) {
                $this->domain_store->persist($domains);
                $this->audit->record(
                    sprintf(
                        "DOMAINS DISCOVERED | %d unique domains saved to .import-domains.json",
                        count($domains),
                    ),
                    false,
                );
            }

            if ($sql_statements_counted > 0) {
                $this->stats_store->persist_total($sql_statements_counted);
                $this->audit->record(
                    sprintf(
                        "SQL STATS | %d statements counted during download",
                        $sql_statements_counted,
                    ),
                    false,
                );
            }
        } catch (Throwable $e) {
            $caught_exception = $e;
            throw $e;
        } finally {
            $pending = $output->pending_buffer();
            $output->close();

            if ($pending !== "") {
                if ($caught_exception !== null) {
                    $this->audit->record(
                        "BUFFER NOT FLUSHED | " . strlen($pending) .
                        " bytes in SQL buffer during exception unwind" .
                        " (original error: " . $caught_exception->getMessage() . ")",
                        true,
                    );
                } elseif ($curl_timed_out) {
                    $this->audit->record(
                        "BUFFER PRESERVED | " . strlen($pending) .
                        " bytes in SQL buffer saved for crash recovery",
                        true,
                    );
                } else {
                    $buffer_not_flushed = $pending;
                }
            }
        }

        if ($buffer_not_flushed !== "") {
            throw new RuntimeException(
                "Buffered SQL was never executed (" . strlen($buffer_not_flushed) .
                " bytes) - incomplete export?"
            );
        }

        return $checkpoint;
    }

    /**
     * @return array{0: ?string, 1: bool, 2: int, 3: int}
     */
    private function response_state(SqlResponseHandler $response_handler): array
    {
        return [
            $response_handler->cursor(),
            $response_handler->complete(),
            $response_handler->sql_statements_counted(),
            $response_handler->chunks_since_save(),
        ];
    }

    private function checkpoint_for_retry(
        DbPullCheckpoint $checkpoint,
        int $sql_bytes_written,
        int $sql_statements_counted,
        ?string $cursor
    ): void {
        $checkpoint->cursor = $cursor;
        $checkpoint->sql_bytes = $sql_bytes_written;
        $checkpoint->sql_statements_counted = $sql_statements_counted;
        $checkpoint->status = "partial";
        $this->checkpoints->save($checkpoint);
    }

    private function is_retryable_incomplete_response(RuntimeException $e): bool
    {
        $message = $e->getMessage();
        $is_retryable_curl = preg_match(
            '/cURL error \((\d+)\):/',
            $message,
            $curl_match,
        ) && in_array((int) $curl_match[1], [18, 52, 56], true);

        return strpos($message, "missing completion chunk") !== false ||
            $is_retryable_curl ||
            strpos($message, "missing multipart boundary") !== false;
    }
}
