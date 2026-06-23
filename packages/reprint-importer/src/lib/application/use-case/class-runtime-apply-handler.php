<?php

namespace Reprint\Importer\Application\UseCase;

use RuntimeException;
use Reprint\Importer\Application\AbstractCommandHandler;
use Reprint\Importer\Application\ImportContext;
use Reprint\Importer\Application\ImportServices;
use Reprint\Importer\Command\ImportCommandResult;
use Reprint\Importer\TargetRuntime\RuntimeConfigurationApplier;

final class RuntimeApplyHandler extends AbstractCommandHandler
{
    public function execute(
        ImportContext $context,
        ImportServices $services,
        array $options
    ): ?ImportCommandResult {
        $preflight_data = $context->preflight_data();
        if ($preflight_data === null) {
            throw new RuntimeException(
                "apply-runtime requires a prior preflight run. " .
                    "Run 'preflight' first to capture the source site's environment.",
            );
        }

        $result = (new RuntimeConfigurationApplier($context->audit_logger()))->apply([
            "runtime" => $options["runtime"] ?? null,
            "output_dir" => $options["output_dir"] ?? null,
            "fs_root" => $context->fs_root(),
            "flat_document_root" => $options["flat_document_root"] ?? null,
            "preflight_data" => $preflight_data,
            "webhost" => $context->detected_webhost(),
            "apply_state" => $context->db_apply_checkpoint()->to_array(),
            "host" => $options["host"] ?? null,
            "port" => $options["port"] ?? null,
            "enable_remote_upload_proxy" => $this->should_enable_remote_upload_proxy($context),
            "state_dir" => $context->state_dir(),
        ]);

        $checkpoint = $context->runtime_checkpoint();
        $checkpoint->remote_paths_removed_from_local_site = $result["paths_removed"];
        $context->save_runtime_checkpoint($checkpoint);

        $context->output_progress([
            "status" => "complete",
            "command" => "apply-runtime",
            "runtime" => $result["runtime"],
            "webhost" => $result["webhost"],
            "webhost_source" => $result["webhost_source"],
            "target_engine" => $result["target_engine"],
            "paths_removed" => $result["paths_removed"],
            "extra_directories" => $result["extra_directories"],
            "start_config" => $result["start_config"],
            "message" => "apply-runtime complete (runtime: " . $result["runtime"] . ")",
        ]);

        if (!$context->output()->is_quiet_lifecycle()) {
            $summary = "\n";
            $summary .= "Runtime: " . $result["runtime"] . "\n";
            $summary .= "Source host: " . $result["webhost"] . "\n";
            if ($result["target_engine"] !== null) {
                $summary .= "Target database: " . $result["target_engine"] . "\n";
            }
            $summary .= "\n";
            foreach ($result["summary"] as $line) {
                $summary .= "{$line}\n";
            }
            $context->output()->write_error($summary);
        }

        return null;
    }

    private function should_enable_remote_upload_proxy(ImportContext $context): bool
    {
        if ($context->has_skipped_files_pending()) {
            return true;
        }

        $state = $context->state();
        if ($state->command !== "files-pull") {
            return false;
        }

        return $state->status !== null && $state->status !== "complete";
    }
}
