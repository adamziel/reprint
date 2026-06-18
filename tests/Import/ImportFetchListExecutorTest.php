<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\DownloadList;
use Reprint\Importer\FileSync\FetchListExecutor;
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
            $batch_file = $state['batch_file'] ?? null;
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

        $complete = $executor->run($list, 'fetch', [], $this->default_fetch_state());

        $this->assertTrue($complete);
        $this->assertSame(['/a.txt', '/b.txt'], $downloaded);
        $this->assertSame(2, $executor->download_list_total());
        $this->assertSame(2, $executor->download_list_done());
        $this->assertSame(0, $executor->files_imported());
        $this->assertGreaterThan(0, $this->saved_states['fetch']['offset']);
        $this->assertNull($this->saved_states['fetch']['batch_file']);
        $this->assertNotEmpty($this->audit);
    }

    public function testRunReturnsFalseWhenBatchDownloadIsPartial(): void
    {
        $list = $this->write_list(['/a.txt']);
        $executor = $this->make_executor(fn(): bool => false);

        $complete = $executor->run($list, 'fetch', [], $this->default_fetch_state());

        $this->assertFalse($complete);
        $this->assertSame(1, $executor->download_list_total());
        $this->assertSame(0, $executor->download_list_done());
        $this->assertNotNull($this->saved_states['fetch']['batch_file']);
    }

    public function testCountersAreAvailableAfterDownloadException(): void
    {
        $list = $this->write_list(['/a.txt', '/b.txt', '/c.txt']);
        $executor = $this->make_executor(function (): bool {
            throw new RuntimeException('network failed');
        });

        try {
            $executor->run($list, 'fetch', [], $this->default_fetch_state());
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
            $download_batch,
            function (string $state_key, array $fetch_state): void {
                $this->saved_states[$state_key] = $fetch_state;
            },
            function (string $message): void {
                $this->audit[] = $message;
            },
        );
    }

    private function default_fetch_state(): array
    {
        return [
            'offset' => 0,
            'next_offset' => 0,
            'batch_file' => null,
            'batch_entries' => 0,
            'cursor' => null,
        ];
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
