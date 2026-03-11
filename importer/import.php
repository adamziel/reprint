#!/usr/bin/env php
<?php
/**
 * Import client for export.php.
 *
 * Downloads SQL and files from a remote export.php script, with support for:
 * - Resumable downloads using cursors
 * - Streaming multipart parsing (no buffering)
 * - Progress reporting via JSON lines to stdout
 * - Three-phase import: files, SQL, then file deltas
 */
error_reporting(E_ALL);
ini_set("display_errors", "stderr");
ini_set("display_startup_errors", 1);

require_once __DIR__ . "/../wordpress-plugin/generic/utils.php";

// Load composer autoloader for wp-php-toolkit dependencies
$autoloader = __DIR__ . '/../vendor/autoload.php'; 
if (file_exists($autoloader)) {
    require_once $autoloader;
}

// Load vendored MySQL query stream (from sqlite-database-integration PR #264)
require_once __DIR__ . '/lib/mysql-query-stream/load.php';

// Load WordPress function stubs (needed by wp-php-toolkit outside WordPress)
require_once __DIR__ . '/lib/wp-stubs.php';

// Load URL rewriting components
require_once __DIR__ . '/lib/url-rewrite/load.php';

/**
 * The wire-protocol version this importer speaks.
 *
 * Both the export plugin (server) and the importer (client) are deployed
 * independently.  These two constants let them detect incompatibility at
 * preflight time instead of producing silent corruption.
 *
 * Bump this whenever a change to the wire protocol (cursor encoding,
 * multipart structure, header names, endpoint parameters, response format)
 * would break an older export plugin.
 */
define('IMPORT_PROTOCOL_VERSION', 1);

/**
 * The oldest *export plugin* protocol version this importer can talk to.
 *
 * During preflight-assert the importer checks that the remote's
 * protocol_version is >= this value; if not, it tells the user to
 * update the export plugin.
 *
 * Raise this when you drop backward-compatibility with old export plugins.
 * Keep it equal to IMPORT_PROTOCOL_VERSION if no backward compat is needed.
 */
define('IMPORT_MIN_EXPORT_VERSION', 1);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    $fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
    if (!($error['type'] & $fatal_types)) {
        return;
    }
    $json = json_encode([
        "error" => "Fatal: {$error['message']}",
        "file" => $error['file'],
        "line" => $error['line'],
        "type" => $error['type'],
    ]);
    if ($json === false) {
        $json = '{"error":"Fatal PHP error","file":"' . addslashes($error['file']) . '"}';
    }
    fwrite(STDERR, $json . "\n");
});


/**
 * Streaming multipart parser.
 * Parses multipart/mixed responses incrementally without buffering entire response.
 */
class MultipartStreamParser
{
    private const MAX_BUFFER_SIZE = 64 * 1024 * 1024; // 64MB
    private const STATE_BOUNDARY = 0;
    private const STATE_HEADERS = 1;
    private const STATE_BODY = 2;

    private $boundary;
    private $boundary_length;
    private $buffer = "";
    private $state = self::STATE_BOUNDARY;
    private $current_headers = [];
    private $body_length = 0;
    private $body_target = null;
    private $chunk_handler;

    public function __construct(string $boundary, callable $chunk_handler)
    {
        $this->boundary = "--" . $boundary;
        $this->boundary_length = strlen($this->boundary);
        $this->chunk_handler = $chunk_handler;
    }

    /**
     * Feed data to parser. Called by curl write callback.
     */
    public function feed(string $data): void
    {
        $this->buffer .= $data;
        if (strlen($this->buffer) > self::MAX_BUFFER_SIZE) {
            throw new RuntimeException(
                "Multipart parser buffer exceeded 64MB — response may be malformed (missing boundary delimiter)."
            );
        }
        $this->parse();
    }

    /**
     * Parse buffered data.
     */
    private function parse(): void
    {
        while (true) {
            if ($this->state === self::STATE_BOUNDARY) {
                if (!$this->parse_boundary()) {
                    break;
                }
            } elseif ($this->state === self::STATE_HEADERS) {
                if (!$this->parse_headers()) {
                    break;
                }
            } elseif ($this->state === self::STATE_BODY) {
                if (!$this->parse_body()) {
                    break;
                }
            }
        }
    }

    /**
     * Parse boundary. Returns true if boundary found and consumed.
     */
    private function parse_boundary(): bool
    {
        // Look for boundary
        $pos = strpos($this->buffer, $this->boundary);
        if ($pos === false) {
            // Keep only last boundary_length bytes in case boundary is split
            if (strlen($this->buffer) > $this->boundary_length) {
                $this->buffer = substr($this->buffer, -$this->boundary_length);
            }
            return false;
        }

        // Check if this is the closing boundary (--boundary--)
        $after_boundary = $pos + $this->boundary_length;
        if ($after_boundary + 2 <= strlen($this->buffer)) {
            $next_chars = substr($this->buffer, $after_boundary, 2);
            if ($next_chars === "--") {
                // Closing boundary - done
                $this->buffer = "";
                return false;
            }
        }

        // Find end of line after boundary (\r\n or \n)
        $line_end = $this->find_line_end($after_boundary);
        if ($line_end === false) {
            return false; // Need more data
        }

        // Consume boundary line
        $this->buffer = substr($this->buffer, $line_end);
        $this->state = self::STATE_HEADERS;
        $this->current_headers = [];
        return true;
    }

    /**
     * Parse headers. Returns true if all headers parsed.
     */
    private function parse_headers(): bool
    {
        while (true) {
            // Check for blank line (end of headers)
            if (strlen($this->buffer) >= 2) {
                if ($this->buffer[0] === "\r" && $this->buffer[1] === "\n") {
                    // \r\n - blank line
                    $this->buffer = substr($this->buffer, 2);
                    $this->prepare_body();
                    return true;
                } elseif ($this->buffer[0] === "\n") {
                    // \n - blank line
                    $this->buffer = substr($this->buffer, 1);
                    $this->prepare_body();
                    return true;
                }
            }

            // Find end of line
            $line_end = $this->find_line_end(0);
            if ($line_end === false) {
                return false; // Need more data
            }

            // Extract header line
            $line = substr($this->buffer, 0, $line_end);
            $this->buffer = substr($this->buffer, $line_end);

            // Trim line endings
            $line = rtrim($line, "\r\n");

            if ($line === "") {
                // Blank line - end of headers
                $this->prepare_body();
                return true;
            }

            // Parse header (find first colon)
            $colon_pos = strpos($line, ":");
            if ($colon_pos !== false) {
                $name = substr($line, 0, $colon_pos);
                $value = substr($line, $colon_pos + 1);

                // Trim spaces
                $name = trim($name);
                $value = ltrim($value); // Only left trim value

                // Store header (lowercase key)
                $key = strtolower($name);
                $this->current_headers[$key] = $value;
            }
        }
    }

    /**
     * Prepare for body parsing.
     */
    private function prepare_body(): void
    {
        $this->state = self::STATE_BODY;
        $this->body_length = 0;

        // Determine target length if Content-Length is specified
        $this->body_target = isset($this->current_headers["content-length"])
            ? (int) $this->current_headers["content-length"]
            : null;
    }

    /**
     * Parse body. Returns true if body complete.
     */
    private function parse_body(): bool
    {
        // If we know the content length, read exactly that many bytes
        if ($this->body_target !== null) {
            $remaining = $this->body_target - $this->body_length;

            if (strlen($this->buffer) < $remaining) {
                // Need more data
                if (strlen($this->buffer) > 0) {
                    // Process what we have
                    $this->emit_body_chunk(substr($this->buffer, 0));
                    $this->body_length += strlen($this->buffer);
                    $this->buffer = "";
                }
                return false;
            }

            // We have enough data
            $body_data = substr($this->buffer, 0, $remaining);
            $this->buffer = substr($this->buffer, $remaining);

            $this->emit_body_chunk($body_data);
            $this->body_length += strlen($body_data);

            // Skip trailing \r\n after body
            $this->skip_crlf();

            // Complete - move to next boundary
            $this->state = self::STATE_BOUNDARY;
            $this->emit_chunk_complete();
            return true;
        }

        // No content-length - read until next boundary
        // Look for boundary in buffer
        $boundary_pos = strpos($this->buffer, "\r\n" . $this->boundary);
        if ($boundary_pos === false) {
            $boundary_pos = strpos($this->buffer, "\n" . $this->boundary);
        }

        if ($boundary_pos === false) {
            // No boundary yet - process all but last boundary_length+2 bytes
            $safe_length = strlen($this->buffer) - $this->boundary_length - 2;
            if ($safe_length > 0) {
                $body_data = substr($this->buffer, 0, $safe_length);
                $this->buffer = substr($this->buffer, $safe_length);
                $this->emit_body_chunk($body_data);
                $this->body_length += strlen($body_data);
            }
            return false;
        }

        // Found boundary - emit remaining body
        $body_data = substr($this->buffer, 0, $boundary_pos);
        $this->buffer = substr($this->buffer, $boundary_pos);

        $this->emit_body_chunk($body_data);
        $this->body_length += strlen($body_data);

        // Skip \r\n before boundary
        $this->skip_crlf();

        // Complete - move to next boundary
        $this->state = self::STATE_BOUNDARY;
        $this->emit_chunk_complete();
        return true;
    }

    /**
     * Skip \r\n or \n at start of buffer.
     */
    private function skip_crlf(): void
    {
        if (
            strlen($this->buffer) >= 2 &&
            $this->buffer[0] === "\r" &&
            $this->buffer[1] === "\n"
        ) {
            $this->buffer = substr($this->buffer, 2);
        } elseif (strlen($this->buffer) >= 1 && $this->buffer[0] === "\n") {
            $this->buffer = substr($this->buffer, 1);
        }
    }

    /**
     * Find line end position (\r\n or \n) starting from offset.
     * Returns position after line ending, or false if not found.
     */
    /** @return int|false */
    private function find_line_end(int $offset)
    {
        $len = strlen($this->buffer);

        for ($i = $offset; $i < $len; $i++) {
            if ($this->buffer[$i] === "\n") {
                return $i + 1;
            }
            if (
                $this->buffer[$i] === "\r" &&
                $i + 1 < $len &&
                $this->buffer[$i + 1] === "\n"
            ) {
                return $i + 2;
            }
        }

        return false;
    }

    /**
     * Emit body chunk to handler.
     */
    private function emit_body_chunk(string $data): void
    {
        if ($data === "") {
            return;
        }

        ($this->chunk_handler)([
            "type" => "body",
            "headers" => $this->current_headers,
            "data" => $data,
        ]);
    }

    /**
     * Emit chunk complete to handler.
     */
    private function emit_chunk_complete(): void
    {
        ($this->chunk_handler)([
            "type" => "complete",
            "headers" => $this->current_headers,
        ]);
    }
}

/**
 * AdaptiveTuner
 *
 * The exporter always runs until its server-side budgets expire, so the goal
 * is not to end early but to maximize useful work per request without pushing
 * the host into timeouts or buffering. We measure server-reported runtime and
 * work done, maintain a per-endpoint throughput EMA, then apply additive
 * increase and multiplicative decrease to the next request size. This lets
 * fast hosts grow steadily while slow hosts back off quickly when throughput
 * drops or errors appear.
 *
 * We only tune on partial responses to avoid tiny final batches skewing the
 * signal. Buffering detection and error backoff temporarily clamp sizes, and
 * a duty-cycle sleep with jitter spaces requests so multiple migrations do not
 * synchronize their load. Everything is decided on the client so PHP workers
 * stay free between requests.
 */
class AdaptiveTuner
{
    private array $config;
    private array $state;

    /**
     * Endpoint lookup table: maps endpoint name to its size state key,
     * throughput EMA state key, HTTP parameter name, AIMD increase config key,
     * min/max config keys, and work metric key.
     */
    private const ENDPOINTS = [
        "file_fetch" => [
            "size_key" => "file_chunk_size",
            "ema_key" => "file_throughput_ema",
            "param" => "chunk_size",
            "increase_key" => "aimd_increase_file_bytes",
            "min_key" => "file_chunk_min",
            "max_key" => "file_chunk_max",
            "start_key" => "file_chunk_start",
            "work_metric" => "bytes_processed",
        ],
        "file_index" => [
            "size_key" => "index_batch_size",
            "ema_key" => "index_throughput_ema",
            "param" => "batch_size",
            "increase_key" => "aimd_increase_index_entries",
            "min_key" => "index_batch_min",
            "max_key" => "index_batch_max",
            "start_key" => "index_batch_start",
            "work_metric" => "entries_processed",
            "work_metric_alt" => "total_entries",
        ],
        "sql_chunk" => [
            "size_key" => "sql_fragments_per_batch",
            "ema_key" => "sql_throughput_ema",
            "param" => "fragments_per_batch",
            "increase_key" => "aimd_increase_sql_fragments",
            "min_key" => "sql_fragments_min",
            "max_key" => "sql_fragments_max",
            "start_key" => "sql_fragments_start",
            "work_metric" => "sql_bytes",
        ],
    ];

    /**
     * @param array $config Tuning configuration (merged with defaults, unknown keys ignored).
     * @param array $state  Persisted tuner state (sizes, EMA values, error backoff).
     */
    public function __construct(array $config, array $state = [])
    {
        $defaults = [
            "enabled" => true,
            "use_server_time" => true,
            "max_execution_time" => 5,
            "memory_threshold" => 0.8,
            "duty" => 0.5,
            "duty_min" => 0.35,
            "duty_max" => 1.0,
            "min_sleep" => 0.2,
            "max_sleep" => 10.0,
            "throughput_ema_alpha" => 0.2,
            "aimd_drop_ratio" => 0.9,
            "aimd_decrease_factor" => 0.7,
            "error_decrease_factor" => 0.5,
            "aimd_increase_file_bytes" => 256 * 1024,
            "aimd_increase_index_entries" => 500,
            "aimd_increase_sql_fragments" => 100,
            "error_backoff_requests" => 3,
            "file_chunk_start" => 5 * 1024 * 1024,
            "file_chunk_min" => 256 * 1024,
            "file_chunk_max" => 16 * 1024 * 1024,
            "index_batch_start" => 5000,
            "index_batch_min" => 500,
            "index_batch_max" => 50000,
            "sql_fragments_start" => 1000,
            "sql_fragments_min" => 100,
            "sql_fragments_max" => 5000,
            "db_unbuffered" => false,
            "db_query_time_limit" => 0,
        ];

        $config = array_merge($defaults, array_intersect_key($config, $defaults));
        $config["enabled"] = (bool) $config["enabled"];
        $config["use_server_time"] = (bool) $config["use_server_time"];
        $config["max_execution_time"] = max(1, (int) $config["max_execution_time"]);
        $config["memory_threshold"] = $this->clamp((float) $config["memory_threshold"], 0.1, 0.95);
        $config["duty"] = $this->clamp((float) $config["duty"], 0.1, 1.0);
        $config["duty_min"] = $this->clamp((float) $config["duty_min"], 0.1, 1.0);
        $config["duty_max"] = $this->clamp((float) $config["duty_max"], 0.1, 1.0);
        $config["min_sleep"] = max(0.0, (float) $config["min_sleep"]);
        $config["max_sleep"] = max($config["min_sleep"], (float) $config["max_sleep"]);
        $config["throughput_ema_alpha"] = $this->clamp((float) $config["throughput_ema_alpha"], 0.05, 0.5);
        $config["aimd_drop_ratio"] = $this->clamp((float) $config["aimd_drop_ratio"], 0.5, 0.99);
        $config["aimd_decrease_factor"] = $this->clamp((float) $config["aimd_decrease_factor"], 0.1, 0.95);
        $config["error_decrease_factor"] = $this->clamp((float) $config["error_decrease_factor"], 0.1, 0.95);
        $config["error_backoff_requests"] = max(1, min(20, (int) $config["error_backoff_requests"]));
        $config["db_unbuffered"] = (bool) $config["db_unbuffered"];
        $config["db_query_time_limit"] = max(0, (int) $config["db_query_time_limit"]);

        foreach (self::ENDPOINTS as $endpoint) {
            $config[$endpoint["increase_key"]] = max(1, min((int) $config[$endpoint["max_key"]], (int) $config[$endpoint["increase_key"]]));
        }

        $this->config = $config;

        // Initialize state with defaults from config start values.
        $state_defaults = [
            "duty" => $config["duty"],
            "error_backoff_remaining" => 0,
        ];
        foreach (self::ENDPOINTS as $endpoint) {
            $state_defaults[$endpoint["size_key"]] = $config[$endpoint["start_key"]];
            $state_defaults[$endpoint["ema_key"]] = null;
        }
        $this->state = array_merge($state_defaults, $state);

        // Clamp restored state values.
        foreach (self::ENDPOINTS as $endpoint) {
            $this->state[$endpoint["size_key"]] = max(
                (int) $config[$endpoint["min_key"]],
                min((int) $config[$endpoint["max_key"]], (int) $this->state[$endpoint["size_key"]]),
            );
            // EMA is Exponential Moving Average.
            // It gives more weight to recent measurements without discarding
            // older history, using: ema = (1 - alpha) * prev + alpha * current.
            // We only restore the EMA if it's valid and greater than 0.
            $ema = $this->state[$endpoint["ema_key"]] ?? null;
            $this->state[$endpoint["ema_key"]] = ($ema !== null && (float) $ema > 0) ? (float) $ema : null;
        }
        $this->state["duty"] = $this->clamp((float) $this->state["duty"], $config["duty_min"], $config["duty_max"]);
        $this->state["error_backoff_remaining"] = max(0, (int) ($this->state["error_backoff_remaining"] ?? 0));
    }

    public function get_config(): array
    {
        return $this->config;
    }

    public function get_state(): array
    {
        return $this->state;
    }

    /**
     * Build request parameters for a specific endpoint.
     *
     * @param string $endpoint Endpoint name: file_fetch, file_index, sql_chunk.
     * @return array Query parameters to send to export.php.
     */
    public function get_request_params(string $endpoint): array
    {
        $params = [
            "max_execution_time" => $this->config["max_execution_time"],
            "memory_threshold" => $this->config["memory_threshold"],
        ];

        $ep = self::ENDPOINTS[$endpoint] ?? null;
        if ($ep === null) {
            return $params;
        }

        $size = max(
            (int) $this->config[$ep["min_key"]],
            min((int) $this->config[$ep["max_key"]], (int) $this->state[$ep["size_key"]]),
        );
        $this->state[$ep["size_key"]] = $size;
        $params[$ep["param"]] = $size;

        if ($endpoint === "sql_chunk") {
            if ($this->config["db_unbuffered"]) {
                $params["db_unbuffered"] = 1;
            }
            if ($this->config["db_query_time_limit"] > 0) {
                $params["db_query_time_limit"] = (int) $this->config["db_query_time_limit"];
            }
        }

        return $params;
    }

    /**
     * Record the outcome of a request and update tuning state using AIMD.
     *
     * @param string $endpoint Endpoint name: file_fetch, file_index, sql_chunk.
     * @param array  $metrics  Request metrics (wall_time, server_time, status, work metrics).
     * @return array Decision summary for logging and sleep.
     */
    public function record_result(string $endpoint, array $metrics): array
    {
        if (!$this->config["enabled"]) {
            return [
                "decision" => "disabled",
                "sleep_seconds" => 0.0,
                "duty" => $this->state["duty"],
            ];
        }

        $wall_time = (float) ($metrics["wall_time"] ?? 0);
        $server_time = (float) ($metrics["server_time"] ?? 0);
        if ($this->config["use_server_time"]) {
            if ($server_time <= 0) {
                return [
                    "decision" => "no_server_time",
                    "sleep_seconds" => 0.0,
                    "duty" => $this->state["duty"],
                    "elapsed" => 0.0,
                    "wall_time" => $wall_time,
                    "server_time" => $server_time,
                ];
            }
            $elapsed = $server_time;
        } else {
            $elapsed = $wall_time > 0 ? $wall_time : 0.001;
        }

        $ep = self::ENDPOINTS[$endpoint] ?? null;
        $status = $metrics["status"] ?? null;
        $work_done = $this->work_done($ep, $metrics);

        $decision = "steady";
        $size_key = $ep["size_key"] ?? null;
        $throughput = null;
        $throughput_ema = null;
        $throughput_ratio = null;

        $should_tune = $work_done !== null && $work_done > 0;

        // Section: throughput estimation and AIMD adjustment.
        if ($should_tune && $ep !== null) {
            $throughput = $work_done / max(0.0001, $elapsed);
            $prev_ema = $this->state[$ep["ema_key"]] ?? null;
            if ($prev_ema !== null && $prev_ema > 0) {
                $throughput_ratio = $throughput / $prev_ema;
            }

            // EMA (Exponential Moving Average) smooths noisy throughput.
            // It gives more weight to recent measurements without discarding
            // older history, using: ema = (1 - alpha) * prev + alpha * current.
            $alpha = (float) $this->config["throughput_ema_alpha"];
            if ($prev_ema === null || $prev_ema <= 0) {
                $throughput_ema = $throughput;
            } else {
                $throughput_ema = $prev_ema * (1.0 - $alpha) + $throughput * $alpha;
            }
            $this->state[$ep["ema_key"]] = $throughput_ema;

            if ($this->state["error_backoff_remaining"] > 0) {
                // Hold sizes steady while error backoff is active.
                $decision = "error_backoff";
            } elseif ($prev_ema === null || $prev_ema <= 0) {
                // First measurement seeds the EMA; no size change yet.
                $decision = "warmup";
            } else {
                $size = (int) $this->state[$size_key];
                if (
                    $throughput_ratio !== null &&
                    $throughput_ratio < (float) $this->config["aimd_drop_ratio"]
                ) {
                    // Multiplicative decrease on throughput drop.
                    $size = (int) round($size * (float) $this->config["aimd_decrease_factor"]);
                    $decision = "decrease";
                } else {
                    // Additive increase on steady or improving throughput.
                    $size += (int) $this->config[$ep["increase_key"]];
                    $decision = "increase";
                }
                $size = max(
                    (int) $this->config[$ep["min_key"]],
                    min((int) $this->config[$ep["max_key"]], $size),
                );
                $this->state[$size_key] = $size;
            }
        } elseif ($work_done === null || $work_done <= 0) {
            $decision = "no_work";
        }

        // Section: decay error backoff counter after each request.
        if ($this->state["error_backoff_remaining"] > 0) {
            $this->state["error_backoff_remaining"]--;
        }

        // Section: compute client-side sleep from duty cycle.
        $duty = $this->clamp((float) $this->state["duty"], $this->config["duty_min"], $this->config["duty_max"]);
        $this->state["duty"] = $duty;

        $sleep = 0.0;
        if ($duty < 1.0 && $elapsed > 0) {
            $sleep = $elapsed * (1.0 / max(0.01, $duty) - 1.0);
            $sleep = $this->clamp($sleep, $this->config["min_sleep"], $this->config["max_sleep"]);
        }
        if ($status === "complete") {
            $sleep = 0.0;
        }

        return [
            "decision" => $decision,
            "sleep_seconds" => $sleep,
            "duty" => $duty,
            "elapsed" => $elapsed,
            "status" => $status,
            "wall_time" => $wall_time,
            "server_time" => $server_time,
            "work_done" => $work_done,
            "throughput" => $throughput,
            "throughput_ema" => $throughput_ema,
            "throughput_ratio" => $throughput_ratio,
            "size_key" => $size_key,
            "size_value" => $size_key ? $this->state[$size_key] : null,
            "error_backoff_remaining" => $this->state["error_backoff_remaining"],
        ];
    }

    /**
     * Record a request-level error and trigger temporary backoff.
     *
     * @param string $endpoint Endpoint name: file_fetch, file_index, sql_chunk.
     * @param array  $error    Error details (http_code, timeout, curl_errno).
     * @return array Decision summary for logging.
     */
    public function record_error(string $endpoint, array $error): array
    {
        $http_code = (int) ($error["http_code"] ?? 0);
        $timeout = (bool) ($error["timeout"] ?? false);
        $curl_errno = (int) ($error["curl_errno"] ?? 0);

        // Only engage backoff on real errors or timeouts.
        $should_backoff =
            $timeout ||
            ($http_code >= 400 && $http_code < 600) ||
            $http_code >= 600;
        if (!$should_backoff) {
            return [
                "decision" => "ignore",
                "http_code" => $http_code,
                "timeout" => $timeout,
                "curl_errno" => $curl_errno,
                "error_backoff_remaining" => $this->state["error_backoff_remaining"],
            ];
        }

        $this->state["error_backoff_remaining"] = max(
            $this->state["error_backoff_remaining"],
            (int) $this->config["error_backoff_requests"],
        );

        // Immediately shrink the endpoint's size to ease pressure.
        $ep = self::ENDPOINTS[$endpoint] ?? null;
        $size_key = $ep["size_key"] ?? null;
        if ($ep !== null) {
            $size = (int) $this->state[$size_key];
            $size = (int) round($size * (float) $this->config["error_decrease_factor"]);
            $size = max(
                (int) $this->config[$ep["min_key"]],
                min((int) $this->config[$ep["max_key"]], $size),
            );
            $this->state[$size_key] = $size;
        }

        return [
            "decision" => "backoff",
            "http_code" => $http_code,
            "timeout" => $timeout,
            "curl_errno" => $curl_errno,
            "error_backoff_remaining" => $this->state["error_backoff_remaining"],
            "size_key" => $size_key,
            "size_value" => $size_key ? $this->state[$size_key] : null,
        ];
    }

    private function work_done(?array $ep, array $metrics): ?int
    {
        if ($ep === null) {
            return null;
        }
        if (isset($metrics[$ep["work_metric"]])) {
            return (int) $metrics[$ep["work_metric"]];
        }
        if (isset($ep["work_metric_alt"]) && isset($metrics[$ep["work_metric_alt"]])) {
            return (int) $metrics[$ep["work_metric_alt"]];
        }
        return null;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }
}

class ImportClient
{

    private const SAVE_STATE_EVERY_N_CHUNKS = 50;
    private const STATE_PATH_ENCODING_PREFIX = "base64:";

    /** @var string Export server URL. */
    private $remote_url;

    /** @var string Directory for import state files (.import-state.json, db.sql, etc.). */
    private $state_dir;

    /** @var string Directory where downloaded site files are written (no filesystem-root/ wrapper). */
    private $docroot;

    /** @var string Path to .import-state.json — persists command, cursor, stage across invocations. */
    private $state_file;

    /**
     * @var float Monotonic timestamp of last progress JSON line emitted.
     * Used with $progress_throttle to rate-limit stdout progress output.
     */
    private $last_progress_output = 0;

    /** @var float Minimum seconds between progress output lines. */
    private $progress_throttle = 1.0;

    /**
     * @var string Path to .import-index.jsonl — sorted JSON-lines file tracking every
     * imported file's path, ctime, size, and type. Used for delta detection: on the next
     * sync we compare this against the remote index to decide what to download or delete.
     */
    private $index_file;

    /**
     * @var string|null Path to .import-index-updates.jsonl — temporary append-only file that
     * collects index mutations (upserts and deletes) during the current run. Merged into
     * $index_file at the end of a successful sync.
     */
    private $index_updates_file;

    /** @var resource|null Open file handle for $index_updates_file while writing. */
    private $index_updates_handle;

    /** @var int Number of entries written to $index_updates_file this run. */
    private $index_updates_count = 0;

    /**
     * Deduplication state for index updates. Consecutive upsert_index_entry() or
     * delete_index_entry() calls for the same path are collapsed into one write.
     *
     * @var string|null Last path written to the index updates file.
     */
    private $last_update_path = null;

    /** @var bool|null Whether the last index update was a deletion (true) or upsert (false). */
    private $last_update_delete = null;

    /** @var int|null ctime of the last upserted index entry. */
    private $last_update_ctime = null;

    /** @var int|null Size in bytes of the last upserted index entry. */
    private $last_update_size = null;

    /** @var string|null Type ("file", "link", "dir") of the last upserted index entry. */
    private $last_update_type = null;

    /** @var string Path to .import-remote-index.jsonl — latest file index received from the server. */
    private $remote_index_file;

    /** @var string Path to .import-download-list.jsonl — files to download, computed by diffing remote vs local index. */
    private $download_list_file;

    /** @var string Path to .import-audit.log — append-only log of every operation for debugging. */
    private $audit_log;

    /** @var string Path to .import-volatile-files.json — files the server marks as frequently-changing. */
    private $volatile_files_file;

    /** @var bool When true, emit detailed operation logs to stdout. Set via --verbose. */
    private $verbose_mode = false;

    /** @var bool Whether stdout is a TTY (enables interactive progress display). */
    private $is_tty;

    /** @var int Running count of files imported in the current invocation. */
    private $files_imported = 0;

    /**
     * @var array Persistent import state loaded from / saved to $state_file.
     * Keys: command, status, cursor, stage, preflight, version, follow_symlinks,
     * max_allowed_packet, db_index, file_index.
     * @var array|null
     */
    private $state;

    /** @var int Chunks processed since last state save — triggers periodic persistence. */
    private $chunks_since_save = 0;

    /** @var bool Set to true by SIGTERM/SIGINT handler to finish the current chunk and exit cleanly. */
    private $shutdown_requested = false;

    /**
     * @var bool When true, tell the server to follow symlinks that point outside
     * the document root (expanding them into real files). Enabled by default,
     * disable with --no-follow-symlinks. Persisted in state so it survives
     * across invocations.
     */
    private $follow_symlinks = true;

    /**
     * @var string Controls behavior when the docroot is non-empty at import start.
     *
     * 'error' (default): throw an error if the docroot is non-empty.
     * 'preserve-local': preserve existing files, symlinks, and directories in the
     * docroot instead of overwriting them; non-writable directories are skipped
     * gracefully and logged to the audit log.
     *
     * On the first sync, existing docroot content is left untouched — any file,
     * symlink, or directory that already exists at a path the remote tries to write
     * is skipped and never added to the local index.
     *
     * On subsequent delta syncs, preserved paths survive because the importer only
     * acts on paths listed in the remote index. Local-only hosting infrastructure
     * (e.g. __wp__ symlinks, drop-in symlinks, shared plugin directories) is simply
     * invisible to the diff and never touched.
     *
     * Set via --on-docroot-nonempty, persisted in state so it survives across invocations.
     */
    private $docroot_nonempty_behavior = 'error';

    /** @var AdaptiveTuner|null Adjusts request pacing based on server response times and errors. */
    private $tuner = null;

    /** @var Site_Export_HMAC_Client|null Signs requests when HMAC auth is configured. */
    private $hmac_client = null;

    /**
     * @var int|null MySQL max_allowed_packet value for the import database connection.
     * Passed to the server so it can split SQL statements to fit within this limit.
     */
    private $max_allowed_packet = null;

    /** @var int|null Last curl error number, for retry/diagnostic logic. */
    private $last_curl_errno = null;

    /** @var bool Whether the last curl request timed out. */
    private $last_curl_timeout = false;

    /** @var int|null Current step in a multi-step pipeline (1-indexed). Set via --step. */
    private $pipeline_step = null;

    /** @var int|null Total number of pipeline steps. Set via --steps. */
    private $pipeline_steps = null;

    /** @var string Path to .import-status.json — machine-readable status for external progress readers. */
    private $status_file;

    /** @var string SQL output mode: 'file' (default), 'stdout', or 'mysql'. */
    private $sql_output_mode = 'file';

    /** @var string|null MySQL host for --sql-output=mysql. */
    private $mysql_host;

    /** @var int|null MySQL port for --sql-output=mysql. */
    private $mysql_port;

    /** @var string|null MySQL user for --sql-output=mysql. */
    private $mysql_user;

    /** @var string|null MySQL password for --sql-output=mysql. */
    private $mysql_password;

    /** @var string|null MySQL database for --sql-output=mysql. */
    private $mysql_database;

    /** @var string|null Path to WordPress wp-load.php for $wpdb-based import. */
    private $wp_load_path;

