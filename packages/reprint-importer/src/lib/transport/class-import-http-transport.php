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
    private ?ImportOutput $output;

    private ?int $last_curl_errno = null;
    private bool $last_curl_timeout = false;
    private ?int $last_http_code = null;
    private ?string $last_error_code = null;
    private ?int $last_error_body_length = null;

    public function __construct(?ImportOutput $output = null)
    {
        $this->output = $output;
    }

    /**
     * Fetch a JSON response for a lightweight request.
     */
    public function fetch_json(
        string $url,
        array $headers,
        bool $has_hmac_secret
    ): array {
        $this->reset_curl_state();

        $ch = curl_init($url);
        \reprint_apply_curl_proxy_from_env($ch);
        \reprint_apply_curl_ca_bundle($ch);

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => "gzip, deflate",
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION =>
                function ($ch, $dl_total, $dl_now, $ul_total, $ul_now) {
                    $this->tick_spinner();
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
        $this->last_http_code = $http_code;
        $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
        @curl_close($ch);

        if ($http_code !== 200) {
            $diagnosis = $this->diagnose_http_error(
                $http_code,
                $body,
                $redirect_url,
                $has_hmac_secret,
            );
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
                $diagnosis = $this->diagnose_http_error(
                    200,
                    $body,
                    null,
                    $has_hmac_secret,
                );
                if ($diagnosis['code'] === 'HTML_RESPONSE') {
                    $json_error = $this->format_diagnosed_error($diagnosis);
                    $error_code = $diagnosis['code'];
                } else {
                    $json_error = "Invalid JSON: " . json_last_error_msg();
                    $error_code = 'INVALID_JSON';
                    $this->last_error_code = $error_code;
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
        StreamingContext $context,
        array $headers,
        ?array $post_data = null,
        bool $has_hmac_secret = false
    ): void {
        $this->reset_curl_state();

        $ch = curl_init($url);
        \reprint_apply_curl_proxy_from_env($ch);
        \reprint_apply_curl_ca_bundle($ch);

        $parser = null;
        $current_chunk = null;
        $error_body = "";

        if ($post_data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            if (self::post_data_has_file($post_data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, self::body_for_signing($post_data));
            }
        }

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 300,
            CURLOPT_ENCODING => "gzip, deflate",
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION =>
                function ($ch, $dl_total, $dl_now, $ul_total, $ul_now) {
                    $this->tick_spinner();
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
                    $boundary_value = $this->extract_boundary($header_line);
                    if ($boundary_value !== "") {
                        $parser = new MultipartStreamParser(
                            $boundary_value,
                            $this->make_chunk_handler($context, $current_chunk)
                        );
                    }
                }

                return $len;
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (
                &$parser,
                &$current_chunk,
                $context,
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
                }

                if ($parser) {
                    $parser->feed($data);
                }

                return strlen($data);
            },
        ]);

        curl_exec($ch);

        try {
            $this->check_curl_error($ch);

            $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $this->last_http_code = $http_code;
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
            $this->last_error_body_length = strlen($error_body);
            $diagnosis = $this->diagnose_http_error(
                $http_code,
                $error_body,
                $redirect_url,
                $has_hmac_secret,
            );
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

    public static function body_for_signing(?array $post_data): string
    {
        if ($post_data === null) {
            return '';
        }

        if (!self::post_data_has_file($post_data)) {
            return http_build_query($post_data);
        }

        $body = '';
        foreach ($post_data as $value) {
            if (!$value instanceof CURLFile) {
                continue;
            }

            $content = file_get_contents($value->getFilename());
            if ($content !== false) {
                $body .= $content;
            }
        }

        return $body;
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

    public function last_curl_errno(): ?int
    {
        return $this->last_curl_errno;
    }

    public function last_curl_timed_out(): bool
    {
        return $this->last_curl_timeout;
    }

    public function last_http_code(): ?int
    {
        return $this->last_http_code;
    }

    public function last_error_code(): ?string
    {
        return $this->last_error_code;
    }

    public function last_error_body_length(): ?int
    {
        return $this->last_error_body_length;
    }

    private function reset_curl_state(): void
    {
        $this->last_curl_errno = null;
        $this->last_curl_timeout = false;
        $this->last_http_code = null;
        $this->last_error_code = null;
        $this->last_error_body_length = null;
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

    private function extract_boundary(string $header_line): string
    {
        $pos = stripos($header_line, "boundary=");
        if ($pos === false) {
            return "";
        }

        $boundary_value = trim(substr($header_line, $pos + 9));
        if ($boundary_value === "") {
            return "";
        }

        if ($boundary_value[0] === '"') {
            $quote_end = strpos($boundary_value, '"', 1);
            return $quote_end === false
                ? ""
                : substr($boundary_value, 1, $quote_end - 1);
        }

        $end_pos = strcspn($boundary_value, ";,\r\n \t");
        return substr($boundary_value, 0, $end_pos);
    }

    private static function post_data_has_file(array $post_data): bool
    {
        foreach ($post_data as $value) {
            if ($value instanceof CURLFile) {
                return true;
            }
        }

        return false;
    }

    private function diagnose_http_error(
        int $http_code,
        ?string $body,
        ?string $redirect_url,
        bool $has_hmac_secret
    ): array {
        return HttpErrorDiagnoser::diagnose(
            $http_code,
            $body,
            $redirect_url,
            $has_hmac_secret
        );
    }

    private function format_diagnosed_error(array $diagnosis): string
    {
        $this->last_error_code = $diagnosis['code'];
        return $diagnosis['message'];
    }

    private function tick_spinner(): void
    {
        if ($this->output instanceof ImportOutput) {
            $this->output->tick_spinner();
        }
    }
}
