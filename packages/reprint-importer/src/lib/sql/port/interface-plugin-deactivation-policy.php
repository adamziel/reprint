<?php

namespace Reprint\Importer\Sql\Port;

use PDO;

interface PluginDeactivationPolicy
{
    /**
     * @return array<int, string>
     */
    public function deactivate_host_specific(PDO $pdo): array;

    /**
     * @return array<int, string>
     */
    public function deactivate_path_incompatible(PDO $pdo, string $new_site_url): array;
}
