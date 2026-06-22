<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Observability\MachineEventEmitter;
use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\Sql\Port\DbApplyObserver;

final class ImportOutputDbApplyObserver implements DbApplyObserver
{
    private ImportOutput $output;
    private MachineEventEmitter $machine_events;

    public function __construct(ImportOutput $output, MachineEventEmitter $machine_events)
    {
        $this->output = $output;
        $this->machine_events = $machine_events;
    }

    public function on_workflow_starting(): void
    {
        $this->output->show_lifecycle_line("Starting db-apply\n");
        $this->machine_events->emit([
            "type" => "lifecycle",
            "event" => "starting",
            "command" => "db-apply",
            "message" => "Starting db-apply",
        ], true);
    }

    public function on_workflow_resuming(int $statements_executed, int $bytes_read): void
    {
        $this->output->show_lifecycle_line("Resuming db-apply (executed: {$statements_executed} statements)\n");
        $this->machine_events->emit([
            "type" => "lifecycle",
            "event" => "resuming",
            "command" => "db-apply",
            "statements_executed" => $statements_executed,
            "bytes_read" => $bytes_read,
            "message" => "Resuming db-apply (executed: {$statements_executed} statements)",
        ], true);
    }

    public function on_domains_discovered(array $domains, array $url_mapping): void
    {
        $this->output->show_lifecycle_line("Discovered domains in SQL dump:\n");
        foreach ($domains as $domain) {
            $mapped = isset($url_mapping[$domain])
                ? " => {$url_mapping[$domain]}"
                : " (not mapped)";
            $this->output->show_lifecycle_line("  {$domain}{$mapped}\n");
        }
        $this->output->show_lifecycle_line("\n");

        $domain_map = [];
        foreach ($domains as $domain) {
            $domain_map[$domain] = $url_mapping[$domain] ?? null;
        }
        $this->machine_events->emit([
            "type" => "domains_discovered",
            "domains" => $domain_map,
            "message" => "Discovered " . count($domains) . " domain(s) in SQL dump",
        ], true);
    }

    public function on_lifecycle_line(string $message): void
    {
        $this->output->show_lifecycle_line($message);
    }

    public function on_apply_starting(?int $statements_total): void
    {
        $this->machine_events->emit([
            "status" => "starting",
            "phase" => "db-apply",
            "statements_total" => $statements_total,
            "message" => "Applying SQL" . ($statements_total !== null ? " ({$statements_total} statements)" : ""),
        ], false);
    }

    public function on_apply_progress(
        int $statements_executed,
        ?int $statements_total,
        int $bytes_read,
        int $bytes_total
    ): void {
        $fraction = $bytes_total > 0 ? $bytes_read / $bytes_total : null;
        $pct = $fraction !== null ? round($fraction * 100, 1) : 0;
        $message = $statements_total === null
            ? number_format($statements_executed) . " statements"
            : number_format($statements_executed) . " / " . number_format($statements_total) . " statements";

        $this->machine_events->emit([
            "phase" => "db-apply",
            "statements_executed" => $statements_executed,
            "bytes_read" => $bytes_read,
            "bytes_total" => $bytes_total,
            "pct" => $pct,
            "statements_total" => $statements_total,
            "message" => $message,
        ], false);
        $this->output->show_progress_line($message, $fraction);
    }

    public function on_apply_partial(int $statements_executed, ?int $statements_total): void
    {
        $this->machine_events->emit([
            "status" => "partial",
            "phase" => "db-apply",
            "statements_executed" => $statements_executed,
            "statements_total" => $statements_total,
            "message" => "db-apply partial: {$statements_executed} statements executed",
        ], true);
    }

    public function on_apply_complete(int $statements_executed, ?int $statements_total): void
    {
        $this->machine_events->emit([
            "status" => "complete",
            "phase" => "db-apply",
            "statements_executed" => $statements_executed,
            "statements_total" => $statements_total,
            "message" => "db-apply complete ({$statements_executed} statements executed)",
        ], false);

        if (!$this->output->is_quiet_lifecycle()) {
            $this->output->clear_progress_line();
        }
        $this->output->show_lifecycle_line("db-apply complete ({$statements_executed} statements executed)\n");
    }

    public function on_fast_query_stream_fallback(int $byte_offset): void
    {
        $this->output->show_lifecycle_line(
            "Fast query stream fell back to lexer-based parser at byte offset {$byte_offset}; see audit log for details\n"
        );
    }
}
