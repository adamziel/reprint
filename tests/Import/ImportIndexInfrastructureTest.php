<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Index\IndexFileSorter;
use Reprint\Importer\Index\IndexLineParser;
use Reprint\Importer\Index\IndexPathPrefixMatcher;
use Reprint\Importer\Index\IndexStore;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class ImportIndexInfrastructureTest extends TestCase
{
    private string $temp_dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir() . '/import-index-infra-' . uniqid('', true);
        mkdir($this->temp_dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->remove_dir($this->temp_dir);
        parent::tearDown();
    }

    public function testIndexStoreMergesPendingUpdates(): void
    {
        $index = $this->temp_dir . '/index.jsonl';
        $updates = $this->temp_dir . '/updates.jsonl';
        file_put_contents($index, implode('', [
            $this->index_line('/a.txt', 1, 10),
            $this->index_line('/b.txt', 2, 20),
            $this->index_line('/d.txt', 4, 40),
        ]));

        $store = new IndexStore($index, $updates);
        $store->delete('/b.txt');
        $store->upsert('/c.txt', 3, 30, 'file');
        $store->upsert('/d.txt', 5, 50, 'file');
        $store->finalize_updates();

        $this->assertSame([
            ['/a.txt', 1, 10, 'file'],
            ['/c.txt', 3, 30, 'file'],
            ['/d.txt', 5, 50, 'file'],
        ], $this->read_index_summary($index));
        $this->assertFileDoesNotExist($updates);
    }

    public function testIndexStoreRecoversPendingUpdatesFromPreviousRun(): void
    {
        $index = $this->temp_dir . '/index.jsonl';
        $updates = $this->temp_dir . '/updates.jsonl';
        file_put_contents($index, $this->index_line('/a.txt', 1, 10));

        $store = new IndexStore($index, $updates);
        $store->upsert('/b.txt', 2, 20, 'file');
        $store = null;

        $recovered = new IndexStore($index, $updates);
        $recovered->recover();

        $this->assertSame([
            ['/a.txt', 1, 10, 'file'],
            ['/b.txt', 2, 20, 'file'],
        ], $this->read_index_summary($index));
        $this->assertFileDoesNotExist($updates);
    }

    public function testIndexFileSorterSortsAndDeduplicatesByPath(): void
    {
        $index = $this->temp_dir . '/remote-index.jsonl';
        file_put_contents($index, implode('', [
            $this->index_line('/b.txt', 2, 20),
            $this->index_line('/a.txt', 1, 10),
            $this->index_line('/b.txt', 3, 30),
        ]));

        $sorter = new IndexFileSorter();
        $sorter->sort($index);

        $entries = $this->read_index_summary($index);
        $this->assertSame('/a.txt', $entries[0][0]);
        $this->assertSame('/b.txt', $entries[1][0]);
        $this->assertCount(2, $entries);
    }

    public function testIndexPathPrefixMatcherFindsExactAndDescendantPaths(): void
    {
        $index = $this->temp_dir . '/remote-index.jsonl';
        file_put_contents($index, implode('', [
            $this->index_line('/srv/site/wp-content/theme/style.css', 1, 10),
            $this->index_line('/srv/site/wp-config.php', 2, 20),
            "not-json\n",
        ]));

        $matcher = new IndexPathPrefixMatcher($index);

        $this->assertTrue($matcher->contains('/srv/site/wp-config.php'));
        $this->assertTrue($matcher->contains('/srv/site/wp-content'));
        $this->assertTrue($matcher->contains('/srv/site/../site/wp-content/'));
        $this->assertFalse($matcher->contains('/srv/site/wp'));
        $this->assertFalse($matcher->contains('/srv/site/wp-content-other'));
    }

    public function testIndexPathPrefixMatcherReturnsFalseWhenFileMissing(): void
    {
        $matcher = new IndexPathPrefixMatcher($this->temp_dir . '/missing.jsonl');

        $this->assertFalse($matcher->contains('/srv/site'));
    }

    private function index_line(
        string $path,
        int $ctime,
        int $size,
        string $type = 'file'
    ): string {
        return json_encode([
            'path' => base64_encode($path),
            'ctime' => $ctime,
            'size' => $size,
            'type' => $type,
        ], JSON_UNESCAPED_SLASHES) . "\n";
    }

    private function read_index_summary(string $path): array
    {
        $entries = [];
        $handle = fopen($path, 'r');
        while (($line = fgets($handle)) !== false) {
            $entry = IndexLineParser::parse($line);
            if ($entry === null) {
                continue;
            }
            $entries[] = [
                $entry['path'],
                $entry['ctime'],
                $entry['size'],
                $entry['type'],
            ];
        }
        fclose($handle);
        return $entries;
    }

    private function remove_dir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->remove_dir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
