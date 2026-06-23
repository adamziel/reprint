<?php

namespace Reprint\Importer\Application\UseCase;

use Reprint\Importer\Application\AbstractCommandHandler;
use Reprint\Importer\Application\ImportContext;
use Reprint\Importer\Application\ImportServices;
use Reprint\Importer\Application\PullRuntimeAdapter;
use Reprint\Importer\Command\ImportCommandResult;
use Reprint\Importer\Pull\Pull;

final class PullHandler extends AbstractCommandHandler
{
    public function supports_abort(): bool
    {
        return true;
    }

    public function abort(ImportContext $context, ImportServices $services, string $command): void
    {
        $this->pull($context, $services)->abort();
    }

    public function execute(
        ImportContext $context,
        ImportServices $services,
        array $options
    ): ?ImportCommandResult {
        $this->pull($context, $services)->run($options);
        return null;
    }

    private function pull(ImportContext $context, ImportServices $services): Pull
    {
        return new Pull(
            new PullRuntimeAdapter($context, $services),
            $context->output()->progress(),
        );
    }
}
