<?php

namespace Reprint\Importer\Application\UseCase;

use InvalidArgumentException;
use Reprint\Importer\Application\AbstractCommandHandler;
use Reprint\Importer\Application\ImportContext;
use Reprint\Importer\Application\ImportServices;
use Reprint\Importer\Application\Result\ImportCommandResult;
use Reprint\Importer\Filesystem\FlatDocumentRootBuilder;

final class FlatDocrootHandler extends AbstractCommandHandler
{
    public function execute(
        ImportContext $context,
        ImportServices $services,
        array $options
    ): ?ImportCommandResult {
        $flatten_to = $options["flatten_to"] ?? null;
        if (empty($flatten_to)) {
            throw new InvalidArgumentException("flat-docroot requires --flatten-to=PATH");
        }

        $context->require_preflight();
        $result = FlatDocumentRootBuilder::build(
            $context->fs_root(),
            rtrim($flatten_to, "/"),
            $context->preflight_data() ?? [],
            (bool) ($options["force"] ?? false),
            function (string $message, bool $to_console = true) use ($context): void {
                $context->audit_log($message, $to_console);
            },
        );

        if (!$context->output()->is_quiet_lifecycle()) {
            $context->output()->write(json_encode($result) . "\n");
        }
        $context->output_progress(array_merge(["type" => "flat_docroot_complete"], $result));

        return null;
    }
}
