<?php

namespace Reprint\Importer\Sql;

use Reprint\Importer\Protocol\CurlTimeoutException;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\QueryStream\WP_MySQL_Naive_Query_Stream;
use Reprint\Importer\UrlRewrite\DomainCollector;
use RuntimeException;
use Throwable;

final class SqlDownloader
{
    /** @var callable */
    private $build_url;

    /** @var callable */
    private $fetch_streaming;

    /** @var callable */
    private $get_tuned_params;

    /** @var callable */
    private $should_stop;

    /** @var callable */
    private $save_state;

    /** @var callable */
    private $show_sql_progress;

    /** @var callable */
    private $handle_progress;

    /** @var callable */
    private $handle_error;

    /** @var callable */
    private $handle_completion_progress;

    /** @var callable */
    private $handle_stdout_write_failed;

    /** @var callable */
    private $assert_can_retry_timeout;

    /** @var callable */
    private $finalize_request;

    /** @var callable */
    private $audit;

    public function __construct(
        callable $build_url,
        callable $fetch_streaming,
        callable $get_tuned_params,
        callable $should_stop,
        callable $save_state,
        callable $show_sql_progress,
        callable $handle_progress,
        callable $handle_error,
        callable $handle_completion_progress,
        callable $handle_stdout_write_failed,
        callable $assert_can_retry_timeout,
        callable $finalize_request,
        callable $audit
    ) {
        $this->build_url = $build_url;
        $this->fetch_streaming = $fetch_streaming;
        $this->get_tuned_params = $get_tuned_params;
        $this->should_stop = $should_stop;
        $this->save_state = $save_state;
        $this->show_sql_progress = $show_sql_progress;
        $this->handle_progress = $handle_progress;
        $this->handle_error = $handle_error;
        $this->handle_completion_progress = $handle_completion_progress;
        $this->handle_stdout_write_failed = $handle_stdout_write_failed;
        $this->assert_can_retry_timeout = $assert_can_retry_timeout;
        $this->finalize_request = $finalize_request;
        $this->audit = $audit;
    }

