<?php

namespace Reprint\Importer\FileSync\Port;

interface FetchBatchDownloader
{
    public function download_batch(
        string $batch_file,
        ?string $cursor,
        string $state_key
    ): bool;
}
