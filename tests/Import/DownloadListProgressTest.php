<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/streaming-importer/src/import.php';

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

    private function makeClient(): \ImportClient
    {
        return new \ImportClient('http://fake.url', $this->stateDir, $this->fs_root);
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
            "cursor" => null,
            "stage" => null,
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
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode(array_merge($defaults, $state), JSON_PRETTY_PRINT),
        );
    }

    private function prepareClient(string $filter = "none"): array
    {
        $client = $this->makeClient();
        $reflection = new \ReflectionClass($client);

        $loadState = $reflection->getMethod('load_state');
        $stateProperty = $reflection->getProperty('state');
        $stateProperty->setValue($client, $loadState->invoke($client));

        $ttyProperty = $reflection->getProperty('is_tty');
        $ttyProperty->setValue($client, false);

        $filterProp = $reflection->getProperty('filter');
        $filterProp->setValue($client, $filter);

        return [$client, $reflection];
    }

    private function readCounters(\ImportClient $client, \ReflectionClass $reflection): array
    {
        return [
            'total' => $reflection->getProperty('download_list_total')->getValue($client),
            'done' => $reflection->getProperty('download_list_done')->getValue($client),
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
            "command" => "files-sync",
            "status" => "in_progress",
            "stage" => "fetch",
        ]);

        [$client, $reflection] = $this->prepareClient();

        $method = $reflection->getMethod('download_files_from_list');
        try {
            $method->invoke($client, $listFile, 'fetch');
        } catch (\Exception $e) {
            // Expected: network error
        }

        $counters = $this->readCounters($client, $reflection);
        $this->assertSame(100, $counters['total']);
        $this->assertSame(0, $counters['done']);
    }

    public function testResumedDownloadReflectsOffset()
    {
        $listFile = $this->writeDownloadList(100);
        $offset = $this->byteOffsetAfterLines($listFile, 40);

        $this->writeState([
            "command" => "files-sync",
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

        [$client, $reflection] = $this->prepareClient();

        try {
            $reflection->getMethod('download_files_from_list')
                ->invoke($client, $listFile, 'fetch');
        } catch (\Exception $e) {
            // Expected
        }

        $counters = $this->readCounters($client, $reflection);
        $this->assertSame(100, $counters['total']);
        $this->assertSame(40, $counters['done']);
    }

    public function testDoneNeverExceedsTotal()
    {
        $listFile = $this->writeDownloadList(50);

        // Offset past the end of the file
        $pastEnd = filesize($listFile) + 1000;

        $this->writeState([
            "command" => "files-sync",
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

        [$client, $reflection] = $this->prepareClient();

        try {
            $reflection->getMethod('download_files_from_list')
                ->invoke($client, $listFile, 'fetch');
        } catch (\Exception $e) {
            // Expected
        }

        $counters = $this->readCounters($client, $reflection);
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
            "command" => "files-sync",
            "status" => "in_progress",
            "stage" => "fetch",
            "fetch" => ["offset" => $offset30, "next_offset" => $offset30, "batch_file" => null, "batch_entries" => 0, "cursor" => null],
        ]);

        [$client1, $ref1] = $this->prepareClient();
        try {
            $ref1->getMethod('download_files_from_list')->invoke($client1, $listFile, 'fetch');
        } catch (\Exception $e) {}

        $c1 = $this->readCounters($client1, $ref1);
        $this->assertSame(100, $c1['total']);
        $this->assertSame(30, $c1['done']);

        // Second invocation at offset 60
        $this->writeState([
            "command" => "files-sync",
            "status" => "in_progress",
            "stage" => "fetch",
            "fetch" => ["offset" => $offset60, "next_offset" => $offset60, "batch_file" => null, "batch_entries" => 0, "cursor" => null],
        ]);

        [$client2, $ref2] = $this->prepareClient();
        try {
            $ref2->getMethod('download_files_from_list')->invoke($client2, $listFile, 'fetch');
        } catch (\Exception $e) {}

        $c2 = $this->readCounters($client2, $ref2);
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
            "command" => "files-sync",
            "status" => "in_progress",
            "stage" => "fetch-skipped",
            "fetch_skipped" => ["offset" => $offset20, "next_offset" => $offset20, "batch_file" => null, "batch_entries" => 0, "cursor" => null],
        ]);

        [$client, $reflection] = $this->prepareClient("skipped-earlier");

        try {
            $reflection->getMethod('download_files_from_list')
                ->invoke($client, $skippedList, 'fetch_skipped');
        } catch (\Exception $e) {}

        $counters = $this->readCounters($client, $reflection);
        $this->assertSame(200, $counters['total']);
        $this->assertSame(20, $counters['done']);
    }

    public function testCountNewlinesMatchesLineCount()
    {
        $listFile = $this->writeDownloadList(500);

        [$client, $reflection] = $this->prepareClient();
        $method = $reflection->getMethod('count_newlines');

        $this->assertSame(500, $method->invoke($client, $listFile));

        $offset100 = $this->byteOffsetAfterLines($listFile, 100);
        $this->assertSame(100, $method->invoke($client, $listFile, $offset100));
    }

    public function testPrepareFetchBatchReturnsEntryCount()
    {
        $listFile = $this->writeDownloadList(10);

        $this->writeState([
            "command" => "files-sync",
            "status" => "in_progress",
            "stage" => "fetch",
        ]);

        [$client, $reflection] = $this->prepareClient();
        $batch = $reflection->getMethod('prepare_fetch_batch')
            ->invoke($client, $listFile, 0);

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
            "command" => "files-sync",
            "status" => "in_progress",
            "stage" => "fetch",
            "fetch" => ["offset" => $offset40, "next_offset" => $offset40, "batch_file" => null, "batch_entries" => 0, "cursor" => null],
        ]);

        [$client, $reflection] = $this->prepareClient();

        try {
            $reflection->getMethod('download_files_from_list')
                ->invoke($client, $listFile, 'fetch');
        } catch (\Exception $e) {}

        // Simulate 5 files written in this invocation
        $reflection->getProperty('files_imported')->setValue($client, 5);

        $done = $reflection->getProperty('download_list_done')->getValue($client);
        $imported = $reflection->getProperty('files_imported')->getValue($client);
        $total = $reflection->getProperty('download_list_total')->getValue($client);

        // files_done as emitted in progress records = done + imported
        $filesDone = $done + $imported;
        $this->assertSame(45, $filesDone); // 40 from offset + 5 imported
        $this->assertSame(100, $total);
        $this->assertLessThanOrEqual($total, $filesDone);
    }
    // ---------------------------------------------------------------
    // Symlink deduplication tests
    // ---------------------------------------------------------------

    /**
     * Build a remote index JSONL file with the given entries.
     * Each entry: ['path' => string, 'type' => 'file'|'link'|'dir', 'target' => string|null]
     */
    private function writeRemoteIndex(array $entries): string
    {
        $file = $this->stateDir . '/.import-remote-index.jsonl';
        $handle = fopen($file, 'w');
        $ctime = 1000;
        foreach ($entries as $entry) {
            $data = [
                'path' => base64_encode($entry['path']),
                'ctime' => $ctime++,
                'size' => 100,
                'type' => $entry['type'] ?? 'file',
            ];
            if (isset($entry['target'])) {
                $data['target'] = base64_encode($entry['target']);
            }
            fwrite($handle, json_encode($data, JSON_UNESCAPED_SLASHES) . "\n");
        }
        fclose($handle);
        return $file;
    }

    /**
     * Run the diff phase and return the paths in the download list.
     */
    private function runDiffAndGetDownloadList(string $docRoot = '/srv/htdocs'): array
    {
        $this->writeState([
            'command' => 'files-sync',
            'status' => 'in_progress',
            'stage' => 'diff',
            'preflight' => [
                'data' => [
                    'ok' => true,
                    'runtime' => [
                        'document_root' => 'base64:' . base64_encode($docRoot),
                    ],
                ],
                'http_code' => 200,
            ],
        ]);

        [$client, $reflection] = $this->prepareClient();
        $diffMethod = $reflection->getMethod('diff_indexes_and_build_fetch_list');
        $diffMethod->invoke($client);

        return $this->readDownloadList();
    }

    private function readDownloadList(): array
    {
        $file = $this->stateDir . '/.import-download-list.jsonl';
        if (!file_exists($file)) {
            return [];
        }
        $paths = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $data = json_decode($line, true);
            if (isset($data['path'])) {
                $paths[] = base64_decode($data['path']);
            }
        }
        return $paths;
    }

    /**
     * Recursive symlink: /srv/htdocs/srv/htdocs/file.php is a re-entry
     * into the document root and must be skipped.
     */
    public function testRecursiveDocRootReentryIsSkipped()
    {
        $this->writeRemoteIndex([
            ['path' => '/srv/htdocs/file.php'],
            ['path' => '/srv/htdocs/srv', 'type' => 'dir'],
            ['path' => '/srv/htdocs/srv/htdocs', 'type' => 'dir'],
            ['path' => '/srv/htdocs/srv/htdocs/file.php'],
            ['path' => '/srv/htdocs/srv/htdocs/wp-config.php'],
            ['path' => '/srv/htdocs/wp-config.php'],
        ]);

        $paths = $this->runDiffAndGetDownloadList();

        $this->assertContains('/srv/htdocs/file.php', $paths);
        $this->assertContains('/srv/htdocs/wp-config.php', $paths);
        // These are recursive re-entries — same files via /srv/htdocs/srv/htdocs/
        $this->assertNotContains('/srv/htdocs/srv/htdocs/file.php', $paths);
        $this->assertNotContains('/srv/htdocs/srv/htdocs/wp-config.php', $paths);
    }

    /**
     * Paths outside the document root that are also reachable inside it
     * should be skipped.  /wordpress/plugins/foo.php is already reachable
     * as /srv/htdocs/wordpress/plugins/foo.php.
     */
    public function testPathsOutsideDocRootSkippedWhenReachableInside()
    {
        $this->writeRemoteIndex([
            ['path' => '/srv/htdocs/wordpress', 'type' => 'dir'],
            ['path' => '/srv/htdocs/wordpress/plugins/foo.php'],
            ['path' => '/wordpress', 'type' => 'dir'],
            ['path' => '/wordpress/plugins/foo.php'],
        ]);

        $paths = $this->runDiffAndGetDownloadList();

        // The doc-root version is canonical
        $this->assertContains('/srv/htdocs/wordpress/plugins/foo.php', $paths);
        // The outside-doc-root version is a duplicate
        $this->assertNotContains('/wordpress/plugins/foo.php', $paths);
    }

    /**
     * Symlink alias directories inside the doc root are NOT deduped
     * (the dedup is stateless — it only uses the doc root path).
     * This is acceptable: the alias files are a small fraction of the
     * total and keeping them is cheaper than in-memory state tracking.
     */
    public function testSymlinkAliasInsideDocRootIsKept()
    {
        $this->writeRemoteIndex([
            ['path' => '/srv/htdocs/wordpress/plugins/jetpack/15.7/file.php'],
            ['path' => '/srv/htdocs/wordpress/plugins/jetpack/latest', 'type' => 'link', 'target' => '/srv/htdocs/wordpress/plugins/jetpack/15.7'],
            ['path' => '/srv/htdocs/wordpress/plugins/jetpack/latest/file.php'],
        ]);

        $paths = $this->runDiffAndGetDownloadList();

        // Both kept — alias dedup would require in-memory state
        $this->assertContains('/srv/htdocs/wordpress/plugins/jetpack/15.7/file.php', $paths);
        $this->assertContains('/srv/htdocs/wordpress/plugins/jetpack/latest/file.php', $paths);
    }

    /**
     * Combined: all three dedup patterns together, matching the real
     * WP.com Atomic site structure.
     */
    public function testCombinedWpComAtomicDedup()
    {
        $this->writeRemoteIndex([
            // Canonical files under document root
            ['path' => '/srv/htdocs/wp-config.php'],
            ['path' => '/srv/htdocs/wordpress', 'type' => 'dir'],
            ['path' => '/srv/htdocs/wordpress/plugins/jetpack/15.7/init.php'],
            ['path' => '/srv/htdocs/wordpress/plugins/jetpack/latest', 'type' => 'link', 'target' => '/srv/htdocs/wordpress/plugins/jetpack/15.7'],
            ['path' => '/srv/htdocs/wordpress/plugins/jetpack/latest/init.php'],
            ['path' => '/srv/htdocs/wp-content/mu-plugins/loader.php'],
            // Recursive re-entry via /srv/htdocs/srv/htdocs/
            ['path' => '/srv/htdocs/srv', 'type' => 'dir'],
            ['path' => '/srv/htdocs/srv/htdocs', 'type' => 'dir'],
            ['path' => '/srv/htdocs/srv/htdocs/wp-config.php'],
            ['path' => '/srv/htdocs/srv/htdocs/wp-content/mu-plugins/loader.php'],
            // Outside doc root — duplicates /srv/htdocs/wordpress/
            ['path' => '/wordpress', 'type' => 'dir'],
            ['path' => '/wordpress/plugins/jetpack/15.7/init.php'],
            ['path' => '/wordpress/plugins/jetpack/latest', 'type' => 'link', 'target' => '/wordpress/plugins/jetpack/15.7'],
            ['path' => '/wordpress/plugins/jetpack/latest/init.php'],
        ]);

        $paths = $this->runDiffAndGetDownloadList();

        // Canonical files kept
        $this->assertContains('/srv/htdocs/wp-config.php', $paths);
        $this->assertContains('/srv/htdocs/wordpress/plugins/jetpack/15.7/init.php', $paths);
        $this->assertContains('/srv/htdocs/wp-content/mu-plugins/loader.php', $paths);

        // Symlink alias inside doc root is kept (stateless dedup doesn't track these)
        $this->assertContains('/srv/htdocs/wordpress/plugins/jetpack/latest/init.php', $paths);

        // Recursive re-entries skipped
        $this->assertNotContains('/srv/htdocs/srv/htdocs/wp-config.php', $paths);
        $this->assertNotContains('/srv/htdocs/srv/htdocs/wp-content/mu-plugins/loader.php', $paths);

        // Outside-doc-root duplicates skipped
        $this->assertNotContains('/wordpress/plugins/jetpack/15.7/init.php', $paths);
        $this->assertNotContains('/wordpress/plugins/jetpack/latest/init.php', $paths);
    }

}
