<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Application\ImportServices;
use Reprint\Importer\FileSync\DownloadList;
use Reprint\Importer\FileSync\FetchCheckpoint;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\Application\Importer;
use Reprint\Importer\Output\BufferedImportOutput;
use Reprint\Importer\Session\StatePathCodec;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

/**
 * Verify that files_done and files_total progress counters are correct
 * across multiple invocations (exit-code-2 restarts), with and without
 * the essential-files filter (which splits the download list into a
 * main list and a skipped list).
 */
class DownloadListProgressTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fs_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/download-list-progress-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fs_root = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->stateDir . '/.reprint', 0755, true);
        mkdir($this->fs_root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function makeClient(): Importer
    {
        return new Importer(
            'http://fake.url',
            $this->stateDir,
            $this->fs_root,
            new BufferedImportOutput(),
        );
    }

    /**
     * Build a download list JSONL file with N entries.
     */
    private function writeDownloadList(int $count, ?string $file = null): string
    {
        $file = $file ?? $this->stateDir . '/.import-download-list.jsonl';
        $handle = fopen($file, 'w');
        for ($i = 0; $i < $count; $i++) {
            fwrite($handle, json_encode(["path" => base64_encode("/file-{$i}.txt")]) . "\n");
        }
        fclose($handle);
        return $file;
    }

    private function writeState(array $state): void
    {
        $defaults = [
            "command" => null,
            "status" => null,
            "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            "remote_protocol_version" => null,
            "remote_protocol_min_version" => null,
            "version" => null,
            "follow_symlinks" => false,
            "fs_root_nonempty_behavior" => "error",
            "max_allowed_packet" => null,
            "fetch" => ["offset" => 0, "next_offset" => 0, "batch_file" => null, "batch_entries" => 0, "cursor" => null],
            "fetch_skipped" => ["offset" => 0, "next_offset" => 0, "batch_file" => null, "batch_entries" => 0, "cursor" => null],
        ];
        $run_state = array_merge($defaults, $state);

        if (($run_state["command"] ?? null) === "files-pull") {
            $this->writeFilesPullCheckpoint($this->filesPullCheckpointFromState($run_state));
        }

        unset($run_state["cursor"], $run_state["stage"]);
        file_put_contents(
            $this->stateDir . '/.reprint/run.json',
            json_encode($run_state, JSON_PRETTY_PRINT),
        );
    }

    private function filesPullCheckpointFromState(array $state): FilesPullCheckpoint
    {
        $diff = is_array($state["diff"] ?? null)
            ? $state["diff"]
            : ["remote_offset" => 0, "local_after" => null];

        return new FilesPullCheckpoint(
            isset($state["status"]) && is_string($state["status"]) ? $state["status"] : null,
            isset($state["stage"]) && is_string($state["stage"]) ? $state["stage"] : null,
            isset($state["index"]["cursor"]) && is_string($state["index"]["cursor"])
                ? $state["index"]["cursor"]
                : null,
            (int) ($diff["remote_offset"] ?? 0),
            isset($diff["local_after"]) && is_string($diff["local_after"])
                ? $diff["local_after"]
                : null,
            FetchCheckpoint::from_array(is_array($state["fetch"] ?? null) ? $state["fetch"] : []),
            FetchCheckpoint::from_array(
                is_array($state["fetch_skipped"] ?? null) ? $state["fetch_skipped"] : [],
            ),
            isset($state["current_file"]) && is_string($state["current_file"])
                ? $state["current_file"]
                : null,
            isset($state["current_file_bytes"]) ? (int) $state["current_file_bytes"] : null,
            (int) ($state["consecutive_timeouts"] ?? 0),
        );
    }

    private function writeFilesPullCheckpoint(FilesPullCheckpoint $checkpoint): void
    {
        $dir = $this->stateDir . '/.reprint/files-pull';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $codec = new StatePathCodec();
        file_put_contents(
            $dir . '/checkpoint.json',
            json_encode(
                $checkpoint->to_persisted_array([$codec, 'encode_value']),
                JSON_PRETTY_PRINT,
            ),
        );
    }

    private function prepareClient(string $filter = "none"): array
    {
        $client = $this->makeClient();
        $context = $client->context();
        $context->state();
        $context->set_filter($filter);

        return [$client, new ImportServices($context)];
    }

    private function readCounters(Importer $client): array
    {
        $progress = $client->context()->file_sync_progress();

        return [
            'total' => $progress->download_list_total(),
            'done' => $progress->download_list_done(),
            'imported' => $progress->files_imported(),
        ];
    }

    private function byteOffsetAfterLines(string $file, int $n): int
    {
        $handle = fopen($file, 'r');
        for ($i = 0; $i < $n; $i++) {
            fgets($handle);
        }
        $offset = ftell($handle);
        fclose($handle);
        return $offset;
    }

    // ---------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------

    public function testFreshDownloadShowsZeroDone()
    {
        $listFile = $this->writeDownloadList(100);

        $this->writeState([
            "command" => "files-pull",
            "status" => "in_progress",
            "stage" => "fetch",
        ]);

        [$client, $services] = $this->prepareClient();
        try {
            $services->files()->fetch_files_from_list(
                $client->context()->files_pull_checkpoint(),
                $listFile,
                'fetch',
            );
        } catch (\Exception $e) {
            // Expected: network error
        }

        $counters = $this->readCounters($client);
        $this->assertSame(100, $counters['total']);
        $this->assertSame(0, $counters['done']);
    }

    public function testResumedDownloadReflectsOffset()
    {
        $listFile = $this->writeDownloadList(100);
        $offset = $this->byteOffsetAfterLines($listFile, 40);

        $this->writeState([
            "command" => "files-pull",
            "status" => "in_progress",
            "stage" => "fetch",
            "fetch" => [
                "offset" => $offset,
                "next_offset" => $offset,
                "batch_file" => null,
                "batch_entries" => 0,
                "cursor" => null,
            ],
        ]);

        [$client, $services] = $this->prepareClient();

        try {
            $services->files()->fetch_files_from_list(
                $client->context()->files_pull_checkpoint(),
                $listFile,
                'fetch',
            );
        } catch (\Exception $e) {
            // Expected
        }

        $counters = $this->readCounters($client);
        $this->assertSame(100, $counters['total']);
        $this->assertSame(40, $counters['done']);
    }

    public function testDoneNeverExceedsTotal()
    {
        $listFile = $this->writeDownloadList(50);

        // Offset past the end of the file
        $pastEnd = filesize($listFile) + 1000;

        $this->writeState([
            "command" => "files-pull",
            "status" => "in_progress",
            "stage" => "fetch",
            "fetch" => [
                "offset" => $pastEnd,
                "next_offset" => $pastEnd,
                "batch_file" => null,
                "batch_entries" => 0,
                "cursor" => null,
            ],
        ]);

        [$client, $services] = $this->prepareClient();

        try {
            $services->files()->fetch_files_from_list(
                $client->context()->files_pull_checkpoint(),
                $listFile,
                'fetch',
            );
        } catch (\Exception $e) {
            // Expected
        }

        $counters = $this->readCounters($client);
        $this->assertSame(50, $counters['total']);
        $this->assertLessThanOrEqual($counters['total'], $counters['done']);
    }

    public function testCountersStableAcrossInvocations()
    {
        $listFile = $this->writeDownloadList(100);
        $offset30 = $this->byteOffsetAfterLines($listFile, 30);
        $offset60 = $this->byteOffsetAfterLines($listFile, 60);

        // First invocation at offset 30
        $this->writeState([
            "command" => "files-pull",
            "status" => "in_progress",
            "stage" => "fetch",
            "fetch" => ["offset" => $offset30, "next_offset" => $offset30, "batch_file" => null, "batch_entries" => 0, "cursor" => null],
        ]);

        [$client1, $services1] = $this->prepareClient();
        try {
            $services1->files()->fetch_files_from_list(
                $client1->context()->files_pull_checkpoint(),
                $listFile,
                'fetch',
            );
        } catch (\Exception $e) {}

        $c1 = $this->readCounters($client1);
        $this->assertSame(100, $c1['total']);
        $this->assertSame(30, $c1['done']);

        // Second invocation at offset 60
        $this->writeState([
            "command" => "files-pull",
            "status" => "in_progress",
            "stage" => "fetch",
            "fetch" => ["offset" => $offset60, "next_offset" => $offset60, "batch_file" => null, "batch_entries" => 0, "cursor" => null],
        ]);

        [$client2, $services2] = $this->prepareClient();
        try {
            $services2->files()->fetch_files_from_list(
                $client2->context()->files_pull_checkpoint(),
                $listFile,
                'fetch',
            );
        } catch (\Exception $e) {}

        $c2 = $this->readCounters($client2);
        $this->assertSame(100, $c2['total']);
        $this->assertSame(60, $c2['done']);

        // done only goes up
        $this->assertGreaterThan($c1['done'], $c2['done']);
    }

    public function testSkippedListHasOwnCounters()
    {
        $this->writeDownloadList(50);
        $skippedList = $this->stateDir . '/.import-download-list-skipped.jsonl';
        $this->writeDownloadList(200, $skippedList);
        $offset20 = $this->byteOffsetAfterLines($skippedList, 20);

        $this->writeState([
            "command" => "files-pull",
            "status" => "in_progress",
            "stage" => "fetch-skipped",
            "fetch_skipped" => ["offset" => $offset20, "next_offset" => $offset20, "batch_file" => null, "batch_entries" => 0, "cursor" => null],
        ]);

        [$client, $services] = $this->prepareClient("skipped-earlier");

        try {
            $services->files()->fetch_files_from_list(
                $client->context()->files_pull_checkpoint(),
                $skippedList,
                'fetch_skipped',
            );
        } catch (\Exception $e) {}

        $counters = $this->readCounters($client);
        $this->assertSame(200, $counters['total']);
        $this->assertSame(20, $counters['done']);
    }

    public function testCountNewlinesMatchesLineCount()
    {
        $listFile = $this->writeDownloadList(500);

        [, $services] = $this->prepareClient();

        $this->assertSame(500, $services->files()->count_download_list_lines($listFile));

        $offset100 = $this->byteOffsetAfterLines($listFile, 100);
        $this->assertSame(100, $services->files()->count_download_list_lines($listFile, $offset100));
    }

    public function testPrepareFetchBatchReturnsEntryCount()
    {
        $listFile = $this->writeDownloadList(10);

        $this->writeState([
            "command" => "files-pull",
            "status" => "in_progress",
            "stage" => "fetch",
        ]);

        $batch = DownloadList::prepare_batch($listFile, 0, 4 * 1024 * 1024);

        $this->assertNotNull($batch);
        $this->assertSame(10, $batch['entries']);
        $this->assertSame(0, $batch['offset']);
        $this->assertGreaterThan(0, $batch['next_offset']);

        if (file_exists($batch['file'])) {
            @unlink($batch['file']);
        }
    }

    public function testFilesDoneIncludesFilesImported()
    {
        $listFile = $this->writeDownloadList(100);
        $offset40 = $this->byteOffsetAfterLines($listFile, 40);

        $this->writeState([
            "command" => "files-pull",
            "status" => "in_progress",
            "stage" => "fetch",
            "fetch" => ["offset" => $offset40, "next_offset" => $offset40, "batch_file" => null, "batch_entries" => 0, "cursor" => null],
        ]);

        [$client, $services] = $this->prepareClient();

        try {
            $services->files()->fetch_files_from_list(
                $client->context()->files_pull_checkpoint(),
                $listFile,
                'fetch',
            );
        } catch (\Exception $e) {}

        // Simulate 5 files written in this invocation
        $progress = $client->context()->file_sync_progress();
        $client->context()->set_file_sync_progress(
            5,
            $progress->download_list_done(),
            $progress->download_list_total(),
        );

        $counters = $this->readCounters($client);
        $done = $counters['done'];
        $imported = $counters['imported'];
        $total = $counters['total'];

        // files_done as emitted in progress records = done + imported
        $filesDone = $done + $imported;
        $this->assertSame(45, $filesDone); // 40 from offset + 5 imported
        $this->assertSame(100, $total);
        $this->assertLessThanOrEqual($total, $filesDone);
    }
}
