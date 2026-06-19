<?php

namespace Reprint\Importer\Transport;

use CURLFile;
use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\Protocol\CurlTimeoutException;
use Reprint\Importer\Protocol\MultipartStreamParser;
use Reprint\Importer\Protocol\StreamingContext;
use RuntimeException;

final class ImportHttpTransport
{
    private ImportOutput $output;

    /** @var callable */
    private $audit;

    /** @var callable */
    private $output_progress;

    /** @var callable */
    private $user_agent;

    /** @var callable */
    private $hmac_headers;

    /** @var callable */
    private $has_hmac_secret;

    /** @var callable */
    private $set_error_code;

    /** @var callable */
    private $handle_tuner_error;

    /** @var callable */
    private $streaming_heartbeat;

    /** @var int|null Last curl error number, for retry/diagnostic logic. */
    private $last_curl_errno = null;

    /** @var bool Whether the last curl request timed out. */
    private $last_curl_timeout = false;

    public function __construct(
        ImportOutput $output,
        callable $audit,
        callable $output_progress,
        callable $user_agent,
        callable $hmac_headers,
        callable $has_hmac_secret,
        callable $set_error_code,
        callable $handle_tuner_error,
        callable $streaming_heartbeat
    ) {
        $this->output = $output;
        $this->audit = $audit;
        $this->output_progress = $output_progress;
        $this->user_agent = $user_agent;
        $this->hmac_headers = $hmac_headers;
        $this->has_hmac_secret = $has_hmac_secret;
        $this->set_error_code = $set_error_code;
        $this->handle_tuner_error = $handle_tuner_error;
        $this->streaming_heartbeat = $streaming_heartbeat;
    }

