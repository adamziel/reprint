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

    public function testFetchSkippedOnlyRunsFilesPullStage(): void
    {
        $this->assertSame(
            ['files-pull'],
            $this->makePull()->stages(['fetch_skipped' => true]),
        );
    }

    public function testFetchSkippedRequiresDeferredFilesState(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            '--fetch-skipped was requested but there are no deferred files pending. ' .
            'Run pull --filter=essential-files first.'
        );

        $client = new \ImportClient('http://example.invalid', $this->stateDir, $this->fsRoot);
        $client->run([
            'command' => 'pull',
            'fetch_skipped' => true,
        ]);
    }

    public function testFetchSkippedRejectsIncompletePrimaryPull(): void
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
            '--fetch-skipped was requested before the prior pull finished. ' .
            'Re-run the original pull command to complete the main sync first.'
        );

        $client = new \ImportClient('http://example.invalid', $this->stateDir, $this->fsRoot);
        $client->run([
            'command' => 'pull',
            'fetch_skipped' => true,
        ]);
    }

    public function testFetchSkippedRejectsExplicitFilterCombination(): void
    {
        $this->writeState([
            'pull' => [
                'stage' => 'complete',
                'files_filter' => 'essential-files',
                'skipped_pending' => true,
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            '--fetch-skipped cannot be combined with --filter. ' .
            'Run either pull --filter=essential-files or pull --fetch-skipped.'
        );

        $client = new \ImportClient('http://example.invalid', $this->stateDir, $this->fsRoot);
        $client->run([
            'command' => 'pull',
            'fetch_skipped' => true,
            'filter' => 'essential-files',
        ]);
    }
}
