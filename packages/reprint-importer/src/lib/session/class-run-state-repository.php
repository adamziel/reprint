<?php

namespace Reprint\Importer\Session;

use Reprint\Importer\Application\CommandRegistry;
use Reprint\Importer\Observability\AuditLogger;
use RuntimeException;

final class RunStateRepository
{
    private JsonStateStore $store;
    private ImportPaths $paths;
    private AuditLogger $audit;

    public function __construct(
        JsonStateStore $store,
        ImportPaths $paths,
        AuditLogger $audit
    ) {
        $this->store = $store;
        $this->paths = $paths;
        $this->audit = $audit;
    }

    public function fresh(): ImportRunState
    {
        return ImportRunState::fresh();
    }

    public function load(): ImportRunState
    {
        try {
            $state = $this->store->load($this->paths->state_file());
        } catch (RuntimeException $e) {
            $this->audit->record($e->getMessage(), true);

            return $this->fresh();
        }

        if ($state === null) {
            return $this->fresh();
        }

        $run_state = ImportRunState::from_array($state);
        if (is_string($run_state->command)) {
            $run_state->command = CommandRegistry::normalize_name($run_state->command);
        }

        return $run_state;
    }

    public function save(ImportRunState $state): void
    {
        $this->store->save($this->paths->state_file(), $state->to_array());
    }
}
