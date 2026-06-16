<?php

namespace Reprint\Importer\Tuning;

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
        // --no-adaptive means "let the server decide everything" — no
        // tuning hints, no per-request overrides. Returning an empty
        // array keeps max_execution_time / memory_threshold /
        // batch-size knobs out of the export.php URL entirely.
        if (!$this->config["enabled"]) {
            return [];
        }

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
