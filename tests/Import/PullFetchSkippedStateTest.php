<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

class PullFetchSkippedStateTest extends TestCase
{
    private string $tempDir;
    private string $stateDir;
    private string $fsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/reprint-pull-fetch-' . uniqid('', true);
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
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

    private function writeState(array $state): void
    {
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode($state),
        );
    }

    private function makePull(): \Pull
    {
        $client = new \ImportClient('http://example.invalid', $this->stateDir, $this->fsRoot);
        return new \Pull($client, new \TerminalProgress(false, STDOUT));
    }

    public function testSkippedEarlierOnlyRunsFilesPullStage(): void
    {
        $this->assertSame(
            ['files-pull'],
            $this->makePull()->stages(['filter' => 'skipped-earlier']),
        );
    }

    public function testSkippedEarlierRequiresDeferredFilesState(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'pull --filter=skipped-earlier was requested but there are no deferred files pending. ' .
            'Run pull --filter=essential-files first.'
        );

        $client = new \ImportClient('http://example.invalid', $this->stateDir, $this->fsRoot);
        $client->run([
            'command' => 'pull',
            'filter' => 'skipped-earlier',
        ]);
    }

    public function testSkippedEarlierRejectsIncompletePrimaryPull(): void
    {
        $this->writeState([
            'pull' => [
                'stage' => 'db-pull',
                'files_filter' => 'essential-files',
                'skipped_pending' => true,
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'pull --filter=skipped-earlier was requested before the prior pull finished. ' .
            'Re-run the original pull command to complete the main sync first.'
        );

        $client = new \ImportClient('http://example.invalid', $this->stateDir, $this->fsRoot);
        $client->run([
            'command' => 'pull',
            'filter' => 'skipped-earlier',
        ]);
    }

    public function testPullDoesNotPersistSkippedEarlierAsDefaultFilter(): void
    {
        $this->writeState([
            'filter' => 'essential-files',
            'pull' => [
                'stage' => 'complete',
                'files_filter' => 'essential-files',
                'skipped_pending' => true,
            ],
        ]);

        $client = new \ImportClient('http://example.invalid', $this->stateDir, $this->fsRoot);
        try {
            $client->run([
                'command' => 'pull',
                'filter' => 'skipped-earlier',
            ]);
        } catch (\Exception $e) {
            // Expected: fake host, missing skipped list, etc. The key
            // assertion is that the persisted default filter stays intact.
        }

        $state = json_decode(
            file_get_contents($this->stateDir . '/.import-state.json'),
            true,
        );

        $this->assertSame('essential-files', $state['filter']);
    }
}
