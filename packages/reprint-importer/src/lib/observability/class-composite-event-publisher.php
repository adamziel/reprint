<?php

namespace Reprint\Importer\Observability;

final class CompositeEventPublisher implements EventPublisher
{
    /** @var array<int, EventPublisher> */
    private array $publishers;

    public function __construct(EventPublisher ...$publishers)
    {
        $this->publishers = $publishers;
    }

    public function publish(ImportEvent $event): void
    {
        foreach ($this->publishers as $publisher) {
            $publisher->publish($event);
        }
    }
}
