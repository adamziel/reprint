<?php

namespace Reprint\Importer\Observability;

interface EventPublisher
{
    public function publish(ImportEvent $event): void;
}
