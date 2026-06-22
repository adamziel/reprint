<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\Session\ImportPaths;
use Reprint\Importer\Session\JsonStateStore;
use Reprint\Importer\Session\StatePathCodec;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\Port\DbPullCheckpointStore;

final class JsonDbPullCheckpointStore implements DbPullCheckpointStore
{
    private JsonStateStore $store;
    private ImportPaths $paths;
    private StatePathCodec $path_codec;
    private ImportOutput $output;

    public function __construct(
        JsonStateStore $store,
        ImportPaths $paths,
        StatePathCodec $path_codec,
        ImportOutput $output
    ) {
        $this->store = $store;
        $this->paths = $paths;
        $this->path_codec = $path_codec;
        $this->output = $output;
    }

    public function get(): DbPullCheckpoint
    {
        return DbPullCheckpoint::from_persisted_array(
            $this->store->load($this->paths->db_pull_checkpoint_file()) ?? [],
            [$this->path_codec, 'decode_value'],
        );
    }

    public function save(DbPullCheckpoint $checkpoint): void
    {
        $this->output->tick_spinner();
        $this->store->save(
            $this->paths->db_pull_checkpoint_file(),
            $checkpoint->to_persisted_array([$this->path_codec, 'encode_value']),
        );
    }
}
