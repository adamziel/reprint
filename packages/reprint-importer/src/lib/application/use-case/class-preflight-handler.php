<?php

namespace Reprint\Importer\Application\UseCase;

use Reprint\Importer\Application\AbstractCommandHandler;
use Reprint\Importer\Application\ImportContext;
use Reprint\Importer\Application\ImportServices;
use Reprint\Importer\Application\Result\ImportCommandResult;
use Reprint\Importer\Application\Result\PreflightReportResult;
use Reprint\Importer\Session\PreflightCheckpoint;
use function Reprint\Importer\Host\detect_host;

final class PreflightHandler extends AbstractCommandHandler
{
    public function execute(
        ImportContext $context,
        ImportServices $services,
        array $options
    ): ?ImportCommandResult {
        return new PreflightReportResult($this->fetch($context, $services));
    }

    /**
     * @return array<string, mixed>
     */
    public function fetch(ImportContext $context, ImportServices $services): array
    {
        $url = $context->build_url("preflight", null, []);
        $context->audit_log("PREFLIGHT REQUEST | {$url}", false);

        $result = null;
        $payload = null;
        foreach (ImportContext::USER_AGENTS as $ua) {
            $context->set_request_user_agent($ua);
            $result = $context->fetch_json($url);
            $payload = $result["json"] ?? null;
            if ($payload !== null) {
                $context->audit_log("USER-AGENT OK | {$ua}", false);
                break;
            }
            $context->audit_log("USER-AGENT BLOCKED | {$ua}", false);
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

        $remote_protocol_version = is_array($payload) && isset($payload["protocol_version"])
            ? (int) $payload["protocol_version"]
            : null;
        $remote_protocol_min_version = is_array($payload) && isset($payload["protocol_min_version"])
            ? (int) $payload["protocol_min_version"]
            : null;

        $wp_version = is_array($payload) ? ($payload["database"]["wp"]["wp_version"] ?? null) : null;
        $exporter_version = is_string($wp_version) && $wp_version !== "" ? $wp_version : null;

        $detected_webhost = is_array($payload) ? detect_host($payload) : "other";
        if ($detected_webhost === "other" && $context->has_wpcloud_docroot_link()) {
            $detected_webhost = "wpcloud";
        }
        $context->audit_log("WEBHOST DETECTED | {$detected_webhost}", true);

        $context->save_preflight_checkpoint(new PreflightCheckpoint(
            $entry,
            $remote_protocol_version,
            $remote_protocol_min_version,
            $exporter_version,
            $detected_webhost,
        ));

        $context->write_status_file();
        $context->audit_log("PREFLIGHT RESULT | " . json_encode($entry), false);

        $paths = is_array($payload) ? ($payload["database"]["wp"]["paths_urls"] ?? null) : null;
        if (is_array($paths)) {
            $this->log_non_standard_layout($context, $paths);
        }

        $services->runtime()->download_runtime_files();

        return $entry;
    }

    /**
     * @param array<string, mixed> $paths
     */
    private function log_non_standard_layout(ImportContext $context, array $paths): void
    {
        $abspath = rtrim($paths["abspath"] ?? "", "/");
        $content_dir = rtrim($paths["content_dir"] ?? "", "/");
        $uploads_basedir = rtrim($paths["uploads"]["basedir"] ?? "", "/");

        if ($abspath !== "" && $content_dir !== "" && $content_dir !== $abspath . "/wp-content") {
            $context->audit_log(
                "NON-STANDARD LAYOUT | wp-content is at {$content_dir} " .
                    "(expected {$abspath}/wp-content)",
            );
        }
        if ($content_dir !== "" && $uploads_basedir !== "" && strpos($uploads_basedir, $content_dir) !== 0) {
            $context->audit_log(
                "NON-STANDARD LAYOUT | uploads at {$uploads_basedir} " .
                    "is outside wp-content ({$content_dir})",
            );
        }
    }
}
