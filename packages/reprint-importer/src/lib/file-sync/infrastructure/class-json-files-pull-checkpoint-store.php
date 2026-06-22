<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\FileSync\Port\ProgressTicker;
use Reprint\Importer\Session\ImportPaths;
use Reprint\Importer\Session\JsonStateStore;
use Reprint\Importer\Session\StatePathCodec;

final class JsonFilesPullCheckpointStore implements FilesPullCheckpointStore
{
    private JsonStateStore $store;
    private ImportPaths $paths;
    private StatePathCodec $path_codec;
    private ProgressTicker $ticker;
    private ?FilesPullCheckpoint $checkpoint = null;

    public function __construct(
        JsonStateStore $store,
        ImportPaths $paths,
        StatePathCodec $path_codec,
        ProgressTicker $ticker
    ) {
        $this->store = $store;
        $this->paths = $paths;
        $this->path_codec = $path_codec;
        $this->ticker = $ticker;
    }

    public function get(): FilesPullCheckpoint
    {
        if ($this->checkpoint instanceof FilesPullCheckpoint) {
            return $this->checkpoint;
        }

        $data = $this->store->load($this->paths->files_pull_checkpoint_file()) ?? [];
        $this->checkpoint = FilesPullCheckpoint::from_persisted_array(
            $data,
            [$this->path_codec, 'decode_value'],
        );

        return $this->checkpoint;
    }

    public function save(FilesPullCheckpoint $checkpoint): void
    {
        $this->ticker->tick();
        $this->checkpoint = $checkpoint;
        $this->store->save(
            $this->paths->files_pull_checkpoint_file(),
            $checkpoint->to_persisted_array([$this->path_codec, 'encode_value']),
        );
    }

    public function clear_cached(): void
    {
        $this->checkpoint = null;
    }
}
