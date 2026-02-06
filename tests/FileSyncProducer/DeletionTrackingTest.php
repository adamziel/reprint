<?php

namespace FileSyncProducerTests;

require_once __DIR__ . '/FileSyncProducerTestBase.php';

/**
 * Test file deletion tracking
 */
class DeletionTrackingTest extends FileSyncProducerTestBase
{
    public function testDetectSingleFileDeletion()
    {
        $dir = $this->createTestDirectory('deletion-test', [
            'file1.txt' => 'Content 1',
            'file2.txt' => 'Content 2',
            'file3.txt' => 'Content 3'
        ]);

        $snapshotFile = $this->fixturesDir . '/snapshot-deletion.tsv';
        $storage = new \FileSnapshotStorage($snapshotFile);

        // First sync - create snapshot
        $sync1 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $this->processAllChunks($sync1);

        $this->assertFileExists($snapshotFile, 'Snapshot file should be created');

        // Delete a file
        $this->deleteFile($dir, 'file2.txt');

        // Second sync - detect deletion
        $sync2 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $this->processAllChunks($sync2);

        $deletions = $sync2->get_deletions();

        $this->assertCount(1, $deletions, 'Should detect one deletion');
        $this->assertStringContainsString('file2.txt', $deletions[0]['path']);
    }

    public function testDetectMultipleFileDeletions()
    {
        $dir = $this->createTestDirectory('multi-deletion', [
            'keep1.txt' => '1',
            'keep2.txt' => '2',
            'delete1.txt' => 'X',
            'delete2.txt' => 'Y',
            'delete3.txt' => 'Z'
        ]);

        $snapshotFile = $this->fixturesDir . '/snapshot-multi.tsv';
        $storage = new \FileSnapshotStorage($snapshotFile);

        // First sync
        $sync1 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $this->processAllChunks($sync1);

        // Delete multiple files
        $this->deleteFile($dir, 'delete1.txt');
        $this->deleteFile($dir, 'delete2.txt');
        $this->deleteFile($dir, 'delete3.txt');

        // Second sync
        $sync2 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $this->processAllChunks($sync2);

        $deletions = $sync2->get_deletions();

        $this->assertCount(3, $deletions, 'Should detect all 3 deletions');
    }

    public function testNoDeletionsWhenNoSnapshot()
    {
        $dir = $this->createTestDirectory('no-snapshot', [
            'file.txt' => 'content'
        ]);

        // Sync without snapshot storage
        $sync = new \FileTreeProducer($dir);
        $this->processAllChunks($sync);

        $deletions = $sync->get_deletions();

        $this->assertEmpty($deletions, 'Should return empty array when no snapshot storage');
    }

    public function testNewFileNotMarkedAsDeleted()
    {
        $dir = $this->createTestDirectory('new-file-test', [
            'existing.txt' => 'exists'
        ]);

        $snapshotFile = $this->fixturesDir . '/snapshot-new.tsv';
        $storage = new \FileSnapshotStorage($snapshotFile);

        // First sync
        $sync1 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $this->processAllChunks($sync1);

        // Add a new file
        $this->createFile($dir, 'new.txt', 'new content');

        // Second sync
        $sync2 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $this->processAllChunks($sync2);

        $deletions = $sync2->get_deletions();

        $this->assertEmpty($deletions, 'New files should not be marked as deleted');
    }

    public function testDeletionPersistsAcrossMultipleSyncs()
    {
        $dir = $this->createTestDirectory('persist-deletion', [
            'file1.txt' => '1',
            'file2.txt' => '2'
        ]);

        $snapshotFile = $this->fixturesDir . '/snapshot-persist.tsv';
        $storage = new \FileSnapshotStorage($snapshotFile);

        // First sync
        $sync1 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $this->processAllChunks($sync1);

        // Delete file
        $this->deleteFile($dir, 'file2.txt');

        // Second sync - detects deletion
        $sync2 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $this->processAllChunks($sync2);
        $deletions2 = $sync2->get_deletions();

        $this->assertCount(1, $deletions2);

        // Third sync - deletion record should still be in snapshot
        $sync3 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $this->processAllChunks($sync3);

        // No NEW deletions, but the deleted file is still marked in snapshot
        $deletions3 = $sync3->get_deletions();
        $this->assertEmpty($deletions3, 'No new deletions on third sync');
    }

    public function testSqliteSnapshotStorage()
    {
        if (!class_exists('SQLite3')) {
            $this->markTestSkipped('SQLite3 extension not available');
        }

        $dir = $this->createTestDirectory('sqlite-test', [
            'file1.txt' => '1',
            'file2.txt' => '2'
        ]);

        $dbFile = $this->fixturesDir . '/snapshot.db';
        $storage = new \SqliteSnapshotStorage($dbFile);

        // First sync
        $sync1 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $this->processAllChunks($sync1);

        $this->assertFileExists($dbFile);

        // Delete file
        $this->deleteFile($dir, 'file2.txt');

        // Second sync
        $sync2 = new \FileTreeProducer($dir, [
            'snapshot_storage' => $storage
        ]);
        $this->processAllChunks($sync2);

        $deletions = $sync2->get_deletions();

        $this->assertCount(1, $deletions, 'SQLite storage should detect deletions');
        $this->assertStringContainsString('file2.txt', $deletions[0]['path']);
    }
}
