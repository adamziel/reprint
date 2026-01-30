<?php

namespace FileSyncProducerTests;

require_once __DIR__ . '/FileSyncProducerTestBase.php';

/**
 * Test snapshot storage implementations
 */
class SnapshotStorageTest extends FileSyncProducerTestBase
{
    public function testFileSnapshotStorageBasicOperations()
    {
        $snapshotFile = $this->fixturesDir . '/test-snapshot.tsv';
        $storage = new \FileSnapshotStorage($snapshotFile);

        $this->assertFileDoesNotExist($snapshotFile, 'Snapshot should not exist initially');

        // Create test data array
        $testData = [
            ['path' => '/test/file1.txt', 'ctime' => time(), 'size' => 100],
            ['path' => '/test/file2.txt', 'ctime' => time(), 'size' => 200],
        ];

        // Update from scan (now takes array directly)
        $deletions = $storage->update_from_scan($testData);

        $this->assertFileExists($snapshotFile, 'Snapshot should be created');
        $this->assertEmpty($deletions, 'First scan should have no deletions');

        // Verify we can retrieve files
        $file1 = $storage->get_file('/test/file1.txt');
        $this->assertNotNull($file1);
        $this->assertEquals(100, $file1['size']);
    }

    public function testFileSnapshotStorageHandlesSpecialCharacters()
    {
        $snapshotFile = $this->fixturesDir . '/special-chars.tsv';
        $storage = new \FileSnapshotStorage($snapshotFile);

        $testData = [
            ['path' => "/test/file\twith\ttabs.txt", 'ctime' => time(), 'size' => 100],
            ['path' => "/test/file with spaces.txt", 'ctime' => time(), 'size' => 300],
            ['path' => "/test/file\"with\"quotes.txt", 'ctime' => time(), 'size' => 400],
            ['path' => "/test/file\\with\\backslashes.txt", 'ctime' => time(), 'size' => 500],
        ];

        $storage->update_from_scan($testData);

        // Verify all files can be retrieved
        $file1 = $storage->get_file("/test/file\twith\ttabs.txt");
        $this->assertNotNull($file1, 'Should handle tabs in paths');

        $file2 = $storage->get_file("/test/file with spaces.txt");
        $this->assertNotNull($file2, 'Should handle spaces in paths');

        $file3 = $storage->get_file("/test/file\"with\"quotes.txt");
        $this->assertNotNull($file3, 'Should handle quotes in paths');

        $file4 = $storage->get_file("/test/file\\with\\backslashes.txt");
        $this->assertNotNull($file4, 'Should handle backslashes in paths');

        // Note: Newlines in paths are NOT supported as they conflict with TSV record separator

        unlink($sortedFile);
    }

    public function testFileSnapshotStorageDetectsDeletions()
    {
        $snapshotFile = $this->fixturesDir . '/deletions-test.tsv';
        $storage = new \FileSnapshotStorage($snapshotFile);

        // First scan - 3 files
        $testData1 = [
            ['path' => '/test/keep.txt', 'ctime' => time(), 'size' => 100],
            ['path' => '/test/delete1.txt', 'ctime' => time(), 'size' => 200],
            ['path' => '/test/delete2.txt', 'ctime' => time(), 'size' => 300],
        ];

        $storage->update_from_scan($testData1);

        // Second scan - 1 file (2 deleted)
        sleep(1);
        $testData2 = [
            ['path' => '/test/keep.txt', 'ctime' => time(), 'size' => 100],
        ];

        $deletions = $storage->update_from_scan($testData2);

        $this->assertCount(2, $deletions, 'Should detect 2 deletions');

        $deletedPaths = array_column($deletions, 'path');
        $this->assertContains('/test/delete1.txt', $deletedPaths);
        $this->assertContains('/test/delete2.txt', $deletedPaths);
    }

