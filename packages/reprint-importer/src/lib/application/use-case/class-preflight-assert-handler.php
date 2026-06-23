<?php

namespace Reprint\Importer\Application\UseCase;

use Reprint\Importer\Application\AbstractCommandHandler;
use Reprint\Importer\Application\ImportContext;
use Reprint\Importer\Application\ImportServices;
use Reprint\Importer\Command\ImportCommandResult;
use Reprint\Importer\Command\PreflightAssertResult;

final class PreflightAssertHandler extends AbstractCommandHandler
{
    public function requires_preflight(): bool
    {
        return true;
    }

    public function execute(
        ImportContext $context,
        ImportServices $services,
        array $options
    ): ?ImportCommandResult {
        $checkpoint = $context->preflight_checkpoint();
        $entry = $checkpoint->entry;
        $data = is_array($entry) && is_array($entry["data"] ?? null) ? $entry["data"] : null;
        $checks = [];
        $all_pass = true;

        $http_ok = is_array($entry) && ($entry["http_code"] ?? 0) === 200;
        $checks[] = [
            "label" => "Server responded",
            "pass" => $http_ok,
            "detail" => $http_ok
                ? "HTTP 200"
                : "HTTP " . (is_array($entry) ? ($entry["http_code"] ?? "no response") : "no response"),
        ];
        $all_pass = $all_pass && $http_ok;

        $top_ok = is_array($data) && !empty($data["ok"]);
        $checks[] = [
            "label" => "Preflight OK",
            "pass" => $top_ok,
            "detail" => $top_ok
                ? "passed"
                : (is_array($data) ? ($data["error"] ?? "preflight not ok") : "preflight not ok"),
        ];
        $all_pass = $all_pass && $top_ok;

        $remote_ver = $checkpoint->remote_protocol_version;
        $remote_min = $checkpoint->remote_protocol_min_version;
        if ($remote_ver === null) {
            $proto_ok = false;
            $proto_detail = "Remote export plugin does not report a protocol version. Update the export plugin.";
        } elseif ($remote_ver < REPRINT_IMPORTER_MIN_EXPORT_VERSION) {
            $proto_ok = false;
            $proto_detail = "Remote protocol v{$remote_ver} is too old (client requires >= v" . REPRINT_IMPORTER_MIN_EXPORT_VERSION . "). Update the export plugin.";
        } elseif ($remote_min !== null && REPRINT_IMPORTER_PROTOCOL_VERSION < $remote_min) {
            $proto_ok = false;
            $proto_detail = "Client protocol v" . REPRINT_IMPORTER_PROTOCOL_VERSION . " is too old (remote requires >= v{$remote_min}). Update the importer.";
        } else {
            $proto_ok = true;
            $proto_detail = "remote v{$remote_ver}, client v" . REPRINT_IMPORTER_PROTOCOL_VERSION;
        }
        $checks[] = ["label" => "Protocol compatible", "pass" => $proto_ok, "detail" => $proto_detail];
        $all_pass = $all_pass && $proto_ok;

        $fs = is_array($data) ? ($data["filesystem"] ?? null) : null;
        $fs_ok = is_array($fs) && !empty($fs["ok"]);
        $checks[] = [
            "label" => "Filesystem accessible",
            "pass" => $fs_ok,
            "detail" => $fs_ok ? "directories readable" : ($fs["error"] ?? "filesystem check failed"),
        ];
        $all_pass = $all_pass && $fs_ok;

        $db = is_array($data) ? ($data["database"] ?? null) : null;
        $db_ok = is_array($db) && !empty($db["connected"]);
        $checks[] = [
            "label" => "Database accessible",
            "pass" => $db_ok,
            "detail" => $db_ok ? ($db["version"] ?? "connected") : ($db["error"] ?? "database check failed"),
        ];
        $all_pass = $all_pass && $db_ok;

        return new PreflightAssertResult($checks, $all_pass);
    }
}