    /** @var resource File descriptor for progress output — STDOUT normally, STDERR in stdout mode. */
    private $progress_fd;

    /**
     * @var int Process exit code. 0 = import complete, 2 = partial progress
     * (caller should invoke again to continue).
     */
    public $exit_code = 0;

    public function __construct(string $remote_url, string $state_dir, string $docroot)
    {
        $this->remote_url = rtrim($remote_url, "?&");
        $this->state_dir = rtrim($state_dir, "/");
        $this->docroot = rtrim($docroot, "/");
        $this->state_file = $this->state_dir . "/.import-state.json";
        $this->index_file = $this->state_dir . "/.import-index.jsonl";
        $this->index_updates_file =
            $this->state_dir . "/.import-index-updates.jsonl";
        $this->remote_index_file =
            $this->state_dir . "/.import-remote-index.jsonl";
        $this->download_list_file =
            $this->state_dir . "/.import-download-list.jsonl";
        $this->audit_log = $this->state_dir . "/.import-audit.log";
        $this->volatile_files_file = $this->state_dir . "/.import-volatile-files.json";
        $this->status_file = $this->state_dir . "/.import-status.json";

        // Detect TTY for progress display. In stdout mode this is re-evaluated
        // against STDERR in run() once we know the output mode.
        $this->is_tty = function_exists("posix_isatty") && posix_isatty(STDOUT);
        $this->progress_fd = STDOUT;

        // Register signal handlers for graceful shutdown
        if (function_exists("pcntl_signal")) {
            // Enable async signals (PHP 7.1+) so signals work during blocking operations
            if (function_exists("pcntl_async_signals")) {
                pcntl_async_signals(true);
            }
            pcntl_signal(SIGINT, [$this, "handle_shutdown"]);
            pcntl_signal(SIGTERM, [$this, "handle_shutdown"]);
        }

        // Create directories
        if (!is_dir($this->state_dir)) {
            if (!mkdir($this->state_dir, 0755, true)) {
                throw new RuntimeException("Failed to create directory: {$this->state_dir}");
            }
        }
        if (!is_dir($this->docroot)) {
            if (!mkdir($this->docroot, 0755, true)) {
                throw new RuntimeException("Failed to create directory: {$this->docroot}");
            }
        }
    }

    /**
     * Return current index size.
     */
    private function index_count(): int
    {
        if (!is_file($this->index_file)) {
            return 0;
        }
        $handle = fopen($this->index_file, "r");
        if (!$handle) {
            return 0;
        }
        $count = 0;
        while (fgets($handle) !== false) {
            $count++;
        }
        fclose($handle);
        return $count;
    }

    /**
     * Upsert a file entry in the index.
     */
    private function upsert_index_entry(
        string $path,
        int $ctime,
        int $size,
        string $type
    ): void {
        $this->record_index_update_file($path, $ctime, $size, $type);
    }

    /**
     * Delete a file entry from the index.
     */
    private function delete_index_entry(string $path): void
    {
        $this->record_index_update_deletion($path);
    }

    /**
     * Recover and merge any pending index updates from a previous run.
     */
    private function recover_index_updates(): void
    {
        if (
            $this->index_updates_file &&
            file_exists($this->index_updates_file)
        ) {
            $this->finalize_index_updates();
        }
    }

    /**
     * Log to audit file (always) and optionally to console.
     *
     * @param string $message Message to log
     * @param bool $to_console Whether to also output to console (respects verbose mode)
     */
    private function audit_log(string $message, bool $to_console = true): void
    {
        $timestamp = date("Y-m-d H:i:s");
        $log_line = "[{$timestamp}] {$message}\n";

        // Always write to audit log
        file_put_contents($this->audit_log, $log_line, FILE_APPEND);

        // Output to console if verbose mode or if explicitly requested
        if ($to_console && $this->verbose_mode) {
            fwrite($this->progress_fd, $log_line);
        }
    }

    /**
     * Load the volatile files tracker from disk.
     *
     * @return array<string, int> Map of path => change count
     */
    private function load_volatile_files(): array
    {
        if (!file_exists($this->volatile_files_file)) {
            return [];
        }
        $json = file_get_contents($this->volatile_files_file);
        if ($json === false) {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save the volatile files tracker to disk.
     * Deletes the file if the array is empty.
     */
    private function save_volatile_files(array $files): void
    {
        if (empty($files)) {
            if (file_exists($this->volatile_files_file)) {
                @unlink($this->volatile_files_file);
            }
            return;
        }
        $json = json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return; // Don't corrupt the file
        }
        file_put_contents($this->volatile_files_file, $json . "\n");
    }

    /**
     * Record that a file changed during streaming.
     * Increments the change counter for the given path.
     */
    private function record_volatile_file(string $path): void
    {
        $files = $this->load_volatile_files();
        $count = ($files[$path] ?? 0) + 1;
        $files[$path] = $count;
        $this->save_volatile_files($files);
        $this->audit_log("VOLATILE | path={$path} | count={$count}");
    }

    /**
     * Clear a file from the volatile tracker after a successful download.
     */
    private function clear_volatile_file(string $path): void
    {
        $files = $this->load_volatile_files();
        if (!isset($files[$path])) {
            return;
        }
        unset($files[$path]);
        $this->save_volatile_files($files);
        $this->audit_log("VOLATILE CLEARED | path={$path}");
    }

    /**
     * Report volatile files to the user at sync completion.
     */
    private function report_volatile_files(): void
    {
        $files = $this->load_volatile_files();
        if (empty($files)) {
            return;
        }

        $count = count($files);
        $this->audit_log(
            sprintf("VOLATILE SUMMARY | %d file(s) changed during sync", $count),
            true,
        );

        if ($this->is_tty && !$this->verbose_mode) {
            fwrite($this->progress_fd, "{$count} file(s) changed during sync and need re-syncing (run files-sync again):\n");
        }

        foreach ($files as $path => $changes) {
            $suffix = $changes >= 3
                ? " (changed {$changes} times — may be too volatile to sync)"
                : " (changed {$changes} time" . ($changes > 1 ? "s" : "") . ")";
            $this->audit_log("  VOLATILE FILE | path={$path} | count={$changes}");
            if ($this->is_tty && !$this->verbose_mode) {
                fwrite($this->progress_fd, "  {$path}{$suffix}\n");
            }
        }

        $this->output_progress(
            [
                "type" => "volatile_files",
                "files" => $files,
                "count" => $count,
            ],
            true,
        );
    }

    /**
     * Show progress in a single refreshing line (TTY mode only).
     * Truncates long messages to fit terminal width.
     *
     * @param string $message Progress message
     */
    private function show_progress_line(string $message): void
    {
        if ($this->is_tty && !$this->verbose_mode) {
            $width = $this->get_terminal_width();

            // Truncate message if too long, leaving room for "..."
            if (strlen($message) > $width - 3) {
                $message = substr($message, 0, $width - 3) . "...";
            }

            // Clear line and write progress
            fwrite($this->progress_fd, "\r\033[K" . $message);
        }
    }

    private ?int $terminal_width_cache = null;

    private function get_terminal_width(): int
    {
        if ($this->terminal_width_cache !== null) {
            return $this->terminal_width_cache;
        }
        $width = 80;
        if (function_exists("exec")) {
            $tput_cols = @exec("tput cols 2>/dev/null");
            if ($tput_cols && is_numeric($tput_cols)) {
                $width = (int) $tput_cols;
            }
        }
        $this->terminal_width_cache = $width;
        return $width;
    }

    /**
     * Clear progress line and move to next line (TTY mode only).
     */
    private function clear_progress_line(): void
    {
        if ($this->is_tty && !$this->verbose_mode) {
            fwrite($this->progress_fd, "\r\033[K");
        }
    }

    /**
     * Run the import process with explicit command validation.
     *
     * @param array $options Options:
     *   - command: Required. One of: files-sync, files-index, db-sync, db-index, preflight, preflight-assert
     *   - abort: Optional. Clear state for the command and exit immediately
     *   - verbose: Optional. Enable verbose output
     */
    public function run(array $options = []): void
    {
        $this->verbose_mode = $options["verbose"] ?? false;
        $this->follow_symlinks = $options["follow_symlinks"] ?? true;
        if (isset($options["docroot_nonempty_behavior"])) {
            $this->docroot_nonempty_behavior = $options["docroot_nonempty_behavior"];
            if (!in_array($this->docroot_nonempty_behavior, ['error', 'preserve-local'])) {
                throw new InvalidArgumentException(
                    "Invalid --on-docroot-nonempty value: {$this->docroot_nonempty_behavior}. " .
                        "Valid values: error, preserve-local",
                );
            }
        }
        $command = $options["command"] ?? null;
        $abort = $options["abort"] ?? false;
        $this->pipeline_step = $options["pipeline_step"] ?? null;
        $this->pipeline_steps = $options["pipeline_steps"] ?? null;

        if (!$command) {
            throw new InvalidArgumentException(
                "Command is required. Valid commands: files-sync, files-index, files-stats, db-sync, db-index, db-domains, db-apply, preflight, preflight-assert",
            );
        }

        if (
            !in_array($command, [
                "files-sync",
                "files-index",
                "db-sync",
                "db-index",
                "db-domains",
                "db-apply",
                "files-stats",
                "preflight",
                "preflight-assert",
            ])
        ) {
            throw new InvalidArgumentException(
                "Invalid command: {$command}. Valid commands: files-sync, files-index, files-stats, db-sync, db-index, db-domains, db-apply, preflight, preflight-assert",
            );
        }

        $this->state = $this->load_state();

        // Persist follow_symlinks in state so it survives across invocations.
        // If explicitly set on CLI, store it.  Otherwise, restore from persisted state.
        if (isset($options["follow_symlinks"])) {
            $this->state["follow_symlinks"] = $this->follow_symlinks;
            $this->save_state($this->state);
        } elseif (isset($this->state["follow_symlinks"])) {
            $this->follow_symlinks = $this->state["follow_symlinks"];
        }

        // Persist docroot_nonempty_behavior in state so it survives across invocations.
        // 'preserve-local' preserves existing local files instead of overwriting
        // them, and gracefully skips non-writable directories.
        if (isset($options["docroot_nonempty_behavior"])) {
            $this->state["docroot_nonempty_behavior"] = $this->docroot_nonempty_behavior;
            $this->save_state($this->state);
        } else {
            $this->docroot_nonempty_behavior = $this->state["docroot_nonempty_behavior"] ?? 'error';
        }

        // Persist max_allowed_packet in state so it survives across invocations.
        // The client sends this to the server so SQL statements are capped to a
        // size the client's MySQL instance can actually import.
        if (isset($options["max_allowed_packet"])) {
            $this->max_allowed_packet = (int) $options["max_allowed_packet"];
            $this->state["max_allowed_packet"] = $this->max_allowed_packet;
            $this->save_state($this->state);
        } elseif (isset($this->state["max_allowed_packet"])) {
            $this->max_allowed_packet = (int) $this->state["max_allowed_packet"];
        }

        // Persist sql_output_mode in state so it survives across resume invocations.
        // The password is NOT persisted — it must be supplied on every run (or via
        // the MYSQL_PASSWORD environment variable).
        if (isset($options["sql_output"])) {
            $mode = $options["sql_output"];
            if (!in_array($mode, ["file", "stdout", "mysql", "wpdb"])) {
                throw new InvalidArgumentException(
                    "Invalid --sql-output mode: {$mode}. Valid modes: file, stdout, mysql, wpdb",
                );
            }
            $this->sql_output_mode = $mode;
            $this->state["sql_output"] = $mode;
        } elseif (isset($this->state["sql_output"])) {
            $this->sql_output_mode = $this->state["sql_output"];
        }

        // In stdout mode, SQL goes to STDOUT, so progress/status output must
        // go to STDERR to keep the streams separate.
        if ($this->sql_output_mode === "stdout") {
            $this->progress_fd = STDERR;
            $this->is_tty = function_exists("posix_isatty") && posix_isatty(STDERR);
        }

        // MySQL connection parameters for --sql-output=mysql.
        if (isset($options["mysql_host"])) {
            $this->mysql_host = $options["mysql_host"];
            $this->state["mysql_host"] = $this->mysql_host;
        } elseif (isset($this->state["mysql_host"])) {
            $this->mysql_host = $this->state["mysql_host"];
        }

        if (isset($options["mysql_port"])) {
            $this->mysql_port = (int) $options["mysql_port"];
            $this->state["mysql_port"] = $this->mysql_port;
        } elseif (isset($this->state["mysql_port"])) {
            $this->mysql_port = (int) $this->state["mysql_port"];
        }

        if (isset($options["mysql_user"])) {
            $this->mysql_user = $options["mysql_user"];
            $this->state["mysql_user"] = $this->mysql_user;
        } elseif (isset($this->state["mysql_user"])) {
            $this->mysql_user = $this->state["mysql_user"];
        }

        if (isset($options["mysql_database"])) {
            $this->mysql_database = $options["mysql_database"];
            $this->state["mysql_database"] = $this->mysql_database;
        } elseif (isset($this->state["mysql_database"])) {
            $this->mysql_database = $this->state["mysql_database"];
        }

        // WordPress wp-load.php path for $wpdb-based import (used by
        // --sql-output=wpdb and db-apply --wp-load).
        if (isset($options["wp_load"])) {
            $path = $options["wp_load"];
            if (!file_exists($path)) {
                throw new InvalidArgumentException(
                    "wp-load.php not found at: {$path}",
                );
            }
            $this->wp_load_path = realpath($path);
            $this->state["wp_load"] = $this->wp_load_path;
        } elseif (isset($this->state["wp_load"])) {
            $this->wp_load_path = $this->state["wp_load"];
        }

        $this->save_state($this->state);

        // Password is never persisted — must be supplied each run or via env.
        if (isset($options["mysql_password"])) {
            $this->mysql_password = $options["mysql_password"];
        } elseif (getenv("MYSQL_PASSWORD") !== false) {
            $this->mysql_password = getenv("MYSQL_PASSWORD");
        }

        // Validate mysql mode requirements.
        if ($this->sql_output_mode === "mysql" && empty($this->mysql_database)) {
            throw new InvalidArgumentException(
                "--mysql-database is required when using --sql-output=mysql",
            );
        }

        // Validate wpdb mode requirements.
        if ($this->sql_output_mode === "wpdb" && empty($this->wp_load_path)) {
            throw new InvalidArgumentException(
                "--wp-load is required when using --sql-output=wpdb",
            );
        }

        $this->initialize_tuner($options);

        // Initialize HMAC authentication if a shared secret was provided.
        // When set, every outgoing HTTP request will include X-Auth-Signature,
        // X-Auth-Nonce, and X-Auth-Timestamp headers so the export API can verify
        // the caller without a SECRET_KEY in the URL.
        if (!empty($options["secret"])) {
            // TODO: Distribute with the importer script somehow. Phar? Co-locate? A build script?
            //       we'll see!
            require_once __DIR__ . "/../wordpress-plugin/generic/class-hmac-client.php";
            $this->hmac_client = new \Site_Export_HMAC_Client($options["secret"]);
        }

        // preflight and preflight-assert run the preflight themselves and
        // exit directly — they do not go through the normal command dispatch.
        if ($command === "preflight") {
            $this->run_preflight();
            $this->run_preflight_report();
            return;
        }

        // db-domains and db-apply are local-only commands that don't need a remote server.
        if ($command === "db-domains") {
            $this->run_db_domains();
            return;
        }
        if ($command === "files-stats") {
            $this->run_files_stats();
            return;
        }
        if ($command === "db-apply") {
            if ($abort) {
                $this->handle_abort($command);
                return;
            }
            try {
                $this->run_db_apply($options);
                $final_status = $this->state["status"] ?? "complete";
                $this->output_progress(["status" => $final_status]);
                if ($final_status === "partial") {
                    $this->exit_code = 2;
                }
            } catch (Exception $e) {
                $this->output_progress([
                    "status" => "error",
                    "error" => $e->getMessage(),
                ]);
                $this->write_status_file($e->getMessage());
                throw $e;
            }
            return;
        }

        // All other commands require a prior preflight run.
        $this->require_preflight();

        // Handle --abort: clear state for the command and exit immediately.
        // To abort a sync, run `<command> --abort` (clears state), then
        // run `<command>` again (starts fresh).
        if ($abort) {
            // @TODO: Co-locate abort for each command with the run_*() method
            //        for that command.
            $this->handle_abort($command);
            return;
        }

        // Dispatch to appropriate command handler
        try {
            switch ($command) {
                case "preflight-assert":
                    $this->run_preflight_assert();
                    return;

                case "files-sync":
                    $this->run_files_sync();
                    break;

                case "files-index":
                    $this->run_files_index();
                    break;

                case "db-sync":
                    $this->run_db_sync();
                    break;
                case "db-index":
                    $this->run_db_index();
                    break;
            }

            $final_status = $this->state["status"] ?? "complete";
            $this->output_progress(["status" => $final_status]);

            // Exit code 2 signals "partial progress, call me again" so
            // runner scripts can loop on $? without reading the state file.
            if ($final_status === "partial") {
                $this->exit_code = 2;
            }
        } catch (Exception $e) {
            $this->output_progress([
                "status" => "error",
                "error" => $e->getMessage(),
            ]);
            $this->write_status_file($e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle --abort for any command: clear relevant state and exit.
     *
     * Each command has its own set of files and state fields that need clearing.
     * After clearing, we save state and return — the caller exits without
     * running the actual sync. The user then runs the command again to start fresh.
     */
    private function handle_abort(string $command): void
    {
        switch ($command) {
            case "files-sync":
                // Clear sync progress (cursor, stage, status) and transient
                // files, but keep the local index and downloaded files intact.
                // This way the next `files-sync` sees a completed local index
                // and runs a delta sync rather than re-downloading everything.
                $this->audit_log(
                    "RESTART | Clearing files-sync progress (keeping local index and files)",
                    true,
                );
                $this->reset_state();

                // Merge any pending index updates into the main index before
                // clearing transient state so we don't lose work.
                $this->recover_index_updates();
                if (
                    $this->index_updates_file &&
                    file_exists($this->index_updates_file)
                ) {
                    @unlink($this->index_updates_file);
                    $this->audit_log("FILE DELETE | {$this->index_updates_file}");
                }
                $this->index_updates_file = null;
                $this->index_updates_handle = null;
                $this->index_updates_count = 0;

                if (file_exists($this->remote_index_file)) {
                    @unlink($this->remote_index_file);
                    $this->audit_log("FILE DELETE | {$this->remote_index_file}");
                }
                if (file_exists($this->download_list_file)) {
                    @unlink($this->download_list_file);
                    $this->audit_log("FILE DELETE | {$this->download_list_file}");
                }
                if (file_exists($this->volatile_files_file)) {
                    @unlink($this->volatile_files_file);
                    $this->audit_log("FILE DELETE | {$this->volatile_files_file}");
                }
                $this->state["index"] = $this->default_state()["index"];
                $this->state["fetch"] = $this->default_state()["fetch"];

                $this->save_state($this->state);
                break;

            case "files-index":
                $this->audit_log(
                    "RESTART | Clearing files-index state",
                    true,
                );
                $this->state["command"] = "files-index";
                $this->state["status"] = null;
                $this->state["stage"] = null;
                $this->state["index"] = $this->default_state()["index"];
                if (file_exists($this->remote_index_file)) {
                    @unlink($this->remote_index_file);
                    $this->audit_log("FILE DELETE | {$this->remote_index_file}");
                }
                $this->save_state($this->state);
                break;

            case "db-sync":
                $this->audit_log(
                    "RESTART | Clearing db-sync state",
                    true,
                );
                $this->reset_state();
                $this->save_state($this->state);

                if ($this->sql_output_mode === "file") {
                    $sql_file = $this->state_dir . "/db.sql";
                    if (file_exists($sql_file)) {
                        unlink($sql_file);
                        $this->audit_log(
                            "FILE DELETE | {$sql_file} | abort db-sync",
                        );
                    }
                }
                $tables_file = $this->state_dir . "/db-tables.jsonl";
                if (file_exists($tables_file)) {
                    unlink($tables_file);
                    $this->audit_log(
                        "FILE DELETE | {$tables_file} | abort db-sync",
                    );
                }
                $domains_file = $this->state_dir . "/.import-domains.json";
                if (file_exists($domains_file)) {
                    unlink($domains_file);
                    $this->audit_log(
                        "FILE DELETE | {$domains_file} | abort db-sync",
                    );
                }
                break;

            case "db-index":
                $this->audit_log(
                    "RESTART | Clearing db-index state",
                    true,
                );
                $this->reset_state();
                $this->save_state($this->state);

                $tables_file = $this->state_dir . "/db-tables.jsonl";
                if (file_exists($tables_file)) {
                    unlink($tables_file);
                    $this->audit_log(
                        "FILE DELETE | {$tables_file} | abort db-index",
                    );
                }
                break;

            case "db-apply":
                $this->audit_log(
                    "RESTART | Clearing db-apply state",
                    true,
                );
                $this->reset_state();
                $this->save_state($this->state);
                break;
        }

        if ($this->is_tty && !$this->verbose_mode) {
            fwrite($this->progress_fd, "State cleared for {$command}.\n");
        }

        $this->output_progress(["status" => "aborted"]);
    }

    /**
     * Initialize adaptive tuning from CLI options and persisted state.
     */
    private function initialize_tuner(array $options): void
    {
        $config = $this->state["tuning"]["config"] ?? [];
        $state = $this->state["tuning"]["state"] ?? [];
        $cli_config = $options["tuning_config"] ?? [];

        $config = array_merge($config, $cli_config);

        $this->tuner = new AdaptiveTuner($config, $state);
        $this->state["tuning"] = [
            "config" => $this->tuner->get_config(),
            "state" => $this->tuner->get_state(),
        ];

        $this->audit_log(
            "TUNER CONFIG | " . json_encode($this->state["tuning"]["config"]),
            false,
        );
    }

    /**
     * Run a cheap preflight check to record exporter environment details.
     */
    private function run_preflight(): void
    {
        $url = $this->build_url("preflight", null, []);
        $this->audit_log("PREFLIGHT REQUEST | {$url}", false);

        $result = $this->fetch_json($url);
        $payload = $result["json"] ?? null;

        $entry = [
            "timestamp" => time(),
            "http_code" => (int) ($result["http_code"] ?? 0),
            "elapsed" => (float) ($result["elapsed"] ?? 0),
            "ok" => is_array($payload) ? ($payload["ok"] ?? null) : null,
            "data" => $payload,
            "error" => $result["error"] ?? null,
        ];

        $this->state["preflight"] = $entry;

        // Store WordPress version at the top level for easy access
        $wp_version = $payload["database"]["wp"]["wp_version"] ?? null;
        if (is_string($wp_version) && $wp_version !== "") {
            $this->state["version"] = $wp_version;
        }

        // Store remote protocol version for compatibility checks
        if (isset($payload["protocol_version"])) {
            $this->state["remote_protocol_version"] = (int) $payload["protocol_version"];
        }
        if (isset($payload["protocol_min_version"])) {
            $this->state["remote_protocol_min_version"] = (int) $payload["protocol_min_version"];
        }

        $this->save_state($this->state);

        $this->audit_log(
            "PREFLIGHT RESULT | " . json_encode($entry),
            false,
        );

        // Log non-standard WordPress directory layouts for awareness
        $paths = $payload["database"]["wp"]["paths_urls"] ?? null;
        if (is_array($paths)) {
            $abspath = rtrim($paths["abspath"] ?? "", "/");
            $content_dir = rtrim($paths["content_dir"] ?? "", "/");
            $uploads_basedir = rtrim(
                $paths["uploads"]["basedir"] ?? "",
                "/",
            );
            if (
                $abspath !== "" &&
                $content_dir !== "" &&
                $content_dir !== $abspath . "/wp-content"
            ) {
                $this->audit_log(
                    "NON-STANDARD LAYOUT | wp-content is at {$content_dir} " .
                        "(expected {$abspath}/wp-content)",
                );
            }
            if (
                $content_dir !== "" &&
                $uploads_basedir !== "" &&
                strpos($uploads_basedir, $content_dir) !== 0
            ) {
                $this->audit_log(
                    "NON-STANDARD LAYOUT | uploads at {$uploads_basedir} " .
                        "is outside wp-content ({$content_dir})",
                );
            }
        }
    }

    /**
     * Assert that a preflight has already been run and stored in state.
     * All commands except preflight/preflight-assert call this before starting work.
     */
    private function require_preflight(): void
    {
        $entry = $this->state["preflight"] ?? null;
        if (!is_array($entry) || empty($entry["data"])) {
            throw new RuntimeException(
                "No preflight data found. Run 'preflight' or 'preflight-assert' first.",
            );
        }
    }

    /**
     * Command: preflight
     *
     * Prints the full preflight response as pretty-printed JSON to stdout.
     * The preflight itself already ran in run_preflight() — this just
     * outputs the stored result.
     */
    private function run_preflight_report(): void
    {
        $entry = $this->state["preflight"] ?? null;
        if ($entry === null) {
            echo "No preflight data available.\n";
            exit(1);
        }
        // @TODO: Store paths as base64 strings, not raw strings, since paths can contain arbitrary bytes
        echo json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $ok = ($entry["http_code"] ?? 0) === 200 && !empty($entry["data"]["ok"]);
        $this->write_status_file($ok ? null : "Preflight failed");
        exit($ok ? 0 : 1);
    }

    /**
     * Command: preflight-assert
     *
     * Inspects the preflight response (already fetched by run_preflight())
     * and exits with code 0 if migration looks feasible, code 1 if not.
     * Prints a human-readable pass/fail summary to stdout.
     */
    private function run_preflight_assert(): void
    {
        $entry = $this->state["preflight"] ?? null;
        $data = $entry["data"] ?? null;
        $checks = [];
        $all_pass = true;

        // 1. Server responded OK
        $http_ok = ($entry["http_code"] ?? 0) === 200;
        $checks[] = [
            "label" => "Server responded",
            "pass" => $http_ok,
            "detail" => $http_ok
                ? "HTTP 200"
                : "HTTP " . ($entry["http_code"] ?? "no response"),
        ];
        if (!$http_ok) {
            $all_pass = false;
        }

        // 2. Top-level ok flag
        $top_ok = is_array($data) && !empty($data["ok"]);
        $checks[] = [
            "label" => "Preflight OK",
            "pass" => $top_ok,
            "detail" => $top_ok
                ? "passed"
                : ($data["error"] ?? "preflight not ok"),
        ];
        if (!$top_ok) {
            $all_pass = false;
        }

        // 3. Protocol version compatibility
        $remote_ver = $this->state["remote_protocol_version"] ?? null;
        $remote_min = $this->state["remote_protocol_min_version"] ?? null;
        if ($remote_ver === null) {
            $proto_ok = false;
            $proto_detail = "Remote export plugin does not report a protocol version. Update the export plugin.";
        } elseif ($remote_ver < IMPORT_MIN_EXPORT_VERSION) {
            $proto_ok = false;
            $proto_detail = "Remote protocol v{$remote_ver} is too old (client requires >= v" . IMPORT_MIN_EXPORT_VERSION . "). Update the export plugin.";
        } elseif (IMPORT_PROTOCOL_VERSION < $remote_min) {
            $proto_ok = false;
            $proto_detail = "Client protocol v" . IMPORT_PROTOCOL_VERSION . " is too old (remote requires >= v{$remote_min}). Update the importer.";
        } else {
            $proto_ok = true;
            $proto_detail = "remote v{$remote_ver}, client v" . IMPORT_PROTOCOL_VERSION;
        }
        $checks[] = [
            "label" => "Protocol compatible",
            "pass" => $proto_ok,
            "detail" => $proto_detail,
        ];
        if (!$proto_ok) {
            $all_pass = false;
        }

        // 4. Filesystem accessible
        $fs = $data["filesystem"] ?? null;
        $fs_ok = is_array($fs) && !empty($fs["ok"]);
        $checks[] = [
            "label" => "Filesystem accessible",
            "pass" => $fs_ok,
            "detail" => $fs_ok
                ? "directories readable"
                : ($fs["error"] ?? "filesystem check failed"),
        ];
        if (!$fs_ok) {
            $all_pass = false;
        }

        // 5. Database accessible
        $db = $data["database"] ?? null;
        $db_ok = is_array($db) && !empty($db["connected"]);
        $checks[] = [
            "label" => "Database accessible",
            "pass" => $db_ok,
            "detail" => $db_ok
                ? ($db["version"] ?? "connected")
                : ($db["error"] ?? "database check failed"),
        ];
        if (!$db_ok) {
            $all_pass = false;
        }

        // We do not check for any encoding issues here. We'll move over
        // the entire database as it is.

        // Print summary
        foreach ($checks as $check) {
            $icon = $check["pass"] ? "PASS" : "FAIL";
            echo "[{$icon}] {$check["label"]}: {$check["detail"]}\n";
        }

        echo "\n";
        if ($all_pass) {
            echo "Migration looks feasible.\n";
            $this->write_status_file();
            exit(0);
        } else {
            echo "Migration may not be feasible. Review the failures above.\n";
            $this->write_status_file("Preflight assertions failed");
            exit(1);
        }
    }

    /**
     * Build request params for an endpoint using the adaptive tuner.
     */
    private function get_tuned_params(string $endpoint): array
    {
        if (!$this->tuner instanceof AdaptiveTuner) {
            return [];
        }
        $params = $this->tuner->get_request_params($endpoint);
        // Tell the server about the client's max_allowed_packet so it can
        // cap SQL statements to a size the client can actually import.
        if ($endpoint === "sql_chunk" && $this->max_allowed_packet !== null) {
            $params["max_allowed_packet"] = $this->max_allowed_packet;
        }
        if (!empty($params)) {
            $this->audit_log(
                "TUNER REQUEST | endpoint={$endpoint} | params=" .
                    json_encode($params),
                false,
            );
        }
        return $params;
    }

    private function handle_tuner_error(string $endpoint, array $error): void
    {
        if (!$this->tuner instanceof AdaptiveTuner) {
            return;
        }

        $decision = $this->tuner->record_error($endpoint, $error);
        $log = [
            "TUNER ERROR",
            "endpoint={$endpoint}",
            "decision={$decision["decision"]}",
            "http_code=" . (int) ($decision["http_code"] ?? 0),
            "timeout=" . (!empty($decision["timeout"]) ? "yes" : "no"),
            "curl_errno=" . (int) ($decision["curl_errno"] ?? 0),
            "error_backoff_remaining=" .
                (int) ($decision["error_backoff_remaining"] ?? 0),
        ];
        if (!empty($decision["size_key"])) {
            $log[] =
                $decision["size_key"] . "=" . (int) ($decision["size_value"] ?? 0);
        }
        $this->audit_log(implode(" | ", $log), false);
    }

    /**
     * Record request metrics, apply tuning decisions, and sleep if needed.
     */
    private function finalize_tuned_request(
        string $endpoint,
        float $wall_time,
        array $response_stats
    ): void {
        if (!$this->tuner instanceof AdaptiveTuner) {
            return;
        }

        $decision = $this->tuner->record_result($endpoint, [
            "wall_time" => $wall_time,
            "server_time" => $response_stats["server_time"] ?? null,
            "status" => $response_stats["status"] ?? null,
            "bytes_processed" => $response_stats["bytes_processed"] ?? null,
            "entries_processed" => $response_stats["entries_processed"] ?? null,
            "sql_bytes" => $response_stats["sql_bytes"] ?? null,
            "ttfb" => $response_stats["ttfb"] ?? null,
            "total_time" => $response_stats["total_time"] ?? null,
            "memory_used" => $response_stats["memory_used"] ?? null,
            "memory_limit" => $response_stats["memory_limit"] ?? null,
        ]);

        $log = [
            "TUNER RESULT",
            "endpoint={$endpoint}",
            "decision={$decision["decision"]}",
            "status=" . ($decision["status"] ?? "unknown"),
            "elapsed=" . sprintf("%.3f", $decision["elapsed"] ?? 0) . "s",
            "server_time=" .
                sprintf("%.3f", (float) ($decision["server_time"] ?? 0)) .
                "s",
            "wall_time=" .
                sprintf("%.3f", (float) ($decision["wall_time"] ?? 0)) .
                "s",
        ];

        if (isset($decision["work_done"]) && $decision["work_done"] !== null) {
            $log[] = "work=" . (int) $decision["work_done"];
        }
        if (isset($decision["throughput"]) && $decision["throughput"] !== null) {
            $log[] =
                "throughput=" . sprintf("%.2f", $decision["throughput"]);
        }
        if (isset($decision["throughput_ema"]) && $decision["throughput_ema"] !== null) {
            $log[] = "ema=" . sprintf("%.2f", $decision["throughput_ema"]);
        }
        if (isset($decision["throughput_ratio"]) && $decision["throughput_ratio"] !== null) {
            $log[] =
                "ratio=" . sprintf("%.2f", (float) $decision["throughput_ratio"]);
        }
        if (!empty($decision["size_key"])) {
            $log[] =
                $decision["size_key"] . "=" . (int) ($decision["size_value"] ?? 0);
        }
        if (isset($decision["error_backoff_remaining"])) {
            $log[] =
                "error_backoff=" . (int) $decision["error_backoff_remaining"];
        }
        $log[] = "duty=" . sprintf("%.2f", $decision["duty"] ?? 0);
        $log[] =
            "sleep=" .
            sprintf("%.2f", $decision["sleep_seconds"] ?? 0) .
            "s";
        $this->audit_log(implode(" | ", $log), false);

        $sleep = (float) ($decision["sleep_seconds"] ?? 0);
        if ($sleep > 0) {
            usleep((int) round($sleep * 1_000_000));
        }
    }

    /**
     * Command: files-sync
     *
     * Unified file synchronization that auto-detects initial vs delta mode:
     * - No prior completed files-sync → initial mode (index all, fetch all)
     * - Prior completed files-sync → delta mode (re-index, diff, fetch changes)
     * - In-progress files-sync → resume from saved state
     *
     * Both modes share the same pipeline: index → diff → fetch.
     */
    private function run_files_sync(): void
    {
        $state_command = $this->state["command"] ?? null;
        $current_status =
            $state_command === "files-sync"
                ? $this->state["status"] ?? null
                : null;
        $has_progress =
            $state_command === "files-sync" &&
            $current_status !== null &&
            $current_status !== "complete";

        $this->recover_index_updates();

        // Already completed → refuse to proceed without --abort
        if ($current_status === "complete") {
            $index_size = $this->index_count();
            $this->clear_progress_line();
            $this->audit_log(
                sprintf("files-sync already complete: %d files indexed", $index_size),
                true,
            );

            if ($this->is_tty && !$this->verbose_mode) {
                fwrite($this->progress_fd, "files-sync already complete: {$index_size} files indexed\n");
                fwrite($this->progress_fd, "To re-sync, run with --abort first to clear state.\n");
            }
            return;
        }

        $is_empty =
            !is_dir($this->docroot) || count(scandir($this->docroot)) <= 2; // only . and ..

        // A local index from a prior completed sync means the next run is a
        // delta: re-index the remote, diff against local, fetch only changes.
        $is_delta =
            file_exists($this->index_file) &&
            filesize($this->index_file) > 0;

        // Resuming an in-progress sync
        if ($has_progress) {
            $this->files_imported = 0;
            $index_size = $this->index_count();

            $stage = $this->state["stage"] ?? "index";
            $this->audit_log(
                sprintf(
                    "RESUME files-sync | stage=%s | indexed_files=%d",
                    $stage,
                    $index_size,
                ),
                true,
            );

            if ($this->is_tty && !$this->verbose_mode) {
                fwrite($this->progress_fd, "Resuming files-sync\n");
                fwrite($this->progress_fd, "  Stage: {$stage}\n");
                fwrite($this->progress_fd, "  Already indexed: {$index_size} files\n");
            }
        } else {
            // Starting fresh — validate that target directory is empty.
            // A delta sync ($is_delta) naturally has a non-empty docroot
            // because we put those files there during the initial sync.
            if (!$is_empty && !$is_delta && $this->docroot_nonempty_behavior === 'error') {
                throw new RuntimeException(
                    "Target directory is not empty and no cursor found. " .
                        "Either clear the target directory, use --abort flag, or use --on-docroot-nonempty=preserve-local to sync while preserving the existing content.",
                );
            }

            $this->state["command"] = "files-sync";
            $this->state["status"] = "in_progress";
            $this->state["stage"] = "index";
            $this->state["diff"] = $this->default_state()["diff"];
            $this->state["index"] = $this->default_state()["index"];
            $this->state["fetch"] = $this->default_state()["fetch"];
            $this->save_state($this->state);

            if ($is_delta) {
                $this->files_imported = 0;
                $index_size = $this->index_count();
                $this->audit_log(
                    "START files-sync (delta) | index_files={$index_size}",
                    true,
                );

                if ($this->is_tty && !$this->verbose_mode) {
                    fwrite($this->progress_fd, "Starting files-sync (delta)\n");
                    fwrite($this->progress_fd, "  Index contains: {$index_size} files\n");
                    fwrite($this->progress_fd, "  Stage: index\n");
                }
            } else {
                $this->audit_log(
                    "START files-sync ({$this->docroot_nonempty_behavior} mode, ".($is_empty ? 'empty directory' : 'non-empty directory').")",
                    true,
                );

                if ($this->is_tty && !$this->verbose_mode) {
                    fwrite($this->progress_fd, "Starting files-sync\n");
                }
            }
        }

        $this->state["command"] = "files-sync";
        $this->state["status"] = "in_progress";
        $this->save_state($this->state);

        $this->run_files_sync_pipeline();

        // Pipeline returns early with partial status if interrupted
        if (($this->state["status"] ?? null) === "partial") {
            return;
        }

        $this->state["status"] = "complete";
        $this->save_state($this->state);

        $this->clear_progress_line();
        $index_size = $this->index_count();
        $label = $is_delta ? "files-sync (delta)" : "files-sync";

        $this->audit_log(
            sprintf("%s complete: %d files indexed", $label, $index_size),
            true,
        );

        if ($this->is_tty && !$this->verbose_mode) {
            fwrite($this->progress_fd, "{$label} complete: {$index_size} files indexed\n");
            fwrite($this->progress_fd, "Audit log: {$this->audit_log}\n");
        }

        $this->report_volatile_files();
    }

    /**
     * Shared index → diff → fetch pipeline used by both initial and delta syncs.
     *
     * Reads the current stage from state and runs each stage in sequence.
     * Returns early (with partial status) if any stage doesn't complete.
     */
    private function run_files_sync_pipeline(): void
    {
        $stage = $this->state["stage"] ?? "index";

        if ($stage === "index") {
            $complete = $this->download_remote_index();
            if (!$complete) {
                $this->state["status"] = "partial";
                $this->save_state($this->state);
                return;
            }
            if ($this->follow_symlinks) {
                $this->discover_symlink_targets();
                if ($this->shutdown_requested) {
                    $this->state["status"] = "partial";
                    $this->save_state($this->state);
                    return;
                }
            }
            $this->sort_index_file($this->remote_index_file);
            $this->state["stage"] = "diff";
            $this->state["diff"] = $this->default_state()["diff"];
            if (file_exists($this->download_list_file)) {
                @unlink($this->download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->download_list_file} | clearing before diff stage",
                );
            }
            $this->save_state($this->state);
            $stage = "diff";
        }

        if ($stage === "diff") {
            $complete = $this->diff_indexes_and_build_fetch_list();
            if (!$complete) {
                $this->state["status"] = "partial";
                $this->save_state($this->state);
                return;
            }

            $has_downloads =
                file_exists($this->download_list_file) &&
                filesize($this->download_list_file) > 0;
            $this->state["stage"] = $has_downloads ? "fetch" : null;
            $this->save_state($this->state);
            $stage = $has_downloads ? "fetch" : null;

            if (!$has_downloads && file_exists($this->download_list_file)) {
                @unlink($this->download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->download_list_file} | no files to fetch",
                );
            }
        }

        if ($stage === "fetch") {
            $complete = $this->download_files_from_list();
            if (!$complete) {
                $this->state["status"] = "partial";
                $this->save_state($this->state);
                return;
            }
            $this->state["stage"] = null;
            $this->state["fetch"] = $this->default_state()["fetch"];
            $this->save_state($this->state);

            if (file_exists($this->download_list_file)) {
                @unlink($this->download_list_file);
                $this->audit_log(
                    "FILE DELETE | {$this->download_list_file} | fetch complete",
                );
            }
        }

        // Recreate intermediate path symlinks so the full symlink chain
        // works locally.  The server discovers these (e.g. /srv/wordpress
        // -> /wordpress) and includes them in the remote index.
        if ($this->follow_symlinks) {
            $this->recreate_intermediate_symlinks();
        }
    }

    /**
     * Command: files-index
     *
     * Rules:
     * - Streams the full remote index (DFS across directories) until complete
     * - If already completed: require --abort flag
     * - If abort flag: clear remote index file and index cursor
     */
    private function run_files_index(): void
    {
        $state_command = $this->state["command"] ?? null;
        $current_status =
            $state_command === "files-index"
                ? $this->state["status"] ?? null
                : null;

        if ($current_status === "complete") {
            throw new RuntimeException(
                "files-index already completed. Use --abort flag to start over.",
            );
        }

        if ($current_status === null) {
            $this->state["command"] = "files-index";
            $this->state["status"] = "in_progress";
            $this->state["stage"] = "index";
            $this->save_state($this->state);
            $this->audit_log("START files-index", true);
            if ($this->is_tty && !$this->verbose_mode) {
                fwrite($this->progress_fd, "Starting files-index\n");
            }
        } else {
            $cursor = $this->state["index"]["cursor"] ?? null;
            $this->audit_log(
                sprintf(
                    "RESUME files-index | cursor=%s",
                    $cursor ? substr($cursor, 0, 20) . "..." : "none",
                ),
                true,
            );
            if ($this->is_tty && !$this->verbose_mode) {
                fwrite($this->progress_fd, "Resuming files-index\n");
            }
        }

        $this->state["command"] = "files-index";
        $this->save_state($this->state);

        $attempts = 0;
        $last_cursor = $this->state["index"]["cursor"] ?? null;
        while (true) {
            $complete = $this->download_remote_index();
            if ($complete) {
                break;
            }

            if ($this->shutdown_requested) {
                $this->state["status"] = "partial";
                $this->save_state($this->state);
                return;
            }

            $current_cursor = $this->state["index"]["cursor"] ?? null;
            if ($current_cursor === $last_cursor) {
                throw new RuntimeException(
                    "files-index made no progress (cursor unchanged)",
                );
            }
            $last_cursor = $current_cursor;

            $attempts++;
            if ($attempts > 100000) {
                throw new RuntimeException(
                    "files-index exceeded maximum attempts",
                );
            }
        }

        // Follow symlinks: discover symlink targets outside known roots and
        // index them as additional directories.  Repeats until no new targets
        // are found, with cycle detection via realpath.
        if ($this->follow_symlinks) {
            $this->discover_symlink_targets();
        }

        $this->sort_index_file($this->remote_index_file);
        $this->state["status"] = "complete";
        $this->state["stage"] = null;
        $this->save_state($this->state);

        $count = 0;
        if (file_exists($this->remote_index_file)) {
            $h = fopen($this->remote_index_file, "r");
            if ($h) {
                while (fgets($h) !== false) {
                    $count++;
                }
                fclose($h);
            }
        }
        $this->audit_log(
            sprintf("files-index complete: %d entries indexed", $count),
            true,
        );

        if ($this->is_tty && !$this->verbose_mode) {
            fwrite($this->progress_fd, "files-index complete: {$count} entries indexed\n");
            fwrite($this->progress_fd, "Remote index: {$this->remote_index_file}\n");
            fwrite($this->progress_fd, "Audit log: {$this->audit_log}\n");
        }
    }

    /**
     * Recursively discover directories that need indexing beyond the primary
     * export roots.
     *
     * Scans the remote index for symlink entries with a "target" field,
     * resolves relative targets to absolute paths, and indexes each target
     * directory. Repeats until the queue is drained, with cycle detection.
     */
    private function discover_symlink_targets(): void
    {
        $roots = $this->get_root_directories_from_preflight();

        // Collect all indexed directory real paths for containment checks
        $visited = [];
        foreach ($roots as $root) {
            $visited[$root] = true;
        }

        $queue = $this->extract_symlink_dirs_from_index($visited);

        while (!empty($queue)) {
            $dir = array_shift($queue);
            if (isset($visited[$dir])) {
                continue;
            }
            // Skip if this directory is a subdirectory of an already-visited path,
            // since those files were already included in the parent's index.
            $already_covered = false;
            foreach ($visited as $v => $_) {
                if (str_starts_with($dir, $v . "/")) {
                    $already_covered = true;
                    break;
                }
            }
            if ($already_covered) {
                $this->audit_log(
                    "FOLLOW SYMLINK SKIP | {$dir} already covered by a visited parent",
                    true,
                );
                continue;
            }
            $visited[$dir] = true;

            $this->audit_log(
                "FOLLOW SYMLINK | indexing target directory: {$dir}",
                true,
            );
            if ($this->is_tty && !$this->verbose_mode) {
                fwrite($this->progress_fd, "Following symlink target: {$dir}\n");
            }

            // Reset the index cursor so download_remote_index starts fresh
            // for this directory, but appends to the existing index file.
            // Note we are not losing the previous cursor position. This code
            // runs only after the previous directory was fully indexed so
            // we won't need any prior cursor information again.
            $this->state["index"]["cursor"] = null;
            $this->save_state($this->state);

            $attempts = 0;
            $last_cursor = null;
            while (true) {
                try {
                    $complete = $this->download_remote_index($dir);
                } catch (RuntimeException $e) {
                    // We won't be able to follow every symlink. If
                    // the response seems like the remote server rejecting
                    // our attempt to index this directory, log a warning
                    // and skip to the next directory instead of crashing.
                    $msg = $e->getMessage();
                    if (
                        strpos($msg, "HTTP error 4") !== false ||
                        strpos($msg, "dir_outside_root") !== false ||
                        strpos($msg, "outside of allowed roots") !== false
                    ) {
                        $this->audit_log(
                            "FOLLOW SYMLINK SKIP | server rejected {$dir}: " .
                                substr($msg, 0, 200),
                            true,
                        );
                        if ($this->is_tty && !$this->verbose_mode) {
                            fwrite($this->progress_fd, "  Skipped (server rejected): {$dir}\n");
                        }
                        continue 2;
                    }

                    // Still throw all the other errors.
                    throw $e;
                }
                if ($complete) {
                    break;
                }

                if ($this->shutdown_requested) {
                    return;
                }

                $current_cursor = $this->state["index"]["cursor"] ?? null;
                if ($current_cursor === $last_cursor) {
                    throw new RuntimeException(
                        "files-index (symlink follow) made no progress (cursor unchanged)",
                    );
                }
                $last_cursor = $current_cursor;

                $attempts++;
                if ($attempts > 10_000) {
                    // @TODO: Consider a configurable maximum attempts for really large sites that
                    //        require more than 10,000 requests to index.
                    throw new RuntimeException(
                        "files-index (symlink follow) exceeded maximum attempts",
                    );
                }
            }

            // Scan newly added entries for more symlink targets
            $new_targets = $this->extract_symlink_dirs_from_index($visited);
            foreach ($new_targets as $target) {
                if (!isset($visited[$target])) {
                    $queue[] = $target;
                }
            }
        }
    }

    /**
     * Scan the remote index file for symlink entries whose targets are
     * directories not already in $visited.  Returns an array of real paths.
     *
     * Skips entries marked as "intermediate" — those are path-component
     * symlinks (e.g. /srv/wordpress -> /wordpress) emitted by the server's
     * discover_path_symlinks() for local recreation only, not for indexing.
     */
    private function extract_symlink_dirs_from_index(array $visited): array
    {
        $targets = [];
        if (!file_exists($this->remote_index_file)) {
            return $targets;
        }

        $handle = fopen($this->remote_index_file, "r");
        if (!$handle) {
            return $targets;
        }

        while (($line = fgets($handle)) !== false) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }
            if (($entry["type"] ?? "") !== "link") {
                continue;
            }
            if (!empty($entry["intermediate"])) {
                continue;
            }
            $target_encoded = $entry["target"] ?? null;
            if (!is_string($target_encoded) || $target_encoded === "") {
                continue;
            }
            $target = base64_decode($target_encoded);
            if ($target === false || $target === "") {
                continue;
            }

            // If we've seen this target already, we can move on
            // to the next one.
            if (isset($visited[$target])) {
                continue;
            }

            // Check containment: skip if already under a visited root
            $contained = false;
            foreach ($visited as $root => $_) {
                if (str_starts_with($target, $root . "/")) {
                    $contained = true;
                    break;
                }
            }
            if ($contained) {
                continue;
            }

            $targets[] = $target;
        }
        fclose($handle);

        return array_values(array_unique($targets));
    }