    /**
     * Fetch a JSON response for a lightweight request.
     */
    public function fetch_json(string $url): array
    {
        $this->reset_curl_state();
        $this->audit("HTTP_REQUEST | GET | {$url}", false);

        $ch = curl_init($url);
        \reprint_apply_curl_proxy_from_env($ch);
        \reprint_apply_curl_ca_bundle($ch);

        $headers = [
            ...$this->base_headers("application/json"),
            ...$this->hmac_headers(''),
        ];

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => "gzip, deflate",
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION =>
                function ($ch, $dl_total, $dl_now, $ul_total, $ul_now) {
                    $this->output->tick_spinner();
                    return 0;
                },
        ]);

        $start = microtime(true);
        $body = curl_exec($ch);
        $elapsed = microtime(true) - $start;

        try {
            $this->check_curl_error($ch);
        } catch (RuntimeException $e) {
            @curl_close($ch);
            return [
                "ok" => false,
                "http_code" => 0,
                "elapsed" => $elapsed,
                "body" => null,
                "json" => null,
                "error" => $e->getMessage(),
                "curl_errno" => $this->last_curl_errno,
                "timeout" => $this->last_curl_timeout,
            ];
        }

        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
        @curl_close($ch);

        if ($http_code !== 200) {
            $diagnosis = $this->diagnose_http_error($http_code, $body, $redirect_url);
            return [
                "ok" => false,
                "http_code" => $http_code,
                "elapsed" => $elapsed,
                "body" => $body,
                "json" => null,
                "error" => $this->format_diagnosed_error($diagnosis),
                "error_code" => $diagnosis['code'],
            ];
        }

        $json = null;
        $json_error = null;
        $error_code = null;
        if ($body !== false && $body !== "") {
            $json = json_decode($body, true);
            if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
                $diagnosis = $this->diagnose_http_error(200, $body);
                if ($diagnosis['code'] === 'HTML_RESPONSE') {
                    $json_error = $this->format_diagnosed_error($diagnosis);
                    $error_code = $diagnosis['code'];
                } else {
                    $json_error = "Invalid JSON: " . json_last_error_msg();
                    $error_code = 'INVALID_JSON';
                }
            }
        }

        return [
            "ok" => $json_error === null,
            "http_code" => $http_code,
            "elapsed" => $elapsed,
            "body" => $body,
            "json" => $json,
            "error" => $json_error,
            "error_code" => $error_code,
        ];
    }

    /**
     * Fetch URL with streaming multipart parsing.
     */
    public function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data = null,
        ?string $endpoint = null
    ): void {
        $this->reset_curl_state();

        $log_parts = ["HTTP_REQUEST", $post_data ? "POST" : "GET", $url];
        if ($post_data && isset($post_data["file_list"])) {
            $file_list_part = $post_data["file_list"];
            if ($file_list_part instanceof CURLFile) {
                $upload_path = $file_list_part->getFilename();
                $upload_size = is_string($upload_path)
                    ? filesize($upload_path)
                    : false;
                $upload_size = $upload_size === false ? 0 : $upload_size;
                $log_parts[] = "file_list_file=" . $upload_size . "b";
            } else {
                $log_parts[] =
                    "file_list=" . strlen((string) $file_list_part) . "b";
            }
        }

        $this->audit(implode(" | ", $log_parts), false);

        $ch = curl_init($url);
        \reprint_apply_curl_proxy_from_env($ch);
        \reprint_apply_curl_ca_bundle($ch);

        $parser = null;
        $current_chunk = null;
        $bytes_received = 0;
        $last_heartbeat = microtime(true);
        $last_progress_check = microtime(true);
        $last_bytes_received = 0;
        $error_body = "";

        $headers = [
            ...$this->base_headers("text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8"),
            "Upgrade-Insecure-Requests: 1",
            "Sec-Fetch-Dest: document",
            "Sec-Fetch-Mode: navigate",
            "Sec-Fetch-Site: none",
            "Sec-Fetch-User: ?1",
        ];

        if ($cursor) {
            $headers[] = "X-Export-Cursor: {$cursor}";
        }

        $body_for_signing = '';
        if ($post_data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            $has_file = false;
            foreach ($post_data as $value) {
                if ($value instanceof CURLFile) {
                    $has_file = true;
                    break;
                }
            }
            if ($has_file) {
                foreach ($post_data as $value) {
                    if ($value instanceof CURLFile) {
                        $content = file_get_contents($value->getFilename());
                        if ($content !== false) {
                            $body_for_signing .= $content;
                        }
                    }
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            } else {
                $body_for_signing = http_build_query($post_data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body_for_signing);
            }
        }

        array_push($headers, ...$this->hmac_headers($body_for_signing));

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 300,
            CURLOPT_ENCODING => "gzip, deflate",
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION =>
                function ($ch, $dl_total, $dl_now, $ul_total, $ul_now) {
                    $this->output->tick_spinner();
                    return 0;
                },
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $header_line) use (
                &$parser,
                $context,
                &$current_chunk
            ) {
                $len = strlen($header_line);

                if (stripos($header_line, "Content-Type:") === 0) {
                    $pos = stripos($header_line, "boundary=");
                    if ($pos !== false) {
                        $boundary_start = $pos + 9;
                        $boundary_value = trim(substr($header_line, $boundary_start));

                        if ($boundary_value !== '' && $boundary_value[0] === '"') {
                            $quote_end = strpos($boundary_value, '"', 1);
                            if ($quote_end !== false) {
                                $boundary_value = substr(
                                    $boundary_value,
                                    1,
                                    $quote_end - 1
                                );
                            }
                        } else {
                            $end_pos = strcspn($boundary_value, ";,\r\n \t");
                            $boundary_value = substr(
                                $boundary_value,
                                0,
                                $end_pos
                            );
                        }

                        if ($boundary_value !== "") {
                            $this->audit(
                                "Creating multipart parser with boundary: $boundary_value",
                                false
                            );
                            $parser = new MultipartStreamParser(
                                $boundary_value,
                                $this->make_chunk_handler($context, $current_chunk)
                            );
                        }
                    }
                }

                return $len;
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (
                &$parser,
                &$current_chunk,
                $context,
                &$bytes_received,
                &$last_heartbeat,
                &$last_progress_check,
                &$last_bytes_received,
                &$error_body
            ) {
                if (!$parser) {
                    $error_body .= $data;
                    if (strlen($error_body) > 65536) {
                        $error_body = substr($error_body, -65536);
                    }

                    if (strncmp($error_body, "--boundary-", 11) === 0) {
                        $line_end = strpos($error_body, "\n");
                        if ($line_end !== false) {
                            $line = rtrim(substr($error_body, 0, $line_end), "\r\n");
                            if (strncmp($line, "--boundary-", 11) === 0) {
                                $boundary = substr($line, 2);
                                if ($boundary !== "") {
                                    $this->audit(
                                        "Detected boundary in body (no Content-Type): {$boundary}",
                                        false
                                    );
                                    $parser = new MultipartStreamParser(
                                        $boundary,
                                        $this->make_chunk_handler($context, $current_chunk)
                                    );
                                    $parser->feed($error_body);
                                    $error_body = "";
                                }
                            }
                        }
                    }

                    static $logged_no_parser = false;
                    if (!$logged_no_parser && strlen($error_body) > 0) {
                        $this->audit(
                            "No parser, accumulating error body (first 500 chars): " .
                                substr($error_body, 0, 500),
                            false
                        );
                        $logged_no_parser = true;
                    }
                }

                if ($parser) {
                    $parser->feed($data);
                }

                $bytes_received += strlen($data);
                $now = microtime(true);

                if ($now - $last_progress_check >= 5.0) {
                    $bytes_since_check = $bytes_received - $last_bytes_received;
                    $rate = $bytes_since_check / 5.0;

                    $this->output_progress([
                        "progress_check" => true,
                        "bytes_received" => $bytes_received,
                        "bytes_last_5s" => $bytes_since_check,
                        "rate_bps" => round($rate),
                    ], true);

                    if ($bytes_since_check < 1024 && $bytes_received > 0) {
                        $this->audit(
                            "Warning: Slow transfer detected - {$bytes_since_check} bytes in 5 seconds",
                            false
                        );
                    }

                    $last_progress_check = $now;
                    $last_bytes_received = $bytes_received;
                }

                if ($now - $last_heartbeat >= 1.0) {
                    $heartbeat = [
                        "heartbeat" => true,
                        "bytes_received" => $bytes_received,
                    ];
                    $heartbeat = array_merge(
                        $heartbeat,
                        (array) ($this->streaming_heartbeat)()
                    );
                    $this->output_progress($heartbeat, true);
                    $last_heartbeat = $now;
                }

                return strlen($data);
            },
        ]);

        $this->audit("Executing curl request...", false);
        $this->output_progress(["debug" => "Waiting for server response..."]);
        $result = curl_exec($ch);
        $this->audit(
            "curl_exec completed, result=" .
                ($result === false ? "false" : "true"),
            false
        );

        try {
            try {
                $this->check_curl_error($ch);
            } catch (RuntimeException $curl_error) {
                if ($endpoint !== null) {
                    $this->handle_tuner_error($endpoint, [
                        "http_code" => 0,
                        "timeout" => $this->last_curl_timeout,
                        "curl_errno" => $this->last_curl_errno,
                    ]);
                }
                throw $curl_error;
            }

            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
            $ttfb = (float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
            $total_time = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        } finally {
            @curl_close($ch);
        }

        if (!isset($context->response_stats) || !is_array($context->response_stats)) {
            $context->response_stats = [];
        }
        $context->response_stats["ttfb"] = $ttfb;
        $context->response_stats["total_time"] = $total_time;

        if ($http_code !== 200) {
            if ($endpoint !== null) {
                $this->handle_tuner_error($endpoint, [
                    "http_code" => $http_code,
                    "timeout" => false,
                    "curl_errno" => 0,
                ]);
            }

            $this->audit(
                "HTTP error {$http_code} | error_body length: " .
                    strlen($error_body),
                true
            );

            $diagnosis = $this->diagnose_http_error($http_code, $error_body, $redirect_url);
            $error_msg = $this->format_diagnosed_error($diagnosis);

            if ($error_body) {
                $error_data = json_decode($error_body, true);
                if (is_array($error_data) && isset($error_data["trace"])) {
                    $error_msg .= "\n\nServer stack trace:\n" . $error_data["trace"];
                }
            }

            throw new RuntimeException($error_msg);
        }

        if (!$parser) {
            $snippet = $error_body ? substr($error_body, 0, 500) : "";
            throw new RuntimeException(
                "Invalid response: missing multipart boundary. " .
                    ($snippet !== "" ? "Body: {$snippet}" : "")
            );
        }

        if (!$context->saw_completion) {
            throw new RuntimeException(
                "Invalid response: missing completion chunk from server."
            );
        }
    }

    public function make_chunk_handler(
        StreamingContext $context,
        &$current_chunk
    ): callable {
        return function ($event) use ($context, &$current_chunk) {
            if ($event["type"] === "body") {
                $headers = $event["headers"];
                $chunk_type = $headers["x-chunk-type"] ?? "";
                if ($chunk_type === "file") {
                    if (!$current_chunk) {
                        $current_chunk = [
                            "headers" => $headers,
                            "body_streamed" => true,
                            "started" => false,
                        ];
                    }

                    if ($context->on_chunk) {
                        $stream_headers = $headers;
                        if (!empty($current_chunk["started"])) {
                            $stream_headers["x-first-chunk"] = "0";
                        }
                        $stream_headers["x-last-chunk"] = "0";
                        ($context->on_chunk)([
                            "headers" => $stream_headers,
                            "body" => $event["data"],
                            "is_streaming_body" => true,
                        ]);
                    }
                    $current_chunk["started"] = true;
                    return;
                }

                if (!$current_chunk) {
                    $current_chunk = [
                        "headers" => $headers,
                        "body" => $event["data"],
                    ];
                } else {
                    $current_chunk["body"] =
                        ($current_chunk["body"] ?? "") .
                        $event["data"];
                }
            } elseif ($event["type"] === "complete") {
                $headers = $event["headers"];
                $chunk_type = $headers["x-chunk-type"] ?? "";
                if ($chunk_type === "file" && !empty($current_chunk["body_streamed"])) {
                    if ($context->on_chunk) {
                        $close_headers = $headers;
                        $close_headers["x-first-chunk"] = "0";
                        ($context->on_chunk)([
                            "headers" => $close_headers,
                            "body" => "",
                            "is_streaming_close" => true,
                        ]);
                    }
                } elseif ($current_chunk) {
                    if ($context->on_chunk) {
                        ($context->on_chunk)($current_chunk);
                    }
                } elseif ($headers) {
                    if ($context->on_chunk) {
                        ($context->on_chunk)([
                            "headers" => $headers,
                            "body" => "",
                        ]);
                    }
                }
                $current_chunk = null;
            }
        };
    }

    private function reset_curl_state(): void
    {
        $this->last_curl_errno = null;
        $this->last_curl_timeout = false;
    }

    private function check_curl_error($ch): void
    {
        if (!curl_errno($ch)) {
            return;
        }
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $timeout_errno = defined("CURLE_OPERATION_TIMEDOUT")
            ? CURLE_OPERATION_TIMEDOUT
            : 28;
        $this->last_curl_errno = $errno;
        $this->last_curl_timeout = $errno === $timeout_errno;
        if ($this->last_curl_timeout) {
            throw new CurlTimeoutException("cURL error: {$error}");
        }
        throw new RuntimeException("cURL error ($errno): {$error}");
    }

    private function base_headers(string $accept): array
    {
        return HttpRequestBuilder::base_headers(
            $accept,
            ($this->user_agent)()
        );
    }

    private function hmac_headers(string $body): array
    {
        return ($this->hmac_headers)($body);
    }

    private function diagnose_http_error(int $http_code, ?string $body, ?string $redirect_url = null): array
    {
        return HttpErrorDiagnoser::diagnose(
            $http_code,
            $body,
            $redirect_url,
            (bool) ($this->has_hmac_secret)()
        );
    }

    private function format_diagnosed_error(array $diagnosis): string
    {
        ($this->set_error_code)($diagnosis['code']);
        return $diagnosis['message'];
    }

    private function handle_tuner_error(string $endpoint, array $error): void
    {
        ($this->handle_tuner_error)($endpoint, $error);
    }

    private function audit(string $message, bool $to_console): void
    {
        ($this->audit)($message, $to_console);
    }

    private function output_progress(array $data, bool $force = false): void
    {
        ($this->output_progress)($data, $force);
    }
}
