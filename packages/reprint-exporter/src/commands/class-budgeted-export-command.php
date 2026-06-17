<?php

namespace Reprint\Exporter\Command;

use Reprint\Exporter\ResourceBudget;

abstract class BudgetedExportCommand extends ExportCommand
{
    abstract public function execute(array $config, ResourceBudget $budget): array;
}
