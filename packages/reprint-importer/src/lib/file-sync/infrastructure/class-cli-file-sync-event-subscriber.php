<?php

namespace Reprint\Importer\FileSync\Infrastructure;

use Reprint\Importer\FileSync\FileSyncEvent;
use Reprint\Importer\Observability\AuditLogger;
use Reprint\Importer\Observability\EventPublisher;
use Reprint\Importer\Observability\ImportEvent;
use Reprint\Importer\Observability\MachineEventEmitter;
use Reprint\Importer\Output\ImportOutput;

final class CliFileSyncEventSubscriber implements EventPublisher
{
    private AuditLogger $audit;
    private ImportOutput $output;
    private MachineEventEmitter $machine_events;

    public function __construct(
        AuditLogger $audit,
        ImportOutput $output,
        MachineEventEmitter $machine_events
    ) {
        $this->audit = $audit;
        $this->output = $output;
        $this->machine_events = $machine_events;
    }

    public function publish(ImportEvent $event): void
    {
        $payload = $event->payload();

        switch ($event->name()) {
            case FileSyncEvent::AUDIT:
                $this->audit->record(
                    (string) ($payload['message'] ?? ''),
                    (bool) ($payload['to_console'] ?? true),
                );
                break;

            case FileSyncEvent::FILES_PULL_STARTING:
                $this->render_files_pull_starting($payload);
                break;

            case FileSyncEvent::FILES_PULL_RESUMING:
                $this->render_files_pull_resuming($payload);
                break;

            case FileSyncEvent::FILES_PULL_ALREADY_COMPLETE:
                $this->render_files_pull_already_complete($payload);
                break;

            case FileSyncEvent::FILES_PULL_COMPLETE:
                $this->render_files_pull_complete($payload);
                break;

            case FileSyncEvent::FILES_PULL_FETCH_SKIPPED_STARTING:
                $this->audit->record(
                    'FETCH SKIPPED | files-pull was complete - downloading previously skipped files',
                    true,
                );
                $this->output->show_lifecycle_line("Downloading previously skipped files\n");
                $this->machine_events->emit([
                    'type' => 'lifecycle',
                    'event' => 'starting',
                    'command' => 'files-pull',
                    'stage' => 'fetch-skipped',
                    'message' => 'Downloading previously skipped files',
                ], true);
                break;

            case FileSyncEvent::FILES_INDEX_STARTING:
                $this->audit->record('START files-index', true);
                $this->output->show_lifecycle_line("Starting files-index\n");
                $this->machine_events->emit([
                    'type' => 'lifecycle',
                    'event' => 'starting',
                    'command' => 'files-index',
                    'message' => 'Starting files-index',
                ], true);
                break;

            case FileSyncEvent::FILES_INDEX_RESUMING:
                $cursor = $payload['cursor'] ?? null;
                $this->audit->record(sprintf(
                    'RESUME files-index | cursor=%s',
                    is_string($cursor) && $cursor !== ''
                        ? substr($cursor, 0, 20) . '...'
                        : 'none',
                ), true);
                $this->output->show_lifecycle_line("Resuming files-index\n");
                $this->machine_events->emit([
                    'type' => 'lifecycle',
                    'event' => 'resuming',
                    'command' => 'files-index',
                    'message' => 'Resuming files-index',
                ], true);
                break;

            case FileSyncEvent::FILES_INDEX_COMPLETE:
                $count = (int) ($payload['entries_indexed'] ?? 0);
                $remote_index = (string) ($payload['remote_index'] ?? '');
                $this->audit->record(sprintf(
                    'files-index complete: %d entries indexed',
                    $count,
                ), true);
                $this->output->show_lifecycle_line("files-index complete: {$count} entries indexed\n");
                $this->output->show_lifecycle_line("Remote index: {$remote_index}\n");
                $this->output->show_lifecycle_line("Audit log: {$this->audit->path()}\n");
                $this->machine_events->emit([
                    'type' => 'lifecycle',
                    'event' => 'complete',
                    'command' => 'files-index',
                    'entries_indexed' => $count,
                    'remote_index' => $remote_index,
                    'audit_log' => $this->audit->path(),
                    'message' => "files-index complete: {$count} entries indexed",
                ], true);
                break;

            case FileSyncEvent::DOWNLOAD_PROGRESS_STARTING:
                $this->render_download_progress_starting($payload);
                break;

            case FileSyncEvent::FILE_DELETED:
                $this->audit->record(sprintf(
                    'FILE DELETE | %s | %s',
                    (string) ($payload['file'] ?? ''),
                    (string) ($payload['reason'] ?? ''),
                ), false);
                break;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function render_files_pull_starting(array $payload): void
    {
        $delta = (bool) ($payload['delta'] ?? false);
        $index_size = (int) ($payload['index_size'] ?? 0);
        $mode = (string) ($payload['fs_root_nonempty_behavior'] ?? 'error');
        $is_empty = (bool) ($payload['is_empty'] ?? false);

        if ($delta) {
            $this->audit->record("START files-pull (delta) | index_entries={$index_size}", true);
            $this->output->show_lifecycle_line("Starting files-pull (delta)\n");
            $this->output->show_lifecycle_line("  Index contains: {$index_size} entries\n");
            $this->output->show_lifecycle_line("  Stage: index\n");
            $this->machine_events->emit([
                'type' => 'lifecycle',
                'event' => 'starting',
                'command' => 'files-pull',
                'delta' => true,
                'index_size' => $index_size,
                'entries_indexed' => $index_size,
                'message' => "Starting files-pull (delta, {$index_size} entries indexed)",
            ], true);
            return;
        }

        $this->audit->record(
            'START files-pull (' . $mode . ' mode, ' . ($is_empty ? 'empty directory' : 'non-empty directory') . ')',
            true,
        );
        $this->output->show_lifecycle_line("Starting files-pull\n");
        $this->machine_events->emit([
            'type' => 'lifecycle',
            'event' => 'starting',
            'command' => 'files-pull',
            'message' => 'Starting files-pull',
        ], true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function render_files_pull_resuming(array $payload): void
    {
        $stage = (string) ($payload['stage'] ?? 'index');
        $index_size = (int) ($payload['index_size'] ?? 0);

        $this->audit->record(sprintf(
            'RESUME files-pull | stage=%s | indexed_entries=%d',
            $stage,
            $index_size,
        ), true);
        $this->output->show_lifecycle_line("Resuming files-pull\n");
        $this->output->show_lifecycle_line("  Stage: {$stage}\n");
        $this->output->show_lifecycle_line("  Already indexed: {$index_size} entries\n");
        $this->machine_events->emit([
            'type' => 'lifecycle',
            'event' => 'resuming',
            'command' => 'files-pull',
            'stage' => $stage,
            'index_size' => $index_size,
            'entries_indexed' => $index_size,
            'message' => "Resuming files-pull (stage: {$stage}, indexed: {$index_size} entries)",
        ], true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function render_files_pull_already_complete(array $payload): void
    {
        $index_size = (int) ($payload['files_indexed'] ?? 0);
        $has_skipped = (bool) ($payload['has_skipped'] ?? false);
        $skipped_note = $has_skipped
            ? ' (some files were skipped - re-run with --filter=skipped-earlier to download them)'
            : '';

        $this->output->clear_progress_line();
        $this->audit->record(
            sprintf('files-pull already complete: %d entries indexed%s', $index_size, $skipped_note),
            true,
        );
        $this->output->show_lifecycle_line("files-pull already complete: {$index_size} entries indexed\n");
        if ($has_skipped) {
            $this->output->show_lifecycle_line("Some files were skipped. Re-run with --filter=skipped-earlier to download them.\n");
        } else {
            $this->output->show_lifecycle_line("To re-sync, run with --abort first to clear state.\n");
        }
        $this->machine_events->emit([
            'type' => 'lifecycle',
            'event' => 'already_complete',
            'command' => 'files-pull',
            'files_indexed' => $index_size,
            'entries_indexed' => $index_size,
            'has_skipped' => $has_skipped,
            'message' => "files-pull already complete: {$index_size} entries indexed",
        ], true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function render_files_pull_complete(array $payload): void
    {
        $index_size = (int) ($payload['files_indexed'] ?? 0);
        $delta = (bool) ($payload['delta'] ?? false);
        $label = $delta ? 'files-pull (delta)' : 'files-pull';

        $this->output->clear_progress_line();
        $this->audit->record(sprintf('%s complete: %d entries indexed', $label, $index_size), true);
        $this->output->show_lifecycle_line("{$label} complete: {$index_size} entries indexed\n");
        $this->output->show_lifecycle_line("Audit log: {$this->audit->path()}\n");
        $this->machine_events->emit([
            'type' => 'lifecycle',
            'event' => 'complete',
            'command' => 'files-pull',
            'delta' => $delta,
            'files_indexed' => $index_size,
            'entries_indexed' => $index_size,
            'audit_log' => $this->audit->path(),
            'message' => "{$label} complete: {$index_size} entries indexed",
        ], true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function render_download_progress_starting(array $payload): void
    {
        if (!$this->output->is_quiet_lifecycle()) {
            return;
        }

        $scanned = (int) ($payload['scanned'] ?? 0);
        $total = (int) ($payload['total'] ?? 0);
        $green = "\033[32m";
        $dim = "\033[2m";
        $reset = "\033[0m";
        $this->output->clear_progress_line();
        $this->output->print_line(
            "  {$green}✓{$reset} Scanned {$dim}- " .
            number_format($scanned) .
            " entries{$reset}\n",
        );
        $this->output->set_active_label(null);
        $this->output->show_progress_line(
            'Downloading - 0 / ' . number_format($total) . ' entries',
            0.0,
        );
    }
}
