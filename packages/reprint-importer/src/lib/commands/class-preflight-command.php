<?php

namespace Reprint\Importer\Command;

use Reprint\Importer\ImportClient;
use Reprint\Importer\Session\PreflightCheckpoint;
use function Reprint\Importer\Host\detect_host;

final class PreflightCommand extends ImportCommand
{
    public function execute(ImportClient $client, array $options): ?ImportCommandResult
    {
        return new PreflightReportResult($this->fetch($client));
    }

    /**
     * Run the remote preflight request and persist the result in importer state.
     *
     * @return array<string, mixed>
     */
    public function fetch(ImportClient $client): array
    {
        $url = $client->build_url("preflight", null, []);
        $client->audit_log("PREFLIGHT REQUEST | {$url}", false);

        // Try each User-Agent until one gets a JSON response. Some WAFs block
        // certain UAs, so we cycle through candidates and remember the winner.
        $result = null;
        $payload = null;
        foreach (ImportClient::USER_AGENTS as $ua) {
            $client->set_request_user_agent($ua);
            $result = $client->fetch_json($url);
            $payload = $result["json"] ?? null;
            if ($payload !== null) {
                $client->audit_log("USER-AGENT OK | {$ua}", false);
                break;
            }
            $client->audit_log("USER-AGENT BLOCKED | {$ua}", false);
        }

        $entry = [
            "timestamp" => time(),
            "url" => $url,
            "http_code" => (int) ($result["http_code"] ?? 0),
            "elapsed" => (float) ($result["elapsed"] ?? 0),
            "ok" => is_array($payload) ? ($payload["ok"] ?? null) : null,
            "data" => $payload,
            "error" => $result["error"] ?? null,
            "response_body_preview" => $payload === null && isset($result["body"])
                ? substr((string) $result["body"], 0, 200)
                : null,
        ];

        $wp_version = is_array($payload) ? ($payload["database"]["wp"]["wp_version"] ?? null) : null;
        $remote_protocol_version = null;
        $remote_protocol_min_version = null;
        if (is_string($wp_version) && $wp_version !== "") {
            $exporter_version = $wp_version;
        } else {
            $exporter_version = null;
        }

        if (is_array($payload) && isset($payload["protocol_version"])) {
            $remote_protocol_version = (int) $payload["protocol_version"];
        }
        if (is_array($payload) && isset($payload["protocol_min_version"])) {
            $remote_protocol_min_version = (int) $payload["protocol_min_version"];
        }

        $detected_webhost = is_array($payload) ? detect_host($payload) : 'other';
        if ($detected_webhost === 'other' && $client->has_wpcloud_docroot_link()) {
            $detected_webhost = 'wpcloud';
        }
        $client->audit_log("WEBHOST DETECTED | {$detected_webhost}", true);

        $client->save_preflight_checkpoint(new PreflightCheckpoint(
            $entry,
            $remote_protocol_version,
            $remote_protocol_min_version,
            $exporter_version,
            $detected_webhost,
        ));

        $client->write_status_file();

        $client->audit_log(
            "PREFLIGHT RESULT | " . json_encode($entry),
            false,
        );

        $paths = $payload["database"]["wp"]["paths_urls"] ?? null;
        if (is_array($paths)) {
            $this->log_non_standard_layout($client, $paths);
        }

        $client->download_runtime_files();

        return $entry;
    }

    /**
     * @param array<string, mixed> $paths
     */
    private function log_non_standard_layout(ImportClient $client, array $paths): void
    {
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
            $client->audit_log(
                "NON-STANDARD LAYOUT | wp-content is at {$content_dir} " .
                    "(expected {$abspath}/wp-content)",
            );
        }
        if (
            $content_dir !== "" &&
            $uploads_basedir !== "" &&
            strpos($uploads_basedir, $content_dir) !== 0
        ) {
            $client->audit_log(
                "NON-STANDARD LAYOUT | uploads at {$uploads_basedir} " .
                    "is outside wp-content ({$content_dir})",
            );
        }
    }
}
