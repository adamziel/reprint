<?php

namespace Reprint\Importer;

use Reprint\Importer\Application\Importer as ApplicationImporter;
use Reprint\Importer\Application\CommandRegistry;
use Reprint\Importer\Output\ImportOutput;

/**
 * Compatibility shell for callers that still import Reprint\Importer\Importer.
 *
 * The implementation lives in Application\Importer. This class intentionally
 * has no import business logic; it exists only as a package-level entry alias.
 */
class Importer extends ApplicationImporter
{
    public function __construct(
        string $remote_url,
        string $state_dir,
        string $fs_root,
        ?ImportOutput $output = null,
        ?CommandRegistry $commands = null
    ) {
        parent::__construct($remote_url, $state_dir, $fs_root, $output, $commands);
    }
}
