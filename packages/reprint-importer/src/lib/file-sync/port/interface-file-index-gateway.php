<?php

namespace Reprint\Importer\FileSync\Port;

interface FileIndexGateway
{
    public function recover_updates(): void;

    public function local_index_has_entries(): bool;

    public function count_local_index(): int;

    public function count_remote_index(): int;

    public function sort_remote_index(): void;

    public function index_entries_counted(): int;

    public function reset_transfer_progress(): void;

    public function finalize_updates(): void;
}
