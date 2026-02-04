<?php

namespace FileSyncProducerTests;

use DirectoryListing;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DirectoryListing class.
 */
class DirectoryListingTest extends TestCase
{
    private ?string $tempDir = null;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/dir_listing_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->tempDir && is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testEmptyDirectory(): void
    {
        $listing = DirectoryListing::scan($this->tempDir);
        $this->assertNotNull($listing);
        $this->assertTrue($listing->isEmpty());
        $this->assertEquals(0, $listing->count());
    }

    public function testSingleFile(): void
    {
        touch("{$this->tempDir}/file.txt");

        $listing = DirectoryListing::scan($this->tempDir);
        $listing->sort();

        $this->assertEquals(1, $listing->count());
        $this->assertEquals('file.txt', $listing->next());
        $this->assertNull($listing->next());
    }

    public function testMultipleFilesSorted(): void
    {
        touch("{$this->tempDir}/zebra.txt");
        touch("{$this->tempDir}/apple.txt");
        touch("{$this->tempDir}/mango.txt");

        $listing = DirectoryListing::scan($this->tempDir);
        $listing->sort();

        $this->assertEquals(3, $listing->count());
        $this->assertEquals('apple.txt', $listing->next());
        $this->assertEquals('mango.txt', $listing->next());
        $this->assertEquals('zebra.txt', $listing->next());
        $this->assertNull($listing->next());
    }

    public function testSeekAfter(): void
    {
        touch("{$this->tempDir}/aaa.txt");
        touch("{$this->tempDir}/bbb.txt");
        touch("{$this->tempDir}/ccc.txt");
        touch("{$this->tempDir}/ddd.txt");

        $listing = DirectoryListing::scan($this->tempDir);
        $listing->sort();

        // Seek to after 'bbb.txt'
        $pos = $listing->seekAfter('bbb.txt');
        $this->assertEquals(2, $pos);
        $this->assertEquals('ccc.txt', $listing->next());
        $this->assertEquals('ddd.txt', $listing->next());
        $this->assertNull($listing->next());
    }

    public function testSeekAfterNonExistent(): void
    {
        touch("{$this->tempDir}/aaa.txt");
        touch("{$this->tempDir}/ccc.txt");
        touch("{$this->tempDir}/eee.txt");

        $listing = DirectoryListing::scan($this->tempDir);
        $listing->sort();

        // Seek to after 'bbb.txt' which doesn't exist
        // Should position at 'ccc.txt' (first entry > 'bbb.txt')
        $pos = $listing->seekAfter('bbb.txt');
        $this->assertEquals(1, $pos);
        $this->assertEquals('ccc.txt', $listing->next());
    }

    public function testSeekAfterLast(): void
    {
        touch("{$this->tempDir}/aaa.txt");
        touch("{$this->tempDir}/bbb.txt");

        $listing = DirectoryListing::scan($this->tempDir);
        $listing->sort();

        // Seek to after 'zzz.txt' which is after all entries
        $pos = $listing->seekAfter('zzz.txt');
        $this->assertEquals(2, $pos);
        $this->assertNull($listing->next());
    }

    public function testRewind(): void
    {
        touch("{$this->tempDir}/aaa.txt");
        touch("{$this->tempDir}/bbb.txt");

        $listing = DirectoryListing::scan($this->tempDir);
        $listing->sort();

        $this->assertEquals('aaa.txt', $listing->next());
        $this->assertEquals('bbb.txt', $listing->next());

        $listing->rewind();

        $this->assertEquals('aaa.txt', $listing->next());
    }

    public function testGetByIndex(): void
    {
        touch("{$this->tempDir}/aaa.txt");
        touch("{$this->tempDir}/bbb.txt");
        touch("{$this->tempDir}/ccc.txt");

        $listing = DirectoryListing::scan($this->tempDir);
        $listing->sort();

        $this->assertEquals('aaa.txt', $listing->get(0));
        $this->assertEquals('bbb.txt', $listing->get(1));
        $this->assertEquals('ccc.txt', $listing->get(2));
        $this->assertNull($listing->get(3));
        $this->assertNull($listing->get(-1));
    }

    public function testToArray(): void
    {
        touch("{$this->tempDir}/zebra.txt");
        touch("{$this->tempDir}/apple.txt");

        $listing = DirectoryListing::scan($this->tempDir);
        $listing->sort();

        $array = $listing->toArray();
        $this->assertEquals(['apple.txt', 'zebra.txt'], $array);
    }

    public function testFromArray(): void
    {
        $entries = ['zebra.txt', 'apple.txt', 'mango.txt'];
        $listing = DirectoryListing::fromArray($entries);
        $listing->sort();

        $this->assertEquals(3, $listing->count());
        $this->assertEquals('apple.txt', $listing->next());
        $this->assertEquals('mango.txt', $listing->next());
        $this->assertEquals('zebra.txt', $listing->next());
    }

    public function testFromArrayFiltersDotEntries(): void
    {
        $entries = ['.', '..', 'file.txt'];
        $listing = DirectoryListing::fromArray($entries);

        $this->assertEquals(1, $listing->count());
    }

    public function testLargeDirectory(): void
    {
        // Create 1000 files
        for ($i = 0; $i < 1000; $i++) {
            touch(sprintf("{$this->tempDir}/file_%04d.txt", $i));
        }

        $listing = DirectoryListing::scan($this->tempDir);
        $listing->sort();

        $this->assertEquals(1000, $listing->count());

        // First entry should be file_0000.txt
        $this->assertEquals('file_0000.txt', $listing->next());

        // Seek to middle
        $listing->seekAfter('file_0500.txt');
        $this->assertEquals('file_0501.txt', $listing->next());

        // Last entries
        $listing->seekAfter('file_0998.txt');
        $this->assertEquals('file_0999.txt', $listing->next());
        $this->assertNull($listing->next());
    }

    public function testInvalidDirectory(): void
    {
        $listing = DirectoryListing::scan('/nonexistent/path/that/does/not/exist');
        $this->assertNull($listing);
    }

    public function testFilenamesWithSpecialCharacters(): void
    {
        touch("{$this->tempDir}/file with spaces.txt");
        touch("{$this->tempDir}/file-with-dashes.txt");
        touch("{$this->tempDir}/file_with_underscores.txt");

        $listing = DirectoryListing::scan($this->tempDir);
        $listing->sort();

        $array = $listing->toArray();
        $this->assertCount(3, $array);
        $this->assertContains('file with spaces.txt', $array);
    }

    public function testSmallMemoryLimit(): void
    {
        // Create enough files to exceed 1KB memory limit
        for ($i = 0; $i < 100; $i++) {
            touch(sprintf("{$this->tempDir}/file_%04d.txt", $i));
        }

        // Use very small memory limit to force spill to disk
        $listing = DirectoryListing::scan($this->tempDir, 1024);
        $listing->sort();

        $this->assertEquals(100, $listing->count());

        // Verify all entries are accessible
        $count = 0;
        while ($listing->next() !== null) {
            $count++;
        }
        $this->assertEquals(100, $count);
    }
}
