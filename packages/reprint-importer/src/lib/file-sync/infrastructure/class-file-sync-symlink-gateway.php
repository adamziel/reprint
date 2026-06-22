<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\IntermediateSymlinkRecreator;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\FileSync\Port\RemoteFileIndexGateway;
use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\FileSync\Port\SymlinkGateway;
use Reprint\Importer\FileSync\Port\SymlinkTargetObserver;
use Reprint\Importer\FileSync\SymlinkTargetIndexer;
use Reprint\Importer\Observability\AuditLogger;

final class FileSyncSymlinkGateway implements SymlinkGateway
{
    private string $remote_index_file;
    private RemoteFileIndexGateway $remote_index;
    private FilesPullCheckpointStore $checkpoints;
    private ShutdownToken $shutdown;
    private AuditLogger $audit;
    private SymlinkTargetObserver $observer;
    private IntermediateSymlinkRecreator $recreator;
    private array $roots;

    /**
     * @param array<int, string> $roots
     */
    public function __construct(
        string $remote_index_file,
        RemoteFileIndexGateway $remote_index,
        FilesPullCheckpointStore $checkpoints,
        ShutdownToken $shutdown,
        AuditLogger $audit,
        SymlinkTargetObserver $observer,
        IntermediateSymlinkRecreator $recreator,
        array $roots
    ) {
        $this->remote_index_file = $remote_index_file;
        $this->remote_index = $remote_index;
        $this->checkpoints = $checkpoints;
        $this->shutdown = $shutdown;
        $this->audit = $audit;
        $this->observer = $observer;
        $this->recreator = $recreator;
        $this->roots = $roots;
    }

    public function discover_targets(FilesPullCheckpoint $checkpoint): void
    {
        (new SymlinkTargetIndexer(
            $this->remote_index_file,
            $this->remote_index,
            $this->checkpoints,
            $this->shutdown,
            $this->audit,
            $this->observer,
        ))->discover($checkpoint, $this->roots);
    }

    public function recreate_intermediate_symlinks(): void
    {
        $this->recreator->recreate($this->remote_index_file);
    }
}
