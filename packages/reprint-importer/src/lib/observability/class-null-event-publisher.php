<?php

namespace Reprint\Importer\Observability;

final class NullEventPublisher implements EventPublisher
{
    public function publish(ImportEvent $event): void
    {
    }
}
