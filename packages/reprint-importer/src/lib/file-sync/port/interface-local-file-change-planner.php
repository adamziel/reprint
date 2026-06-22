<?php

namespace Reprint\Importer\FileSync\Port;

interface LocalFileChangePlanner
{
    public function delete_local_file_path(string $path): void;

    public function should_skip_for_preserve_local(string $path): ?string;

    public function emit_skip_progress(string $path): void;
}
