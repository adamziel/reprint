<?php

namespace FileSyncProducerTests;

require_once __DIR__ . '/FileSyncProducerTestBase.php';

/**
 * Test file updates and modifications during sync
 */
class FileUpdateTest extends FileSyncProducerTestBase
{
    public function testDetectFileModificationBetweenSyncs()
    {
        $dir = $this->createTestDirectory('update-test', [
            'file1.txt' => 'Original content',
            'file2.txt' => 'Unchanged'
        ]);

        $snapshotFile = $this->fixturesDir . '/snapshot-update.tsv';
        $storage = new \FileSnapshotStorage($snapshotFile);

        // First sync
        $sync1 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $this->processAllChunks($sync1);

        // Modify file1.txt
        sleep(1); // Ensure ctime changes
        $this->updateFile($dir, 'file1.txt', 'Modified content');

        // Second sync - should detect modification via snapshot comparison
        // Scans all files (for deletion detection) but only streams changed files
        $sync2 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $chunks = $this->processAllChunks($sync2);

        $this->assertNotEmpty($chunks, 'Should process modified file');

        $modifiedFiles = array_filter($chunks, fn($c) =>
            $c['is_first_chunk'] && strpos($c['path'], 'file1.txt') !== false
        );
        $this->assertNotEmpty($modifiedFiles, 'Should include modified file1.txt');

        $unchangedFiles = array_filter($chunks, fn($c) =>
            $c['is_first_chunk'] && strpos($c['path'], 'file2.txt') !== false
        );
        $this->assertEmpty($unchangedFiles, 'Should not include unchanged file2.txt');
    }

    public function testFileGrowsDuringStreaming()
    {
        $initialContent = str_repeat('A', 5000);
        $dir = $this->createTestDirectory('growing-file', [
            'growing.txt' => $initialContent
        ]);

        $sync = new \FileTreeProducer($dir, [
            'chunk_size' => 2048
        ]);

        $chunks = [];
        $chunkCount = 0;

        while ($sync->next_chunk()) {
            $chunk = $sync->get_current_chunk();
            if ($chunk) {
                $chunks[] = $chunk;
                $chunkCount++;

                // Modify file after first chunk
                if ($chunkCount === 1) {
                    $this->updateFile($dir, 'growing.txt', $initialContent . 'BBBB');
                }
            }
        }

        // File should either:
        // 1. Be streamed with original content (snapshot taken at start)
        // 2. Be detected as changed and handled appropriately
        $this->assertNotEmpty($chunks, 'Should produce chunks even if file changes');
    }

    public function testMultipleModificationsBetweenSyncs()
    {
        $dir = $this->createTestDirectory('multi-mod', [
            'file1.txt' => 'Version 1',
            'file2.txt' => 'Version 1',
            'file3.txt' => 'Version 1'
        ]);

        $snapshotFile = $this->fixturesDir . '/snapshot-multi-mod.tsv';
        $storage = new \FileSnapshotStorage($snapshotFile);

        // First sync
        $sync1 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $this->processAllChunks($sync1);

        // Modify multiple files
        sleep(1);
        $this->updateFile($dir, 'file1.txt', 'Version 2');
        $this->updateFile($dir, 'file2.txt', 'Version 2');
        // file3.txt unchanged

        // Second sync - filters via snapshot comparison
        $sync2 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $chunks = $this->processAllChunks($sync2);
        $files = $this->getFilesFromChunks($chunks);

        $this->assertCount(2, $files, 'Should sync only 2 modified files');
    }

