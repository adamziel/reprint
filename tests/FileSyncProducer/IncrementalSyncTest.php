<?php

namespace FileSyncProducerTests;

require_once __DIR__ . '/FileSyncProducerTestBase.php';

/**
 * Test incremental sync with min_ctime filtering
 */
class IncrementalSyncTest extends FileSyncProducerTestBase
{
    public function testMinCtimeFiltersOldFiles()
    {
        $dir = $this->createTestDirectory('min-ctime-test', [
            'old.txt' => 'Old content',
            'new.txt' => 'New content'
        ]);

        // Get timestamp
        $cutoffTime = time();

        // Wait and create another file
        sleep(1);
        $this->createFile($dir, 'newer.txt', 'Newer content');

        // Sync with min_ctime filter
        $sync = new \FileTreeProducer($dir, [
            'min_ctime' => $cutoffTime
        ]);
        $chunks = $this->processAllChunks($sync);
        $files = $this->getFilesFromChunks($chunks);

        $this->assertCount(1, $files, 'Should only sync files created after cutoff');

        $newerFile = array_filter($files, fn($f) => strpos($f, 'newer.txt') !== false);
        $this->assertNotEmpty($newerFile, 'Should include newer.txt');
    }

    public function testMinCtimeZeroSyncsAll()
    {
        $dir = $this->createTestDirectory('all-files', [
            'file1.txt' => '1',
            'file2.txt' => '2',
            'file3.txt' => '3'
        ]);

        // Sync with min_ctime = 0 (all files)
        $sync = new \FileTreeProducer($dir, [
            'min_ctime' => 0
        ]);
        $chunks = $this->processAllChunks($sync);
        $files = $this->getFilesFromChunks($chunks);

        $this->assertCount(3, $files, 'min_ctime=0 should sync all files');
    }

    public function testIncrementalSyncWorkflow()
    {
        $dir = $this->createTestDirectory('incremental', [
            'batch1-a.txt' => 'A',
            'batch1-b.txt' => 'B'
        ]);

        // First full sync
        $sync1 = new \FileTreeProducer($dir, [
            'min_ctime' => 0
        ]);
        $chunks1 = $this->processAllChunks($sync1);
        $files1 = $this->getFilesFromChunks($chunks1);

        $this->assertCount(2, $files1, 'First sync should get 2 files');

        // Remember cutoff time
        $cutoffTime = time();
        sleep(1);

        // Add more files
        $this->createFile($dir, 'batch2-c.txt', 'C');
        $this->createFile($dir, 'batch2-d.txt', 'D');

        // Second incremental sync
        $sync2 = new \FileTreeProducer($dir, [
            'min_ctime' => $cutoffTime
        ]);
        $chunks2 = $this->processAllChunks($sync2);
        $files2 = $this->getFilesFromChunks($chunks2);

        $this->assertCount(2, $files2, 'Second sync should get only new 2 files');

        $hasOldFiles = array_filter($files2, fn($f) =>
            strpos($f, 'batch1') !== false
        );
        $this->assertEmpty($hasOldFiles, 'Should not include old files');
    }

    public function testMinCtimeWithModifiedFiles()
    {
        $dir = $this->createTestDirectory('modified-filter', [
            'original.txt' => 'Original'
        ]);

        $cutoffTime = time();
        sleep(1);

        // Modify existing file
        $this->updateFile($dir, 'original.txt', 'Modified');

        // Sync should pick up modification
        $sync = new \FileTreeProducer($dir, [
            'min_ctime' => $cutoffTime
        ]);
        $chunks = $this->processAllChunks($sync);
        $files = $this->getFilesFromChunks($chunks);

        $this->assertCount(1, $files, 'Should sync modified file');

        $content = $this->reconstructFileFromChunks($chunks, $dir . '/original.txt');
        $this->assertEquals('Modified', $content, 'Should have new content');
    }

    public function testMinCtimeWithCursorResumption()
    {
        // Create many files
        $files = [];
        for ($i = 1; $i <= 20; $i++) {
            $files["file{$i}.txt"] = "Content {$i}";
        }

        $dir = $this->createTestDirectory('cursor-with-filter', $files);

        $cutoffTime = time() - 100; // Before file creation

        // Start sync with filter
        $sync1 = new \FileTreeProducer($dir, [
            'min_ctime' => $cutoffTime,
            'chunk_size' => 1024
        ]);

        // Process a few chunks
        $iterations = 0;
        while ($sync1->next_chunk() && $iterations++ < 5) {
            $sync1->get_current_chunk();
        }

        $cursor = $sync1->get_reentrancy_cursor();

        // Resume with same filter
        $sync2 = new \FileTreeProducer($dir, [
            'min_ctime' => $cutoffTime,
            'chunk_size' => 1024,
            'cursor' => $cursor
        ]);

        $remainingChunks = $this->processAllChunks($sync2);

        $this->assertNotEmpty($remainingChunks, 'Should have remaining chunks after resume');
    }

    public function testMinCtimeNoMatches()
    {
        $dir = $this->createTestDirectory('no-matches', [
            'old1.txt' => '1',
            'old2.txt' => '2'
        ]);

        // Use future timestamp
        $futureTime = time() + 1000;

        $sync = new \FileTreeProducer($dir, [
            'min_ctime' => $futureTime
        ]);
        $chunks = $this->processAllChunks($sync);

        $this->assertEmpty($chunks, 'Should return no chunks when no files match filter');
    }

    public function testMinCtimePrecision()
    {
        $dir = $this->createTestDirectory('precision', [
            'before.txt' => 'Before'
        ]);

        $exactTime = time();

        // Create file at almost same time
        $this->createFile($dir, 'at.txt', 'At');

        sleep(1);
        $this->createFile($dir, 'after.txt', 'After');

        // Sync with exact timestamp
        $sync = new \FileTreeProducer($dir, [
            'min_ctime' => $exactTime
        ]);
        $chunks = $this->processAllChunks($sync);
        $files = $this->getFilesFromChunks($chunks);

        // Should include files created at or after exactTime
        $this->assertGreaterThanOrEqual(1, count($files), 'Should sync files from cutoff time onward');
    }

}
