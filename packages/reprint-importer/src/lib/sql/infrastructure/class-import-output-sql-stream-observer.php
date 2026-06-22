<?php

namespace Reprint\Importer\Sql\Infrastructure;

use Reprint\Importer\Output\ImportOutput;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Sql\DbPullCheckpoint;
use Reprint\Importer\Sql\Port\SqlStreamObserver;
use Reprint\Importer\Support\ByteFormatter;
use Reprint\Importer\Observability\MachineEventEmitter;

final class ImportOutputSqlStreamObserver implements SqlStreamObserver
{
    private ImportOutput $output;
    private MachineEventEmitter $machine_events;
    private DbPullCheckpoint $checkpoint;

    public function __construct(
        ImportOutput $output,
        MachineEventEmitter $machine_events,
        DbPullCheckpoint $checkpoint
    ) {
        $this->output = $output;
        $this->machine_events = $machine_events;
        $this->checkpoint = $checkpoint;
    }

    public function on_sql_progress(int $sql_bytes_written): void
    {
        $db_bytes_est = $this->checkpoint->db_index->bytes;
        $est_is_useful = $db_bytes_est > $sql_bytes_written;
        $sql_fraction = $est_is_useful
            ? $sql_bytes_written / $db_bytes_est
            : null;
        $sql_progress = ByteFormatter::format($sql_bytes_written);
        if ($est_is_useful) {
            $sql_progress .= ' / ' . ByteFormatter::format($db_bytes_est);
        }
        $this->output->show_progress_line($sql_progress, $sql_fraction);
    }

    public function on_progress_chunk(array $chunk, string $phase): void
    {
        $body = $chunk['body'] ?? '';
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return;
        }

        $this->machine_events->emit(array_merge(['phase' => $phase], $data), true);
    }

    public function on_error_chunk(array $chunk, string $phase, StreamingContext $context): void
    {
        $body = $chunk['body'] ?? '';
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return;
        }

        $this->machine_events->emit([
            'type' => 'error',
            'phase' => $phase,
            'error_type' => $data['error_type'] ?? 'unknown',
            'path' => $data['path'] ?? '',
            'error_message' => $data['message'] ?? 'Error',
            'message' => 'Remote error: ' . ($data['error_type'] ?? 'unknown'),
        ], true);
    }

    /**
     * @param array<string, mixed> $progress
     */
    public function on_completion_progress(array $progress): void
    {
        $this->machine_events->emit($progress, true);
    }

    public function on_stdout_write_failed(): void
    {
        $this->machine_events->emit([
            'type' => 'stdout_write_failed',
            'message' => 'stdout write failed',
        ], true);
    }
}
