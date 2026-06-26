<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

class ImportMetadataTest extends TestCase
{
    private string $tempDir;
    private string $stateDir;
    private string $fsRoot;

    /**
     * Creates an isolated state directory for each metadata scenario.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/reprint-import-metadata-' . uniqid('', true);
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
    }

    /**
     * Removes the temporary state and filesystem roots created for the test.
     */
    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    /**
     * Deletes a directory tree while preserving symlink boundaries.
     */
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

    /**
     * Writes importer state directly so each test can model one lifecycle shape.
     */
    private function writeState(array $state): void
    {
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Runs the metadata command and returns its decoded JSON response.
     */
    private function readMetadata(): array
    {
        $client = new \ImportClient('http://example.invalid', $this->stateDir, $this->fsRoot);

        ob_start();
        $client->run(['command' => 'import-metadata']);
        $output = ob_get_clean();

        $metadata = json_decode($output, true);
        $this->assertIsArray($metadata, $output);

        return $metadata;
    }

    /**
     * Verifies a missing state file is reported as a never-completed pull.
     */
    public function testImportMetadataReportsNoCompletedPullForFreshState(): void
    {
        $metadata = $this->readMetadata();

        $this->assertFalse($metadata['hasCompletedOnce']);
        $this->assertFileDoesNotExist($this->stateDir . '/.import-state.json');
        $this->assertNull($metadata['pullStage']);
    }

    /**
     * Verifies completed low-level commands do not imply a completed pull.
     */
    public function testImportMetadataDoesNotTreatCompletedSubcommandAsCompletedPull(): void
    {
        $this->writeState([
            'command' => 'files-download',
            'status' => 'complete',
        ]);

        $metadata = $this->readMetadata();

        $this->assertFalse($metadata['hasCompletedOnce']);
        $this->assertNull($metadata['pullStage']);
    }

    /**
     * Verifies old state files with pull.stage=complete still report completion.
     */
    public function testImportMetadataReportsCompletedLegacyState(): void
    {
        $this->writeState([
            'status' => 'complete',
            'pull' => [
                'stage' => 'complete',
                'files_filter' => 'essential-files',
                'skipped_pending' => true,
            ],
        ]);

        $metadata = $this->readMetadata();

        $this->assertTrue($metadata['hasCompletedOnce']);
        $this->assertSame('complete', $metadata['pullStage']);
    }

    /**
     * Verifies delta re-pull state preserves that a pull completed previously.
     */
    public function testImportMetadataReportsPriorCompletionDuringRepull(): void
    {
        $this->writeState([
            'status' => null,
            'pull' => [
                'stage' => null,
                'files_filter' => null,
                'skipped_pending' => false,
                'has_completed_once' => true,
            ],
        ]);

        $metadata = $this->readMetadata();

        $this->assertTrue($metadata['hasCompletedOnce']);
        $this->assertNull($metadata['pullStage']);
    }
}
