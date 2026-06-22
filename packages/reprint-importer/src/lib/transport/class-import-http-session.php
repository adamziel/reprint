<?php

namespace Reprint\Importer\Transport;

use CURLFile;
use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Tuning\AdaptiveTuner;
use RuntimeException;

final class ImportHttpSession
{
    public const USER_AGENTS = HttpRequestBuilder::USER_AGENTS;

    private string $remote_url;
    private ImportHttpTransport $transport;
    private ?AdaptiveTuner $tuner = null;
    private $hmac_client = null;
    private ?string $user_agent = null;
    private ?string $last_error_code = null;
    private ?int $max_allowed_packet = null;
    private $audit_log;
    private $emit_progress;

    public function __construct(
        string $remote_url,
        ImportOutput $output,
        callable $audit_log,
        callable $emit_progress
    ) {
        $this->remote_url = $remote_url;
        $this->transport = new ImportHttpTransport($output);
        $this->audit_log = $audit_log;
        $this->emit_progress = $emit_progress;
    }

    public function ensure_site_export_api_url(): void
    {
        if (strpos($this->remote_url, 'site-export-api') !== false) {
            return;
        }

        $separator = strpos($this->remote_url, '?') === false ? '?' : '&';
        $this->remote_url .= $separator . 'site-export-api';
    }

    public function remote_url(): string
    {
        return $this->remote_url;
    }

    public function remote_host(): string
    {
        return parse_url($this->remote_url, PHP_URL_HOST) ?? $this->remote_url;
    }

    public function set_user_agent(string $user_agent): void
    {
        $this->user_agent = $user_agent;
    }

    public function set_hmac_secret(?string $secret): void
    {
        $this->hmac_client = $secret !== null && $secret !== ''
            ? new \Reprint\Exporter\Site_Export_HMAC_Client($secret)
            : null;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $state
     */
    public function configure_tuner(
        array $config,
        array $state,
        ?int $max_allowed_packet
    ): void {
        $this->max_allowed_packet = $max_allowed_packet;
        $this->tuner = new AdaptiveTuner($config, $state);
    }

    public function has_tuner(): bool
    {
        return $this->tuner instanceof AdaptiveTuner;
    }

    /**
     * @return array<string, mixed>
     */
    public function tuning_config(): array
    {
        return $this->tuner instanceof AdaptiveTuner
            ? $this->tuner->get_config()
            : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function tuning_state(): array
    {
        return $this->tuner instanceof AdaptiveTuner
            ? $this->tuner->get_state()
            : [];
    }

    public function build_url(
        string $endpoint,
        ?string $cursor,
        array $params = []
    ): string {
        return HttpRequestBuilder::url($this->remote_url, $endpoint, $cursor, $params);
    }

    public function tuned_params(string $endpoint): array
    {
        if (!$this->tuner instanceof AdaptiveTuner) {
            return [];
        }

        $params = $this->tuner->get_request_params($endpoint);
        if ($endpoint === "sql_chunk" && $this->max_allowed_packet !== null) {
            $params["max_allowed_packet"] = $this->max_allowed_packet;
        }
        if (!empty($params)) {
            $this->audit(
                "TUNER REQUEST | endpoint={$endpoint} | params=" .
                    json_encode($params),
                false,
            );
        }

        return $params;
    }

    public function fetch_json(string $url): array
    {
        $this->audit("HTTP_REQUEST | GET | {$url}", false);

        $result = $this->transport->fetch_json(
            $url,
            $this->json_request_headers(''),
            $this->has_hmac_secret(),
        );

        if (!empty($result["error_code"])) {
            $this->last_error_code = $result["error_code"];
        } elseif ($this->transport->last_error_code() !== null) {
            $this->last_error_code = $this->transport->last_error_code();
        }

        return $result;
    }

    public function fetch_streaming(
        string $url,
        ?string $cursor,
        StreamingContext $context,
        ?array $post_data = null,
        ?string $endpoint = null
    ): void {
        $body_for_signing = ImportHttpTransport::body_for_signing($post_data);

        $this->audit(
            $this->streaming_request_log_message($url, $post_data),
            false,
        );
        $this->progress(["debug" => "Waiting for server response..."]);

        try {
            $this->transport->fetch_streaming(
                $url,
                $context,
                $this->streaming_request_headers($cursor, $body_for_signing),
                $post_data,
                $this->has_hmac_secret(),
            );
        } catch (RuntimeException $e) {
            $this->sync_transport_error_state();
            if ($endpoint !== null) {
                $this->handle_transport_tuner_error($endpoint);
            }

            $http_code = $this->transport->last_http_code();
            if ($http_code !== null && $http_code !== 200) {
                $this->audit(
                    "HTTP error {$http_code} | error_body length: " .
                        (int) ($this->transport->last_error_body_length() ?? 0),
                    true,
                );
            }

            throw $e;
        }
    }

    public function finalize_tuned_request(
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
        $this->audit(implode(" | ", $log), false);

        $sleep = (float) ($decision["sleep_seconds"] ?? 0);
        if ($sleep > 0) {
            usleep((int) round($sleep * 1_000_000));
        }
    }

    public function last_error_code(): ?string
    {
        return $this->last_error_code ?? $this->transport->last_error_code();
    }

    private function json_request_headers(string $body): array
    {
        return [
            ...HttpRequestBuilder::base_headers(
                "application/json",
                $this->user_agent,
            ),
            ...$this->hmac_headers($body),
        ];
    }

    private function streaming_request_headers(
        ?string $cursor,
        string $body
    ): array {
        $headers = [
            ...HttpRequestBuilder::base_headers(
                "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8",
                $this->user_agent,
            ),
            "Upgrade-Insecure-Requests: 1",
            "Sec-Fetch-Dest: document",
            "Sec-Fetch-Mode: navigate",
            "Sec-Fetch-Site: none",
            "Sec-Fetch-User: ?1",
        ];

        if ($cursor) {
            $headers[] = "X-Export-Cursor: {$cursor}";
        }

        return [
            ...$headers,
            ...$this->hmac_headers($body),
        ];
    }

    private function hmac_headers(string $body): array
    {
        if ($this->hmac_client === null) {
            return [];
        }

        return $this->hmac_client->get_curl_headers($body);
    }

    private function has_hmac_secret(): bool
    {
        return $this->hmac_client !== null;
    }

    private function streaming_request_log_message(
        string $url,
        ?array $post_data
    ): string {
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

        return implode(" | ", $log_parts);
    }

    private function sync_transport_error_state(): void
    {
        if ($this->transport->last_error_code() !== null) {
            $this->last_error_code = $this->transport->last_error_code();
        }
    }

    private function handle_transport_tuner_error(string $endpoint): void
    {
        $http_code = $this->transport->last_http_code();
        $curl_errno = $this->transport->last_curl_errno();

        if (
            $curl_errno === null &&
            ($http_code === null || $http_code === 200)
        ) {
            return;
        }

        $this->handle_tuner_error($endpoint, [
            "http_code" => $http_code ?? 0,
            "timeout" => $this->transport->last_curl_timed_out(),
            "curl_errno" => $curl_errno ?? 0,
        ]);
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
        $this->audit(implode(" | ", $log), false);
    }

    private function audit(string $message, bool $to_console): void
    {
        ($this->audit_log)($message, $to_console);
    }

    private function progress(array $event): void
    {
        ($this->emit_progress)($event);
    }
}
