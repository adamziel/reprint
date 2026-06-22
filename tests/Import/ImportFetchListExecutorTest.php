<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\DownloadList;
use Reprint\Importer\FileSync\FetchCheckpoint;
use Reprint\Importer\FileSync\FetchListExecutor;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Port\FetchBatchDownloader;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\Observability\AuditLogger;
use RuntimeException;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class ImportFetchListExecutorTest extends TestCase
{
    private string $temp_dir;
    private array $saved_states = [];
    private array $audit = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir() . '/import-fetch-list-executor-' . uniqid('', true);
        mkdir($this->temp_dir, 0755, true);
        $this->saved_states = [];
        $this->audit = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->saved_states as $state) {
            $batch_file = $state instanceof FetchCheckpoint ? $state->batch_file : null;
            if (is_string($batch_file) && is_file($batch_file)) {
                @unlink($batch_file);
            }
        }
        foreach (glob($this->temp_dir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->temp_dir);
        parent::tearDown();
    }

    public function testRunDownloadsBatchAndAdvancesState(): void
    {
        $list = $this->write_list(['/a.txt', '/b.txt']);
        $downloaded = [];
        $executor = $this->make_executor(
            function (string $batch_file) use (&$downloaded): bool {
                $downloaded = json_decode(file_get_contents($batch_file), true);
                return true;
            },
        );

        $complete = $executor->run($list, 'fetch', FilesPullCheckpoint::fresh());

        $this->assertTrue($complete);
        $this->assertSame(['/a.txt', '/b.txt'], $downloaded);
        $this->assertSame(2, $executor->download_list_total());
        $this->assertSame(2, $executor->download_list_done());
        $this->assertSame(0, $executor->files_imported());
        $this->assertGreaterThan(0, $this->saved_states['fetch']->offset);
        $this->assertNull($this->saved_states['fetch']->batch_file);
        $this->assertNotEmpty($this->audit);
    }

    public function testRunReturnsFalseWhenBatchDownloadIsPartial(): void
    {
        $list = $this->write_list(['/a.txt']);
        $executor = $this->make_executor(fn(): bool => false);

        $complete = $executor->run($list, 'fetch', FilesPullCheckpoint::fresh());

        $this->assertFalse($complete);
        $this->assertSame(1, $executor->download_list_total());
        $this->assertSame(0, $executor->download_list_done());
        $this->assertNotNull($this->saved_states['fetch']->batch_file);
    }

    public function testCountersAreAvailableAfterDownloadException(): void
    {
        $list = $this->write_list(['/a.txt', '/b.txt', '/c.txt']);
        $executor = $this->make_executor(function (): bool {
            throw new RuntimeException('network failed');
        });

        try {
            $executor->run($list, 'fetch', FilesPullCheckpoint::fresh());
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('network failed', $e->getMessage());
        }

        $this->assertSame(3, $executor->download_list_total());
        $this->assertSame(0, $executor->download_list_done());
    }

    private function make_executor(callable $download_batch): FetchListExecutor
    {
        return new FetchListExecutor(
            null,
            null,
            7,
            4 * 1024 * 1024,
            new class($download_batch) implements FetchBatchDownloader {
                /** @var callable */
                private $download_batch;

                public function __construct(callable $download_batch)
                {
                    $this->download_batch = $download_batch;
                }

                public function download_batch(
                    string $batch_file,
                    ?string $cursor,
                    string $state_key
                ): bool {
                    return (bool) ($this->download_batch)($batch_file, $cursor, $state_key);
                }
            },
            new class($this->saved_states) implements FilesPullCheckpointStore {
                private array $saved_states;

                public function __construct(array &$saved_states)
                {
                    $this->saved_states = &$saved_states;
                }

                public function get(): FilesPullCheckpoint
                {
                    return FilesPullCheckpoint::fresh();
                }

                public function save(FilesPullCheckpoint $checkpoint): void
                {
                    $this->saved_states['fetch'] = clone $checkpoint->fetch;
                    $this->saved_states['fetch_skipped'] = clone $checkpoint->fetch_skipped;
                }
            },
            new class($this->audit) implements AuditLogger {
                private array $audit;

                public function __construct(array &$audit)
                {
                    $this->audit = &$audit;
                }

                public function record(string $message, bool $to_console = true): void
                {
                    $this->audit[] = $message;
                }

                public function path(): string
                {
                    return '';
                }
            },
        );
    }

    private function write_list(array $paths): string
    {
        $path = $this->temp_dir . '/download-list.jsonl';
        $handle = fopen($path, 'w');
        foreach ($paths as $entry) {
            DownloadList::append_path($handle, $entry);
        }
        fclose($handle);
        return $path;
    }
}
