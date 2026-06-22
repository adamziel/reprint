<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\Port\FilesSyncRunStore;
use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\Session\ImportRunState;
use Reprint\Importer\Session\JsonStateStore;

final class RunStateFilesSyncRunStore implements FilesSyncRunStore
{
    private ImportRunState $state;
    private JsonStateStore $store;
    private string $state_file;
    private ImportOutput $output;

    public function __construct(
        ImportRunState $state,
        JsonStateStore $store,
        string $state_file,
        ImportOutput $output
    ) {
        $this->state = $state;
        $this->store = $store;
        $this->state_file = $state_file;
        $this->output = $output;
    }

    public function current_command(): ?string
    {
        return $this->state->command;
    }

    public function current_status(): ?string
    {
        return $this->state->status;
    }

    public function record_command_status(string $command, ?string $status): void
    {
        $this->state->set_command_status($command, $status);
        $this->output->tick_spinner();
        $this->store->save($this->state_file, $this->state->to_array());
    }
}
