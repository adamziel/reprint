<?php

namespace Reprint\Importer\Cli;

use Reprint\Importer\Command\DbDomainsResult;
use Reprint\Importer\Command\FilesStatsResult;
use Reprint\Importer\Command\ImportCommandResult;
use Reprint\Importer\Command\PreflightAssertResult;
use Reprint\Importer\Command\PreflightReportResult;
use Reprint\Importer\Importer;

final class CliCommandResultRenderer
{
    /** @var array<string, callable> */
    private $renderers = [];

    public function __construct()
    {
        $this->register(PreflightReportResult::class, [$this, 'render_preflight_report']);
        $this->register(PreflightAssertResult::class, [$this, 'render_preflight_assert']);
        $this->register(DbDomainsResult::class, [$this, 'render_db_domains']);
        $this->register(FilesStatsResult::class, [$this, 'render_files_stats']);
    }

    public function register(string $result_class, callable $renderer): void
    {
        $this->renderers[$result_class] = $renderer;
    }

    public function render(Importer $client, ?ImportCommandResult $result): void
    {
        if ($result === null) {
            return;
        }

        foreach ($this->renderers as $result_class => $renderer) {
            if ($result instanceof $result_class) {
                call_user_func($renderer, $client, $result);
                return;
            }
        }
    }

    private function render_preflight_report(
        Importer $client,
        PreflightReportResult $result
    ): void {
        $entry = $result->entry();
        if ($entry === null) {
            echo "No preflight data available.\n";
            $client->set_exit_code(1);
            return;
        }

        // @TODO: Store paths as base64 strings, not raw strings, since paths can contain arbitrary bytes.
        echo json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
        $client->write_status_file($result->is_ok() ? null : "Preflight failed");
        $client->set_exit_code($result->is_ok() ? 0 : 1);
    }

    private function render_preflight_assert(
        Importer $client,
        PreflightAssertResult $result
    ): void {
        foreach ($result->checks() as $check) {
            if (!is_array($check)) {
                continue;
            }
            $icon = !empty($check["pass"]) ? "PASS" : "FAIL";
            $label = (string) ($check["label"] ?? "");
            $detail = (string) ($check["detail"] ?? "");
            echo "[{$icon}] {$label}: {$detail}\n";
        }

        echo "\n";
        if ($result->all_pass()) {
            echo "Migration looks feasible.\n";
            $client->write_status_file();
            $client->set_exit_code(0);
            return;
        }

        echo "Migration may not be feasible. Review the failures above.\n";
        $client->write_status_file("Preflight assertions failed");
        $client->set_exit_code(1);
    }

    private function render_db_domains(
        Importer $client,
        DbDomainsResult $result
    ): void {
        foreach ($result->domains() as $domain) {
            echo $domain . "\n";
        }
    }

    private function render_files_stats(
        Importer $client,
        FilesStatsResult $result
    ): void {
        $json = json_encode($result->stats(), JSON_PRETTY_PRINT);
        echo ($json === false ? "null" : $json) . "\n";
    }
}
