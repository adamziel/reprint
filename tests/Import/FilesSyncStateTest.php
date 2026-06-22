<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\FetchCheckpoint;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\FileSyncLocalApplier;
use Reprint\Importer\Filesystem\LocalImportFilesystem;
use Reprint\Importer\Index\IndexStore;
use Reprint\Importer\ImportClient;
use Reprint\Importer\Output\BufferedImportOutput;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Session\StatePathCodec;
use Reprint\Importer\Session\VolatileFileTracker;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Test files-pull state transitions and preserve-local diff behavior.
 *
 * A completed files-pull should refuse to re-run without --abort.
 * After --abort, the next run should start fresh (not "already complete").
 * In preserve-local mode, previously-synced files that changed remotely
 * must still be re-downloaded (not skipped).
 */
class FilesSyncStateTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fs_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/import-state-test-' . uniqid();
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

    private function makeClient(): ImportClient
    {
        return new ImportClient(
            'http://fake.url',
            $this->stateDir,
            $this->fs_root,
            new BufferedImportOutput(),
        );
    }

    private function makeLocalApplier(FilesPullCheckpoint $checkpoint): FileSyncLocalApplier
    {
        return new FileSyncLocalApplier(
            new LocalImportFilesystem(
                $this->fs_root,
                'preserve-local',
                function (string $message, bool $to_console): void {
                },
            ),
            new IndexStore(
                $this->stateDir . '/.import-index.jsonl',
                $this->stateDir . '/.import-index-updates.jsonl',
            ),
            new VolatileFileTracker($this->stateDir . '/.import-volatile-files.json'),
            new BufferedImportOutput(),
            $this->fs_root,
            $this->stateDir . '/.import-remote-index.jsonl',
            'preserve-local',
            false,
            0,
            null,
            null,
            $checkpoint,
            function (string $message, bool $to_console = true): void {
            },
            function (array $progress, bool $force = false): void {
            },
        );
    }

    /**
     * Write a state file directly.
     */
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
            "fs_root_nonempty_behavior" => "preserve-local",
            "max_allowed_packet" => null,
        ];
        file_put_contents(
            $this->stateDir . '/.reprint/run.json',
            json_encode(array_merge($defaults, $state), JSON_PRETTY_PRINT),
        );

        if (($state["command"] ?? null) === "files-pull") {
            $this->writeFilesPullCheckpoint($this->filesPullCheckpointFromState($state));
        }
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

    /**
     * Read the current state file.
     */
    private function readState(): array
    {
        $contents = file_get_contents($this->stateDir . '/.reprint/run.json');
        return json_decode($contents, true);
    }

    /**
     * Build a sorted index line from a path, ctime, size, and type.
     */
    private function indexLine(string $path, int $ctime, int $size, string $type = "file"): string
    {
        return json_encode([
            "path" => base64_encode($path),
            "ctime" => $ctime,
            "size" => $size,
            "type" => $type,
        ], JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Read the download list file and return the list of paths.
     */
    private function readDownloadList(): array
    {
        $file = $this->stateDir . '/.import-download-list.jsonl';
        if (!file_exists($file)) {
            return [];
        }
        $paths = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $data = json_decode($line, true);
            if (isset($data["path"])) {
                $paths[] = base64_decode($data["path"]);
            }
        }
        return $paths;
    }

    /**
     * Set up a client with state loaded and preserve-local mode.
     */
    private function prepareClient(): array
    {
        $client = $this->makeClient();
        $reflection = new \ReflectionClass($client);

        $stateProperty = $reflection->getProperty('state');
        $loadState = $reflection->getMethod('load_state');
        $stateProperty->setValue($client, $loadState->invoke($client));

        $behaviorProp = $reflection->getProperty('fs_root_nonempty_behavior');
        $behaviorProp->setValue($client, 'preserve-local');

        return [$client, $reflection];
    }

    // ---------------------------------------------------------------
    // State transition tests
    // ---------------------------------------------------------------

    /**
     * A completed files-pull should refuse to re-run.
     */
    public function testCompletedFilesSyncRefusesToRerun()
    {
        $this->writeState([
            "command" => "files-pull",
            "status" => "complete",
        ]);

        [$client, $reflection] = $this->prepareClient();

        $method = $reflection->getMethod('run_files_sync');
        $method->invoke($client);

        $state = $this->readState();
        $this->assertEquals("complete", $state["status"]);
        $this->assertEquals("files-pull", $state["command"]);
    }

    /**
     * After --abort, the state should not be "complete".
     */
    public function testAbortClearsCompletedStatus()
    {
        $indexFile = $this->stateDir . '/.import-index.jsonl';
        file_put_contents($indexFile, $this->indexLine('/wp-login.php', 1000, 100));

        $this->writeState([
            "command" => "files-pull",
            "status" => "complete",
        ]);

        [$client, $reflection] = $this->prepareClient();

        $abortMethod = $reflection->getMethod('handle_abort');
        $abortMethod->invoke($client, 'files-pull');

        $state = $this->readState();
        $this->assertNotEquals(
            "complete",
            $state["status"] ?? null,
            "After abort, status must not be 'complete' — the next run should start fresh",
        );
    }

    /**
     * Full abort→re-run cycle: the next run should start fresh, not
     * report "already complete".
     */
    public function testAbortThenRerunStartsFresh()
    {
        $indexFile = $this->stateDir . '/.import-index.jsonl';
        file_put_contents($indexFile, $this->indexLine('/wp-login.php', 1000, 100));

        $this->writeState([
            "command" => "files-pull",
            "status" => "complete",
        ]);

        // Step 1: abort
        [$client, $reflection] = $this->prepareClient();
        $reflection->getMethod('handle_abort')->invoke($client, 'files-pull');

        // Step 2: new client, try run_files_sync
        [$client2, $reflection2] = $this->prepareClient();

        try {
            $reflection2->getMethod('run_files_sync')->invoke($client2);
        } catch (\Exception $e) {
            // Expected: will fail trying to contact the fake URL
        }

        $state = $this->readState();
        $this->assertNotEquals(
            "complete",
            $state["status"],
            "After abort + re-run, the sync should start fresh, not report 'already complete'",
        );
        $this->assertEquals("files-pull", $state["command"]);
    }

    // ---------------------------------------------------------------
    // Preserve-local diff tests
    // ---------------------------------------------------------------

    /**
     * In preserve-local mode, a file that is in the local index and changed
     * remotely (different ctime) must be added to the download list.
     *
     * Preserve-local protects pre-existing local files, not files we
     * previously synced. A changed file in the local index is ours to update.
     */
    public function testDeltaDiffRedownloadsChangedIndexedFile()
    {
        // Local index: file synced at ctime 1000
        $localIndex = $this->stateDir . '/.import-index.jsonl';
        file_put_contents($localIndex, $this->indexLine('/wp-content/themes/flavor/style.css', 1000, 200));

        // Remote index: same file at ctime 2000 (changed)
        $remoteIndex = $this->stateDir . '/.import-remote-index.jsonl';
        file_put_contents($remoteIndex, $this->indexLine('/wp-content/themes/flavor/style.css', 2000, 250));

        // The file exists locally (downloaded during the initial sync)
        $localFile = $this->fs_root . '/wp-content/themes/flavor/style.css';
        mkdir(dirname($localFile), 0755, true);
        file_put_contents($localFile, 'old content');

        $this->writeState([
            "command" => "files-pull",
            "status" => "in_progress",
            "stage" => "diff",
        ]);

        [$client, $reflection] = $this->prepareClient();

        $diffMethod = $reflection->getMethod('diff_indexes_and_build_fetch_list');
        $diffMethod->invoke($client, $client->files_pull_checkpoint());

        $downloads = $this->readDownloadList();
        $this->assertContains(
            '/wp-content/themes/flavor/style.css',
            $downloads,
            "A changed file in the local index must be re-downloaded, not skipped by preserve-local",
        );
    }

    /**
     * In preserve-local mode, a file that is NOT in the local index but
     * exists locally (pre-existing) must be skipped.
     */
    public function testDeltaDiffSkipsPreExistingLocalFile()
    {
        // Local index: empty (file was never synced by us)
        $localIndex = $this->stateDir . '/.import-index.jsonl';
        file_put_contents($localIndex, '');

        // Remote index: file exists on remote
        $remoteIndex = $this->stateDir . '/.import-remote-index.jsonl';
        file_put_contents($remoteIndex, $this->indexLine('/wp-content/object-cache.php', 1000, 500));

        // The file exists locally (pre-existing, e.g. hosting drop-in)
        $localFile = $this->fs_root . '/wp-content/object-cache.php';
        mkdir(dirname($localFile), 0755, true);
        file_put_contents($localFile, 'local drop-in');

        $this->writeState([
            "command" => "files-pull",
            "status" => "in_progress",
            "stage" => "diff",
        ]);

        [$client, $reflection] = $this->prepareClient();

        $diffMethod = $reflection->getMethod('diff_indexes_and_build_fetch_list');
        $diffMethod->invoke($client, $client->files_pull_checkpoint());

        $downloads = $this->readDownloadList();
        $this->assertNotContains(
            '/wp-content/object-cache.php',
            $downloads,
            "A pre-existing local file not in the index must be skipped by preserve-local",
        );
    }

    /**
     * handle_file_chunk must overwrite an existing local file in
     * preserve-local mode when the file was placed in the download list
     * by the diff stage (i.e., it's a file we previously synced that
     * changed remotely).
     *
     * This is the fetch-stage counterpart to testDeltaDiffRedownloadsChangedIndexedFile.
     * The diff stage decides what to download; the fetch stage must not
     * second-guess that decision.
     */
    public function testFetchStageOverwritesPreviouslySyncedFile()
    {
        // Create the file locally (simulates a prior sync)
        $localFile = $this->fs_root . '/wp-content/themes/flavor/style.css';
        mkdir(dirname($localFile), 0755, true);
        file_put_contents($localFile, 'old content');

        $this->writeState([
            "command" => "files-pull",
            "status" => "in_progress",
            "stage" => "fetch",
        ]);

        // Send a file chunk with new content
        $applier = $this->makeLocalApplier(FilesPullCheckpoint::fresh());
        $context = new StreamingContext();
        $chunk = [
            'headers' => [
                'x-file-path' => base64_encode('/wp-content/themes/flavor/style.css'),
                'x-first-chunk' => '1',
                'x-last-chunk' => '1',
                'x-file-ctime' => '2000',
                'x-file-size' => '11',
            ],
            'body' => 'new content',
        ];

        $applier->handle_file_chunk($chunk, $context);

        if ($context->file_handle) {
            fclose($context->file_handle);
        }

        $this->assertEquals(
            'new content',
            file_get_contents($localFile),
            "Fetch stage must overwrite existing files that were placed in the download list",
        );
    }
}