    /**
     * Recreate intermediate symlinks discovered by the server's
     * discover_path_symlinks() function.
     *
     * When following symlinks, the server walks each target path component by
     * component and emits index entries for any intermediate symlinks it finds.
     * For example, if /srv/wordpress is a symlink to /wordpress, the server
     * emits an index entry with path=/srv/wordpress, target=/wordpress,
     * type=link, intermediate=true.
     *
     * Since the server indexes everything under realpath()-resolved paths,
     * the files are already downloaded to the target location (e.g.
     * docroot/wordpress/...).  We just need to create the symlink
     * (e.g. docroot/srv/wordpress -> /wordpress) so the directory
     * layout matches the server.
     */
    private function recreate_intermediate_symlinks(): void
    {
        if (!file_exists($this->remote_index_file)) {
            return;
        }

        $h = fopen($this->remote_index_file, "r");
        if (!$h) {
            return;
        }

        $created = 0;
        while (($line = fgets($h)) !== false) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }
            if (($entry["type"] ?? "") !== "link") {
                continue;
            }
            if (empty($entry["intermediate"])) {
                continue;
            }
            $target_encoded = $entry["target"] ?? null;
            if (!is_string($target_encoded) || $target_encoded === "") {
                continue;
            }
            $path_encoded = $entry["path"] ?? null;
            if (!is_string($path_encoded) || $path_encoded === "") {
                continue;
            }

            /**
             * base64_decode second parameter is a `strict` flag. It rejects the entire
             * input if it contains any bytes that are not produced by base64_encode().
             * 
             * @see https://www.php.net/base64_decode
             */
            $path = base64_decode($path_encoded, true);
            $target = base64_decode($target_encoded, true);
            if ($path === false || $path === "" || $target === false || $target === "") {
                continue;
            }

            try {
                $local_path = $this->remote_path_to_local_path_within_import_root($path);
            } catch (RuntimeException $e) {
                $this->audit_log(
                    "INTERMEDIATE SYMLINK SKIP: invalid path {$path}: " . $e->getMessage(),
                    true,
                );
                continue;
            }

            // Already correct — skip
            if (is_link($local_path) && readlink($local_path) === $target) {
                continue;
            }

            // Create parent directory
            $parent = dirname($local_path);
            if (!is_dir($parent)) {
                try {
                    $this->ensure_directory_path($parent);
                } catch (RuntimeException $e) {
                    $this->audit_log(
                        "INTERMEDIATE SYMLINK SKIP: failed to prepare parent for {$path}: " .
                            $e->getMessage(),
                        true,
                    );
                    continue;
                }
            }

            // Remove stale symlink if present
            if (is_link($local_path)) {
                @unlink($local_path);
            }

            // Don't overwrite a real directory — that shouldn't exist for
            // an intermediate symlink path, and if it does something else
            // is wrong.
            if (file_exists($local_path)) {
                $this->audit_log(
                    "INTERMEDIATE SYMLINK SKIP: {$path} already exists as a real file/dir",
                    true,
                );
                continue;
            }

            // Validate that the symlink target doesn't escape the filesystem root.
            $root = $this->get_filesystem_root_path();
            try {
                $this->assert_symlink_target_within_root(
                    dirname($local_path),
                    $target,
                    $root
                );
            } catch (RuntimeException $e) {
                $this->audit_log(
                    "INTERMEDIATE SYMLINK SKIP: " . $e->getMessage(),
                    true,
                );
                continue;
            }

