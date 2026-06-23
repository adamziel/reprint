<?php

namespace Reprint\Importer\Sql\Infrastructure;

use PDO;
use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Sql\ActivePluginDeactivator;
use Reprint\Importer\Sql\DbApplySourceContext;
use Reprint\Importer\Sql\Port\PluginDeactivationPolicy;
use function Reprint\Importer\Host\host_analyzer_for;

final class HostPluginDeactivationPolicy implements PluginDeactivationPolicy
{
    private DbApplySourceContext $source;
    private AuditLogger $audit;

    public function __construct(DbApplySourceContext $source, AuditLogger $audit)
    {
        $this->source = $source;
        $this->audit = $audit;
    }

    public function deactivate_host_specific(PDO $pdo): array
    {
        $analyzer = host_analyzer_for($this->source->webhost());
        $manifest = $analyzer->analyze($this->source->preflight_data());

        return ActivePluginDeactivator::deactivate_for_removed_paths(
            $pdo,
            $manifest->paths_to_remove,
            $this->source->table_prefix(),
            function (string $message): void {
                $this->audit->record($message);
            },
        );
    }

    public function deactivate_path_incompatible(PDO $pdo, string $new_site_url): array
    {
        return ActivePluginDeactivator::deactivate_path_incompatible(
            $pdo,
            $new_site_url,
            $this->source->table_prefix(),
            function (string $message): void {
                $this->audit->record($message);
            },
        );
    }
}
