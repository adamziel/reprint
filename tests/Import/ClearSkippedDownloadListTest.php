<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Test that clear_skipped_download_list() truncates the skipped-files
 * download list in place rather than deleting it.
 *
 * apply-runtime mounts this file into the generated runtime, so deleting
 * it crashes the running site with ENOENT on the next runtime rotation.
 * Truncating to empty keeps the mount valid while signalling "no
 * remote-only files left" (every has-skipped check tests for size > 0).
 */
class ClearSkippedDownloadListTest extends TestCase
{
    private string $tempDir;
    private string $stateDir;
    private string $skippedListFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/import-clear-skipped-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->tempDir . '/fs-root', 0755, true);
        $this->skippedListFile = $this->stateDir . '/.import-download-list-skipped.jsonl';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->stateDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->stateDir);
        @rmdir($this->tempDir . '/fs-root');
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    private function makeClient(): \ImportClient
    {
        return new \ImportClient('http://fake.url', $this->stateDir, $this->tempDir . '/fs-root');
    }

    public function testTruncatesExistingListToEmptyWithoutDeleting(): void
    {
        file_put_contents(
            $this->skippedListFile,
            json_encode(['path' => base64_encode('/wp-content/uploads/2024/photo.jpg')]) . "\n",
        );
        $this->assertGreaterThan(0, filesize($this->skippedListFile));

        $this->makeClient()->clear_skipped_download_list('test reason');

        $this->assertFileExists(
            $this->skippedListFile,
            'The list must remain on disk (truncated, not deleted) so the runtime mount stays valid',
        );
        $this->assertSame(
            0,
            filesize($this->skippedListFile),
            'The list must be truncated to empty',
        );
    }

    public function testNoOpWhenListIsAbsent(): void
    {
        $this->assertFileDoesNotExist($this->skippedListFile);

        // Must not throw and must not create the file.
        $this->makeClient()->clear_skipped_download_list('test reason');

        $this->assertFileDoesNotExist(
            $this->skippedListFile,
            'A missing list must stay missing (no file is created)',
        );
    }
}