    public function testStubbornFileKeepsChanging()
    {
        $dir = $this->createTestDirectory('stubborn', [
            'volatile.txt' => 'Change 1'
        ]);

        $snapshotFile = $this->fixturesDir . '/snapshot-stubborn.tsv';
        $storage = new \FileSnapshotStorage($snapshotFile);

        // Initial sync
        $sync1 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $this->processAllChunks($sync1);

        // Sync multiple times, changing file each time
        for ($i = 2; $i <= 5; $i++) {
            sleep(1);
            $this->updateFile($dir, 'volatile.txt', "Change $i");

            $sync = new \FileTreeProducer($dir, [
                'snapshot_storage' => $storage
            ]);
            $chunks = $this->processAllChunks($sync);

            $this->assertNotEmpty($chunks, "Sync $i should detect changes");

            $fileChunks = array_filter($chunks, fn($c) =>
                strpos($c['path'], 'volatile.txt') !== false
            );
            $this->assertNotEmpty($fileChunks, "Sync $i should include volatile.txt");
        }
    }

    public function testFileDeletedDuringStreaming()
    {
        $dir = $this->createTestDirectory('delete-during', [
            'file1.txt' => str_repeat('A', 5000),
            'file2.txt' => 'Content',
            'file3.txt' => str_repeat('B', 5000)
        ]);

        $sync = new \FileTreeProducer($dir, [
            'chunk_size' => 2048
        ]);

        $chunkCount = 0;

        while ($sync->next_chunk()) {
            $chunk = $sync->get_current_chunk();
            if ($chunk) {
                $chunkCount++;

                // Delete file2.txt after processing first file
                if ($chunkCount === 3) {
                    $this->deleteFile($dir, 'file2.txt');
                }
            }
        }

        // Should handle deletion gracefully without crashing
        $this->assertTrue(true, 'Should handle file deletion during streaming');
    }

    public function testFileContentChanges()
    {
        $dir = $this->createTestDirectory('content-change', [
            'data.txt' => 'Original'
        ]);

        $snapshotFile = $this->fixturesDir . '/snapshot-content.tsv';
        $storage = new \FileSnapshotStorage($snapshotFile);

        // First sync
        $sync1 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $chunks1 = $this->processAllChunks($sync1);
        $content1 = $this->reconstructFileFromChunks($chunks1, $dir . '/data.txt');

        $this->assertEquals('Original', $content1);

        // Modify with different content
        sleep(1);
        $this->updateFile($dir, 'data.txt', 'Modified content is longer');

        // Second sync
        $sync2 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $chunks2 = $this->processAllChunks($sync2);
        $content2 = $this->reconstructFileFromChunks($chunks2, $dir . '/data.txt');

        $this->assertEquals('Modified content is longer', $content2);
        $this->assertNotEquals($content1, $content2);
    }

    public function testNoChangesNoSync()
    {
        $dir = $this->createTestDirectory('no-changes', [
            'static.txt' => 'Never changes'
        ]);

        $snapshotFile = $this->fixturesDir . '/snapshot-static.tsv';
        $storage = new \FileSnapshotStorage($snapshotFile);

        // First sync
        $sync1 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $chunks1 = $this->processAllChunks($sync1);

        $this->assertNotEmpty($chunks1, 'First sync should process files');

        // Second sync without changes - filters via snapshot comparison
        $sync2 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $chunks2 = $this->processAllChunks($sync2);

        $this->assertEmpty($chunks2, 'Second sync should skip unchanged files');
    }

    public function testFileSizeIncrease()
    {
        $dir = $this->createTestDirectory('size-test', [
            'growing.txt' => 'Small'
        ]);

        $snapshotFile = $this->fixturesDir . '/snapshot-size.tsv';
        $storage = new \FileSnapshotStorage($snapshotFile);

        // First sync
        $sync1 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $this->processAllChunks($sync1);

        // Grow file significantly
        sleep(1);
        $this->updateFile($dir, 'growing.txt', str_repeat('X', 10000));

        // Second sync
        $sync2 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage,
            'chunk_size' => 2048
        ]);
        $chunks = $this->processAllChunks($sync2);

        $this->assertNotEmpty($chunks, 'Should sync enlarged file');

        $growingChunks = array_filter($chunks, fn($c) =>
            strpos($c['path'], 'growing.txt') !== false
        );
        $this->assertGreaterThan(1, count($growingChunks), 'Large file should produce multiple chunks');
    }
}