    public function testFileSnapshotStorageGetAllFiles()
    {
        $snapshotFile = $this->fixturesDir . '/all-files-test.tsv';
        $storage = new \FileSnapshotStorage($snapshotFile);

        $testData = [];
        for ($i = 1; $i <= 10; $i++) {
            $testData[] = ['path' => "/test/file{$i}.txt", 'ctime' => time(), 'size' => $i * 100];
        }

        $storage->update_from_scan($testData);

        // Get all files via generator
        $allFiles = iterator_to_array($storage->get_all_files());

        $this->assertCount(10, $allFiles, 'Should retrieve all 10 files');
    }

    public function testSqliteSnapshotStorageBasicOperations()
    {
        if (!class_exists('SQLite3')) {
            $this->markTestSkipped('SQLite3 extension not available');
        }

        $dbFile = $this->fixturesDir . '/test.db';
        $storage = new \SqliteSnapshotStorage($dbFile);

        $this->assertFileExists($dbFile, 'Database should be created');

        // Create test data
        $testData = [
            ['path' => '/test/file1.txt', 'ctime' => time(), 'size' => 100],
            ['path' => '/test/file2.txt', 'ctime' => time(), 'size' => 200],
        ];

        $deletions = $storage->update_from_scan($testData);

        $this->assertEmpty($deletions, 'First scan should have no deletions');

        // Retrieve file
        $file1 = $storage->get_file('/test/file1.txt');
        $this->assertNotNull($file1);
        $this->assertEquals(100, $file1['size']);
    }

    public function testSqliteSnapshotStorageDetectsDeletions()
    {
        if (!class_exists('SQLite3')) {
            $this->markTestSkipped('SQLite3 extension not available');
        }

        $dbFile = $this->fixturesDir . '/deletions.db';
        $storage = new \SqliteSnapshotStorage($dbFile);

        // First scan
        $testData1 = [
            ['path' => '/test/keep.txt', 'ctime' => time(), 'size' => 100],
            ['path' => '/test/delete.txt', 'ctime' => time(), 'size' => 200],
        ];

        $storage->update_from_scan($testData1);

        // Second scan - one file deleted
        $testData2 = [
            ['path' => '/test/keep.txt', 'ctime' => time(), 'size' => 100],
        ];

        $deletions = $storage->update_from_scan($testData2);

        $this->assertCount(1, $deletions, 'Should detect 1 deletion');
        $this->assertEquals('/test/delete.txt', $deletions[0]['path']);

        unlink($sortedFile1);
        unlink($sortedFile2);
    }

    public function testSqliteSnapshotStorageHandlesLargeDataset()
    {
        if (!class_exists('SQLite3')) {
            $this->markTestSkipped('SQLite3 extension not available');
        }

        $dbFile = $this->fixturesDir . '/large.db';
        $storage = new \SqliteSnapshotStorage($dbFile);

        // Create large dataset (1000 files)
        $testData = [];
        for ($i = 1; $i <= 1000; $i++) {
            $testData[] = ['path' => "/test/file{$i}.txt", 'ctime' => time(), 'size' => $i * 100];
        }

        $storage->update_from_scan($testData);

        // Verify we can retrieve files
        $file500 = $storage->get_file('/test/file500.txt');
        $this->assertNotNull($file500);
        $this->assertEquals(50000, $file500['size']);

        // Verify get_all_files works
        $count = 0;
        foreach ($storage->get_all_files() as $file) {
            $count++;
        }

        $this->assertEquals(1000, $count, 'Should iterate all 1000 files');
    }

    public function testSnapshotPersistenceAcrossInstances()
    {
        $snapshotFile = $this->fixturesDir . '/persistence.tsv';

        // First instance
        $storage1 = new \FileSnapshotStorage($snapshotFile);

        $testData = [
            ['path' => '/test/persistent.txt', 'ctime' => time(), 'size' => 12345],
        ];

        $storage1->update_from_scan($testData);

        // Second instance - should load existing snapshot
        $storage2 = new \FileSnapshotStorage($snapshotFile);

        $file = $storage2->get_file('/test/persistent.txt');
        $this->assertNotNull($file, 'Should load from existing snapshot');
        $this->assertEquals(12345, $file['size']);
    }
}
