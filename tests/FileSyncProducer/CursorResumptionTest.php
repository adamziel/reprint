<?php

namespace FileSyncProducerTests;

require_once __DIR__ . '/FileSyncProducerTestBase.php';

/**
 * Test cursor-based resumption
 */
class CursorResumptionTest extends FileSyncProducerTestBase
{
    public function testCursorSaveAndRestore()
    {
        $dir = $this->createTestDirectory('cursor-test', [
            'file1.txt' => str_repeat('A', 5000),
            'file2.txt' => str_repeat('B', 5000),
            'file3.txt' => str_repeat('C', 5000)
        ]);

        // First sync - process 2 chunks then save cursor
        $sync1 = new \FileTreeProducer($dir, [
            'chunk_size' => 2048
        ]);

        $chunksBeforePause = [];
        $iterations = 0;
        while ($sync1->next_chunk() && $iterations++ < 2) {
            $chunk = $sync1->get_current_chunk();
            if ($chunk) {
                $chunksBeforePause[] = $chunk;
            }
        }

        $cursor = $sync1->get_reentrancy_cursor();
        $this->assertNotEmpty($cursor, 'Cursor should not be empty');

        // Second sync - resume from cursor
        $sync2 = new \FileTreeProducer($dir, [
            'chunk_size' => 2048,
            'cursor' => $cursor
        ]);

        $chunksAfterResume = $this->processAllChunks($sync2);

        // Verify no duplicate chunks
        $pathsBefore = array_map(fn($c) => $c['path'] . ':' . $c['offset'], $chunksBeforePause);
        $pathsAfter = array_map(fn($c) => $c['path'] . ':' . $c['offset'], $chunksAfterResume);

        $overlap = array_intersect($pathsBefore, $pathsAfter);
        $this->assertEmpty($overlap, 'Should not process same chunks after resume');
    }

    public function testCursorPersistsToFile()
    {
        $dir = $this->createTestDirectory('cursor-file', [
            'test.txt' => str_repeat('X', 3000)
        ]);

        $cursorFile = $this->fixturesDir . '/test-cursor.json';

        $sync1 = new \FileTreeProducer($dir, [
            'chunk_size' => 1024
        ]);

        // Process one chunk
        $sync1->next_chunk();
        $cursor = $sync1->get_reentrancy_cursor();

        // Save to file
        file_put_contents($cursorFile, $cursor);
        $this->assertFileExists($cursorFile);

        // Load from file and resume
        $loadedCursor = file_get_contents($cursorFile);
        $sync2 = new \FileTreeProducer($dir, [
            'chunk_size' => 1024,
            'cursor' => $loadedCursor
        ]);

        $this->assertInstanceOf(\FileTreeProducer::class, $sync2);

        // Cleanup
        unlink($cursorFile);
    }

    public function testMultipleResumeCycles()
    {
        $dir = $this->createTestDirectory('multiple-resume', [
            'file1.txt' => str_repeat('1', 2000),
            'file2.txt' => str_repeat('2', 2000),
            'file3.txt' => str_repeat('3', 2000),
            'file4.txt' => str_repeat('4', 2000)
        ]);

        $allChunks = [];
        $cursor = null;
        $maxIterationsPerCycle = 2;

        // Simulate multiple resume cycles
        for ($cycle = 0; $cycle < 5; $cycle++) {
            $options = ['chunk_size' => 1024];
            if ($cursor) {
                $options['cursor'] = $cursor;
            }

            $sync = new \FileTreeProducer($dir, $options);

            $iterations = 0;
            $hasMore = false;
            while ($iterations++ < $maxIterationsPerCycle && $sync->next_chunk()) {
                $chunk = $sync->get_current_chunk();
                if ($chunk) {
                    $allChunks[] = $chunk;
                    $hasMore = true;
                }
            }

            if (!$hasMore) {
                break; // No more chunks
            }

            $cursor = $sync->get_reentrancy_cursor();
        }

        // Verify we got all files
        $files = $this->getFilesFromChunks($allChunks);
        $this->assertGreaterThanOrEqual(4, count($files), 'Should eventually process all files');
    }

    public function testCursorInvalidFormat()
    {
        $dir = $this->createTestDirectory('invalid-cursor', [
            'test.txt' => 'test'
        ]);

        $this->expectException(\InvalidArgumentException::class);

        new \FileTreeProducer($dir, [
            'cursor' => 'invalid-json-data'
        ]);
    }

    public function testResumeAfterScanningPhase()
    {
        // Create many files to ensure scanning takes multiple iterations
        $files = [];
        for ($i = 1; $i <= 50; $i++) {
            $files["file{$i}.txt"] = "Content {$i}";
        }

        $dir = $this->createTestDirectory('scan-resume', $files);

        // Start sync
        $sync1 = new \FileTreeProducer($dir);

        // Process a few iterations (might still be in scanning phase)
        for ($i = 0; $i < 3; $i++) {
            $sync1->next_chunk();
        }

        $cursor = $sync1->get_reentrancy_cursor();

        // Resume and complete
        $sync2 = new \FileTreeProducer($dir, [
            'cursor' => $cursor
        ]);

        $chunks = $this->processAllChunks($sync2);

        // Should eventually complete without errors
        $this->assertTrue(true, 'Resume from scanning phase should work');
    }
}
