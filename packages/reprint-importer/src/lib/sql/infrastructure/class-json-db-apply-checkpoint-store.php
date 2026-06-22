<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\Session\ImportPaths;
use Reprint\Importer\Session\JsonStateStore;
use Reprint\Importer\Sql\DbApplyCheckpoint;
use Reprint\Importer\Sql\Port\DbApplyCheckpointStore;

final class JsonDbApplyCheckpointStore implements DbApplyCheckpointStore
{
    private JsonStateStore $store;
    private ImportPaths $paths;
    private ImportOutput $output;

    public function __construct(
        JsonStateStore $store,
        ImportPaths $paths,
        ImportOutput $output
    ) {
        $this->store = $store;
        $this->paths = $paths;
        $this->output = $output;
    }

    public function get(): DbApplyCheckpoint
    {
        return DbApplyCheckpoint::from_array(
            $this->store->load($this->paths->db_apply_checkpoint_file()) ?? [],
        );
    }

    public function save(DbApplyCheckpoint $checkpoint): void
    {
        $this->output->tick_spinner();
        $this->store->save(
            $this->paths->db_apply_checkpoint_file(),
            $checkpoint->to_array(),
        );
    }
}