    /**
     * Download SQL from the remote exporter.
     *
     * @param array<string, mixed> $state
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
    public function download(array &$state, array $config): void
    {
        $cursor = $state["cursor"] ?? null;
        $complete = false;
        $mode = $config["mode"];
        $state_dir = $config["state_dir"];

        $sql_handle = null;
        $mysql_conn = null;
        $buffer_handle = null;
        $sql_bytes_written = 0;
        $sql_buffer = "";

        if ($mode === "file") {
            $sql_file = $state_dir . "/db.sql";

            $tracked_bytes = $state["sql_bytes"] ?? null;
            if ($tracked_bytes !== null && file_exists($sql_file)) {
                $actual_size = filesize($sql_file);
                if ($actual_size > $tracked_bytes) {
                    ($this->audit)(
                        sprintf(
                            "CRASH RECOVERY | Truncating db.sql from %d to %d bytes",
                            $actual_size,
                            $tracked_bytes,
                        ),
                        true,
                    );
                    $handle = fopen($sql_file, "r+");
                    if ($handle) {
                        ftruncate($handle, $tracked_bytes);
                        fclose($handle);
                    }
                }
            }

            $sql_bytes_written = file_exists($sql_file) ? filesize($sql_file) : 0;

            $sql_handle = fopen($sql_file, $cursor ? "a" : "w");
            if (!$sql_handle) {
                throw new RuntimeException("Cannot open SQL file: {$sql_file}");
            }
        } elseif ($mode === "stdout") {
            $sql_bytes_written = $state["sql_bytes"] ?? 0;
        } elseif ($mode === "mysql") {
            $sql_bytes_written = $state["sql_bytes"] ?? 0;

            $host = $config["mysql_host"] ?? "127.0.0.1";
            $user = $config["mysql_user"] ?? "root";
            $pass = $config["mysql_password"] ?? "";
            $name = $config["mysql_database"];

            $port = $config["mysql_port"] ?? 3306;
            $socket = null;
            if (strpos($host, ":") !== false) {
                list($host, $port_or_socket) = explode(":", $host, 2);
                if ($port_or_socket[0] === "/") {
                    $socket = $port_or_socket;
                } elseif (($config["mysql_port"] ?? null) === null) {
                    $port = (int) $port_or_socket;
                }
            }

            $mysql_conn = new \mysqli($host, $user, $pass, $name, $port, $socket);
            if ($mysql_conn->connect_error) {
                throw new RuntimeException("MySQL connection failed: " . $mysql_conn->connect_error);
            }
            $mysql_conn->set_charset("utf8mb4");

            ($this->audit)(
                "SQL OUTPUT mysql | connected via multi_query(): {$user}@{$host}:{$port}/{$name}",
                true,
            );

            $buffer_file = $state_dir . "/.sql-buffer";
            if (file_exists($buffer_file)) {
                $sql_buffer = file_get_contents($buffer_file);
                ($this->audit)(
                    sprintf("CRASH RECOVERY | Restored %d bytes from .sql-buffer", strlen($sql_buffer)),
                    true,
                );
            }
            $buffer_handle = fopen($buffer_file, $sql_buffer !== "" ? "a" : "w");
            if (!$buffer_handle) {
                throw new RuntimeException("Cannot open SQL buffer file: {$buffer_file}");
            }
        }

        $query_stream = new WP_MySQL_Naive_Query_Stream();
        $domain_collector = new DomainCollector();
        $domain_scanner = new SqlDomainScanner($this->audit);
        $domains_file = $state_dir . "/.import-domains.json";
        $sql_stats_file = $state_dir . "/.import-sql-stats.json";
        $sql_statements_counted = (int) ($state["sql_statements_counted"] ?? 0);

        $parsed_url = parse_url($config["remote_url"]);
        if ($parsed_url && isset($parsed_url['scheme'], $parsed_url['host'])) {
            $source_origin = $parsed_url['scheme'] . '://' . $parsed_url['host'];
            if (!empty($parsed_url['port'])) {
                $source_origin .= ':' . $parsed_url['port'];
            }
            $domain_collector->merge([$source_origin]);
        }

        if (file_exists($domains_file)) {
            $prev = json_decode(file_get_contents($domains_file), true);
            if (is_array($prev)) {
                $domain_collector->merge($prev);
            }
        }

        $has_cursor = $cursor !== null;
        ($this->audit)(
            sprintf(
                "START SQL REQUEST | mode=%s | cursor=%s | bytes_written=%s",
                $mode,
                $has_cursor ? "YES" : "NO",
                number_format($sql_bytes_written) . " bytes",
            ),
            false,
        );

        $curl_timed_out = false;
        $caught_exception = null;
        $buffer_not_flushed = "";
        $chunks_since_save = 0;
        $sync_sql_response_state = function (
            SqlResponseHandler $response_handler
        ) use (
            &$cursor,
            &$complete,
            &$sql_bytes_written,
            &$sql_buffer,
            &$sql_statements_counted,
            &$chunks_since_save
        ): void {
            $cursor = $response_handler->cursor();
            $complete = $response_handler->complete();
            $sql_bytes_written = $response_handler->sql_bytes_written();
            $sql_buffer = $response_handler->sql_buffer();
            $sql_statements_counted = $response_handler->sql_statements_counted();
            $chunks_since_save = $response_handler->chunks_since_save();
        };

        try {
            while (!$complete) {
                $params = (array) ($this->get_tuned_params)("sql_chunk");
                $url = (string) ($this->build_url)("sql_chunk", $cursor, $params);

                $context = new StreamingContext();
                $context->chunk_fingerprints = [];
                $response_handler = new SqlResponseHandler(
                    $mode,
                    $cursor,
                    $context,
                    $sql_handle,
                    $mysql_conn,
                    $buffer_handle,
                    $sql_buffer,
                    $sql_bytes_written,
                    $query_stream,
                    $domain_collector,
                    $domain_scanner,
                    $sql_statements_counted,
                    $chunks_since_save,
                    $config["save_every"],
                    $this->should_stop,
                    function (
                        ?string $cursor,
                        int $sql_bytes_written,
                        int $sql_statements_counted
                    ) use (&$state): void {
                        $state["cursor"] = $cursor;
                        $state["sql_bytes"] = $sql_bytes_written;
                        $state["sql_statements_counted"] = $sql_statements_counted;
                        ($this->save_state)($state);
                    },
                    function (array $domains) use ($domains_file): void {
                        file_put_contents(
                            $domains_file,
                            json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                        );
                    },
                    $this->show_sql_progress,
                    $this->handle_progress,
                    $this->handle_error,
                    $this->handle_completion_progress,
                    $this->handle_stdout_write_failed,
                );
                $context->on_chunk = [$response_handler, "handle"];

                $cursor_before = $cursor;
                $request_start = microtime(true);
                try {
                    ($this->fetch_streaming)($url, $cursor, $context, null, "sql_chunk");
                } catch (CurlTimeoutException $e) {
                    $sync_sql_response_state($response_handler);

                    ($this->assert_can_retry_timeout)("sql_chunk", $cursor_before, $cursor);
                    if ($sql_handle) {
                        fflush($sql_handle);
                    }
                    $state["cursor"] = $cursor;
                    $state["sql_bytes"] = $sql_bytes_written;
                    $state["sql_statements_counted"] = $sql_statements_counted;
                    $state["status"] = "partial";
                    ($this->save_state)($state);
                    $sql_buffer = "";
                    $curl_timed_out = true;
                    break;
                } catch (RuntimeException $e) {
                    $sync_sql_response_state($response_handler);

                    $msg = $e->getMessage();
                    $is_retryable_curl = preg_match(
                        '/cURL error \((\d+)\):/',
                        $msg,
                        $curl_match,
                    ) && in_array((int) $curl_match[1], [18, 52, 56], true);
                    $is_retryable =
                        strpos($msg, "missing completion chunk") !== false ||
                        $is_retryable_curl ||
                        strpos($msg, "missing multipart boundary") !== false;
                    if ($is_retryable) {
                        ($this->audit)(
                            "INCOMPLETE RESPONSE | " . $msg .
                            " | buffered_sql=" . strlen($sql_buffer) . " bytes" .
                            " - will save state for retry",
                            true,
                        );
                        ($this->assert_can_retry_timeout)("sql_chunk", $cursor_before, $cursor);
                        if ($sql_handle) {
                            fflush($sql_handle);
                        }
                        $state["cursor"] = $cursor;
                        $state["sql_bytes"] = $sql_bytes_written;
                        $state["sql_statements_counted"] = $sql_statements_counted;
                        $state["status"] = "partial";
                        ($this->save_state)($state);
                        $curl_timed_out = true;
                        break;
                    }
                    throw $e;
                }
                $sync_sql_response_state($response_handler);

                $state["consecutive_timeouts"] = 0;
                $wall_time = microtime(true) - $request_start;
                ($this->finalize_request)(
                    "sql_chunk",
                    $wall_time,
                    $context->response_stats ?? [],
                );

                if ($sql_handle) {
                    fflush($sql_handle);
                }

                $state["cursor"] = $cursor;
                $state["sql_bytes"] = $complete ? null : $sql_bytes_written;
                ($this->save_state)($state);
            }

            $query_stream->mark_input_complete();
            $sql_statements_counted = $domain_scanner->drain_query_stream(
                $query_stream,
                $domain_collector,
                $sql_statements_counted,
            );

            $domains = $domain_collector->get_domains();
            if (!empty($domains)) {
                file_put_contents(
                    $domains_file,
                    json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                );
                ($this->audit)(
                    sprintf(
                        "DOMAINS DISCOVERED | %d unique domains saved to .import-domains.json",
                        count($domains),
                    ),
                    false,
                );
            }

            if ($sql_statements_counted > 0) {
                file_put_contents(
                    $sql_stats_file,
                    json_encode(["statements_total" => $sql_statements_counted]) . "\n",
                );
                ($this->audit)(
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
            if ($sql_handle) {
                fclose($sql_handle);
            }
            if ($buffer_handle) {
                fclose($buffer_handle);
                $buffer_handle = null;
            }
            if ($mysql_conn) {
                $pending = $sql_buffer;
                $mysql_conn->close();
                $mysql_conn = null;
                $buffer_file = $state_dir . "/.sql-buffer";
                if ($pending === "" && file_exists($buffer_file)) {
                    unlink($buffer_file);
                }
                if ($pending !== "") {
                    if ($caught_exception !== null) {
                        ($this->audit)(
                            "BUFFER NOT FLUSHED | " . strlen($pending) .
                            " bytes in SQL buffer during exception unwind" .
                            " (original error: " . $caught_exception->getMessage() . ")",
                            true,
                        );
                    } elseif ($curl_timed_out) {
                        ($this->audit)(
                            "BUFFER PRESERVED | " . strlen($pending) .
                            " bytes in SQL buffer saved for crash recovery",
                            true,
                        );
                    } else {
                        $buffer_not_flushed = $pending;
                    }
                }
            }
        }

        if ($buffer_not_flushed !== "") {
            throw new RuntimeException(
                "Buffered SQL was never executed (" . strlen($buffer_not_flushed) .
                " bytes) - incomplete export?"
            );
        }
    }
}