            if (@symlink($target, $local_path)) {
                $created++;
                $this->audit_log(
                    "INTERMEDIATE SYMLINK: {$path} -> {$target}",
                    false,
                );
            } else {
                $this->audit_log(
                    "Failed to create intermediate symlink: {$path} -> {$target}",
                    true,
                );
            }
        }
        fclose($h);

        if ($created > 0) {
            $this->audit_log(
                "Recreated {$created} intermediate symlink(s)",
                false,
            );
        }
    }

    /**
     * Command: db-sync
     *
     * Rules:
     * - Stream next portion of SQL from last saved cursor
     * - If already completed and db.sql exists: require --abort flag
     * - If db.sql missing but state says complete: warn and require --abort flag
     * - Otherwise: error
     */
    private function run_db_sync(): void
    {
        $state_command = $this->state["command"] ?? null;
        $sql_file = $this->state_dir . "/db.sql";

        $has_progress =
            $state_command === "db-sync" &&
            ($this->state["status"] ?? null) === "in_progress";
        $current_status =
            $state_command === "db-sync"
                ? $this->state["status"] ?? null
                : null;

        // Check if already completed
        if ($current_status === "complete") {
            if ($this->sql_output_mode === "file") {
                $sql_exists = file_exists($sql_file);
                if ($sql_exists) {
                    throw new RuntimeException(
                        "db-sync already completed and db.sql exists. Use --abort flag to start over.",
                    );
                } else {
                    throw new RuntimeException(
                        "db-sync marked complete but db.sql is missing. Use --abort flag to re-sync.",
                    );
                }
            } else {
                throw new RuntimeException(
                    "db-sync already completed. Use --abort flag to start over.",
                );
            }
        }

        if ($has_progress) {
            $stage = $this->state["stage"] ?? "db-index";
            $this->audit_log(
                sprintf(
                    "RESUME db-sync | stage=%s | cursor=%s",
                    $stage,
                    !empty($this->state["cursor"])
                        ? substr($this->state["cursor"], 0, 20) . "..."
                        : "none",
                ),
                true,
            );

            if ($this->is_tty && !$this->verbose_mode) {
                fwrite($this->progress_fd, "Resuming db-sync (stage: {$stage})\n");
            }
        } else {
            // Starting fresh
            $this->state["command"] = "db-sync";
            $this->state["status"] = "in_progress";
            $this->state["cursor"] = null;
            $this->state["stage"] = "db-index";
            $this->state["diff"] = $this->default_state()["diff"];
            $this->state["db_index"] = $this->default_state()["db_index"];
            $this->save_state($this->state);

            $this->audit_log("START db-sync", true);

            if ($this->is_tty && !$this->verbose_mode) {
                fwrite($this->progress_fd, "Starting db-sync\n");
            }
        }

        $this->state["command"] = "db-sync";
        $this->save_state($this->state);

        // Stage 1: db-index (table metadata for progress estimation)
        $stage = $this->state["stage"] ?? "db-index";
        if ($stage === "db-index") {
            $this->output_progress([
                "status" => "starting",
                "phase" => "db-index",
            ]);

            $this->download_db_index();

            $tables = (int) ($this->state["db_index"]["tables"] ?? 0);
            $this->audit_log(
                sprintf("db-sync db-index stage complete: %d tables", $tables),
            );

            // Transition to sql stage
            $this->state["stage"] = "sql";
            $this->state["cursor"] = null;
            $this->save_state($this->state);
        }

        // Stage 2: SQL dump download
        $this->output_progress([
            "status" => "starting",
            "phase" => "sql",
        ]);

        $this->download_sql();

        // Mark as complete
        $this->state["status"] = "complete";
        $this->save_state($this->state);

        $this->audit_log("db-sync complete", true);

        if ($this->is_tty && !$this->verbose_mode) {
            fwrite($this->progress_fd, "db-sync complete\n");
            if ($this->sql_output_mode === "file") {
                fwrite($this->progress_fd, "SQL file: {$sql_file}\n");
            } elseif ($this->sql_output_mode === "stdout") {
                fwrite($this->progress_fd, "SQL written to stdout\n");
            } elseif ($this->sql_output_mode === "mysql") {
                fwrite($this->progress_fd, "SQL imported into {$this->mysql_database}\n");
            } elseif ($this->sql_output_mode === "wpdb") {
                fwrite($this->progress_fd, "SQL imported via \$wpdb\n");
            }
            fwrite($this->progress_fd, "Audit log: {$this->audit_log}\n");
        }
    }

    // =========================================================================
    // db-apply: Apply SQL dump to a target MySQL database with URL rewriting
    // =========================================================================

    /**
     * Command: db-apply
     *
     * Reads db.sql, optionally rewrites URLs, and executes statements against
     * a target MySQL database. Supports resumption via statement count tracking.
     *
     */
    private function run_db_domains(): void
    {
        $domains_file = $this->state_dir . "/.import-domains.json";
        $sql_file = $this->state_dir . "/db.sql";

        if (file_exists($domains_file)) {
            // Fast path: domains were already discovered during db-sync
            $domains = json_decode(file_get_contents($domains_file), true);
            if (!is_array($domains)) {
                throw new RuntimeException(
                    "Failed to parse {$domains_file}",
                );
            }
        } elseif (file_exists($sql_file)) {
            // Scan db.sql for domains using the same pipeline as db-sync
            $query_stream = new \WP_MySQL_Naive_Query_Stream();
            $domain_collector = new \DomainCollector();

            $sql_handle = fopen($sql_file, "r");
            if (!$sql_handle) {
                throw new RuntimeException("Cannot open SQL file: {$sql_file}");
            }

            try {
                $chunk_size = 64 * 1024;
                while (!feof($sql_handle)) {
                    $data = fread($sql_handle, $chunk_size);
                    if ($data === false || $data === '') {
                        break;
                    }
                    $query_stream->append_sql($data);
                    $this->drain_query_stream_for_domains(
                        $query_stream,
                        $domain_collector,
                    );
                }

                $query_stream->mark_input_complete();
                $this->drain_query_stream_for_domains(
                    $query_stream,
                    $domain_collector,
                );
            } finally {
                fclose($sql_handle);
            }

            $domains = $domain_collector->get_domains();

            // Save for future calls
            file_put_contents(
                $domains_file,
                json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            );
        } else {
            throw new RuntimeException(
                "No domain data found. Run db-sync first, or place a db.sql file in {$this->state_dir}.",
            );
        }

        // Print one domain per line to stdout
        foreach ($domains as $domain) {
            echo $domain . "\n";
        }
    }

    /**
     * Print file index statistics: total indexed files and their size,
     * plus pending downloads and their size.
     *
     * Reads .import-remote-index.jsonl for all indexed files and
     * .import-download-list.jsonl for files not yet downloaded.
     */
    private function run_files_stats(): void
    {
        $remote_index = $this->remote_index_file;
        $download_list = $this->download_list_file;

        // Single pass over the remote index: count, sum sizes, and build
        // a path→size map for resolving download list entries.
        $indexed_count = 0;
        $indexed_bytes = 0;
        $size_by_path = [];

        if (is_file($remote_index)) {
            $handle = fopen($remote_index, "r");
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    $entry = $this->parse_index_line($line);
                    if ($entry === null) {
                        continue;
                    }
                    $indexed_count++;
                    $indexed_bytes += $entry["size"];
                    $size_by_path[$entry["path"]] = $entry["size"];
                }
                fclose($handle);
            }
        }

        // Walk the download list to count pending files. The download
        // list only stores paths, so look up sizes from the map above.
        // Files before the fetch byte offset have already been downloaded.
        $pending_count = 0;
        $pending_bytes = 0;
        $fetch_offset = $this->state["fetch"]["offset"] ?? 0;

        if (is_file($download_list)) {
            $handle = fopen($download_list, "r");
            if ($handle) {
                // Seek past already-downloaded entries. The fetch offset
                // is the byte position where the next batch starts, so
                // everything before it has been fetched.
                if ($fetch_offset > 0) {
                    fseek($handle, $fetch_offset);
                }
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if ($line === "") {
                        continue;
                    }
                    $data = json_decode($line, true);
                    if (!is_array($data)) {
                        continue;
                    }
                    $path_encoded = $data["path"] ?? "";
                    $path = base64_decode($path_encoded, true);
                    if ($path === false || $path === "") {
                        continue;
                    }
                    $pending_count++;
                    $pending_bytes += $size_by_path[$path] ?? 0;
                }
                fclose($handle);
            }
        }

        echo json_encode([
            "indexed" => [
                "files" => $indexed_count,
                "bytes" => $indexed_bytes,
            ],
            "pending" => [
                "files" => $pending_count,
                "bytes" => $pending_bytes,
            ],
        ], JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * If --new-site-url is set, derive the source origin from the export URL
     * and append an implicit --rewrite-url mapping to $options.
     */
    private function resolve_new_site_url_option(array &$options): void
    {
        if (empty($options["new_site_url"])) {
            return;
        }

        $parsed_url = parse_url($this->remote_url);
        if (!$parsed_url || !isset($parsed_url['scheme'], $parsed_url['host'])) {
            throw new InvalidArgumentException(
                "--new-site-url requires a valid export URL to derive the source site origin.",
            );
        }

        $source_origin = $parsed_url['scheme'] . '://' . $parsed_url['host'];
        if (!empty($parsed_url['port'])) {
            $source_origin .= ':' . $parsed_url['port'];
        }

        if (!isset($options["rewrite_url"])) {
            $options["rewrite_url"] = [];
        }
        $options["rewrite_url"][] = [$source_origin, $options["new_site_url"]];
    }

    private function run_db_apply(array $options): void
    {
        $sql_file = $this->state_dir . "/db.sql";
        if (!file_exists($sql_file)) {
            throw new RuntimeException(
                "db.sql not found in {$this->state_dir}. Run db-sync first.",
            );
        }

        // Determine which database backend to use: either direct MySQL via
        // PDO, or WordPress's $wpdb (which may be backed by MySQL or SQLite
        // via the sqlite-database-integration plugin).
        $use_wpdb = !empty($this->wp_load_path) || !empty($options["wp_load"]);

        // Parse target database options (only needed for direct MySQL mode)
        $target_host = $options["target_host"] ?? "127.0.0.1";
        $target_port = (int) ($options["target_port"] ?? 3306);
        $target_user = $options["target_user"] ?? null;
        $target_pass = $options["target_pass"] ?? "";
        $target_db = $options["target_db"] ?? null;

        if (!$use_wpdb && (!$target_user || !$target_db)) {
            throw new InvalidArgumentException(
                "db-apply requires --target-user and --target-db, or --wp-load.",
            );
        }

        // If --new-site-url is provided, derive the source origin from the
        // export URL and add an implicit --rewrite-url mapping.
        $this->resolve_new_site_url_option($options);

        // Parse URL mapping
        $url_mapping = [];
        if (!empty($options["rewrite_url"])) {
            foreach ($options["rewrite_url"] as $pair) {
                $url_mapping[$pair[0]] = $pair[1];
            }
        }

        // Show discovered domains if available
        $domains_file = $this->state_dir . "/.import-domains.json";
        if (file_exists($domains_file)) {
            $domains = json_decode(file_get_contents($domains_file), true);
            if (is_array($domains) && !empty($domains)) {
                $this->audit_log(
                    sprintf("DISCOVERED DOMAINS | %s", implode(", ", $domains)),
                    false,
                );
                if ($this->is_tty && !$this->verbose_mode) {
                    echo "Discovered domains in SQL dump:\n";
                    foreach ($domains as $domain) {
                        $mapped = isset($url_mapping[$domain]) ? " => {$url_mapping[$domain]}" : " (not mapped)";
                        echo "  {$domain}{$mapped}\n";
                    }
                    echo "\n";
                }
            }
        }

        // Check state for resume
        $state_command = $this->state["command"] ?? null;
        $current_status = $state_command === "db-apply" ? ($this->state["status"] ?? null) : null;

        if ($current_status === "complete") {
            throw new RuntimeException(
                "db-apply already completed. Use --abort flag to re-run.",
            );
        }

        $apply_state = $this->state["apply"] ?? $this->default_state()["apply"];
        $statements_executed = (int) ($apply_state["statements_executed"] ?? 0);
        $bytes_read = (int) ($apply_state["bytes_read"] ?? 0);
        $is_resume = $current_status === "in_progress" && $statements_executed > 0;

        if ($is_resume) {
            $this->audit_log(
                sprintf(
                    "RESUME db-apply | statements=%d | bytes_read=%d",
                    $statements_executed,
                    $bytes_read,
                ),
                true,
            );
            if ($this->is_tty && !$this->verbose_mode) {
                echo "Resuming db-apply (executed: {$statements_executed} statements)\n";
            }
        } else {
            $this->state["command"] = "db-apply";
            $this->state["status"] = "in_progress";
            $this->state["apply"] = $this->default_state()["apply"];
            if (!empty($url_mapping)) {
                $this->state["apply"]["rewrite_url"] = $url_mapping;
            }
            $this->save_state($this->state);
            $statements_executed = 0;
            $bytes_read = 0;

            $this->audit_log("START db-apply", true);
            if ($this->is_tty && !$this->verbose_mode) {
                echo "Starting db-apply\n";
            }
        }

        // On resume, use the persisted URL mapping if none provided on CLI
        if (empty($url_mapping) && !empty($apply_state["rewrite_url"])) {
            $url_mapping = $apply_state["rewrite_url"];
        }

        // Set up SQL statement rewriter if we have URL mappings
        $stmt_rewriter = null;
        if (!empty($url_mapping)) {
            $table_prefix = $this->state["preflight"]["data"]["database"]["wp"]["table_prefix"] ?? 'wp_';
            $stmt_rewriter = new SqlStatementRewriter(
                new StructuredDataUrlRewriter($url_mapping),
                $table_prefix,
            );
            $this->audit_log(
                sprintf(
                    "URL MAPPING | %d mapping(s): %s",
                    count($url_mapping),
                    implode(", ", array_map(
                        fn($from, $to) => "{$from} => {$to}",
                        array_keys($url_mapping),
                        array_values($url_mapping),
                    )),
                ),
                false,
            );
        }

        // Set up the database executor — a closure that takes a SQL string
        // and throws RuntimeException on failure.  This lets the streaming
        // loop work identically regardless of the backend.
        if ($use_wpdb) {
            $wpdb = $this->load_wordpress();

            // Suppress errors so $wpdb->query() returns false instead of
            // calling wp_die().  We inspect $wpdb->last_error ourselves.
            $wpdb->suppress_errors(true);
            $wpdb->show_errors(false);

            $exec_query = function (string $sql) use ($wpdb): void {
                $result = $wpdb->query($sql);
                if ($result === false && $wpdb->last_error) {
                    throw new RuntimeException($wpdb->last_error);
                }
            };

            $this->audit_log("CONNECTED | via \$wpdb (wp-load: {$this->wp_load_path})", false);
        } else {
            $dsn = "mysql:host={$target_host};port={$target_port};dbname={$target_db};charset=utf8mb4";
            try {
                $pdo = new PDO($dsn, $target_user, $target_pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_LOCAL_INFILE => false,
                ]);
            } catch (PDOException $e) {
                throw new RuntimeException(
                    "Cannot connect to target database: " . $e->getMessage(),
                );
            }

            $exec_query = function (string $sql) use ($pdo): void {
                $pdo->exec($sql);
            };

            $this->audit_log(
                sprintf(
                    "CONNECTED | host=%s port=%d db=%s user=%s",
                    $target_host,
                    $target_port,
                    $target_db,
                    $target_user,
                ),
                false,
            );
        }

        // Stream db.sql through the query stream and execute
        $query_stream = new \WP_MySQL_Naive_Query_Stream();
        $sql_handle = fopen($sql_file, "r");
        if (!$sql_handle) {
            throw new RuntimeException("Cannot open SQL file: {$sql_file}");
        }

        $sql_file_size = filesize($sql_file);
        $total_bytes_read = 0;
        $stmt_count = 0;
        $skipped = 0;
        $save_every = 100;
        $stmts_since_save = 0;

        // If resuming, seek to saved position. bytes_read is the byte offset
        // right after the last successfully executed query (tracked via
        // query_stream->get_bytes_consumed()), so no statement skipping is
        // needed after seeking — we're exactly at the next un-executed query.
        $seek_offset = 0;
        $stmts_to_skip = 0;
        if ($bytes_read > 0 && $bytes_read < $sql_file_size) {
            fseek($sql_handle, $bytes_read);
            $total_bytes_read = $bytes_read;
            $seek_offset = $bytes_read;
        } elseif ($statements_executed > 0) {
            // Can't seek — need to scan from beginning and skip statements
            $stmts_to_skip = $statements_executed;
        }

        $this->output_progress([
            "status" => "starting",
            "phase" => "db-apply",
        ]);

        try {
            $chunk_size = 64 * 1024; // 64KB read chunks

            while (!feof($sql_handle)) {
                // Check shutdown
                if ($this->shutdown_requested) {
                    $this->audit_log("SHUTDOWN REQUESTED | saving state", true);
                    break;
                }
                if (function_exists("pcntl_signal_dispatch")) {
                    pcntl_signal_dispatch();
                }

                $data = fread($sql_handle, $chunk_size);
                if ($data === false || $data === '') {
                    break;
                }
                $total_bytes_read += strlen($data);
                $query_stream->append_sql($data);

                while ($query_stream->next_query()) {
                    $query = $query_stream->get_query();
                    $stmt_count++;

                    // Skip already-executed statements on resume
                    if ($stmts_to_skip > 0) {
                        $stmts_to_skip--;
                        continue;
                    }

                    // Rewrite URLs if mapping is configured
                    if ($stmt_rewriter) {
                        $query = $stmt_rewriter->rewrite($query);
                    }

                    // Execute against target database
                    try {
                        $exec_query($query);
                    } catch (\Exception $e) {
                        $this->audit_log(
                            sprintf(
                                "SQL ERROR | stmt=%d | %s | query=%.200s",
                                $stmt_count,
                                $e->getMessage(),
                                $query,
                            ),
                            true,
                        );
                        throw new RuntimeException(
                            "SQL execution error at statement {$stmt_count}: " .
                            $e->getMessage(),
                        );
                    }

                    $statements_executed++;
                    $stmts_since_save++;

                    // Save state periodically. bytes_read is the file offset
                    // right after the last extracted query — NOT total_bytes_read,
                    // which includes bytes buffered in the query stream that haven't
                    // formed a complete query yet. This ensures resumption starts at
                    // the exact boundary between executed and un-executed queries.
                    if ($stmts_since_save >= $save_every) {
                        $this->state["apply"]["statements_executed"] = $statements_executed;
                        $this->state["apply"]["bytes_read"] = $seek_offset + $query_stream->get_bytes_consumed();
                        $this->save_state($this->state);
                        $stmts_since_save = 0;

                        // Progress output
                        $pct = $sql_file_size > 0
                            ? round(100 * $total_bytes_read / $sql_file_size, 1)
                            : 0;
                        $this->output_progress([
                            "phase" => "db-apply",
                            "statements_executed" => $statements_executed,
                            "bytes_read" => $total_bytes_read,
                            "bytes_total" => $sql_file_size,
                            "pct" => $pct,
                        ]);
                    }
                }
            }

            // Drain any remaining buffered query
            $query_stream->mark_input_complete();
            while ($query_stream->next_query()) {
                $query = $query_stream->get_query();
                $stmt_count++;

                if ($stmts_to_skip > 0) {
                    $stmts_to_skip--;
                    continue;
                }

                if ($stmt_rewriter) {
                    $query = $stmt_rewriter->rewrite($query);
                }

                try {
                    $exec_query($query);
                } catch (\Exception $e) {
                    $this->audit_log(
                        sprintf(
                            "SQL ERROR | stmt=%d | %s | query=%.200s",
                            $stmt_count,
                            $e->getMessage(),
                            $query,
                        ),
                        true,
                    );
                    throw new RuntimeException(
                        "SQL execution error at statement {$stmt_count}: " .
                        $e->getMessage(),
                    );
                }

                $statements_executed++;
            }

            if ($this->shutdown_requested) {
                // Save partial progress
                $this->state["apply"]["statements_executed"] = $statements_executed;
                $this->state["apply"]["bytes_read"] = $seek_offset + $query_stream->get_bytes_consumed();
                $this->state["status"] = "partial";
                $this->save_state($this->state);
                $this->audit_log(
                    sprintf(
                        "PARTIAL db-apply | %d statements executed",
                        $statements_executed,
                    ),
                    true,
                );
            } else {
                // Mark complete
                $this->state["apply"]["statements_executed"] = $statements_executed;
                $this->state["apply"]["bytes_read"] = $seek_offset + $query_stream->get_bytes_consumed();
                $this->state["status"] = "complete";
                $this->save_state($this->state);

                $this->audit_log(
                    sprintf(
                        "db-apply complete | %d statements executed",
                        $statements_executed,
                    ),
                    true,
                );

                if ($this->is_tty && !$this->verbose_mode) {
                    echo "db-apply complete ({$statements_executed} statements executed)\n";
                }
            }
        } finally {
            fclose($sql_handle);
        }
    }

    /**
     * Load WordPress via wp-load.php and return the global $wpdb instance.
     *
     * Uses SHORTINIT to skip plugins/themes — only the database layer
     * (including the db.php drop-in for SQLite) is bootstrapped.  This
     * lets the importer route SQL through $wpdb whether WordPress is
     * running on MySQL or SQLite (via sqlite-database-integration).
     *
     * @return \wpdb The global WordPress database object.
     */
    private function load_wordpress(): object
    {
        $wp_load = $this->wp_load_path;
        if (empty($wp_load)) {
            throw new RuntimeException("--wp-load path is not set.");
        }

        // SHORTINIT tells WordPress to stop after the database layer,
        // skipping plugins, themes, and the rest of the bootstrap.
        if (!defined('SHORTINIT')) {
            define('SHORTINIT', true);
        }

        require_once $wp_load;

        if (!isset($GLOBALS['wpdb'])) {
            throw new RuntimeException(
                "WordPress loaded from {$wp_load} but \$wpdb is not available. " .
                "Check that wp-config.php and the database layer are working."
            );
        }

        return $GLOBALS['wpdb'];
    }

    /**
     * Command: db-index
     *
     * Streams table metadata (name/rows/size) for planning and diagnostics.
     */
    private function run_db_index(): void
    {
        $state_command = $this->state["command"] ?? null;
        $tables_file = $this->state_dir . "/db-tables.jsonl";

        $has_cursor =
            $state_command === "db-index" &&
            !empty($this->state["cursor"] ?? null);
        $current_status =
            $state_command === "db-index"
                ? $this->state["status"] ?? null
                : null;
        $tables_exists = file_exists($tables_file);

        if ($current_status === "complete") {
            if ($tables_exists) {
                throw new RuntimeException(
                    "db-index already completed and db-tables.jsonl exists. Use --abort flag to start over.",
                );
            } else {
                throw new RuntimeException(
                    "db-index marked complete but db-tables.jsonl is missing. Use --abort flag to re-run.",
                );
            }
        }

        if (!$has_cursor) {
            $this->state["command"] = "db-index";
            $this->state["status"] = "in_progress";
            $this->state["cursor"] = null;
            $this->state["stage"] = null;
            $this->state["diff"] = $this->default_state()["diff"];
            $this->state["db_index"] = $this->default_state()["db_index"];
            $this->save_state($this->state);

            $this->audit_log("START db-index", true);
            if ($this->is_tty && !$this->verbose_mode) {
                fwrite($this->progress_fd, "Starting db-index\n");
            }
        } else {
            $this->audit_log(
                sprintf(
                    "RESUME db-index | cursor=%s",
                    substr($this->state["cursor"], 0, 20) . "...",
                ),
                true,
            );
            if ($this->is_tty && !$this->verbose_mode) {
                fwrite($this->progress_fd, "Resuming db-index\n");
            }
        }

        $this->state["command"] = "db-index";
        $this->save_state($this->state);

        $this->output_progress([
            "status" => "starting",
            "phase" => "db-index",
        ]);

        $this->download_db_index();

        $this->state["status"] = "complete";
        $this->save_state($this->state);

        $tables = (int) ($this->state["db_index"]["tables"] ?? 0);
        $this->audit_log(
            sprintf("db-index complete: %d tables", $tables),
            true,
        );

        if ($this->is_tty && !$this->verbose_mode) {
            fwrite($this->progress_fd, "db-index complete: {$tables} tables\n");
            fwrite($this->progress_fd, "Table stats: {$tables_file}\n");
            fwrite($this->progress_fd, "Audit log: {$this->audit_log}\n");
        }
    }

    /**
     * Download file content for a prepared file list (file_fetch).
     *
     * @param array|null $post_data Optional POST data
     * @param string|null $cursor Cursor for resumption within the current batch
     */
    private function download_file_fetch(
        ?array $post_data,
        ?string $cursor
    ): bool {
        $cursor = $cursor ?? ($this->state["fetch"]["cursor"] ?? null);
        $complete = false;
        $this->chunks_since_save = 0;

        // Crash recovery: if we have a tracked file that's larger than expected,
        // truncate it. This happens if we crashed after writing but before saving
        // the new cursor, so we'll re-fetch the same data.
        $tracked_file = $this->state["current_file"] ?? null;
        $tracked_bytes = $this->state["current_file_bytes"] ?? null;
        if ($tracked_file !== null && $tracked_bytes !== null && file_exists($tracked_file)) {
            $actual_size = filesize($tracked_file);
            if ($actual_size > $tracked_bytes) {
                $this->audit_log(
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

        $params = $this->get_tuned_params("file_fetch");
        $url = $this->build_url("file_fetch", $cursor, $params);
        $this->audit_log("Downloading file fetch from {$url}");
        $this->audit_log("POST data: " . json_encode($post_data));

        $context = new StreamingContext();
        $context->file_handle = null;
        $context->file_path = null;
        $context->file_ctime = null;

        // Resume recovery: if a file was partially downloaded in a previous
        // request, re-open it in append mode so continuation chunks (where
        // is_first=false) can still be written.  Without this, the context
        // starts with file_handle=null and non-first chunks are silently dropped.
        if ($tracked_file !== null && $tracked_bytes !== null && file_exists($tracked_file)) {
            $context->file_handle = fopen($tracked_file, "ab");
            if ($context->file_handle) {
                $context->file_path = $tracked_file;
                $context->file_bytes_written = $tracked_bytes;
                $this->audit_log(
                    sprintf(
                        "RESUME FILE | Re-opened %s at %d bytes for continued download",
                        $tracked_file,
                        $tracked_bytes,
                    ),
                    true,
                );
            }
        }

        $context->on_chunk = function ($chunk) use (
            &$cursor,
            &$complete,
            $context
        ) {
            if ($this->shutdown_requested) {
                throw new RuntimeException("Shutdown requested");
            }

            if (function_exists("pcntl_signal_dispatch")) {
                pcntl_signal_dispatch();
            }

            $this->chunks_since_save++;
            if ($this->chunks_since_save >= self::SAVE_STATE_EVERY_N_CHUNKS) {
                $this->state["fetch"]["cursor"] = $cursor;
                // Track current file for crash recovery
                if ($context->file_handle && $context->file_path) {
                    // Flush to ensure bytes are on disk before saving state
                    fflush($context->file_handle);
                    $this->state["current_file"] = $context->file_path;
                    $this->state["current_file_bytes"] = $context->file_bytes_written;
                } else {
                    $this->state["current_file"] = null;
                    $this->state["current_file_bytes"] = null;
                }
                $this->save_state($this->state);
                $this->chunks_since_save = 0;
            }

            if (isset($chunk["headers"]["x-cursor"])) {
                $cursor = $chunk["headers"]["x-cursor"];
            }

            $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

            if ($chunk_type === "metadata") {
                $this->handle_metadata_chunk($chunk, $context);
            } elseif ($chunk_type === "file") {
                $this->handle_file_chunk($chunk, $context);
            } elseif ($chunk_type === "directory") {
                $this->handle_directory_chunk($chunk);
            } elseif ($chunk_type === "symlink") {
                $this->handle_symlink_chunk($chunk);
            } elseif ($chunk_type === "missing") {
                $path = base64_decode($chunk["headers"]["x-file-path"] ?? "");
                if ($path) {
                    $this->audit_log("Missing on server: {$path}", true);
                }
                // @TODO: Cleanup the local file that we may have started downloading.
            } elseif ($chunk_type === "error") {
                $this->handle_error_chunk($chunk, "files", $context);
            } elseif ($chunk_type === "progress") {
                $this->handle_progress($chunk, "files");
            } elseif ($chunk_type === "completion") {
                $complete =
                    ($chunk["headers"]["x-status"] ?? "") === "complete";
                $context->saw_completion = true;
                $context->response_stats = [
                    "status" => $chunk["headers"]["x-status"] ?? null,
                    "bytes_processed" =>
                        isset($chunk["headers"]["x-bytes-processed"])
                            ? (int) $chunk["headers"]["x-bytes-processed"]
                            : null,
                    "server_time" =>
                        isset($chunk["headers"]["x-time-elapsed"])
                            ? (float) $chunk["headers"]["x-time-elapsed"]
                            : null,
                    "memory_used" =>
                        isset($chunk["headers"]["x-memory-used"])
                            ? (int) $chunk["headers"]["x-memory-used"]
                            : null,
                    "memory_limit" =>
                        isset($chunk["headers"]["x-memory-limit"])
                            ? (int) $chunk["headers"]["x-memory-limit"]
                            : null,
                ];
                $this->output_progress(
                    [
                        "phase" => "files",
                        "status" => $chunk["headers"]["x-status"] ?? "unknown",
                        "files_completed" =>
                            (int) ($chunk["headers"]["x-files-completed"] ?? 0),
                        "bytes_processed" =>
                            (int) ($chunk["headers"]["x-bytes-processed"] ?? 0),
                    ],
                    true,
                );
            }
        };

        $request_start = microtime(true);
        $this->fetch_streaming(
            $url,
            $cursor,
            $context,
            $post_data,
            "file_fetch",
        );
        $wall_time = microtime(true) - $request_start;

        $this->finalize_tuned_request(
            "file_fetch",
            $wall_time,
            $context->response_stats ?? [],
        );
        $this->state["fetch"]["cursor"] = $cursor;
        $this->finalize_index_updates();
        // Update file tracking: track in-progress file, or clear if complete/no active file
        if ($context->file_handle && $context->file_path) {
            fflush($context->file_handle);
            $this->state["current_file"] = $context->file_path;
            $this->state["current_file_bytes"] = $context->file_bytes_written;
        } else {
            $this->state["current_file"] = null;
            $this->state["current_file_bytes"] = null;
        }
        $this->save_state($this->state);

        return $complete;
    }

    /**
     * Download the remote index stream and write to disk.
     */
    private function download_remote_index(?string $list_dir_override = null): bool
    {
        $index_state = $this->state["index"] ?? $this->default_state()["index"];
        $cursor = $index_state["cursor"] ?? null;

        $roots = $this->get_root_directories_from_preflight();
        if (empty($roots)) {
            throw new RuntimeException(
                "No root directories found. Either add directory[]=... to the " .
                    "export URL, or run preflight first so directories can be auto-detected.",
            );
        }

        $mode = file_exists($this->remote_index_file) ? "a" : "w";
        if ($mode === "w") {
            $this->audit_log(
                "FILE CREATE | {$this->remote_index_file} | downloading fresh remote index",
            );
        } else {
            $this->audit_log(
                "FILE APPEND | {$this->remote_index_file} | resuming remote index download",
            );
        }
        $handle = fopen($this->remote_index_file, $mode);
        if (!$handle) {
            throw new RuntimeException("Failed to open remote index file");
        }

        $complete = false;
        $this->chunks_since_save = 0;
        $params = $this->get_tuned_params("file_index");
        if ($cursor === null) {
            $params["list_dir"] = $list_dir_override ?? $roots[0];
        }
        if ($this->follow_symlinks) {
            $params["follow_symlinks"] = "1";
        }
        $url = $this->build_url("file_index", $cursor, $params);
        $context = new StreamingContext();

        $context->on_chunk = function ($chunk) use (
            &$cursor,
            &$complete,
            $handle,
            $context
        ) {
            if ($this->shutdown_requested) {
                throw new RuntimeException("Shutdown requested");
            }

            if (function_exists("pcntl_signal_dispatch")) {
                pcntl_signal_dispatch();
            }

            $this->chunks_since_save++;
            if ($this->chunks_since_save >= self::SAVE_STATE_EVERY_N_CHUNKS) {
                $this->state["index"] = [
                    "cursor" => $cursor,
                ];
                $this->save_state($this->state);
                $this->chunks_since_save = 0;
            }

            if (isset($chunk["headers"]["x-cursor"])) {
                $cursor = $chunk["headers"]["x-cursor"];
            }

            $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

            if ($chunk_type === "index_batch") {
                $body = $chunk["body"] ?? "";
                if ($body === "") {
                    return;
                }
                $items = json_decode($body, true);
                if (!is_array($items)) {
                    throw new RuntimeException(
                        "Invalid index batch JSON received from server",
                    );
                }
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $path_encoded = $item["path"] ?? "";
                    if (!is_string($path_encoded) || $path_encoded === "") {
                        throw new RuntimeException(
                            "Invalid index batch item: missing path",
                        );
                    }
                    $path = base64_decode($path_encoded, true);
                    if ($path === "" || $path === false) {
                        throw new RuntimeException(
                            "Invalid index batch item: path base64 decode failed",
                        );
                    }
                    assert_valid_path(
                        $path,
                        "index batch path",
                    );
                    $ctime = (int) ($item["ctime"] ?? 0);
                    $size = (int) ($item["size"] ?? 0);
                    $type = (string) ($item["type"] ?? "file");

                    $entry = [
                        "path" => base64_encode($path),
                        "ctime" => $ctime,
                        "size" => $size,
                        "type" => $type,
                    ];
                    if (isset($item["target"]) && is_string($item["target"]) && $item["target"] !== "") {
                        $entry["target"] = $item["target"]; // already base64-encoded
                    }
                    if (!empty($item["intermediate"])) {
                        $entry["intermediate"] = true;
                    }
                    $line = json_encode(
                        $entry,
                        JSON_UNESCAPED_SLASHES,
                    );
                    if ($line === false) {
                        continue;
                    }
                    $bytes = fwrite($handle, $line . "\n");
                    if ($bytes === false) {
                        throw new RuntimeException("Failed to write to remote index file (disk full?)");
                    }

                }
            } elseif ($chunk_type === "progress") {
                $this->handle_progress($chunk, "index");
            } elseif ($chunk_type === "metadata") {
                $this->handle_metadata_chunk($chunk, $context);
            } elseif ($chunk_type === "completion") {
                $complete =
                    ($chunk["headers"]["x-status"] ?? "") === "complete";
                $context->saw_completion = true;
                $context->response_stats = [
                    "status" => $chunk["headers"]["x-status"] ?? null,
                    "entries_processed" =>
                        isset($chunk["headers"]["x-total-entries"])
                            ? (int) $chunk["headers"]["x-total-entries"]
                            : null,
                    "server_time" =>
                        isset($chunk["headers"]["x-time-elapsed"])
                            ? (float) $chunk["headers"]["x-time-elapsed"]
                            : null,
                    "memory_used" =>
                        isset($chunk["headers"]["x-memory-used"])
                            ? (int) $chunk["headers"]["x-memory-used"]
                            : null,
                    "memory_limit" =>
                        isset($chunk["headers"]["x-memory-limit"])
                            ? (int) $chunk["headers"]["x-memory-limit"]
                            : null,
                ];
            } elseif ($chunk_type === "error") {
                $this->handle_error_chunk($chunk, "index", $context);
            }
        };

        $request_start = microtime(true);
        $this->fetch_streaming($url, $cursor, $context, null, "file_index");
        $wall_time = microtime(true) - $request_start;
        $this->finalize_tuned_request(
            "file_index",
            $wall_time,
            $context->response_stats ?? [],
        );
        fclose($handle);

        $this->state["index"] = [
            "cursor" => $complete ? null : $cursor,
        ];
        $this->save_state($this->state);

        return $complete;
    }

    /**
     * Diff local index against remote index and build download list.
     */
    private function diff_indexes_and_build_fetch_list(): bool
    {
        if (!file_exists($this->remote_index_file)) {
            throw new RuntimeException("Remote index file not found");
        }

        $diff = $this->state["diff"] ?? [];
        $remote_offset = (int) ($diff["remote_offset"] ?? 0);
        $local_after = $diff["local_after"] ?? null;
        $download_mode = $remote_offset > 0 ? "a" : "w";
        if ($download_mode === "w") {
            $this->audit_log(
                "FILE CREATE | {$this->download_list_file} | building download list",
            );
        } else {
            $this->audit_log(
                "FILE APPEND | {$this->download_list_file} | resuming download list build",
            );
        }
        $download_handle = fopen($this->download_list_file, $download_mode);
        if (!$download_handle) {
            throw new RuntimeException("Failed to open download list file");
        }

        $remote_handle = fopen($this->remote_index_file, "r");
        if (!$remote_handle) {
            fclose($download_handle);
            throw new RuntimeException("Failed to open remote index file");
        }
        if ($remote_offset > 0) {
            fseek($remote_handle, $remote_offset);
        }

        $local_handle = file_exists($this->index_file)
            ? fopen($this->index_file, "r")
            : null;
        $local = $this->read_index_line($local_handle);
        if ($local_after) {
            while (
                $local !== null &&
                strcmp($local["path"], $local_after) <= 0
            ) {
                $local = $this->read_index_line($local_handle);
            }
        }
        $this->begin_index_updates();
        $processed = 0;

        while (($line = fgets($remote_handle)) !== false) {
            if ($this->shutdown_requested) {
                break;
            }

            if (function_exists("pcntl_signal_dispatch")) {
                pcntl_signal_dispatch();
            }

            $remote_offset = ftell($remote_handle);
            $remote = $this->parse_index_line($line);
            if (!$remote) {
                continue;
            }

            while (
                $local !== null &&
                strcmp($local["path"], $remote["path"]) < 0
            ) {
                $this->delete_local_file_path($local["path"]);
                $this->delete_index_entry($local["path"]);
                $local_after = $local["path"];
                $local = $this->read_index_line($local_handle);
            }

            if ($local !== null && $local["path"] === $remote["path"]) {
                if (
                    $local["ctime"] !== $remote["ctime"] ||
                    $local["size"] !== $remote["size"] ||
                    $local["type"] !== $remote["type"]
                ) {
                    // File is in both indexes but changed on the remote.
                    // Always re-download — this file is in our local index,
                    // meaning we synced it before; preserve-local does not
                    // protect files we own.
                    $this->append_download_list(
                        $remote["path"],
                        $download_handle,
                    );
                }
                $local_after = $local["path"];
                $local = $this->read_index_line($local_handle);
            } elseif (
                $local === null ||
                strcmp($local["path"], $remote["path"]) > 0
            ) {
                $skip_reason = $this->should_skip_for_preserve_local($remote["path"]);
                if ($skip_reason) {
                    $this->audit_log($skip_reason, true);
                    $this->show_progress_line("[skip] " . $this->display_path($remote["path"]));
                } else {
                    $this->append_download_list($remote["path"], $download_handle);
                }
            }

            $processed++;
            if ($processed % 200 === 0) {
                $this->state["diff"] = [
                    "remote_offset" => $remote_offset,
                    "local_after" => $local_after,
                ];
                $this->save_state($this->state);
            }
        }

        while ($local !== null) {
            $this->delete_local_file_path($local["path"]);
            $this->delete_index_entry($local["path"]);
            $local_after = $local["path"];
            $local = $this->read_index_line($local_handle);
        }

        if ($local_handle) {
            fclose($local_handle);
        }
        fclose($remote_handle);
        fclose($download_handle);

        $this->state["diff"] = [
            "remote_offset" => $remote_offset,
            "local_after" => $local_after,
        ];
        $this->save_state($this->state);

        $this->finalize_index_updates();

        return !$this->shutdown_requested;
    }

    /**
     * Download files from a prepared list.
     */
    private function download_files_from_list(): bool
    {
        if (!file_exists($this->download_list_file)) {
            return true;
        }

        if (filesize($this->download_list_file) === 0) {
            return true;
        }
        $fetch_state = $this->state["fetch"] ?? $this->default_state()["fetch"];
        $batch_file = $fetch_state["batch_file"] ?? null;
        $batch_offset = (int) ($fetch_state["offset"] ?? 0);
        $next_offset = (int) ($fetch_state["next_offset"] ?? 0);
        $cursor = $fetch_state["cursor"] ?? null;

        if ($batch_file === null || !file_exists($batch_file)) {
            $batch = $this->prepare_fetch_batch($batch_offset);
            if ($batch === null) {
                return true;
            }
            $batch_file = $batch["file"];
            $batch_offset = $batch["offset"];
            $next_offset = $batch["next_offset"];
            $cursor = null;
            $this->state["fetch"] = [
                "offset" => $batch_offset,
                "next_offset" => $next_offset,
                "batch_file" => $batch_file,
                "cursor" => null,
            ];
            $this->save_state($this->state);
        }

        $post_data = [
            "file_list" => new CURLFile(
                $batch_file,
                "application/json",
                "file-list.json",
            ),
        ];

        $complete = $this->download_file_fetch($post_data, $cursor);
        if (!$complete) {
            return false;
        }

        if (file_exists($batch_file)) {
            @unlink($batch_file);
            $this->audit_log("FILE DELETE | {$batch_file} | fetch batch complete");
        }

        $this->state["fetch"] = [
            "offset" => $next_offset,
            "next_offset" => $next_offset,
            "batch_file" => null,
            "cursor" => null,
        ];
        $this->save_state($this->state);

        return $next_offset >= filesize($this->download_list_file);
    }

    /**
     * Builds a JSON batch file listing the next set of paths to download.
     *
     * Reads from the download list (.import-download-list.jsonl) starting at
     * $offset, accumulating paths into a JSON array until the batch approaches
     * 80% of the server's max request size.  Always includes at least one path,
     * even if it alone exceeds the limit.
     *
     * The batch file is written to a temp file and intended to be uploaded as
     * the request body for the file_fetch endpoint.
     *
     * @param int $offset Byte offset into the download list file.
     * @return array{file: string, offset: int, next_offset: int}|null
     *         The temp file path and byte offsets, or null if no paths remain.
     */
    private function prepare_fetch_batch(int $offset): ?array
    {
        // Cap the batch at 80% of the server's max request size so the
        // multipart envelope and headers still fit.  Floor at 256 KB so
        // tiny max_request values don't produce degenerate single-file batches.
        $max_request = $this->get_max_request_bytes();
        $limit = (int) max(256 * 1024, $max_request * 0.8);

        // Open the download list and seek to where the previous batch left off.
        $handle = fopen($this->download_list_file, "r");
        if (!$handle) {
            throw new RuntimeException("Failed to open download list file");
        }

        if ($offset > 0) {
            fseek($handle, $offset);
        }

        // The output is a temp file containing a JSON array of paths, e.g.
        // ["/wp-content/uploads/photo.jpg","/wp-content/themes/flavor/style.css"]
        // This file gets uploaded as the request body for the file_fetch endpoint.
        $tmp = tempnam(sys_get_temp_dir(), "file-fetch-");
        if ($tmp === false) {
            fclose($handle);
            throw new RuntimeException("Failed to create fetch batch file");
        }
        $out = fopen($tmp, "w");
        if (!$out) {
            fclose($handle);
            @unlink($tmp);
            throw new RuntimeException("Failed to open fetch batch file");
        }

        // Read lines from the download list (one JSON entry per line) and
        // accumulate them into the JSON array until we approach the size limit.
        // The download list supports two formats:
        //   - A bare JSON string:   "/path/to/file"
        //   - A JSON object:        {"path": "<base64-encoded path>"}
        $bytes = 0;
        $first = true;
        fwrite($out, "[");
        $bytes = 1;
        while (true) {
            // Remember where this line started so we can rewind if the
            // entry doesn't fit in the current batch.
            $line_start = ftell($handle);
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $line = trim($line);
            if ($line === "") {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_string($decoded)) {
                $path = $decoded;
            } elseif (is_array($decoded) && isset($decoded["path"])) {
                $path = base64_decode($decoded["path"]);
            } else {
                continue;
            }
            if (!is_string($path) || $path === "") {
                continue;
            }
            $json_path = json_encode(
                $path,
                JSON_UNESCAPED_SLASHES,
            );
            if ($json_path === false) {
                continue;
            }
            $prefix = $first ? "" : ",";
            $chunk = $prefix . $json_path;
            $needed = $bytes + strlen($chunk) + 1; // +1 for closing bracket

            // Would this entry push us over the limit?
            if (!$first && $needed > $limit) {
                // Rewind to the start of this line so the next batch picks it up.
                fseek($handle, $line_start);
                break;
            }
            if ($first && $needed > $limit) {
                // Still write at least one entry even if it exceeds the limit,
                // otherwise we'd loop forever on a single long path.
                if (fwrite($out, $chunk) === false) {
                    throw new RuntimeException("Failed to write fetch batch file (disk full?)");
                }
                $bytes += strlen($chunk);
                $first = false;
                break;
            }

            if (fwrite($out, $chunk) === false) {
                throw new RuntimeException("Failed to write fetch batch file (disk full?)");
            }
            $bytes += strlen($chunk);
            $first = false;
        }
        fwrite($out, "]");
        $bytes += 1;

        $next_offset = ftell($handle);
        fclose($handle);
        fclose($out);

        // An empty batch (just "[]") means we've exhausted the download list.
        if ($bytes <= 2) {
            @unlink($tmp);
            return null;
        }

        return [
            "file" => $tmp,
            "offset" => $offset,
            "next_offset" => $next_offset,
        ];
    }

    /**
     * Determine maximum request size for file_fetch uploads.
     */
    private function get_max_request_bytes(): int
    {
        $preflight = $this->state["preflight"]["data"]["limits"] ?? null;
        $max_request = null;
        if (is_array($preflight) && isset($preflight["max_request_bytes"])) {
            $max_request = (int) $preflight["max_request_bytes"];
        }

        if ($max_request === null || $max_request <= 0) {
            return 4 * 1024 * 1024;
        }

        return $max_request;
    }

    /**
     * Append a path to the download list file.
     */
    private function append_download_list(string $path, $handle): void
    {
        $line = json_encode(
            ["path" => base64_encode($path)],
            JSON_UNESCAPED_SLASHES,
        );
        if ($line !== false) {
            fwrite($handle, $line . "\n");
        }
        $this->audit_log("Added to the download list: {$path}", false);
    }

    /**
     * Delete a local file path safely under the docroot.
     */
    private function delete_local_file_path(string $path): void
    {
        if ($path === "") {
            return;
        }
        try {
            $local_path = $this->remote_path_to_local_path_within_import_root($path);
        } catch (RuntimeException $e) {
            $this->audit_log(
                "Security: refusing to delete invalid path '{$path}': " . $e->getMessage(),
                true,
            );
            return;
        }
        if (!file_exists($local_path) && !is_link($local_path)) {
            return;
        }

        if ($this->remove_local_path_without_following_symlinks($local_path)) {
            $this->audit_log("Deleted: {$path}", false);
            return;
        }

        $this->audit_log("Failed to delete: {$path}", true);
    }

    /**
     * Remove a local path recursively without traversing symlink targets.
     *
     * Symlinks are always unlinked as links. Directories are traversed
     * depth-first.
     */
    private function remove_local_path_without_following_symlinks(
        string $local_path
    ): bool {
        if (!file_exists($local_path) && !is_link($local_path)) {
            return true;
        }

        if (is_link($local_path) || is_file($local_path)) {
            return true === @unlink($local_path);
        }

        if (is_dir($local_path)) {
            $entries = @scandir($local_path);
            if ($entries === false) {
                return false;
            }
            foreach ($entries as $entry) {
                if ($entry === "." || $entry === "..") {
                    continue;
                }
                if (
                    !$this->remove_local_path_without_following_symlinks(
                        $local_path . "/" . $entry
                    )
                ) {
                    return false;
                }
            }
            return true === @rmdir($local_path);
        }

        return true === @unlink($local_path);
    }

    /**
     * Parse one JSON index line into an array.
     */
    private function parse_index_line(string $line): ?array
    {
        $line = trim($line);
        if ($line === "") {
            return null;
        }
        $data = json_decode($line, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid index line format");
        }
        $path_encoded = $data["path"] ?? "";
        if (!is_string($path_encoded) || $path_encoded === "") {
            throw new RuntimeException("Invalid index path");
        }
        $path = base64_decode($path_encoded, true);
        if ($path === "" || $path === false) {
            throw new RuntimeException("Invalid index path (base64 decode failed)");
        }
        assert_valid_path($path, "index path");
        return [
            "path" => $path,
            "ctime" => (int) ($data["ctime"] ?? 0),
            "size" => (int) ($data["size"] ?? 0),
            "type" => (string) ($data["type"] ?? "file"),
        ];
    }

    /**
     * Start collecting index updates into a temp file for streaming merge.
     */
    private function begin_index_updates(): void
    {
        if ($this->index_updates_handle) {
            return;
        }
        $is_new = false;
        if ($this->index_updates_file === null) {
            $tmp = tempnam(sys_get_temp_dir(), "index-updates-");
            if ($tmp === false) {
                throw new RuntimeException(
                    "Failed to create temp index updates file",
                );
            }
            $this->index_updates_file = $tmp;
            $is_new = true;
        } elseif (!file_exists($this->index_updates_file)) {
            $dir = dirname($this->index_updates_file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $is_new = true;
        }
        $this->index_updates_handle = fopen($this->index_updates_file, "a");
        if (!$this->index_updates_handle) {
            throw new RuntimeException(
                "Failed to open temp index updates file",
            );
        }
        if ($is_new) {
            $this->audit_log(
                "FILE CREATE | {$this->index_updates_file} | index updates buffer",
            );
        }
        $this->index_updates_count = 0;
        $this->last_update_path = null;
        $this->last_update_delete = null;
        $this->last_update_ctime = null;
        $this->last_update_size = null;
        $this->last_update_type = null;
    }

    /**
     * Record a file upsert into the index updates stream.
     */
    private function record_index_update_file(
        string $path,
        int $ctime,
        int $size,
        string $type
    ): void {
        if (!$this->index_updates_handle) {
            $this->begin_index_updates();
        }
        if (
            $this->last_update_path === $path &&
            $this->last_update_delete === false &&
            $this->last_update_ctime === $ctime &&
            $this->last_update_size === $size &&
            $this->last_update_type === $type
        ) {
            return;
        }
        $line = json_encode(
            [
                "op" => "F",
                "path" => base64_encode($path),
                "ctime" => $ctime,
                "size" => $size,
                "type" => $type,
            ],
            JSON_UNESCAPED_SLASHES,
        );
        if ($line !== false) {
            $bytes = fwrite($this->index_updates_handle, $line . "\n");
            if ($bytes === false) {
                throw new RuntimeException("Failed to write to index updates file (disk full?)");
            }
        }
        $this->index_updates_count++;
        $this->last_update_path = $path;
        $this->last_update_delete = false;
        $this->last_update_ctime = $ctime;
        $this->last_update_size = $size;
        $this->last_update_type = $type;
    }

    /**
     * Record a deletion into the index updates stream.
     */
    private function record_index_update_deletion(string $path): void
    {
        if (!$this->index_updates_handle) {
            $this->begin_index_updates();
        }
        if (
            $this->last_update_path === $path &&
            $this->last_update_delete === true
        ) {
            return;
        }
        $line = json_encode(
            [
                "op" => "D",
                "path" => base64_encode($path),
            ],
            JSON_UNESCAPED_SLASHES,
        );
        if ($line !== false) {
            $bytes = fwrite($this->index_updates_handle, $line . "\n");
            if ($bytes === false) {
                throw new RuntimeException("Failed to write to index updates file (disk full?)");
            }
        }
        $this->index_updates_count++;
        $this->last_update_path = $path;
        $this->last_update_delete = true;
        $this->last_update_ctime = null;
        $this->last_update_size = null;
        $this->last_update_type = null;
    }

    /**
     * Merge the collected updates with the existing sorted index without loading it into memory.
     */
    private function finalize_index_updates(): void
    {
        if ($this->index_updates_handle) {
            fclose($this->index_updates_handle);
            $this->index_updates_handle = null;
        }
        $this->last_update_path = null;
        $this->last_update_delete = null;
        $this->last_update_ctime = null;
        $this->last_update_size = null;
        $this->last_update_type = null;

        $has_updates =
            $this->index_updates_count > 0 ||
            ($this->index_updates_file &&
                file_exists($this->index_updates_file) &&
                filesize($this->index_updates_file) > 0);

        if (!$has_updates) {
            if (
                $this->index_updates_file &&
                file_exists($this->index_updates_file)
            ) {
                @unlink($this->index_updates_file);
                $this->audit_log(
                    "FILE DELETE | {$this->index_updates_file} | no updates to merge",
                );
            }
            $this->index_updates_count = 0;
            return;
        }

        $updates_path = $this->index_updates_file;
        $new_index = $this->index_file . ".new";

        $this->audit_log(
            "INDEX MERGE START | merging updates into {$this->index_file}",
        );

        $old_handle = file_exists($this->index_file)
            ? fopen($this->index_file, "r")
            : null;
        $upd_handle = fopen($updates_path, "r");
        $new_handle = fopen($new_index, "w");

        if (!$upd_handle || !$new_handle) {
            throw new RuntimeException("Failed to merge index updates");
        }

        $write_line = function ($handle, array $entry): void {
            $line = json_encode(
                [
                    "path" => base64_encode($entry["path"]),
                    "ctime" => (int) $entry["ctime"],
                    "size" => (int) $entry["size"],
                    "type" => (string) $entry["type"],
                ],
                JSON_UNESCAPED_SLASHES,
            );
            if ($line !== false) {
                fwrite($handle, $line . "\n");
            }
        };

        $old = $this->read_index_line($old_handle);
        $carry = null;
        $upd = $this->read_update_line($upd_handle, $carry);
        $last_written_path = null;

        while ($old !== null || $upd !== null) {
            if ($upd === null) {
                if ($last_written_path !== $old["path"]) {
                    $write_line($new_handle, $old);
                    $last_written_path = $old["path"];
                }
                $old = $this->read_index_line($old_handle);
                continue;
            }

            if ($old === null) {
                if (!$upd["delete"] && $last_written_path !== $upd["path"]) {
                    $write_line($new_handle, $upd);
                    $last_written_path = $upd["path"];
                }
                $upd = $this->read_update_line($upd_handle, $carry);
                continue;
            }

            $cmp = strcmp($old["path"], $upd["path"]);
            if ($cmp === 0) {
                if (!$upd["delete"] && $last_written_path !== $upd["path"]) {
                    $write_line($new_handle, $upd);
                    $last_written_path = $upd["path"];
                }
                $old = $this->read_index_line($old_handle);
                $upd = $this->read_update_line($upd_handle, $carry);
            } elseif ($cmp < 0) {
                if ($last_written_path !== $old["path"]) {
                    $write_line($new_handle, $old);
                    $last_written_path = $old["path"];
                }
                $old = $this->read_index_line($old_handle);
            } else {
                if (!$upd["delete"] && $last_written_path !== $upd["path"]) {
                    $write_line($new_handle, $upd);
                    $last_written_path = $upd["path"];
                }
                $upd = $this->read_update_line($upd_handle, $carry);
            }
        }

        if ($old_handle) {
            fclose($old_handle);
        }
        fclose($upd_handle);
        fclose($new_handle);

        if (!rename($new_index, $this->index_file)) {
            throw new RuntimeException("Failed to replace index file");
        }
        $this->audit_log("INDEX MERGE COMPLETE | {$this->index_file} updated");

        @unlink($updates_path);
        $this->audit_log("FILE DELETE | {$updates_path} | updates merged");
        $this->index_updates_count = 0;
    }

    /**
     * Read one JSON record from the on-disk index.
     */
    private function read_index_line($handle): ?array
    {
        if (!$handle) {
            return null;
        }
        while (($line = fgets($handle)) !== false) {
            $parsed = $this->parse_index_line($line);
            if ($parsed !== null) {
                return $parsed;
            }
        }
        return null;
    }

    /**
     * Read one raw update record (F/D) from the updates file.
     */
    private function read_update_line_raw($handle): ?array
    {
        if (!$handle) {
            return null;
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === "") {
                continue;
            }
            $data = json_decode($line, true);
            if (!is_array($data)) {
                throw new RuntimeException("Invalid index update line format");
            }
            $op = $data["op"] ?? null;
            $path_encoded = $data["path"] ?? null;
            if (!is_string($path_encoded) || $path_encoded === "") {
                throw new RuntimeException("Invalid index update path");
            }
            $path = base64_decode($path_encoded);
            if ($path === false || $path === "") {
                throw new RuntimeException("Invalid index update path (base64 decode failed)");
            }
            if ($op === "D") {
                return [
                    "path" => $path,
                    "delete" => true,
                    "ctime" => 0,
                    "size" => 0,
                    "type" => null,
                ];
            }
            if ($op === "F") {
                return [
                    "path" => $path,
                    "delete" => false,
                    "ctime" => (int) ($data["ctime"] ?? 0),
                    "size" => (int) ($data["size"] ?? 0),
                    "type" => (string) ($data["type"] ?? "file"),
                ];
            }
        }
        return null;
    }

    /**
     * Read one update record, coalescing consecutive updates to the same path.
     *
     * @param mixed $handle Update file handle
     * @param array|null $carry Read-ahead buffer for the next record
     */
    private function read_update_line($handle, ?array &$carry = null): ?array
    {
        if (!$handle) {
            return null;
        }
        $current = $carry ?? $this->read_update_line_raw($handle);
        $carry = null;
        if ($current === null) {
            return null;
        }

        while (true) {
            $next = $this->read_update_line_raw($handle);
            if ($next === null) {
                return $current;
            }
            if ($next["path"] !== $current["path"]) {
                $carry = $next;
                return $current;
            }
            // Same path: keep the latest update.
            $current = $next;
        }
    }
    /**
     * Download SQL from remote.
     */
    private function download_sql(): void
    {
        $cursor = $this->state["cursor"] ?? null;
        $complete = false;
        $mode = $this->sql_output_mode;

        // ── Set up write strategy based on output mode ──────────────

        $sql_handle = null;
        $mysql_conn = null;
        $wpdb = null;
        $buffer_handle = null;
        $sql_bytes_written = 0;
        $sql_buffer = "";

        if ($mode === "file") {
            $sql_file = $this->state_dir . "/db.sql";

            // Crash recovery: if SQL file is larger than expected, truncate it.
            // This happens if we crashed after writing but before saving the new cursor.
            $tracked_bytes = $this->state["sql_bytes"] ?? null;
            if ($tracked_bytes !== null && file_exists($sql_file)) {
                $actual_size = filesize($sql_file);
                if ($actual_size > $tracked_bytes) {
                    $this->audit_log(
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

            // Open in write mode if no cursor (starting fresh), append mode if resuming
            $sql_handle = fopen($sql_file, $cursor ? "a" : "w");
            if (!$sql_handle) {
                throw new RuntimeException("Cannot open SQL file: {$sql_file}");
            }

        } elseif ($mode === "stdout") {
            $sql_bytes_written = $this->state["sql_bytes"] ?? 0;

        } elseif ($mode === "mysql") {
            $sql_bytes_written = $this->state["sql_bytes"] ?? 0;

            $host = $this->mysql_host ?? "127.0.0.1";
            $user = $this->mysql_user ?? "root";
            $pass = $this->mysql_password ?? "";
            $name = $this->mysql_database;

            // Parse host for port/socket (same format as WordPress DB_HOST).
            // An explicit --mysql-port takes precedence over a port embedded
            // in the host string.
            $port = $this->mysql_port ?? 3306;
            $socket = null;
            if (strpos($host, ":") !== false) {
                list($host, $port_or_socket) = explode(":", $host, 2);
                if ($port_or_socket[0] === "/") {
                    $socket = $port_or_socket;
                } elseif ($this->mysql_port === null) {
                    $port = (int) $port_or_socket;
                }
            }

            $mysql_conn = new \mysqli($host, $user, $pass, $name, $port, $socket);
            if ($mysql_conn->connect_error) {
                throw new RuntimeException("MySQL connection failed: " . $mysql_conn->connect_error);
            }
            $mysql_conn->set_charset("utf8mb4");

            $this->audit_log(
                "SQL OUTPUT mysql | connected via multi_query(): {$user}@{$host}:{$port}/{$name}",
                true,
            );

            // Open a persistent buffer file so partial queries survive crashes.
            // Each SQL chunk is appended to this file as it arrives; when the
            // query completes and executes, the file is truncated. If the process
            // dies at any point, the next run reloads whatever was accumulated.
            $buffer_file = $this->state_dir . "/.sql-buffer";
            if (file_exists($buffer_file)) {
                $sql_buffer = file_get_contents($buffer_file);
                $this->audit_log(
                    sprintf("CRASH RECOVERY | Restored %d bytes from .sql-buffer", strlen($sql_buffer)),
                    true,
                );
            }
            // Open in write mode (truncate) if we loaded nothing, append if we
            // have a partial query to continue accumulating into.
            $buffer_handle = fopen($buffer_file, $sql_buffer !== "" ? "a" : "w");
            if (!$buffer_handle) {
                throw new RuntimeException("Cannot open SQL buffer file: {$buffer_file}");
            }

        } elseif ($mode === "wpdb") {
            $sql_bytes_written = $this->state["sql_bytes"] ?? 0;

            $wpdb = $this->load_wordpress();
            $wpdb->suppress_errors(true);
            $wpdb->show_errors(false);

            $this->audit_log(
                "SQL OUTPUT wpdb | connected via \$wpdb (wp-load: {$this->wp_load_path})",
                true,
            );

            // Use the same persistent buffer/crash-recovery strategy as mysql mode.
            $buffer_file = $this->state_dir . "/.sql-buffer";
            if (file_exists($buffer_file)) {
                $sql_buffer = file_get_contents($buffer_file);
                $this->audit_log(
                    sprintf("CRASH RECOVERY | Restored %d bytes from .sql-buffer", strlen($sql_buffer)),
                    true,
                );
            }
            $buffer_handle = fopen($buffer_file, $sql_buffer !== "" ? "a" : "w");
            if (!$buffer_handle) {
                throw new RuntimeException("Cannot open SQL buffer file: {$buffer_file}");
            }
        }

        // Domain discovery: scan SQL for URLs during download
        $query_stream = class_exists('WP_MySQL_Naive_Query_Stream')
            ? new \WP_MySQL_Naive_Query_Stream()
            : null;
        $domain_collector = class_exists('DomainCollector')
            ? new \DomainCollector()
            : null;
        $domains_file = $this->state_dir . "/.import-domains.json";

        // Auto-detect the source site domain from the export URL so it
        // always appears in .import-domains.json even if the SQL dump
        // hasn't been fully scanned yet.
        if ($domain_collector) {
            $parsed_url = parse_url($this->remote_url);
            if ($parsed_url && isset($parsed_url['scheme'], $parsed_url['host'])) {
                $source_origin = $parsed_url['scheme'] . '://' . $parsed_url['host'];
                if (!empty($parsed_url['port'])) {
                    $source_origin .= ':' . $parsed_url['port'];
                }
                $domain_collector->merge([$source_origin]);
            }
        }

        // Load previously discovered domains (from earlier partial downloads)
        if ($domain_collector && file_exists($domains_file)) {
            $prev = json_decode(file_get_contents($domains_file), true);
            if (is_array($prev)) {
                $domain_collector->merge($prev);
            }
        }

        // Log current progress at start of request
        $has_cursor = $cursor !== null;
        $this->audit_log(
            sprintf(
                "START SQL REQUEST | mode=%s | cursor=%s | bytes_written=%s",
                $mode,
                $has_cursor ? "YES" : "NO",
                number_format($sql_bytes_written) . " bytes",
            ),
            false,
        );

        try {
            while (!$complete) {
                $params = $this->get_tuned_params("sql_chunk");
                $url = $this->build_url("sql_chunk", $cursor, $params);

                $context = new StreamingContext();
                $context->chunk_fingerprints = [];
                $context->on_chunk = function ($chunk) use (
                    $mode,
                    &$cursor,
                    &$complete,
                    &$sql_handle,
                    $mysql_conn,
                    $wpdb,
                    &$buffer_handle,
                    &$sql_buffer,
                    &$sql_bytes_written,
                    $context,
                    $query_stream,
                    $domain_collector,
                    $domains_file
                ) {
                    // Check if shutdown was requested
                    if ($this->shutdown_requested) {
                        throw new RuntimeException("Shutdown requested");
                    }

                    // Allow signal handlers to run
                    if (function_exists("pcntl_signal_dispatch")) {
                        pcntl_signal_dispatch();
                    }

                    $cursor = $chunk["headers"]["x-cursor"] ?? $cursor;

                    // Save cursor periodically (every 50 chunks).
                    // Skip saving when there's buffered SQL waiting for a
                    // complete statement — crash recovery would replay the
                    // cursor but miss the buffered bytes.
                    $this->chunks_since_save++;
                    if (
                        $this->chunks_since_save >= self::SAVE_STATE_EVERY_N_CHUNKS
                        && $sql_buffer === ""
                    ) {
                        if ($sql_handle) {
                            fflush($sql_handle);
                        }
                        $this->state["cursor"] = $cursor;
                        $this->state["sql_bytes"] = $sql_bytes_written;
                        $this->save_state($this->state);
                        $this->chunks_since_save = 0;

                        // Also persist discovered domains so they survive crashes.
                        // On resume, the SQL download picks up from the cursor,
                        // skipping already-downloaded data — so domains from that
                        // earlier data would be lost without periodic saves.
                        if ($domain_collector) {
                            $domains = $domain_collector->get_domains();
                            if (!empty($domains)) {
                                file_put_contents(
                                    $domains_file,
                                    json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                                );
                            }
                        }
                    }

                    $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";

                    if ($chunk_type === "sql") {
                        $query_complete = ($chunk["headers"]["x-query-complete"] ?? "1") === "1";
                        $data = $chunk["body"];

                        switch ($mode) {
                            case "file":
                                $bytes = fwrite($sql_handle, $data);
                                if ($bytes === false || $bytes !== strlen($data)) {
                                    throw new RuntimeException(
                                        "SQL write failed: wrote " . ($bytes === false ? "0" : $bytes) .
                                        "/" . strlen($data) . " bytes (disk full?)"
                                    );
                                }
                                $sql_bytes_written += $bytes;
                                break;

                            case "stdout":
                                $bytes = @fwrite(STDOUT, $data);
                                if ($bytes === false) {
                                    // Broken pipe — save state and exit cleanly so the
                                    // pipe reader (e.g. `mysql`) can finish on its own.
                                    $this->save_state($this->state);
                                    exit(0);
                                }
                                $sql_bytes_written += $bytes;
                                break;

                            case "mysql":
                                // Append to disk immediately so the buffer survives
                                // even if the process is killed mid-chunk.
                                if ($buffer_handle) {
                                    fwrite($buffer_handle, $data);
                                    fflush($buffer_handle);
                                }

                                $sql_buffer .= $data;
                                $sql_bytes_written += strlen($data);

                                if ($query_complete) {
                                    if (!$mysql_conn->multi_query($sql_buffer)) {
                                        throw new RuntimeException("MySQL execution failed: " . $mysql_conn->error);
                                    }
                                    // Drain all result sets from multi_query before sending the
                                    // next chunk — mysqli requires this.
                                    do {
                                        $result = $mysql_conn->store_result();
                                        if ($result) { $result->free(); }
                                        if ($mysql_conn->errno) {
                                            throw new RuntimeException("MySQL statement error: " . $mysql_conn->error);
                                        }
                                    } while ($mysql_conn->more_results() && $mysql_conn->next_result());

                                    // Query executed — truncate the buffer file and reset.
                                    if ($buffer_handle) {
                                        ftruncate($buffer_handle, 0);
                                        rewind($buffer_handle);
                                    }
                                    $sql_buffer = "";
                                }
                                break;

                            case "wpdb":
                                // Same buffer/crash-recovery strategy as mysql mode,
                                // but execute through $wpdb->query() which routes
                                // through whatever database backend WordPress uses
                                // (MySQL or SQLite via sqlite-database-integration).
                                if ($buffer_handle) {
                                    fwrite($buffer_handle, $data);
                                    fflush($buffer_handle);
                                }

                                $sql_buffer .= $data;
                                $sql_bytes_written += strlen($data);

                                if ($query_complete && $sql_buffer !== "") {
                                    // $wpdb->query() handles one statement at a time,
                                    // so split the buffer using the query stream.
                                    $buf_stream = new \WP_MySQL_Naive_Query_Stream();
                                    $buf_stream->append_sql($sql_buffer);
                                    $buf_stream->mark_input_complete();
                                    while ($buf_stream->next_query()) {
                                        $q = $buf_stream->get_query();
                                        $result = $wpdb->query($q);
                                        if ($result === false && $wpdb->last_error) {
                                            throw new RuntimeException(
                                                "wpdb execution failed: " . $wpdb->last_error
                                            );
                                        }
                                    }

                                    if ($buffer_handle) {
                                        ftruncate($buffer_handle, 0);
                                        rewind($buffer_handle);
                                    }
                                    $sql_buffer = "";
                                }
                                break;
                        }

                        // Feed data to query stream for domain discovery
                        if ($query_stream && $domain_collector) {
                            $query_stream->append_sql($data);
                            $this->drain_query_stream_for_domains(
                                $query_stream,
                                $domain_collector,
                            );
                        }
                    } elseif ($chunk_type === "progress") {
                        $this->handle_progress($chunk, "sql");
                    } elseif ($chunk_type === "completion") {
                        $complete =
                            ($chunk["headers"]["x-status"] ?? "") ===
                            "complete";
                        $context->saw_completion = true;
                        $context->response_stats = [
                            "status" => $chunk["headers"]["x-status"] ?? null,
                            "sql_bytes" =>
                                isset($chunk["headers"]["x-sql-bytes"])
                                    ? (int) $chunk["headers"]["x-sql-bytes"]
                                    : null,
                            "server_time" =>
                                isset($chunk["headers"]["x-time-elapsed"])
                                    ? (float) $chunk["headers"]["x-time-elapsed"]
                                    : null,
                            "memory_used" =>
                                isset($chunk["headers"]["x-memory-used"])
                                    ? (int) $chunk["headers"]["x-memory-used"]
                                    : null,
                            "memory_limit" =>
                                isset($chunk["headers"]["x-memory-limit"])
                                    ? (int) $chunk["headers"]["x-memory-limit"]
                                    : null,
                        ];
                        $this->output_progress(
                            [
                                "phase" => "sql",
                                "status" =>
                                    $chunk["headers"]["x-status"] ?? "unknown",
                                "batches_processed" =>
                                    (int) ($chunk["headers"][
                                        "x-batches-processed"
                                    ] ?? 0),
                            ],
                            true,
                        );
                    } elseif ($chunk_type === "error") {
                        $this->handle_error_chunk($chunk, "db-index", $context);
                    }
                };

                $request_start = microtime(true);
                $this->fetch_streaming($url, $cursor, $context, null, "sql_chunk");
                $wall_time = microtime(true) - $request_start;
                $this->finalize_tuned_request(
                    "sql_chunk",
                    $wall_time,
                    $context->response_stats ?? [],
                );

                // Save cursor for resumption (keep it even when complete for reference)
                if ($sql_handle) {
                    fflush($sql_handle);
                }

                $this->state["cursor"] = $cursor;
                // Clear sql_bytes when complete, otherwise save current position
                $this->state["sql_bytes"] = $complete ? null : $sql_bytes_written;
                $this->save_state($this->state);
            }

            // Drain any remaining statements after download completes
            if ($query_stream && $domain_collector) {
                $query_stream->mark_input_complete();
                $this->drain_query_stream_for_domains(
                    $query_stream,
                    $domain_collector,
                );

                // Save discovered domains
                $domains = $domain_collector->get_domains();
                if (!empty($domains)) {
                    file_put_contents(
                        $domains_file,
                        json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                    );
                    $this->audit_log(
                        sprintf(
                            "DOMAINS DISCOVERED | %d unique domains saved to .import-domains.json",
                            count($domains),
                        ),
                        false,
                    );
                }
            }
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
                // Clean up buffer file — if we got here with an empty buffer,
                // all queries were executed successfully.
                $buffer_file = $this->state_dir . "/.sql-buffer";
                if ($pending === "" && file_exists($buffer_file)) {
                    unlink($buffer_file);
                }
                if ($pending !== "") {
                    throw new RuntimeException(
                        "Buffered SQL was never executed (" . strlen($pending) .
                        " bytes) — incomplete export?"
                    );
                }
            }
            // wpdb mode: same buffer cleanup logic as mysql mode.
            if ($wpdb && !$mysql_conn) {
                $pending = $sql_buffer;
                $buffer_file = $this->state_dir . "/.sql-buffer";
                if ($pending === "" && file_exists($buffer_file)) {
                    unlink($buffer_file);
                }
                if ($pending !== "") {
                    throw new RuntimeException(
                        "Buffered SQL was never executed (" . strlen($pending) .
                        " bytes) — incomplete export?"
                    );
                }
            }
        }
    }

    /**
     * Drain complete SQL statements from a query stream and scan their
     * base64-decoded values for URL domains.
     */
    private function drain_query_stream_for_domains(
        \WP_MySQL_Naive_Query_Stream $query_stream,
        \DomainCollector $domain_collector
    ) {
        while ($query_stream->next_query()) {
            $query = $query_stream->get_query();
            // Only scan INSERT statements (they contain data values).
            if (!self::sql_starts_with_token($query, \WP_MySQL_Lexer::INSERT_SYMBOL)) {
                continue;
            }
            // Only scan statements with base64 values
            if (strpos($query, "FROM_BASE64(") === false) {
                continue;
            }

            $table = self::extract_insert_table($query);
            $is_options_table = substr($table, -8) === '_options';

            $scanner = new \Base64ValueScanner($query);
            while ($scanner->next_value()) {
                // For _options tables, extract the option_name (second column)
                // and skip transients — they contain ephemeral cached data
                // that would pollute the domain list.
                $option_name = null;
                $match_offset = $scanner->get_match_offset();
                if ($is_options_table) {
                    $option_name = self::extract_option_name($query, $match_offset);
                    if ($option_name !== null && (
                        strpos($option_name, '_transient') === 0 ||
                        strpos($option_name, '_site_transient') === 0
                    )) {
                        continue;
                    }
                }

                $new_domains = $domain_collector->scan($scanner->get_value());
                if (!empty($new_domains)) {
                    $row_id = self::extract_row_identifier($query, $match_offset);

                    $option_ctx = '';
                    if ($option_name !== null) {
                        $option_ctx = ' option=' . $option_name;
                    }

                    foreach ($new_domains as $domain) {
                        $this->audit_log(
                            sprintf(
                                "NEW DOMAIN | %s | table=%s %s%s",
                                $domain,
                                $table,
                                $row_id,
                                $option_ctx,
                            ),
                            false,
                        );
                    }
                }
            }
        }
    }

    /**
     * Extract the table name from an INSERT INTO statement.
     */
    private static function extract_insert_table(string $query): string
    {
        if (preg_match('/INSERT\s+INTO\s+`([^`]+)`/i', $query, $m)) {
            return $m[1];
        }
        return '?';
    }

    /**
     * Extract a row identifier (PK value or offset) from the INSERT row
     * containing the base64 expression at $offset.
     *
     * Scans backwards from $offset to find the row-opening parenthesis,
     * then reads the first column value — typically the primary key.
     */
    private static function extract_row_identifier(string $query, int $offset): string
    {
        // Walk backwards from the match to find the row-opening '('.
        // Track parenthesis depth so we skip inner '(' from FROM_BASE64()
        // and CONVERT() wrappers.
        $depth = 0;
        $row_start = -1;
        for ($i = $offset - 1; $i >= 0; $i--) {
            $ch = $query[$i];
            if ($ch === ')') {
                $depth++;
            } elseif ($ch === '(') {
                if ($depth === 0) {
                    $row_start = $i + 1;
                    break;
                }
                $depth--;
            }
        }

        if ($row_start < 0) {
            return 'offset=?';
        }

        // Read the first value after the row-opening '('.
        // Numeric PKs: (123, ...  or (-5, ...
        $after = substr($query, $row_start, 40);
        if (preg_match('/^(-?\d+)/', $after, $m)) {
            return 'pk=' . $m[1];
        }
        // String PKs: ('some-uuid', ...
        if (preg_match("/^'([^']{0,30})'/", $after, $m)) {
            return "pk=" . $m[1];
        }
        if (preg_match('/^NULL/i', $after)) {
            return 'pk=NULL';
        }

        return 'offset=?';
    }

    /**
     * Extract the option_name (second column) from a wp_options INSERT row.
     *
     * WordPress options tables have columns: option_id, option_name, option_value, autoload.
     * Given an offset inside the row, this finds the row-opening '(' and reads
     * past the first column (option_id) to extract the second column (option_name).
     */
    private static function extract_option_name(string $query, int $offset): ?string
    {
        // Find the row-opening '(' by walking backwards, same as extract_row_identifier.
        $depth = 0;
        $row_start = -1;
        for ($i = $offset - 1; $i >= 0; $i--) {
            $ch = $query[$i];
            if ($ch === ')') {
                $depth++;
            } elseif ($ch === '(') {
                if ($depth === 0) {
                    $row_start = $i + 1;
                    break;
                }
                $depth--;
            }
        }

        if ($row_start < 0) {
            return null;
        }

        // Skip the first column value (option_id) and the comma separator,
        // then read the second column value (option_name) which is a quoted string.
        $after = substr($query, $row_start, 200);
        // First column is typically a number: "123," or could be FROM_BASE64(...)
        // Skip to the first comma that's outside parentheses.
        $len = strlen($after);
        $d = 0;
        $comma_pos = -1;
        for ($j = 0; $j < $len; $j++) {
            $c = $after[$j];
            if ($c === '(') { $d++; }
            elseif ($c === ')') { $d--; }
            elseif ($c === ',' && $d === 0) {
                $comma_pos = $j;
                break;
            }
        }

        if ($comma_pos < 0) {
            return null;
        }

        // After the comma, skip whitespace and read a quoted string or FROM_BASE64(...)
        $rest = ltrim(substr($after, $comma_pos + 1));
        // Simple quoted string: 'option_name'
        if (isset($rest[0]) && $rest[0] === "'") {
            if (preg_match("/^'([^']{0,80})'/", $rest, $m)) {
                return $m[1];
            }
        }
        // FROM_BASE64('...') wrapped value — decode it
        if (strpos($rest, 'FROM_BASE64(') === 0) {
            if (preg_match("/^FROM_BASE64\\('([A-Za-z0-9+\\/=]+)'\\)/", $rest, $m)) {
                $decoded = base64_decode($m[1], true);
                if ($decoded !== false) {
                    return substr($decoded, 0, 80);
                }
            }
        }

        return null;
    }

    /**
     * Check whether a SQL statement's first keyword token matches a given token ID.
     * Skips leading whitespace and comments, so "/* ... *​/ INSERT INTO ..." is handled.
     */
    private static function sql_starts_with_token(string $sql, int $expected_token_id): bool
    {
        $lexer = new \WP_MySQL_Lexer($sql);
        while ($lexer->next_token()) {
            $token = $lexer->get_token();
            if (
                $token->id === \WP_MySQL_Lexer::WHITESPACE
                || $token->id === \WP_MySQL_Lexer::COMMENT
                || $token->id === \WP_MySQL_Lexer::MYSQL_COMMENT_START
                || $token->id === \WP_MySQL_Lexer::MYSQL_COMMENT_END
            ) {
                continue;
            }
            return $token->id === $expected_token_id;
        }
        return false;
    }

    /**
     * Download table stats from the db_index endpoint.
     */
    private function download_db_index(): void
    {
        $cursor = $this->state["cursor"] ?? null;
        $complete = false;
        $tables_file = $this->state_dir . "/db-tables.jsonl";

        $stats = $this->state["db_index"] ?? [];
        $tables_written = (int) ($stats["tables"] ?? 0);
        $rows_estimated = (int) ($stats["rows_estimated"] ?? 0);
        $bytes_written = (int) ($stats["bytes"] ?? 0);

        if ($bytes_written > 0 && file_exists($tables_file)) {
            $actual_size = filesize($tables_file);
            if ($actual_size > $bytes_written) {
                $this->audit_log(
                    sprintf(
                        "CRASH RECOVERY | Truncating db-tables.jsonl from %d to %d bytes",
                        $actual_size,
                        $bytes_written,
                    ),
                    true,
                );
                $handle = fopen($tables_file, "r+");
                if ($handle) {
                    ftruncate($handle, $bytes_written);
                    fclose($handle);
                }
            }
        }

        $handle = fopen($tables_file, $cursor ? "a" : "w");
        if (!$handle) {
            throw new RuntimeException("Cannot open table stats file: {$tables_file}");
        }

        try {
            while (!$complete) {
                $params = [
                    "tables_per_batch" => 1000,
                ];
                $url = $this->build_url("db_index", $cursor, $params);

                $context = new StreamingContext();
                $context->on_chunk = function ($chunk) use (
                    &$cursor,
                    &$complete,
                    &$tables_written,
                    &$rows_estimated,
                    &$bytes_written,
                    $handle,
                    $context
                ) {
                    if ($this->shutdown_requested) {
                        throw new RuntimeException("Shutdown requested");
                    }
                    if (function_exists("pcntl_signal_dispatch")) {
                        pcntl_signal_dispatch();
                    }

                    $cursor = $chunk["headers"]["x-cursor"] ?? $cursor;

                    $chunk_type = $chunk["headers"]["x-chunk-type"] ?? "";
                    if ($chunk_type === "table_stats") {
                        $data = json_decode($chunk["body"], true);
                        if (is_array($data)) {
                            foreach ($data as $row) {
                                $line = json_encode($row) . "\n";
                                $bytes = fwrite($handle, $line);
                                if ($bytes === false || $bytes !== strlen($line)) {
                                    throw new RuntimeException(
                                        "Table stats write failed: wrote " . ($bytes === false ? "0" : $bytes) .
                                        "/" . strlen($line) . " bytes (disk full?)"
                                    );
                                }
                                $bytes_written += $bytes;
                                $tables_written++;
                                if (
                                    isset($row["rows"]) &&
                                    is_numeric($row["rows"])
                                ) {
                                    $rows_estimated += (int) $row["rows"];
                                }
                            }
                        }
                    } elseif ($chunk_type === "progress") {
                        $this->handle_progress($chunk, "db-index");
                    } elseif ($chunk_type === "completion") {
                        $complete =
                            ($chunk["headers"]["x-status"] ?? "") ===
                            "complete";
                        $context->saw_completion = true;
                        $context->response_stats = [
                            "status" => $chunk["headers"]["x-status"] ?? null,
                            "tables_processed" =>
                                isset($chunk["headers"]["x-tables-processed"])
                                    ? (int) $chunk["headers"]["x-tables-processed"]
                                    : null,
                            "rows_estimated" =>
                                isset($chunk["headers"]["x-rows-estimated"])
                                    ? (int) $chunk["headers"]["x-rows-estimated"]
                                    : null,
                            "server_time" =>
                                isset($chunk["headers"]["x-time-elapsed"])
                                    ? (float) $chunk["headers"]["x-time-elapsed"]
                                    : null,
                            "memory_used" =>
                                isset($chunk["headers"]["x-memory-used"])
                                    ? (int) $chunk["headers"]["x-memory-used"]
                                    : null,
                            "memory_limit" =>
                                isset($chunk["headers"]["x-memory-limit"])
                                    ? (int) $chunk["headers"]["x-memory-limit"]
                                    : null,
                        ];
                        $this->output_progress(
                            [
                                "phase" => "db-index",
                                "status" =>
                                    $chunk["headers"]["x-status"] ?? "unknown",
                                "tables_processed" =>
                                    (int) ($chunk["headers"][
                                        "x-tables-processed"
                                    ] ?? 0),
                            ],
                            true,
                        );
                    } elseif ($chunk_type === "error") {
                        $this->handle_error_chunk($chunk, "sql", $context);
                    }
                };

                $request_start = microtime(true);
                $this->fetch_streaming(
                    $url,
                    $cursor,
                    $context,
                    null,
                    "db_index",
                );
                $wall_time = microtime(true) - $request_start;
                $this->finalize_tuned_request(
                    "db_index",
                    $wall_time,
                    $context->response_stats ?? [],
                );

                fflush($handle);
                $this->state["cursor"] = $cursor;
                $this->state["db_index"] = [
                    "file" => $tables_file,
                    "tables" => $tables_written,
                    "rows_estimated" => $rows_estimated,
                    "bytes" => $bytes_written,
                    "updated_at" => time(),
                ];
                $this->save_state($this->state);
            }
        } finally {
            fclose($handle);
        }
    }


    /**
     * Assert that a symlink target resolves to a path within $root.
     *
     * For absolute targets, the target itself must be under $root.
     * For relative targets, the resolved path (parent dir + target) must be
     * under $root. We normalize ".." segments without touching the filesystem,
     * since the target may not exist yet.
     *
     * @throws RuntimeException if the target escapes the root.
     */
    private function assert_symlink_target_within_root(
        string $symlink_parent_dir,
        string $target,
        string $root
    ): void {
        if (str_starts_with($target, "/")) {
            // Absolute target: must be under root
            $resolved = normalize_path($target);
        } else {
            // Relative target: resolve against the symlink's parent directory
            $resolved = normalize_path($symlink_parent_dir . "/" . $target);
        }

        if (!path_is_within_root($resolved, $root)) {
            throw new RuntimeException(
                "Security: symlink target escapes filesystem root: {$target} " .
                "(resolves to {$resolved}, root is {$root})"
            );
        }
    }

    /**
     * Return canonical docroot path, creating it if it doesn't exist.
     */
    private function get_filesystem_root_path(): string
    {
        if (!is_dir($this->docroot)) {
            if (!mkdir($this->docroot, 0755, true) && !is_dir($this->docroot)) {
                throw new RuntimeException(
                    "Failed to create docroot directory: {$this->docroot}",
                );
            }
        }

        $real = realpath($this->docroot);
        if ($real === false) {
            throw new RuntimeException(
                "Failed to resolve docroot path: {$this->docroot}",
            );
        }

        return $real;
    }


    /**
     * Resolve a remote absolute path into a local path under the docroot.
     *
     * Maps a remote absolute path (e.g. "/wp-content/uploads/photo.jpg") to a
     * local path under the import docroot. Performs symlink traversal security
     * checks to prevent directory traversal attacks that could write files
     * outside the import root.
     */
    private function remote_path_to_local_path_within_import_root(
        string $path
    ): string {
        assert_valid_path($path, "remote path");
        return $this->get_filesystem_root_path() . $path;
    }

    /**
     * Handle a metadata chunk from multipart response.
     */
    private function handle_metadata_chunk(
        array $chunk,
        StreamingContext $context
    ): void {
        $headers = $chunk["headers"];
        $filesystem_root = base64_decode($headers["x-filesystem-root"] ?? "", true);

        if ($filesystem_root) {
            $context->filesystem_root = $filesystem_root;
            $this->audit_log("Filesystem root: {$filesystem_root}", false);
        }
    }

    /**
     * Handle a file chunk from multipart response.
     */
    private function handle_file_chunk(
        array $chunk,
        StreamingContext $context
    ): void {
        $headers = $chunk["headers"];
        $raw_header = $headers["x-file-path"] ?? "";
        $path = base64_decode($raw_header, true);
        $is_first = ($headers["x-first-chunk"] ?? "0") === "1";
        $is_last = ($headers["x-last-chunk"] ?? "0") === "1";

        if ($path === false || $path === "") {
            if ($raw_header !== "") {
                $this->audit_log(
                    "Warning: base64_decode failed for x-file-path header: " .
                        substr($raw_header, 0, 100),
                    true,
                );
            }
            return;
        }

        $local_path = $this->remote_path_to_local_path_within_import_root($path);

        // Open file on first chunk
        if ($is_first) {
            // Reset skip flag for each new file
            $context->skip_current_file = false;

            if (
                (file_exists($local_path) || is_link($local_path)) &&
                (!is_file($local_path) || is_link($local_path))
            ) {
                if (
                    !$this->remove_local_path_without_following_symlinks(
                        $local_path
                    )
                ) {
                    throw new RuntimeException(
                        "Failed to replace path with file: {$path}",
                    );
                }
            }

            // Check if file exists locally
            $exists_locally = file_exists($local_path);
            $local_size = $exists_locally ? filesize($local_path) : 0;
            $file_size = (int) ($headers["x-file-size"] ?? 0);

            // Log file import with useful context
            $this->audit_log(
                sprintf(
                    "File: %s (remote_size=%d, ctime=%d, local_exists=%s, local_size=%d)",
                    $path,
                    $file_size,
                    (int) ($headers["x-file-ctime"] ?? 0),
                    $exists_locally ? "yes" : "no",
                    $local_size,
                ),
                false,
            );

            $this->show_progress_line(
                sprintf("[%d files] %s", $this->files_imported, $this->display_path($path)),
            );
        }

        // Skip body/close for files being preserved
        if ($context->skip_current_file) {
            return;
        }

        // Open file handle on first chunk
        if ($is_first) {
            // Close previous file if any
            if ($context->file_handle) {
                fclose($context->file_handle);
                if ($context->file_ctime && $context->file_path) {
                    touch($context->file_path, $context->file_ctime);
                }
            }

            // Create parent directory if needed
            $dir = dirname($local_path);
            if (!is_dir($dir)) {
                // Check if any component of the path exists as a file and remove it
                try {
                    $this->ensure_directory_path($dir);
                } catch (PreserveLocalSkipException $e) {
                    $context->skip_current_file = true;
                    $this->audit_log($e->getMessage(), true);
                    $this->show_progress_line("[skip] " . $this->display_path($path));
                    return;
                }
            }

            // Open new file
            $context->file_handle = fopen($local_path, "wb");
            if (!$context->file_handle) {
                $error = error_get_last();
                throw new RuntimeException(
                    "Failed to open file for writing: {$local_path}\n" .
                        "Parent directory: {$dir}\n" .
                        "Directory exists: " .
                        (is_dir($dir) ? "yes" : "no") .
                        "\n" .
                        "Error: " .
                        ($error["message"] ?? "unknown"),
                );
            }
            $context->file_path = $local_path;
            $context->file_ctime = (int) ($headers["x-file-ctime"] ?? 0);
            $context->file_bytes_written = 0;  // Reset byte counter for new file
        }

        // Write body data if present
        if (isset($chunk["body"]) && $chunk["body"] !== "") {
            if ($context->file_handle) {
                $data = $chunk["body"];
                $bytes = fwrite($context->file_handle, $data);
                if ($bytes === false || $bytes !== strlen($data)) {
                    throw new RuntimeException(
                        "Write failed for {$context->file_path}: wrote " .
                        ($bytes === false ? "0" : $bytes) . "/" . strlen($data) .
                        " bytes (disk full?)"
                    );
                }
                $context->file_bytes_written += $bytes;
            }
        }

        // Close on last chunk
        if ($is_last && $context->file_handle) {
            fclose($context->file_handle);

            // Set file modification time
            if ($context->file_ctime && $context->file_path) {
                touch($context->file_path, $context->file_ctime);
            }

            // Index update (JSON lines)
            $file_size = (int) ($headers["x-file-size"] ?? 0);
            $final_size = file_exists($context->file_path)
                ? filesize($context->file_path)
                : 0;

            $file_changed = ($headers["x-file-changed"] ?? "0") === "1";

            if ($context->file_ctime && !$file_changed) {
                $this->upsert_index_entry(
                    $path,
                    $context->file_ctime,
                    $file_size,
                    "file",
                );
                $this->files_imported++; // Count completed files only
                $this->clear_volatile_file($path);
                $this->audit_log(
                    sprintf("  Indexed (wrote %d bytes)", $final_size),
                    false,
                );
            } elseif ($file_changed) {
                $this->audit_log(
                    "  File changed during stream; index not updated",
                    true,
                );
            }

            $context->file_handle = null;
            $context->file_path = null;
            $context->file_ctime = null;
            $context->file_bytes_written = 0;
            // Clear crash recovery tracking - file is complete
            $this->state["current_file"] = null;
            $this->state["current_file_bytes"] = null;
        }
    }

    /**
     * Build a short display path for progress messages: strip leading slash,
     * truncate from the left when too long.
     */
    private function display_path(string $path): string
    {
        $rel = ltrim($path, "/");
        $max = 60;
        if (strlen($rel) > $max) {
            $rel = "..." . substr($rel, -($max - 3));
        }
        return $rel;
    }

    /**
     * Check whether any component of the path (between the filesystem root
     * and the target) is a symlink.  In preserve-local mode this is used
     * to prevent creating new content through symlinked directories — their
     * contents belong to shared hosting infrastructure and must not be
     * modified.
     */
    private function should_skip_for_preserve_local(string $path): ?string
    {
        if ($this->docroot_nonempty_behavior !== 'preserve-local') {
            return null;
        }

        $local_path = $this->remote_path_to_local_path_within_import_root($path);

        // Skip if anything already exists at this path — regular file, symlink
        // (even to a file), or directory.  This preserves hosting symlinks like
        // wp-load.php -> __wp__/wp-load.php and drop-in symlinks like
        // object-cache.php -> ../../wordpress/drop-ins/...
        if (file_exists($local_path) || is_link($local_path)) {
            return "PRESERVE-LOCAL skip file (exists): {$path}";
        }

        // Skip if parent directory is not writable or if any directory component
        // in the path is a symlink.  We never create new files through symlinks —
        // the symlink and its target contents are shared hosting infrastructure.
        $dir = dirname($local_path);
        if (is_dir($dir) && !is_writable($dir)) {
            return "PRESERVE-LOCAL skip file (dir not writable): {$path}";
        }
        if ($this->path_traverses_symlink($dir)) {
            return "PRESERVE-LOCAL skip file (symlink in path): {$path}";
        }

        return null;
    }

    private function path_traverses_symlink(string $path): bool
    {
        $root = $this->get_filesystem_root_path();
        $relative = ltrim(substr($path, strlen($root)), "/");
        if ($relative === "") {
            return false;
        }

        $current = $root;
        foreach (explode("/", $relative) as $part) {
            if ($part === "") {
                continue;
            }
            $current .= "/" . $part;
            if (is_link($current)) {
                return true;
            }
            if (!file_exists($current)) {
                break;
            }
        }
        return false;
    }

    /**
     * Ensure a directory path exists, removing any files that block it.
     *
     * @param string $dir Directory path to ensure
     * @throws RuntimeException if directory cannot be created or is outside allowed path
     */
    private function ensure_directory_path(string $dir): void
    {
        // Security: Ensure path is under the docroot
        $real_filesystem_root = $this->get_filesystem_root_path();

        // Resolve the target path (or what it would be)
        // For non-existent paths, resolve the parent and append the final component
        $check_path = $dir;
        while (
            !file_exists($check_path) &&
            $check_path !== dirname($check_path)
        ) {
            $check_path = dirname($check_path);
        }

        if (file_exists($check_path)) {
            $real_check = realpath($check_path);
            if (
                $real_check === false ||
                !path_is_within_root($real_check, $real_filesystem_root)
            ) {
                // In preserve-local mode, a path that resolves outside the
                // docroot is expected when a directory like wp-content/plugins
                // is symlinked to a shared hosting location.  Skip gracefully
                // instead of treating it as a security violation.
                if ($this->docroot_nonempty_behavior === 'preserve-local') {
                    throw new PreserveLocalSkipException(
                        "PRESERVE-LOCAL: path resolves outside docroot via symlink: {$dir}",
                    );
                }
                throw new RuntimeException(
                    "Security: Refusing to create directory outside docroot: {$dir}",
                );
            }
        }

        if (is_dir($dir) && !is_link($dir)) {
            if ($this->docroot_nonempty_behavior === 'preserve-local' && !is_writable($dir)) {
                throw new PreserveLocalSkipException(
                    "PRESERVE-LOCAL: directory not writable: {$dir}",
                );
            }
            return;
        }

        if (
            $dir !== $real_filesystem_root &&
            !str_starts_with($dir, $real_filesystem_root . "/")
        ) {
            throw new RuntimeException(
                "Security: Refusing to create directory outside docroot: {$dir}",
            );
        }

        $relative = ltrim(substr($dir, strlen($real_filesystem_root)), "/");
        if ($relative === "") {
            return;
        }

        $current = $real_filesystem_root;
        foreach (explode("/", $relative) as $part) {
            if ($part === "") {
                continue;
            }
            $current .= "/" . $part;

            if (is_link($current)) {
                if ($this->docroot_nonempty_behavior === 'preserve-local') {
                    // Never create directories through symlinks — the symlink
                    // and its target contents are shared hosting infrastructure
                    // that must not be modified.
                    throw new PreserveLocalSkipException(
                        "PRESERVE-LOCAL: symlink in directory path: {$current}",
                    );
                }
                $this->audit_log(
                    "Removing symlink blocking directory: {$current}",
                    true,
                );
                if (!unlink($current)) {
                    throw new RuntimeException(
                        "Failed to remove symlink blocking directory: {$current}",
                    );
                }
                // Clear cached realpath so the subsequent realpath() check
                // sees the new directory instead of the removed symlink.
                clearstatcache(true, $current);
            }

            // Remove file if blocking directory creation
            if (is_file($current)) {
                if ($this->docroot_nonempty_behavior === 'preserve-local') {
                    throw new PreserveLocalSkipException(
                        "PRESERVE-LOCAL: file blocks directory creation: {$current}",
                    );
                }
                $this->audit_log(
                    "Removing file blocking directory: {$current}",
                    true,
                );
                if (!unlink($current)) {
                    throw new RuntimeException(
                        "Failed to remove file blocking directory: {$current}",
                    );
                }
            }

            // Create directory if it doesn't exist
            if (is_dir($current)) {
                if ($this->docroot_nonempty_behavior === 'preserve-local' && !is_writable($current)) {
                    throw new PreserveLocalSkipException(
                        "PRESERVE-LOCAL: directory not writable: {$current}",
                    );
                }
            } elseif (!mkdir($current, 0755) && !is_dir($current)) {
                throw new RuntimeException(
                    "Failed to create directory: {$current}\n" .
                        "Error: " .
                        (error_get_last()["message"] ?? "unknown"),
                );
            }

            $resolved = realpath($current);
            if ($resolved === false || !path_is_within_root($resolved, $real_filesystem_root)) {
                throw new RuntimeException(
                    "Security: Refusing to create directory outside docroot: {$current}",
                );
            }
        }
    }

    /**
     * Handle a directory chunk (create empty directory).
     */
    private function handle_directory_chunk(array $chunk): void
    {
        $headers = $chunk["headers"];
        $raw_header = $headers["x-directory-path"] ?? "";
        $path = base64_decode($raw_header, true);
        $ctime = (int) ($headers["x-directory-ctime"] ?? 0);

        if ($path === false || $path === "") {
            if ($raw_header !== "") {
                $this->audit_log(
                    "Warning: base64_decode failed for x-directory-path header: " .
                        substr($raw_header, 0, 100),
                    true,
                );
            }
            return;
        }

        $local_path = $this->remote_path_to_local_path_within_import_root($path);

        // In preserve-local mode, if the directory already exists (as a real
        // directory or via a symlink to a directory), keep it as-is.
        // Also skip if any parent component is a symlink — we never create
        // new directories through symlinked paths.
        if ($this->docroot_nonempty_behavior === 'preserve-local') {
            if (is_dir($local_path)) {
                $this->audit_log("PRESERVE-LOCAL skip directory (exists): {$path}", true);
                $this->show_progress_line("[skip] " . $this->display_path($path));
                if ($ctime > 0) {
                    $this->upsert_index_entry($path, $ctime, 0, "dir");
                }
                return;
            }
            if ($this->path_traverses_symlink($local_path)) {
                $this->audit_log("PRESERVE-LOCAL skip directory (symlink in path): {$path}", true);
                $this->show_progress_line("[skip] " . $this->display_path($path));
                if ($ctime > 0) {
                    $this->upsert_index_entry($path, $ctime, 0, "dir");
                }
                return;
            }
        }

        if (
            (file_exists($local_path) || is_link($local_path)) &&
            (!is_dir($local_path) || is_link($local_path))
        ) {
            if (
                !$this->remove_local_path_without_following_symlinks($local_path)
            ) {
                throw new RuntimeException(
                    "Failed to replace path with directory: {$path}",
                );
            }
        }

        // Create directory, removing any files that block the path
        try {
            $this->ensure_directory_path($local_path);
        } catch (PreserveLocalSkipException $e) {
            $this->audit_log($e->getMessage(), true);
            $this->show_progress_line("[skip] " . $this->display_path($path));
            return;
        }

        $this->audit_log("Directory: {$path}", false);

        if ($ctime > 0) {
            $this->upsert_index_entry($path, $ctime, 0, "dir");
        }
    }

    /**
     * Recreates a symlink from the export stream in the local filesystem.
     *
     * Decodes the base64-encoded path and target from the chunk headers,
     * validates that the target stays within the filesystem root (preventing
     * directory traversal), then creates the symlink.  Failures are logged
     * to the audit log and reported as symlink_error progress events — they
     * do not halt the import.
     *
     * @param array $chunk Multipart chunk with x-symlink-path, x-symlink-target,
     *                     and x-symlink-ctime headers (all base64-encoded).
     */
    private function handle_symlink_chunk(array $chunk): void
    {
        $headers = $chunk["headers"];
        $raw_path = $headers["x-symlink-path"] ?? "";
        $path = base64_decode($raw_path, true);
        $target = base64_decode($headers["x-symlink-target"] ?? "", true);
        $ctime = (int) ($headers["x-symlink-ctime"] ?? 0);

        // Skip if path or target is missing/empty
        if ($path === false || $path === "" || $target === false || $target === "") {
            if ($raw_path !== "" && ($path === false || $path === "")) {
                $this->audit_log(
                    "Warning: base64_decode failed for x-symlink-path header: " .
                        substr($raw_path, 0, 100),
                    true,
                );
            }
            return;
        }

        $local_path = $this->remote_path_to_local_path_within_import_root($path);

        // In preserve-local mode, if something already exists at the symlink
        // path, keep it — whether it's a file, directory, or another symlink.
        // Also skip if any parent component is a symlink — we never create
        // new content through symlinked directories.
        if ($this->docroot_nonempty_behavior === 'preserve-local') {
            if (file_exists($local_path) || is_link($local_path)) {
                $this->audit_log("PRESERVE-LOCAL skip symlink (path exists): {$path} -> {$target}", true);
                $this->show_progress_line("[skip] " . $this->display_path($path));
                return;
            }
            if ($this->path_traverses_symlink(dirname($local_path))) {
                $this->audit_log("PRESERVE-LOCAL skip symlink (symlink in path): {$path} -> {$target}", true);
                $this->show_progress_line("[skip] " . $this->display_path($path));
                return;
            }
        }

        // Validate that the symlink target doesn't escape the filesystem root.
        $root = $this->get_filesystem_root_path();
        try {
            $this->assert_symlink_target_within_root(
                dirname($local_path),
                $target,
                $root
            );
        } catch (RuntimeException $e) {
            $this->audit_log($e->getMessage(), true);
            $this->output_progress([
                "type" => "symlink_error",
                "path" => $path,
                "target" => $target,
                "error" => $e->getMessage(),
            ]);
            return;
        }

        // Remove existing file/symlink if present
        if (file_exists($local_path) || is_link($local_path)) {
            if (
                !$this->remove_local_path_without_following_symlinks($local_path)
            ) {
                $this->audit_log(
                    "Failed to remove existing path for symlink: {$local_path}",
                    true,
                );
                $this->output_progress([
                    "type" => "symlink_error",
                    "path" => $path,
                    "target" => $target,
                    "error" => "Failed to replace existing path",
                ]);
                return;
            }
        }

        // Create parent directory
        $dir = dirname($local_path);
        if (!is_dir($dir)) {
            try {
                $this->ensure_directory_path($dir);
            } catch (PreserveLocalSkipException $e) {
                $this->audit_log($e->getMessage(), true);
                $this->show_progress_line("[skip] " . $this->display_path($path));
                return;
            } catch (RuntimeException $e) {
                // Log error and skip this symlink
                $this->audit_log(
                    "Failed to create directory for symlink: {$dir}",
                    true,
                );
                $this->output_progress([
                    "type" => "symlink_error",
                    "path" => $path,
                    "target" => $target,
                    "error" => "Failed to create parent directory",
                ]);
                return;
            }
        }

        // Create symlink
        $symlink_result = symlink($target, $local_path);
        if (true !== $symlink_result || !is_link($local_path)) {
            // Log error and skip this symlink
            $this->audit_log(
                "Failed to create symlink: {$local_path} -> {$target}",
                true,
            );
            $this->output_progress([
                "type" => "symlink_error",
                "path" => $path,
                "target" => $target,
                "error" => "Failed to create symlink",
            ]);
            return;
        }

        // Try to set the ctime (may not work on all systems)
        if ($ctime > 0) {
            @touch($local_path, $ctime);
        }

        $this->audit_log("Symlink: {$path} -> {$target}", false);

        if ($ctime > 0) {
            $this->upsert_index_entry($path, $ctime, 0, "link");
        }

        $this->output_progress([
            "type" => "symlink",
            "path" => $path,
            "target" => $target,
        ]);
    }

    /**
     * Handle an error chunk from the server.
     */
    private function handle_error_chunk(
        array $chunk,
        string $phase,
        StreamingContext $context
    ): void {
        $body = $chunk["body"] ?? "";
        $data = json_decode($body, true);
        if (!$data) {
            $this->audit_log(
                "REMOTE ERROR | phase={$phase} | raw (JSON decode failed): " .
                    substr($body, 0, 500),
                true,
            );
            return;
        }

        $error_type = $data["error_type"] ?? "unknown";
        $path = $data["path"] ?? "";
        $message = $data["message"] ?? "Error";

        $this->audit_log(
            "REMOTE ERROR | phase={$phase} | type={$error_type} | path={$path} | message={$message}",
            true,
        );

        $is_file_error = in_array(
            $error_type,
            ["file_changed", "file_missing", "file_open", "file_read"],
            true,
        );
        if ($path !== "" && $is_file_error) {
            $local_path = $this->docroot . $path;
            if ($context->file_handle && $context->file_path === $local_path) {
                fclose($context->file_handle);
                $context->file_handle = null;
                $context->file_path = null;
                $context->file_ctime = null;
                $context->file_bytes_written = 0;
            }

            if (file_exists($local_path)) {
                @unlink($local_path);
            }
            $this->delete_index_entry($path);

            if ($error_type === "file_changed") {
                $this->record_volatile_file($path);
            }
        }

        $this->show_progress_line(
            "Remote error: {$error_type} " . ($path !== "" ? $path : ""),
        );
        $this->output_progress(
            [
                "type" => "error",
                "phase" => $phase,
                "error_type" => $error_type,
                "path" => $path,
                "message" => $message,
            ],
            true,
        );
    }

    /**
     * Handle progress chunk.
     */
    private function handle_progress(array $chunk, string $phase): void
    {
        $body = $chunk["body"] ?? "";
        $data = json_decode($body, true);
        if (!$data) {
            return;
        }

        $this->output_progress(array_merge(["phase" => $phase], $data));
    }

    /**
     * Build request URL with endpoint and cursor.
     */
    private function build_url(
        string $endpoint,
        ?string $cursor,
        array $params = []
    ): string {
        $url = $this->remote_url;
        $separator = strpos($url, "?") === false ? "?" : "&";

        $params["endpoint"] = $endpoint;
        if ($cursor) {
            // Also include cursor in query params as a fallback when headers are stripped.
            $params["cursor"] = $cursor;
        }
        $params["_cache_bust"] = time() . "-" . rand(0, 999999);

        return $url . $separator . http_build_query($params);
    }

    /**
     * Extract root directories from the remote URL query.
     */
    /**
     * Extract root directories from preflight wp_detect data.
     * Falls back to this when the URL doesn't contain directory[] params.
     */
    private function get_root_directories_from_preflight(): array
    {
        $roots = $this->state["preflight"]["data"]["wp_detect"]["roots"] ?? [];
        if (!is_array($roots) || empty($roots)) {
            return [];
        }
        $dirs = [];
        foreach ($roots as $root) {
            $path = $root["path"] ?? null;
            if (is_string($path) && $path !== "") {
                $dirs[] = rtrim($path, "/");
            }
        }
        $dirs = array_values(array_unique($dirs));
        if (!empty($dirs)) {
            $this->audit_log(
                "DIRECTORY AUTO-DETECT | from preflight wp_detect.roots: " .
                    implode(", ", $dirs),
            );
        }
        return $dirs;
    }

    /**
     * Check if a function is available (not disabled).
     */
    private function function_available(string $name): bool
    {
        if (!function_exists($name)) {
            return false;
        }
        $disabled = ini_get("disable_functions");
        if ($disabled === false || trim($disabled) === "") {
            return true;
        }
        $list = array_map("trim", explode(",", $disabled));
        return !in_array($name, $list, true);
    }


    /**
     * Fast-path index sort via shell exec.
     *
     * Prepends a hex-encoded sort key to each line, shells out to `sort(1)`,
     * strips the keys, and deduplicates.  This handles arbitrarily large
     * files with no PHP memory pressure.
     *
     * @param string $path         The JSONL index file to sort.
     * @param string $tmp          Temporary output path for the sorted result.
     * @return bool True if the exec-based sort succeeded (and $path was replaced).
     */
    private function try_exec_sort(string $path, string $tmp): bool
    {
        if (!$this->function_available("exec")) {
            return false;
        }

        $keyed = $path . ".keyed";
        $sorted_keyed = $path . ".keyed.sorted";
        $in = fopen($path, "r");
        $out = fopen($keyed, "w");
        if (!$in || !$out) {
            if ($in) {
                fclose($in);
            }
            if ($out) {
                fclose($out);
            }
            $this->audit_log("Failed to prepare keyed index file, falling back to PHP sort");
            return false;
        }
        while (($line = fgets($in)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === "") {
                continue;
            }
            $entry = $this->parse_index_line($line);
            if ($entry === null) {
                continue;
            }
            $key = bin2hex($entry["path"]);
            fwrite($out, $key . "\t" . $line . "\n");
        }
        fclose($in);
        fclose($out);

        $cmd =
            "LC_ALL=C sort -t '\t' -k1,1 " .
            escapeshellarg($keyed) .
            " > " .
            escapeshellarg($sorted_keyed);
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        if ($code !== 0) {
            @unlink($keyed);
            @unlink($sorted_keyed);
            $this->audit_log("exec() sort failed (exit code {$code}), falling back to PHP sort");
            return false;
        }

        $sorted_in = fopen($sorted_keyed, "r");
        $sorted_out = fopen($tmp, "w");
        if (!$sorted_in || !$sorted_out) {
            if ($sorted_in) {
                fclose($sorted_in);
            }
            if ($sorted_out) {
                fclose($sorted_out);
            }
            @unlink($keyed);
            @unlink($sorted_keyed);
            $this->audit_log("Failed to open sorted index files, falling back to PHP sort");
            return false;
        }

        $prev_key = null;
        while (($line = fgets($sorted_in)) !== false) {
            $pos = strpos($line, "\t");
            if ($pos === false) {
                continue;
            }
            $key = substr($line, 0, $pos);
            $data = substr($line, $pos + 1);
            if ($data === "") {
                continue;
            }
            // Deduplicate: skip entries with the same path as the previous one.
            // This handles overlapping symlink targets that index the same files.
            if ($key === $prev_key) {
                continue;
            }
            $prev_key = $key;
            fwrite($sorted_out, $data);
        }
        fclose($sorted_in);
        fclose($sorted_out);
        @unlink($keyed);
        @unlink($sorted_keyed);
        if (!rename($tmp, $path)) {
            throw new RuntimeException("Failed to replace sorted index file");
        }
        return true;
    }

    /**
     * Sorts an index file by path and removes duplicate entries.
     *
     * Tries the fast path first: prepends a hex-encoded sort key to each line,
     * shells out to `sort(1)`, then strips the keys.  This handles arbitrarily
     * large files with no PHP memory pressure.  If exec() is unavailable or
     * the sort command fails, falls back to an in-memory usort() — which
     * requires roughly 5x the file size in available memory.
     *
     * Duplicates arise from overlapping symlink targets that index the same
     * files; they are removed during the final write pass.
     *
     * @param string $path The JSONL index file to sort in place.
     */
    private function sort_index_file(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (filesize($path) === 0) {
            return;
        }

        $tmp = $path . ".sorted";

        // Try the fast path first: shell out to `sort` for O(n log n) with
        // no memory pressure.  If anything goes wrong, fall through to the
        // PHP-native sorting below.
        if ($this->try_exec_sort($path, $tmp)) {
            return;
        }

        // Estimate how much memory we can use: 60% of whatever headroom
        // remains between current usage and the PHP memory limit.
        $mem_limit_raw = ini_get("memory_limit");
        $mem_limit = ($mem_limit_raw === "-1" || $mem_limit_raw === "" || $mem_limit_raw === "0")
            ? 0
            : parse_size($mem_limit_raw);
        $mem_used = memory_get_usage(true);
        $available = $mem_limit > 0
            ? (int) (($mem_limit - $mem_used) * 0.6)
            : 256 * 1024 * 1024;

        $size = filesize($path);
        // In-memory sorting requires roughly 4-5x the file size (raw lines +
        // parsed entries + sorted output string), so be conservative.
        if ($size * 5 > $available) {
            throw new RuntimeException(
                "Index file is too large to sort without exec() " .
                "({$size} bytes, ~" . round($available / 1024 / 1024) . " MB available)",
            );
        }

        $raw_lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($raw_lines === false) {
            throw new RuntimeException("Failed to read index file for sorting");
        }
        $entries = [];
        foreach ($raw_lines as $line) {
            $entry = $this->parse_index_line($line);
            if ($entry === null) {
                continue;
            }
            $entries[] = [
                "path" => $entry["path"],
                "line" => $line,
            ];
        }
        // Free the raw lines array before sorting — we've extracted what we need.
        unset($raw_lines);

        usort($entries, function ($a, $b) {
            return strcmp($a["path"], $b["path"]);
        });

        $out = fopen($tmp, "w");
        if (!$out) {
            throw new RuntimeException("Failed to write sorted index file");
        }
        $prev_path = null;
        foreach ($entries as $entry) {
            if ($entry["path"] === $prev_path) {
                continue;
            }
            $prev_path = $entry["path"];
            fwrite($out, $entry["line"] . "\n");
        }
        fclose($out);
        unset($entries);

        if (!rename($tmp, $path)) {
            throw new RuntimeException("Failed to replace sorted index file");
        }
    }

    /**
     * Return HMAC authentication headers formatted for curl ("Name: value"),
     * or an empty array if no secret was configured.
     *
     * @param string $body The request body content whose SHA-256 hash will
     *                     be included in the HMAC signature.  For CURLFile
     *                     uploads, pass the raw file content (not the
     *                     multipart envelope); for form-encoded POST, pass
     *                     the http_build_query() output; for GET, omit or
     *                     pass empty string.
     */
    private function get_hmac_headers(string $body = ''): array
    {
        if ($this->hmac_client === null) {
            return [];
        }
        return $this->hmac_client->get_curl_headers($body);
    }

    /**
     * Reset curl-related state at the start of each HTTP request.
     */
    private function reset_curl_state(): void
    {
        $this->last_curl_errno = null;
        $this->last_curl_timeout = false;
    }

    /**
     * Build the shared browser-mimicry headers used by both fetch_json and
     * fetch_streaming.  The Accept value differs between the two callers.
     */
    private function get_base_headers(string $accept): array
    {
        return [
            "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
            "Accept: {$accept}",
            "Accept-Language: en-US,en;q=0.9",
            "Accept-Encoding: gzip, deflate",
            "Cache-Control: no-cache",
            "Pragma: no-cache",
            "Connection: keep-alive",
        ];
    }

    /**
     * Build the multipart chunk handler callback shared by both parser
     * creation sites inside fetch_streaming.
     *
     * The callback accumulates "body" events into $current_chunk and emits
     * completed chunks to $context->on_chunk on "complete" events.
     */
    private function make_chunk_handler(
        StreamingContext $context,
        &$current_chunk
    ): callable {
        return function ($event) use ($context, &$current_chunk) {
            if ($event["type"] === "body") {
                // Accumulate body data in current chunk
                if (!$current_chunk) {
                    $current_chunk = [
                        "headers" => $event["headers"],
                        "body" => $event["data"],
                    ];
                } else {
                    $current_chunk["body"] =
                        ($current_chunk["body"] ?? "") .
                        $event["data"];
                }
            } elseif ($event["type"] === "complete") {
                // Chunk complete - emit to handler
                if ($current_chunk) {
                    if ($context->on_chunk) {
                        ($context->on_chunk)(
                            $current_chunk,
                        );
                    }
                } elseif ($event["headers"]) {
                    // No body data - emit just headers
                    if ($context->on_chunk) {
                        ($context->on_chunk)([
                            "headers" =>
                                $event["headers"],
                            "body" => "",
                        ]);
                    }
                }
                $current_chunk = null;
            }
        };
    }

    /**
     * Check for curl errors after curl_exec and record timeout state.
     * Throws RuntimeException on any curl error.
     */
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
        throw new RuntimeException("cURL error: {$error}");
    }

    /**
     * Fetch a JSON response for a lightweight request (non-streaming).
     */
    private function fetch_json(string $url): array
    {
        $this->reset_curl_state();

        $this->audit_log("HTTP_REQUEST | GET | {$url}", false);

        $ch = curl_init($url);

        $headers = [
            ...$this->get_base_headers("application/json"),
            ...($this->get_hmac_headers()),
        ];

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => "gzip, deflate",
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $start = microtime(true);
        $body = curl_exec($ch);
        $elapsed = microtime(true) - $start;

        try {
            $this->check_curl_error($ch);
        } catch (RuntimeException $e) {
            curl_close($ch);
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
        curl_close($ch);

        if ($http_code !== 200) {
            return [
                "ok" => false,
                "http_code" => $http_code,
                "elapsed" => $elapsed,
                "body" => $body,
                "json" => null,
                "error" => "HTTP error {$http_code}" .
                    ($body ? ": " . substr($body, 0, 500) : ""),
            ];
        }

        $json = null;
        $json_error = null;
        if ($body !== false && $body !== "") {
            $json = json_decode($body, true);
            if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
                $json_error = "Invalid JSON: " . json_last_error_msg();
            }
        }

        return [
            "ok" => $json_error === null,
            "http_code" => $http_code,
            "elapsed" => $elapsed,
            "body" => $body,
            "json" => $json,
            "error" => $json_error,
        ];
    }

    /**
     * Fetch URL with streaming multipart parsing.
     */
    private function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data = null,
        ?string $endpoint = null
    ): void {
        $this->reset_curl_state();

        // Log HTTP request details
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

        $this->audit_log(implode(" | ", $log_parts), false);

        $ch = curl_init($url);

        $parser = null;
        $current_chunk = null;
        $bytes_received = 0;
        $last_heartbeat = microtime(true);
        $last_progress_check = microtime(true);
        $last_bytes_received = 0;
        $error_body = "";

        // Build headers to look like a real browser
        $headers = [
            ...$this->get_base_headers("text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8"),
            "Upgrade-Insecure-Requests: 1",
            "Sec-Fetch-Dest: document",
            "Sec-Fetch-Mode: navigate",
            "Sec-Fetch-Site: none",
            "Sec-Fetch-User: ?1",
        ];

        if ($cursor) {
            $headers[] = "X-Export-Cursor: {$cursor}";
        }

        // Configure POST data if provided.  We need to know the body
        // content BEFORE generating HMAC headers so the content hash
        // can be included in the signature.
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
                // For CURLFile uploads, sign the raw file content — this
                // is the logical payload the server will receive, even
                // though curl wraps it in multipart framing.
                foreach ($post_data as $value) {
                    if ($value instanceof CURLFile) {
                        $body_for_signing .= file_get_contents(
                            $value->getFilename(),
                        );
                    }
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            } else {
                $body_for_signing = http_build_query($post_data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body_for_signing);
            }
        }

        // Append HMAC auth headers now that we know the body content
        array_push($headers, ...($this->get_hmac_headers($body_for_signing)));

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_ENCODING => "gzip, deflate",
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $header_line) use (
                &$parser,
                $context,
                &$current_chunk
            ) {
                $len = strlen($header_line);

                // Parse Content-Type to extract boundary
                if (stripos($header_line, "Content-Type:") === 0) {
                    // Find boundary parameter
                    $pos = stripos($header_line, "boundary=");
                    if ($pos !== false) {
                        $boundary_start = $pos + 9; // length of 'boundary='
                        $boundary_value = substr($header_line, $boundary_start);
                        $boundary_value = trim($boundary_value);

                        // Remove quotes if present
                        if ($boundary_value[0] === '"') {
                            $quote_end = strpos($boundary_value, '"', 1);
                            if ($quote_end !== false) {
                                $boundary_value = substr(
                                    $boundary_value,
                                    1,
                                    $quote_end - 1,
                                );
                            }
                        } else {
                            // Find end (semicolon, comma, or whitespace)
                            $end_pos = strcspn($boundary_value, ";,\r\n \t");
                            $boundary_value = substr(
                                $boundary_value,
                                0,
                                $end_pos,
                            );
                        }

                        if ($boundary_value !== "") {
                            $this->audit_log(
                                "Creating multipart parser with boundary: $boundary_value",
                                false,
                            );
                            $parser = new MultipartStreamParser(
                                $boundary_value,
                                $this->make_chunk_handler($context, $current_chunk),
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
                // If no parser yet, we might be receiving an error response
                if (!$parser) {
                    $error_body .= $data;
                    if (strlen($error_body) > 65536) {
                        $error_body = substr($error_body, -65536);
                    }

                    // Strict fallback: if body starts with a boundary line, parse it.
                    if (strncmp($error_body, "--boundary-", 11) === 0) {
                        $line_end = strpos($error_body, "\n");
                        if ($line_end !== false) {
                            $line = rtrim(substr($error_body, 0, $line_end), "\r\n");
                            if (strncmp($line, "--boundary-", 11) === 0) {
                                $boundary = substr($line, 2);
                                if ($boundary !== "") {
                                    $this->audit_log(
                                        "Detected boundary in body (no Content-Type): {$boundary}",
                                        false,
                                    );
                                    $parser = new MultipartStreamParser(
                                        $boundary,
                                        $this->make_chunk_handler($context, $current_chunk),
                                    );
                                    $parser->feed($error_body);
                                    $error_body = "";
                                }
                            }
                        }
                    }

                    static $logged_no_parser = false;
                    if (!$logged_no_parser && strlen($error_body) > 0) {
                        $this->audit_log(
                            "No parser, accumulating error body (first 500 chars): " .
                                substr($error_body, 0, 500),
                            false,
                        );
                        $logged_no_parser = true;
                    }
                }

                if ($parser) {
                    $parser->feed($data);
                }

                $bytes_received += strlen($data);

                // Check for stuck/slow transfer every 5 seconds
                $now = microtime(true);
                if ($now - $last_progress_check >= 5.0) {
                    $bytes_since_check = $bytes_received - $last_bytes_received;
                    $rate = $bytes_since_check / 5.0; // bytes per second

                    // Only output progress_check in verbose mode or non-TTY
                    if ($this->verbose_mode || !$this->is_tty) {
                        fwrite($this->progress_fd, json_encode([
                            "progress_check" => true,
                            "bytes_received" => $bytes_received,
                            "bytes_last_5s" => $bytes_since_check,
                            "rate_bps" => round($rate),
                        ]) . "\n");
                    }

                    // If we're receiving less than 1KB/s for 5 seconds, something is wrong
                    if ($bytes_since_check < 1024 && $bytes_received > 0) {
                        $this->audit_log(
                            "Warning: Slow transfer detected - {$bytes_since_check} bytes in 5 seconds",
                            false,
                        );
                    }

                    $last_progress_check = $now;
                    $last_bytes_received = $bytes_received;
                }

                // Output heartbeat every second (only in verbose/non-TTY mode)
                if ($now - $last_heartbeat >= 1.0) {
                    if ($this->verbose_mode || !$this->is_tty) {
                        fwrite($this->progress_fd, json_encode([
                            "heartbeat" => true,
                            "bytes_received" => $bytes_received,
                        ]) . "\n");
                    }
                    $last_heartbeat = $now;
                }

                return strlen($data);
            },
        ]);

        $this->audit_log("Executing curl request...", false);
        $this->output_progress(["debug" => "Waiting for server response..."]);
        $result = curl_exec($ch);
        $this->audit_log(
            "curl_exec completed, result=" .
                ($result === false ? "false" : "true"),
            false,
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
            $ttfb = (float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
            $total_time = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        } finally {
            curl_close($ch);
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
            $error_msg = "HTTP error {$http_code}";

            // Log what we received
            $this->audit_log(
                "HTTP error {$http_code} | error_body length: " .
                    strlen($error_body),
                true,
            );

            // Try to parse error response as JSON
            if ($error_body) {
                $error_data = json_decode($error_body, true);
                if ($error_data && isset($error_data["error"])) {
                    $error_msg .= ": " . $error_data["error"];
                    if (isset($error_data["trace"])) {
                        $error_msg .=
                            "\n\nStack trace:\n" . $error_data["trace"];
                    }
                } else {
                    // Not JSON, show raw body
                    $error_msg .=
                        "\n\nResponse: " . substr($error_body, 0, 1000);
                }
            } else {
                // No error body captured - server might have sent multipart response
                // Check server error log for details
                $error_msg .=
                    "\n\nNo error body received. Check server error log at:\n";
                $error_msg .=
                    "  " .
                    dirname(parse_url($url, PHP_URL_PATH)) .
                    "/error_log\n";
                $error_msg .= "  or enable display_errors on the server";
            }

            throw new RuntimeException($error_msg);
        }

        if (!$parser) {
            $snippet = $error_body ? substr($error_body, 0, 500) : "";
            throw new RuntimeException(
                "Invalid response: missing multipart boundary. " .
                    ($snippet !== "" ? "Body: {$snippet}" : ""),
            );
        }

        if (!$context->saw_completion) {
            throw new RuntimeException(
                "Invalid response: missing completion chunk from server.",
            );
        }
    }

    /**
     * Return the default compact state structure.
     */
    /**
     * Reset state to defaults while preserving cross-command data like
     * preflight results, version, and follow_symlinks.
     */
    private function reset_state(): void
    {
        $preflight = $this->state["preflight"] ?? null;
        $version = $this->state["version"] ?? null;
        $follow = $this->state["follow_symlinks"] ?? false;
        $nonempty = $this->state["docroot_nonempty_behavior"] ?? "error";
        $max_packet = $this->state["max_allowed_packet"] ?? null;
        $this->state = $this->default_state();
        $this->state["preflight"] = $preflight;
        $this->state["version"] = $version;
        $this->state["follow_symlinks"] = $follow;
        $this->state["docroot_nonempty_behavior"] = $nonempty;
        $this->state["max_allowed_packet"] = $max_packet;
    }

    private function default_state(): array
    {
        return [
            "command" => null,
            "status" => null,
            "cursor" => null,
            "stage" => null,
            "preflight" => null,
            "remote_protocol_version" => null,
            "remote_protocol_min_version" => null,
            "version" => null,
            "follow_symlinks" => true,
            "docroot_nonempty_behavior" => "error",
            "max_allowed_packet" => null,
            "db_index" => [
                "file" => null,
                "tables" => 0,
                "rows_estimated" => 0,
                "bytes" => 0,
                "updated_at" => null,
            ],
            "diff" => [
                "remote_offset" => 0,
                "local_after" => null,
            ],
            "index" => [
                "cursor" => null,
            ],
            "fetch" => [
                "offset" => 0,
                "next_offset" => 0,
                "batch_file" => null,
                "cursor" => null,
            ],
            // Crash recovery: track in-progress file downloads
            // If we crash mid-write, we can truncate to the expected size on resume
            "current_file" => null,        // Path to file being written
            "current_file_bytes" => null,  // Expected bytes written so far
            // Crash recovery: track SQL file size
            "sql_bytes" => null,           // Expected SQL file size
            // db-apply state
            "apply" => [
                "statements_executed" => 0,
                "bytes_read" => 0,
                "rewrite_url" => null,
            ],
            // SQL output mode (file, stdout, mysql, wpdb) — persisted for resume
            "sql_output" => null,
            // MySQL connection parameters — persisted for resume (password excluded)
            "mysql_host" => null,
            "mysql_port" => null,
            "mysql_user" => null,
            "mysql_database" => null,
            // WordPress wp-load.php path — persisted for resume
            "wp_load" => null,
            // Adaptive tuning state/config
            "tuning" => [
                "config" => [],
                "state" => [],
            ],
        ];
    }

    /**
     * Normalize state array to the compact schema.
     */
    private function normalize_state(array $state): array
    {
        $defaults = $this->default_state();
        $state = array_intersect_key($state, $defaults);
        $state = array_merge($defaults, $state);
        $diff = $state["diff"];
        if (!is_array($diff)) {
            $diff = [];
        }
        $diff = array_intersect_key($diff, $defaults["diff"]);
        $state["diff"] = array_merge($defaults["diff"], $diff);
        $index = $state["index"] ?? [];
        if (!is_array($index)) {
            $index = [];
        }
        $index = array_intersect_key($index, $defaults["index"]);
        $state["index"] = array_merge($defaults["index"], $index);
        $fetch = $state["fetch"] ?? [];
        if (!is_array($fetch)) {
            $fetch = [];
        }
        $fetch = array_intersect_key($fetch, $defaults["fetch"]);
        $state["fetch"] = array_merge($defaults["fetch"], $fetch);
        $tuning = $state["tuning"] ?? [];
        if (!is_array($tuning)) {
            $tuning = [];
        }
        $tuning = array_intersect_key($tuning, $defaults["tuning"]);
        $tuning = array_merge($defaults["tuning"], $tuning);
        $state["tuning"] = $tuning;
        $index_db = $state["db_index"] ?? [];
        if (!is_array($index_db)) {
            $index_db = [];
        }
        $index_db = array_intersect_key(
            $index_db,
            $defaults["db_index"],
        );
        $index_db = array_merge(
            $defaults["db_index"],
            $index_db,
        );
        $state["db_index"] = $index_db;
        $apply = $state["apply"] ?? [];
        if (!is_array($apply)) {
            $apply = [];
        }
        $apply = array_intersect_key($apply, $defaults["apply"]);
        $state["apply"] = array_merge($defaults["apply"], $apply);
        return $state;
    }

    /**
     * Encode state path fields as base64 to make JSON persistence byte-safe.
     */
    private function encode_state_paths(array $state): array
    {
        $state["diff"]["local_after"] = $this->encode_state_path_value(
            $state["diff"]["local_after"] ?? null,
        );
        $state["fetch"]["batch_file"] = $this->encode_state_path_value(
            $state["fetch"]["batch_file"] ?? null,
        );
        $state["current_file"] = $this->encode_state_path_value(
            $state["current_file"] ?? null,
        );
        $state["db_index"]["file"] = $this->encode_state_path_value(
            $state["db_index"]["file"] ?? null,
        );

        if (
            isset($state["preflight"]) &&
            is_array($state["preflight"]) &&
            isset($state["preflight"]["data"]) &&
            is_array($state["preflight"]["data"])
        ) {
            $state["preflight"]["data"] = $this->encode_preflight_data_paths(
                $state["preflight"]["data"],
            );
        }

        return $state;
    }

    /**
     * Decode base64-encoded path fields in state after loading.
     *
     * Supports legacy plain-string fields for backward compatibility.
     */
    private function decode_state_paths(array $state): array
    {
        $state["diff"]["local_after"] = $this->decode_state_path_value(
            $state["diff"]["local_after"] ?? null,
        );
        $state["fetch"]["batch_file"] = $this->decode_state_path_value(
            $state["fetch"]["batch_file"] ?? null,
        );
        $state["current_file"] = $this->decode_state_path_value(
            $state["current_file"] ?? null,
        );
        $state["db_index"]["file"] = $this->decode_state_path_value(
            $state["db_index"]["file"] ?? null,
        );

        if (
            isset($state["preflight"]) &&
            is_array($state["preflight"]) &&
            isset($state["preflight"]["data"]) &&
            is_array($state["preflight"]["data"])
        ) {
            $state["preflight"]["data"] = $this->decode_preflight_data_paths(
                $state["preflight"]["data"],
            );
        }

        return $state;
    }

    /**
     * Encode preflight path fields.
     */
    private function encode_preflight_data_paths(array $data): array
    {
        if (isset($data["wp_detect"]["searched"]) && is_array($data["wp_detect"]["searched"])) {
            foreach ($data["wp_detect"]["searched"] as $idx => $path) {
                $data["wp_detect"]["searched"][$idx] = $this->encode_state_path_value($path);
            }
        }

        if (isset($data["wp_detect"]["roots"]) && is_array($data["wp_detect"]["roots"])) {
            foreach ($data["wp_detect"]["roots"] as $idx => $root) {
                if (!is_array($root)) {
                    continue;
                }
                foreach (["path", "wp_load_path", "wp_config_path"] as $key) {
                    if (array_key_exists($key, $root)) {
                        $data["wp_detect"]["roots"][$idx][$key] = $this->encode_state_path_value($root[$key]);
                    }
                }
            }
        }

        if (isset($data["runtime"]) && is_array($data["runtime"])) {
            foreach (["php_ini", "temp_dir", "document_root", "script_filename", "cwd"] as $key) {
                if (array_key_exists($key, $data["runtime"])) {
                    $data["runtime"][$key] = $this->encode_state_path_value($data["runtime"][$key]);
                }
            }
        }

        if (isset($data["filesystem"]["directories"]) && is_array($data["filesystem"]["directories"])) {
            foreach ($data["filesystem"]["directories"] as $idx => $dir_entry) {
                if (!is_array($dir_entry) || !array_key_exists("path", $dir_entry)) {
                    continue;
                }
                $data["filesystem"]["directories"][$idx]["path"] = $this->encode_state_path_value($dir_entry["path"]);
            }
        }

        if (isset($data["htaccess"]["files"]) && is_array($data["htaccess"]["files"])) {
            foreach ($data["htaccess"]["files"] as $idx => $file_entry) {
                if (!is_array($file_entry) || !array_key_exists("path", $file_entry)) {
                    continue;
                }
                $data["htaccess"]["files"][$idx]["path"] = $this->encode_state_path_value($file_entry["path"]);
            }
        }

        if (isset($data["wp_content"]["roots"]) && is_array($data["wp_content"]["roots"])) {
            foreach ($data["wp_content"]["roots"] as $idx => $root_entry) {
                if (!is_array($root_entry)) {
                    continue;
                }
                foreach (["root", "content_dir"] as $key) {
                    if (array_key_exists($key, $root_entry)) {
                        $data["wp_content"]["roots"][$idx][$key] = $this->encode_state_path_value($root_entry[$key]);
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Decode preflight path fields.
     */
    private function decode_preflight_data_paths(array $data): array
    {
        if (isset($data["wp_detect"]["searched"]) && is_array($data["wp_detect"]["searched"])) {
            foreach ($data["wp_detect"]["searched"] as $idx => $path) {
                $data["wp_detect"]["searched"][$idx] = $this->decode_state_path_value($path);
            }
        }

        if (isset($data["wp_detect"]["roots"]) && is_array($data["wp_detect"]["roots"])) {
            foreach ($data["wp_detect"]["roots"] as $idx => $root) {
                if (!is_array($root)) {
                    continue;
                }
                foreach (["path", "wp_load_path", "wp_config_path"] as $key) {
                    if (array_key_exists($key, $root)) {
                        $data["wp_detect"]["roots"][$idx][$key] = $this->decode_state_path_value($root[$key]);
                    }
                }
            }
        }

        if (isset($data["runtime"]) && is_array($data["runtime"])) {
            foreach (["php_ini", "temp_dir", "document_root", "script_filename", "cwd"] as $key) {
                if (array_key_exists($key, $data["runtime"])) {
                    $data["runtime"][$key] = $this->decode_state_path_value($data["runtime"][$key]);
                }
            }
        }

        if (isset($data["filesystem"]["directories"]) && is_array($data["filesystem"]["directories"])) {
            foreach ($data["filesystem"]["directories"] as $idx => $dir_entry) {
                if (!is_array($dir_entry) || !array_key_exists("path", $dir_entry)) {
                    continue;
                }
                $data["filesystem"]["directories"][$idx]["path"] = $this->decode_state_path_value($dir_entry["path"]);
            }
        }

        if (isset($data["htaccess"]["files"]) && is_array($data["htaccess"]["files"])) {
            foreach ($data["htaccess"]["files"] as $idx => $file_entry) {
                if (!is_array($file_entry) || !array_key_exists("path", $file_entry)) {
                    continue;
                }
                $data["htaccess"]["files"][$idx]["path"] = $this->decode_state_path_value($file_entry["path"]);
            }
        }

        if (isset($data["wp_content"]["roots"]) && is_array($data["wp_content"]["roots"])) {
            foreach ($data["wp_content"]["roots"] as $idx => $root_entry) {
                if (!is_array($root_entry)) {
                    continue;
                }
                foreach (["root", "content_dir"] as $key) {
                    if (array_key_exists($key, $root_entry)) {
                        $data["wp_content"]["roots"][$idx][$key] = $this->decode_state_path_value($root_entry[$key]);
                    }
                }
            }
        }

        return $data;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function encode_state_path_value($value)
    {
        if (!is_string($value) || $value === "") {
            return $value;
        }
        return self::STATE_PATH_ENCODING_PREFIX . base64_encode($value);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function decode_state_path_value($value)
    {
        if (!is_string($value) || $value === "") {
            return $value;
        }
        if (!str_starts_with($value, self::STATE_PATH_ENCODING_PREFIX)) {
            return $value;
        }
        $encoded = substr($value, strlen(self::STATE_PATH_ENCODING_PREFIX));
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            $this->audit_log(
                "Warning: invalid base64-encoded state path; resetting field",
                true,
            );
            return null;
        }
        return $decoded;
    }

    /**
     * Load import state from disk.
     */
    private function load_state(): array
    {
        if (!file_exists($this->state_file)) {
            return $this->default_state();
        }

        $contents = file_get_contents($this->state_file);
        if ($contents === false) {
            return $this->default_state();
        }

        $state = json_decode($contents, true);
        if (!is_array($state)) {
            $this->audit_log(
                "Warning: corrupt state file detected, renaming and starting fresh",
                true,
            );
            $corrupt_name = $this->state_file . ".corrupt." . time();
            @rename($this->state_file, $corrupt_name);
            return $this->default_state();
        }

        $state = $this->normalize_state($state);
        return $this->decode_state_paths($state);
    }

    /**
     * Save import state to disk.
     *
     * Uses atomic write (temp file + rename) to prevent corruption if
     * the process is killed mid-write.
     */
    private function save_state(array $state): void
    {
        if ($this->tuner instanceof AdaptiveTuner) {
            $state["tuning"] = [
                "config" => $this->tuner->get_config(),
                "state" => $this->tuner->get_state(),
            ];
        }
        $state = $this->normalize_state($state);
        $state = $this->encode_state_paths($state);

        // Write to temp file first, then atomic rename
        $json = json_encode($state, JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException("Failed to encode state: " . json_last_error_msg());
        }
        $tmp_file = $this->state_file . '.tmp';
        $bytes = file_put_contents($tmp_file, $json);
        if ($bytes === false) {
            throw new RuntimeException("Failed to write state file: $tmp_file (disk full?)");
        }
        if (!rename($tmp_file, $this->state_file)) {
            throw new RuntimeException("Failed to rename state file: $tmp_file -> {$this->state_file}");
        }

        $indexed = $this->index_count();
        $files_imported = $this->files_imported; // Completed in this run
        $has_cursor =
            !empty($state["cursor"] ?? null) ||
            !empty($state["index"]["cursor"] ?? null) ||
            !empty($state["fetch"]["cursor"] ?? null);
        $cursor_info = $has_cursor ? "cursor=saved" : "cursor=none";

        $this->audit_log(
            sprintf(
                "SAVE CURSOR | total_indexed=%d | completed_this_run=%d | %s",
                $indexed,
                $files_imported,
                $cursor_info,
            ),
            false,
        );

        $this->write_status_file();
    }

    /**
     * Write a flat status file for external consumers (e.g. web UI polling).
     *
     * Derives a simple JSON object from the current state and pipeline
     * position. Written atomically via temp file + rename so readers
     * never see a partial write.
     */
    private function write_status_file(?string $error = null): void
    {
        $state = $this->state ?? [];
        $command = $state["command"] ?? null;
        $status = $error !== null ? "error" : ($state["status"] ?? "in_progress");

        // Derive phase from the state's stage field
        $phase = $state["stage"] ?? null;

        $payload = [
            "step" => $this->pipeline_step,
            "steps" => $this->pipeline_steps,
            "command" => $command,
            "status" => $status,
            "phase" => $phase,
            "error" => $error,
            "ts" => microtime(true),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT);
        if ($json === false) {
            return; // Best-effort — don't crash the import over a status file
        }
        $tmp = $this->status_file . ".tmp";
        if (file_put_contents($tmp, $json) !== false) {
            rename($tmp, $this->status_file);
        }
    }

    /**
     * Handle shutdown signals (SIGINT, SIGTERM).
     * Saves state before exiting.
     */
    public function handle_shutdown(int $signal): void
    {
        // Prevent multiple signal handling
        static $already_shutting_down = false;
        if ($already_shutting_down) {
            // Force kill on second signal
            if (
                function_exists("posix_kill") &&
                function_exists("posix_getpid")
            ) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            die("\nForced exit.\n");
        }
        $already_shutting_down = true;

        $this->shutdown_requested = true;
        $this->clear_progress_line();

        // Flush index updates so progress is not lost on interrupt
        try {
            $this->finalize_index_updates();
        } catch (Exception $e) {
            $this->audit_log(
                "Failed to finalize index updates on shutdown: " .
                    $e->getMessage(),
                true,
            );
        }

        // Log final progress before exit
        $indexed = $this->index_count();
        $files_imported = $this->files_imported; // Files completed in this run
        $current_command = $this->state["command"] ?? "unknown";

        $this->audit_log(
            sprintf(
                "SHUTDOWN REQUESTED | command=%s | total_indexed=%d files | completed_this_run=%d files",
                $current_command,
                $indexed,
                $files_imported,
            ),
            true,
        );

        if ($this->is_tty && !$this->verbose_mode) {
            fwrite($this->progress_fd, "\nInterrupted - saving state...\n");
            fwrite($this->progress_fd, "  Command: {$current_command}\n");
            fwrite($this->progress_fd, "  Total files indexed: {$indexed}\n");
            fwrite($this->progress_fd, "  Files completed in this run: {$files_imported}\n");
        }

        // Save current state (with timeout protection)
        try {
            $this->save_state($this->state);
            if ($this->is_tty && !$this->verbose_mode) {
                fwrite($this->progress_fd, "✓ State saved successfully\n");
            }
        } catch (Exception $e) {
            fwrite($this->progress_fd, "Warning: Failed to save state: " . $e->getMessage() . "\n");
        }

        if ($this->is_tty && !$this->verbose_mode) {
            fwrite($this->progress_fd, "Exiting...\n");
        }

        // CRITICAL: Use SIGKILL for immediate termination
        // Regular exit() hangs because PHP's shutdown sequence tries to
        // close the curl handle gracefully, which blocks waiting for server.
        // curl_close() also hangs when called during an active curl_exec().
        // SIGKILL bypasses all cleanup and terminates at OS level immediately.
        if (function_exists("posix_kill") && function_exists("posix_getpid")) {
            posix_kill(posix_getpid(), SIGKILL);
        }

        // Fallback if posix functions not available
        die();
    }

    /**
     * Output progress as JSON line.
     * Only outputs in verbose mode or non-TTY mode (for programmatic consumption).
     *
     * @param array $data Progress data to output
     * @param bool $force Force output regardless of throttle
     */
    private function output_progress(array $data, bool $force = false): void
    {
        // In TTY non-verbose mode, suppress JSON output (use show_progress_line instead)
        if ($this->is_tty && !$this->verbose_mode) {
            return;
        }

        $now = microtime(true);

        // Always output status changes
        $is_status_change =
            isset($data["status"]) &&
            in_array($data["status"], ["starting", "complete", "error"]);

        // Output if forced, status change, or throttle time passed
        if (
            $force ||
            $is_status_change ||
            $now - $this->last_progress_output >= $this->progress_throttle
        ) {
            $written = @fwrite($this->progress_fd, json_encode($data) . "\n");
            if ($written === false) {
                // Broken pipe — save state and exit cleanly
                $this->save_state($this->state);
                exit(0);
            }
            @flush();
            $this->last_progress_output = $now;
        }
    }
}

/**
 * Context object passed to streaming callbacks.
 */
class StreamingContext
{
    public $on_chunk = null;
    public $file_handle = null;
    public $file_path = null;
    public $file_ctime = null;
    public $filesystem_root = null;
    public $chunk_fingerprints = [];
    public $need_client_slice = false;
    public $next_client_offset = 0;
    // Crash recovery: track bytes written for current file
    public $file_bytes_written = 0;
    // Last response stats from completion chunk
    public $response_stats = [];
    // Stream integrity
    public $saw_completion = false;
    // When true, skip writing the current file (preserve-local mode)
    public $skip_current_file = false;
}

/**
 * Thrown by ensure_directory_path() in preserve-local mode when a directory
 * component is not writable or a symlink blocks directory creation.
 * Callers catch this to skip the current file/directory/symlink gracefully.
 */
class PreserveLocalSkipException extends RuntimeException {}

// ============================================================================
// CLI Entry Point
// ============================================================================

// Only run CLI logic if this file is executed directly (not included/required).
// IMPORTER_PHAR_ENTRY is defined by the phar stub so the guard also passes
// when running as `php importer.phar`.
if (
    PHP_SAPI === "cli" &&
    isset($argv) &&
    (realpath($argv[0] ?? "") === __FILE__ || defined('IMPORTER_PHAR_ENTRY'))
) {
    // Per-command help definitions. Each command has a short description
    // shown in the main help, and a detailed help block shown when you
    // run `php import.php <command> <url> <local-path> --help`.
    $command_help = [
        "files-sync" => [
            "short" => "Sync files (auto-detects initial vs delta)",
            "detail" =>
                "Streams files from the remote server into the --docroot directory.\n" .
                "Auto-detects whether to run an initial or delta sync based on state:\n" .
                "\n" .
                "  - No prior sync: downloads the full directory tree (initial)\n" .
                "  - Completed sync: re-indexes and downloads only changes (delta)\n" .
                "  - Interrupted sync: resumes from the last saved cursor\n" .
                "\n" .
                "Options:\n" .
                "  --abort              Abort current sync and exit (keeps files and index)\n" .
                "  --no-follow-symlinks Do not follow symlinks pointing outside root directories\n" .
                "  --on-docroot-nonempty=MODE\n" .
                "                       What to do when docroot is non-empty (error|preserve-local)\n" .
                "  --secret=TOKEN       HMAC shared secret for export API authentication\n" .
                "  --verbose, -v        Show detailed request/response logs\n" .
                "\n" .
                "Output files:\n" .
                "  (docroot)/                    Downloaded files\n" .
                "  .import-index.jsonl           Local file index\n" .
                "  .import-remote-index.jsonl    Remote index snapshot\n" .
                "  .import-download-list.jsonl   Files pending download\n" .
                "  .import-state.json            Resumable state\n" .
                "  .import-audit.log             Audit log\n",
        ],
        "files-index" => [
            "short" => "Download the remote file index without fetching file contents",
            "detail" =>
                "Traverses the full remote directory tree and writes each entry\n" .
                "to .import-index.jsonl. Does not download any file data.\n" .
                "\n" .
                "Options:\n" .
                "  --abort        Clear state and output, then exit\n" .
                "  --secret=TOKEN   HMAC shared secret for export API authentication\n" .
                "  --verbose, -v    Show detailed request/response logs\n",
        ],
        "files-stats" => [
            "short" => "Show file count and total size of indexed and pending files",
            "detail" =>
                "Reads the remote file index and download list to report:\n" .
                "\n" .
                "  - Total indexed files and their combined size\n" .
                "  - Files not yet downloaded and their combined size\n" .
                "\n" .
                "Output is JSON with 'indexed' and 'pending' sections.\n" .
                "The <remote-url> parameter is kept for CLI consistency but ignored.\n" .
                "\n" .
                "Requires a prior files-index or files-sync run.\n",
        ],
        "db-sync" => [
            "short" => "Download the database as a SQL dump",
            "detail" =>
                "Streams the full database dump into --state-dir/db.sql (default),\n" .
                "to stdout for piping, or directly into a MySQL connection.\n" .
                "Automatically resumes from the last cursor if interrupted.\n" .
                "\n" .
                "Options:\n" .
                "  --abort                     Clear state and output, then exit\n" .
                "  --secret=TOKEN              HMAC shared secret for export API authentication\n" .
                "  --verbose, -v               Show detailed request/response logs\n" .
                "  --max-allowed-packet=SIZE   Client max_allowed_packet (e.g. 16M, 64M)\n" .
                "  --sql-output=MODE           Output mode: file (default), stdout, mysql, wpdb\n" .
                "  --mysql-host=HOST           MySQL host (default: 127.0.0.1, for --sql-output=mysql)\n" .
                "  --mysql-port=PORT           MySQL port (default: 3306, for --sql-output=mysql)\n" .
                "  --mysql-user=USER           MySQL user (default: root, for --sql-output=mysql)\n" .
                "  --mysql-password=PASS       MySQL password (or set MYSQL_PASSWORD env)\n" .
                "  --mysql-database=DB         MySQL database (required for --sql-output=mysql)\n" .
                "  --wp-load=PATH              Path to wp-load.php (required for --sql-output=wpdb)\n" .
                "\n" .
                "Output modes:\n" .
                "  file    Write to --state-dir/db.sql (default)\n" .
                "  stdout  Write raw SQL to stdout; progress goes to stderr\n" .
                "  mysql   Stream directly into a MySQL connection\n" .
                "  wpdb    Stream through WordPress \$wpdb (supports MySQL and SQLite)\n",
        ],
        "db-index" => [
            "short" => "Index database tables and their statistics",
            "detail" =>
                "Streams table metadata (name, estimated rows, data size) into\n" .
                "--state-dir/db-tables.jsonl. Useful for planning and diagnostics.\n" .
                "\n" .
                "Options:\n" .
                "  --abort        Clear state and output, then exit\n" .
                "  --secret=TOKEN   HMAC shared secret for export API authentication\n" .
                "  --verbose, -v    Show detailed request/response logs\n" .
                "\n" .
                "Output files:\n" .
                "  db-tables.jsonl  One JSON object per table\n",
        ],
        "db-domains" => [
            "short" => "List domains discovered in the SQL dump",
            "detail" =>
                "Prints domains found in the SQL dump, one per line.\n" .
                "\n" .
                "If .import-domains.json exists (written by db-sync), it is read\n" .
                "directly. Otherwise, db.sql is scanned for domains and the result\n" .
                "is saved for future calls.\n" .
                "\n" .
                "The <remote-url> parameter is kept for CLI consistency but ignored.\n" .
                "\n" .
                "Example:\n" .
                "  php import.php db-domains - --state-dir=/path/to/state\n",
        ],
        "db-apply" => [
            "short" => "Apply SQL dump to a target database with URL rewriting",
            "detail" =>
                "Reads <local-path>/db.sql, optionally rewrites URLs, and executes\n" .
                "all statements against a target database. Resumable.\n" .
                "\n" .
                "Supports two backends:\n" .
                "  1. Direct MySQL via --target-* options (default)\n" .
                "  2. WordPress \$wpdb via --wp-load (works with MySQL and SQLite)\n" .
                "\n" .
                "The <remote-url> parameter is kept for CLI consistency but ignored.\n" .
                "\n" .
                "Options:\n" .
                "  --target-host=HOST         Target MySQL host (default: 127.0.0.1)\n" .
                "  --target-port=PORT         Target MySQL port (default: 3306)\n" .
                "  --target-user=USER         Target MySQL user (required without --wp-load)\n" .
                "  --target-pass=PASS         Target MySQL password\n" .
                "  --target-db=NAME           Target MySQL database (required without --wp-load)\n" .
                "  --wp-load=PATH             Path to wp-load.php (uses \$wpdb instead of direct MySQL)\n" .
                "  --rewrite-url FROM TO      Rewrite FROM to TO (repeatable)\n" .
                "  --abort                    Clear state and exit\n" .
                "  --verbose, -v              Show detailed logs\n" .
                "\n" .
                "Examples:\n" .
                "  # Direct MySQL:\n" .
                "  php import.php db-apply - /path/to/import \\\n" .
                "    --target-user=root --target-db=wp_new \\\n" .
                "    --rewrite-url https://old.com https://new.com\n" .
                "\n" .
                "  # Via WordPress \$wpdb (MySQL or SQLite):\n" .
                "  php import.php db-apply - /path/to/import \\\n" .
                "    --wp-load=/path/to/wordpress/wp-load.php \\\n" .
                "    --rewrite-url https://old.com https://new.com\n",
        ],
        "preflight" => [
            "short" => "Run preflight check and print the full result as JSON",
            "detail" =>
                "Contacts the export server and collects environment details:\n" .
                "PHP/MySQL versions, memory limits, filesystem access, database\n" .
                "connectivity, WordPress version, plugins, themes, and directory layout.\n" .
                "\n" .
                "Prints the full preflight response as pretty-printed JSON.\n" .
                "Exits 0 if the server reported OK, 1 otherwise.\n" .
                "\n" .
                "Options:\n" .
                "  --secret=TOKEN   HMAC shared secret for export API authentication\n",
        ],
        "preflight-assert" => [
            "short" => "Check if migration is feasible (exits 0 or 1)",
            "detail" =>
                "Runs the same preflight check as the preflight command, then\n" .
                "evaluates key assertions:\n" .
                "\n" .
                "  - Server responded with HTTP 200\n" .
                "  - Preflight OK flag is set\n" .
                "  - Filesystem directories are accessible\n" .
                "  - Database connection works\n" .
                "\n" .
                "Prints a PASS/FAIL summary and exits 0 if all checks pass, 1 if not.\n" .
                "\n" .
                "Options:\n" .
                "  --secret=TOKEN   HMAC shared secret for export API authentication\n",
        ],
    ];

    // Show main help when invoked with no arguments or just --help
    if ($argc < 2 || (isset($argv[1]) && in_array($argv[1], ["--help", "-h", "help"]))) {
        echo "Usage: php import.php <command> <remote-url> --state-dir=DIR --docroot=DIR [options]\n";
        echo "\n";
        echo "Commands:\n";
        $max_len = max(array_map('strlen', array_keys($command_help)));
        foreach ($command_help as $name => $info) {
            echo "  " . str_pad($name, $max_len + 2) . $info["short"] . "\n";
        }
        echo "\n";
        echo "Run 'php import.php <command> --help' for command-specific help.\n";
        echo "\n";
        echo "Required options:\n";
        echo "  --state-dir=DIR      Directory for import state files and SQL dumps\n";
        echo "  --docroot=DIR        Directory where downloaded site files are written\n";
        echo "\n";
        echo "Global options:\n";
        echo "  --secret=TOKEN       HMAC shared secret for export API authentication\n";
        echo "  --abort            Abort current sync and exit (preserves downloaded files)\n";
        echo "  --no-follow-symlinks Do not follow symlinks pointing outside root directories\n";
        echo "  --on-docroot-nonempty=MODE\n";
        echo "                       What to do when docroot is non-empty (error|preserve-local)\n";
        echo "  --verbose, -v        Show detailed request/response logs\n";
        echo "  --no-adaptive        Disable adaptive request tuning\n";
        echo "  --step=N             Current pipeline step (1-indexed, for status file)\n";
        echo "  --steps=N            Total pipeline steps (for status file)\n";
        echo "\n";
        echo "Exit codes:\n";
        echo "  0  Command completed successfully\n";
        echo "  2  Partial progress — run the same command again to continue\n";
        echo "  1  Error\n";
        echo "\n";
        echo "State is stored in --state-dir/.import-state.json. Interrupted\n";
        echo "commands automatically resume. Use --abort to abort the current\n";
        echo "sync and exit — downloaded files are preserved.\n";
        exit(1);
    }

    $command = $argv[1];

    // Per-command --help (can be requested before providing url/path)
    if (in_array("--help", array_slice($argv, 2)) || in_array("-h", array_slice($argv, 2))) {
        if (isset($command_help[$command])) {
            echo "Usage: php import.php {$command} <remote-url> --state-dir=DIR --docroot=DIR [options]\n";
            echo "\n";
            echo $command_help[$command]["detail"] . "\n";
        } else {
            fwrite(STDERR, "Unknown command: {$command}\n");
        }
        exit(0);
    }

    $remote_url = $argv[2] ?? null;

    if (!$remote_url) {
        fwrite(STDERR, "Error: <remote-url> is required\n");
        fwrite(STDERR, "Usage: php import.php {$command} <remote-url> --state-dir=DIR --docroot=DIR [options]\n");
        exit(1);
    }

    // Parse options (--state-dir and --docroot are required named options)
    $state_dir = null;
    $docroot = null;
    $options = [
        "command" => $command,
        "abort" => false,
        "verbose" => false,
        "secret" => null,
        "tuning_config" => [],
    ];

    for ($i = 3; $i < $argc; $i++) {
        if (strpos($argv[$i], "--state-dir=") === 0) {
            $state_dir = substr($argv[$i], strlen("--state-dir="));
        } elseif (strpos($argv[$i], "--docroot=") === 0) {
            $docroot = substr($argv[$i], strlen("--docroot="));
        } elseif (strpos($argv[$i], "--secret=") === 0) {
            $options["secret"] = substr($argv[$i], strlen("--secret="));
        } elseif ($argv[$i] === "--abort") {
            $options["abort"] = true;
        } elseif ($argv[$i] === "--verbose" || $argv[$i] === "-v") {
            $options["verbose"] = true;
        } elseif ($argv[$i] === "--follow-symlinks") {
            $options["follow_symlinks"] = true;
        } elseif ($argv[$i] === "--no-follow-symlinks") {
            $options["follow_symlinks"] = false;
        } elseif (strpos($argv[$i], "--on-docroot-nonempty=") === 0) {
            $options["docroot_nonempty_behavior"] = substr(
                $argv[$i],
                strlen("--on-docroot-nonempty="),
            );
        } elseif (strpos($argv[$i], "--duty=") === 0) {
            $options["tuning_config"]["duty"] = (float) substr(
                $argv[$i],
                strlen("--duty="),
            );
        } elseif (strpos($argv[$i], "--duty-min=") === 0) {
            $options["tuning_config"]["duty_min"] = (float) substr(
                $argv[$i],
                strlen("--duty-min="),
            );
        } elseif (strpos($argv[$i], "--duty-max=") === 0) {
            $options["tuning_config"]["duty_max"] = (float) substr(
                $argv[$i],
                strlen("--duty-max="),
            );
        } elseif (strpos($argv[$i], "--throughput-alpha=") === 0) {
            $options["tuning_config"]["throughput_ema_alpha"] = (float) substr(
                $argv[$i],
                strlen("--throughput-alpha="),
            );
        } elseif (strpos($argv[$i], "--aimd-drop-ratio=") === 0) {
            $options["tuning_config"]["aimd_drop_ratio"] = (float) substr(
                $argv[$i],
                strlen("--aimd-drop-ratio="),
            );
        } elseif (strpos($argv[$i], "--aimd-decrease-factor=") === 0) {
            $options["tuning_config"]["aimd_decrease_factor"] = (float) substr(
                $argv[$i],
                strlen("--aimd-decrease-factor="),
            );
        } elseif (strpos($argv[$i], "--error-decrease-factor=") === 0) {
            $options["tuning_config"]["error_decrease_factor"] = (float) substr(
                $argv[$i],
                strlen("--error-decrease-factor="),
            );
        } elseif (strpos($argv[$i], "--aimd-increase-file=") === 0) {
            $options["tuning_config"]["aimd_increase_file_bytes"] = (int) substr(
                $argv[$i],
                strlen("--aimd-increase-file="),
            );
        } elseif (strpos($argv[$i], "--aimd-increase-index=") === 0) {
            $options["tuning_config"]["aimd_increase_index_entries"] = (int) substr(
                $argv[$i],
                strlen("--aimd-increase-index="),
            );
        } elseif (strpos($argv[$i], "--aimd-increase-sql=") === 0) {
            $options["tuning_config"]["aimd_increase_sql_fragments"] = (int) substr(
                $argv[$i],
                strlen("--aimd-increase-sql="),
            );
        } elseif ($argv[$i] === "--tune-all") {
            $options["tuning_config"]["tune_only_partial"] = false;
        } elseif (strpos($argv[$i], "--buffered-ratio=") === 0) {
            $options["tuning_config"]["buffered_ratio_threshold"] = (float) substr(
                $argv[$i],
                strlen("--buffered-ratio="),
            );
        } elseif (strpos($argv[$i], "--buffered-min-time=") === 0) {
            $options["tuning_config"]["buffered_min_server_time"] = (float) substr(
                $argv[$i],
                strlen("--buffered-min-time="),
            );
        } elseif (strpos($argv[$i], "--buffered-cooldown=") === 0) {
            $options["tuning_config"]["buffered_cooldown"] = (int) substr(
                $argv[$i],
                strlen("--buffered-cooldown="),
            );
        } elseif (strpos($argv[$i], "--error-backoff=") === 0) {
            $options["tuning_config"]["error_backoff_requests"] = (int) substr(
                $argv[$i],
                strlen("--error-backoff="),
            );
        } elseif (strpos($argv[$i], "--slow-host-threshold=") === 0) {
            $options["tuning_config"]["slow_host_threshold"] = (int) substr(
                $argv[$i],
                strlen("--slow-host-threshold="),
            );
        } elseif (strpos($argv[$i], "--slow-file-chunk-max=") === 0) {
            $options["tuning_config"]["slow_host_file_chunk_max"] = (int) substr(
                $argv[$i],
                strlen("--slow-file-chunk-max="),
            );
        } elseif (strpos($argv[$i], "--slow-index-batch-max=") === 0) {
            $options["tuning_config"]["slow_host_index_batch_max"] = (int) substr(
                $argv[$i],
                strlen("--slow-index-batch-max="),
            );
        } elseif (strpos($argv[$i], "--slow-sql-fragments-max=") === 0) {
            $options["tuning_config"]["slow_host_sql_fragments_max"] = (int) substr(
                $argv[$i],
                strlen("--slow-sql-fragments-max="),
            );
        } elseif (strpos($argv[$i], "--sleep-jitter=") === 0) {
            $options["tuning_config"]["sleep_jitter"] = (float) substr(
                $argv[$i],
                strlen("--sleep-jitter="),
            );
        } elseif (strpos($argv[$i], "--max-exec=") === 0) {
            $options["tuning_config"]["max_execution_time"] = (int) substr(
                $argv[$i],
                strlen("--max-exec="),
            );
        } elseif (strpos($argv[$i], "--memory-threshold=") === 0) {
            $options["tuning_config"]["memory_threshold"] = (float) substr(
                $argv[$i],
                strlen("--memory-threshold="),
            );
        } elseif ($argv[$i] === "--no-adaptive") {
            $options["tuning_config"]["enabled"] = false;
        } elseif ($argv[$i] === "--adaptive") {
            $options["tuning_config"]["enabled"] = true;
        } elseif (strpos($argv[$i], "--file-chunk-start=") === 0) {
            $options["tuning_config"]["file_chunk_start"] = (int) substr(
                $argv[$i],
                strlen("--file-chunk-start="),
            );
        } elseif (strpos($argv[$i], "--file-chunk-min=") === 0) {
            $options["tuning_config"]["file_chunk_min"] = (int) substr(
                $argv[$i],
                strlen("--file-chunk-min="),
            );
        } elseif (strpos($argv[$i], "--file-chunk-max=") === 0) {
            $options["tuning_config"]["file_chunk_max"] = (int) substr(
                $argv[$i],
                strlen("--file-chunk-max="),
            );
        } elseif (strpos($argv[$i], "--index-batch-start=") === 0) {
            $options["tuning_config"]["index_batch_start"] = (int) substr(
                $argv[$i],
                strlen("--index-batch-start="),
            );
        } elseif (strpos($argv[$i], "--index-batch-min=") === 0) {
            $options["tuning_config"]["index_batch_min"] = (int) substr(
                $argv[$i],
                strlen("--index-batch-min="),
            );
        } elseif (strpos($argv[$i], "--index-batch-max=") === 0) {
            $options["tuning_config"]["index_batch_max"] = (int) substr(
                $argv[$i],
                strlen("--index-batch-max="),
            );
        } elseif (strpos($argv[$i], "--sql-fragments-start=") === 0) {
            $options["tuning_config"]["sql_fragments_start"] = (int) substr(
                $argv[$i],
                strlen("--sql-fragments-start="),
            );
        } elseif (strpos($argv[$i], "--sql-fragments-min=") === 0) {
            $options["tuning_config"]["sql_fragments_min"] = (int) substr(
                $argv[$i],
                strlen("--sql-fragments-min="),
            );
        } elseif (strpos($argv[$i], "--sql-fragments-max=") === 0) {
            $options["tuning_config"]["sql_fragments_max"] = (int) substr(
                $argv[$i],
                strlen("--sql-fragments-max="),
            );
        } elseif ($argv[$i] === "--db-unbuffered") {
            $options["tuning_config"]["db_unbuffered"] = true;
        } elseif (strpos($argv[$i], "--db-query-time-limit=") === 0) {
            $options["tuning_config"]["db_query_time_limit"] = (int) substr(
                $argv[$i],
                strlen("--db-query-time-limit="),
            );
        } elseif (strpos($argv[$i], "--max-allowed-packet=") === 0) {
            $options["max_allowed_packet"] = parse_size(
                substr($argv[$i], strlen("--max-allowed-packet=")),
            );
        } elseif (strpos($argv[$i], "--step=") === 0) {
            $options["pipeline_step"] = (int) substr($argv[$i], strlen("--step="));
        } elseif (strpos($argv[$i], "--steps=") === 0) {
            $options["pipeline_steps"] = (int) substr($argv[$i], strlen("--steps="));
        } elseif (strpos($argv[$i], "--sql-output=") === 0) {
            $options["sql_output"] = substr($argv[$i], strlen("--sql-output="));
        } elseif (strpos($argv[$i], "--mysql-host=") === 0) {
            $options["mysql_host"] = substr($argv[$i], strlen("--mysql-host="));
        } elseif (strpos($argv[$i], "--mysql-port=") === 0) {
            $options["mysql_port"] = substr($argv[$i], strlen("--mysql-port="));
        } elseif (strpos($argv[$i], "--mysql-user=") === 0) {
            $options["mysql_user"] = substr($argv[$i], strlen("--mysql-user="));
        } elseif (strpos($argv[$i], "--mysql-password=") === 0) {
            $options["mysql_password"] = substr($argv[$i], strlen("--mysql-password="));
        } elseif (strpos($argv[$i], "--mysql-database=") === 0) {
            $options["mysql_database"] = substr($argv[$i], strlen("--mysql-database="));
        } elseif (strpos($argv[$i], "--target-host=") === 0) {
            $options["target_host"] = substr($argv[$i], strlen("--target-host="));
        } elseif (strpos($argv[$i], "--target-port=") === 0) {
            $options["target_port"] = (int) substr($argv[$i], strlen("--target-port="));
        } elseif (strpos($argv[$i], "--target-user=") === 0) {
            $options["target_user"] = substr($argv[$i], strlen("--target-user="));
        } elseif (strpos($argv[$i], "--target-pass=") === 0) {
            $options["target_pass"] = substr($argv[$i], strlen("--target-pass="));
        } elseif (strpos($argv[$i], "--target-db=") === 0) {
            $options["target_db"] = substr($argv[$i], strlen("--target-db="));
        } elseif (strpos($argv[$i], "--wp-load=") === 0) {
            $options["wp_load"] = substr($argv[$i], strlen("--wp-load="));
        } elseif ($argv[$i] === "--rewrite-url") {
            if (!isset($argv[$i + 1]) || !isset($argv[$i + 2])) {
                fwrite(STDERR, "--rewrite-url requires two arguments: FROM TO\n");
                exit(1);
            }
            if (!isset($options["rewrite_url"])) {
                $options["rewrite_url"] = [];
            }
            $options["rewrite_url"][] = [$argv[$i + 1], $argv[$i + 2]];
            $i += 2;
        } elseif (strpos($argv[$i], "--new-site-url=") === 0) {
            $options["new_site_url"] = substr($argv[$i], strlen("--new-site-url="));
        } elseif ($argv[$i] === "--new-site-url") {
            if (!isset($argv[$i + 1])) {
                fwrite(STDERR, "--new-site-url requires one argument: URL\n");
                exit(1);
            }
            $options["new_site_url"] = $argv[$i + 1];
            $i += 1;
        } else {
            fwrite(STDERR, "Unknown option: {$argv[$i]}\n");
            exit(1);
        }
    }

    if (!$state_dir) {
        fwrite(STDERR, "Error: --state-dir=DIR is required\n");
        fwrite(STDERR, "Usage: php import.php {$command} <remote-url> --state-dir=DIR --docroot=DIR [options]\n");
        exit(1);
    }

    if (!$docroot) {
        fwrite(STDERR, "Error: --docroot=DIR is required\n");
        fwrite(STDERR, "Usage: php import.php {$command} <remote-url> --state-dir=DIR --docroot=DIR [options]\n");
        exit(1);
    }

    try {
        $client = new ImportClient($remote_url, $state_dir, $docroot);
        $client->run($options);
        exit($client->exit_code);
    } catch (\Throwable $e) {
        $error = [
            "error" => $e->getMessage(),
            "exception" => get_class($e),
            "file" => $e->getFile(),
            "line" => $e->getLine(),
        ];
        $json = json_encode($error);
        if ($json === false) {
            $json = '{"error":"' . addslashes($e->getMessage()) . '","exception":"' . get_class($e) . '"}';
        }
        fwrite(STDERR, $json . "\n");
        exit(1);
    }
}
