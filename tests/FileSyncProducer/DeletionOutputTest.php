<?php

namespace FileSyncProducerTests;

require_once __DIR__ . '/FileSyncProducerTestBase.php';

/**
 * Test that deletions are always output, even when no files change
 */
class DeletionOutputTest extends FileSyncProducerTestBase
{
    public function testDeletionOutputWhenNoFilesChange()
    {
        $dir = $this->createTestDirectory('deletion-only', [
            'file1.txt' => 'content1',
            'file2.txt' => 'content2',
            'file3.txt' => 'content3'
        ]);

        $snapshotFile = $this->fixturesDir . '/snapshot-deletion-only.tsv';
        $storage = new \FileSnapshotStorage($snapshotFile);

        // First sync - all files
        $sync1 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $chunks1 = $this->processAllChunks($sync1);
        $this->assertCount(3, $this->getFilesFromChunks($chunks1), 'First sync should get all 3 files');

        // Delete file2.txt, but don't modify any other files
        $this->deleteFile($dir, 'file2.txt');

        // Second sync - should output deletion even though no files changed
        $sync2 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $chunks2 = $this->processAllChunks($sync2);
        $deletions2 = $sync2->get_deletions();

        // Should have detected deletion
        $this->assertCount(1, $deletions2, 'Should detect 1 deletion');
        $this->assertStringContainsString('file2.txt', $deletions2[0]['path']);

        // Should NOT output any file chunks (all files unchanged)
        $this->assertCount(0, $chunks2, 'Should not output any file chunks since all files unchanged');
    }

    public function testDeletionOutputWithSomeFilesChanged()
    {
        $dir = $this->createTestDirectory('deletion-with-changes', [
            'file1.txt' => 'v1',
            'file2.txt' => 'v1',
            'file3.txt' => 'v1'
        ]);

        $snapshotFile = $this->fixturesDir . '/snapshot-deletion-changes.tsv';
        $storage = new \FileSnapshotStorage($snapshotFile);

        // First sync
        $sync1 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $this->processAllChunks($sync1);

        sleep(1);

        // Modify file1 and delete file2
        $this->updateFile($dir, 'file1.txt', 'v2');
        $this->deleteFile($dir, 'file2.txt');

        // Second sync
        $sync2 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $chunks2 = $this->processAllChunks($sync2);
        $deletions2 = $sync2->get_deletions();

        // Should output deletion
        $this->assertCount(1, $deletions2, 'Should detect deletion');
        $this->assertStringContainsString('file2.txt', $deletions2[0]['path']);

        // Should output only changed file
        $files2 = $this->getFilesFromChunks($chunks2);
        $this->assertCount(1, $files2, 'Should output only changed file');
        $this->assertStringContainsString('file1.txt', $files2[0]);
    }

    public function testMultipleDeletionsNoChanges()
    {
        $dir = $this->createTestDirectory('multi-deletion', [
            'file1.txt' => 'content',
            'file2.txt' => 'content',
            'file3.txt' => 'content',
            'file4.txt' => 'content'
        ]);

        $snapshotFile = $this->fixturesDir . '/snapshot-multi-deletion.tsv';
        $storage = new \FileSnapshotStorage($snapshotFile);

        // First sync
        $sync1 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $this->processAllChunks($sync1);

        // Delete multiple files
        $this->deleteFile($dir, 'file2.txt');
        $this->deleteFile($dir, 'file3.txt');

        // Second sync
        $sync2 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $chunks2 = $this->processAllChunks($sync2);
        $deletions2 = $sync2->get_deletions();

        // Should detect both deletions
        $this->assertCount(2, $deletions2, 'Should detect 2 deletions');

        $deletedPaths = array_map(fn($d) => basename($d['path']), $deletions2);
        $this->assertContains('file2.txt', $deletedPaths);
        $this->assertContains('file3.txt', $deletedPaths);

        // No file chunks
        $this->assertCount(0, $chunks2, 'Should not output file chunks');
    }
}
