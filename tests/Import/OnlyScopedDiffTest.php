<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * --only: the diff must reconcile ONLY within scope. A scoped remote index
 * lists in-scope paths only, so the delete drains in
 * diff_indexes_and_build_fetch_list() would otherwise wrongly delete every
 * out-of-scope local entry. Guard both drains so out-of-scope local files +
 * index entries survive, while in-scope orphans are still deleted (delta).
 */
class OnlyScopedDiffTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fs_root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/only-diff-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fs_root = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fs_root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

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

    private function indexLine(string $path, int $ctime, int $size, string $type = "file"): string
    {
        return json_encode([
            "path" => base64_encode($path),
            "ctime" => $ctime,
            "size" => $size,
            "type" => $type,
        ], JSON_UNESCAPED_SLASHES) . "\n";
    }

    /** Create a local file under fs_root for a (source-absolute) index path. */
    private function seedLocalFile(string $path, string $contents = "x"): string
    {
        $full = $this->fs_root . $path;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0755, true);
        }
        file_put_contents($full, $contents);
        return $full;
    }

    private function writeIndex(string $name, string $contents): void
    {
        file_put_contents($this->stateDir . '/' . $name, $contents);
    }

    private function readLocalIndexPaths(): array
    {
        $file = $this->stateDir . '/.import-index.jsonl';
        if (!file_exists($file)) {
            return [];
        }
        $paths = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $data = json_decode($line, true);
            if (isset($data["path"])) {
                $paths[] = base64_decode($data["path"]);
            }
        }
        return $paths;
    }

    /** Mirror FilesSyncStateTest: load state + preserve-local, then set scope. */
    private function prepareClient(array $scope): array
    {
        $defaults = [
            "command" => "files-pull",
            "status" => "in_progress",
            "stage" => "diff",
            "preflight" => ["data" => ["ok" => true], "http_code" => 200],
            "follow_symlinks" => false,
            "fs_root_nonempty_behavior" => "preserve-local",
        ];
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode($defaults, JSON_PRETTY_PRINT),
        );

        $client = new \ImportClient('http://fake.url', $this->stateDir, $this->fs_root);
        $r = new \ReflectionClass($client);
        $r->getProperty('state')->setValue($client, $r->getMethod('load_state')->invoke($client));
        $r->getProperty('is_tty')->setValue($client, false);
        $r->getProperty('fs_root_nonempty_behavior')->setValue($client, 'preserve-local');
        $r->getProperty('scope')->setValue($client, $scope);
        return [$client, $r];
    }

    public function testScopedDiffKeepsOutOfScopeAndDeletesInScopeOrphan(): void
    {
        // Local index (sorted): an out-of-scope entry, a matched in-scope file,
        // and an in-scope orphan absent from the (scoped) remote index. The
        // delete drains must reconcile only within scope, so the local index
        // accumulates as a union across scoped runs.
        $this->writeIndex('.import-index.jsonl',
            $this->indexLine('/wp-config.php', 1000, 10)               // out of scope
            . $this->indexLine('/wp-content/keep.txt', 1000, 10)       // matched
            . $this->indexLine('/wp-content/old/orphan.txt', 1000, 10) // in-scope orphan
        );
        $this->writeIndex('.import-remote-index.jsonl',
            $this->indexLine('/wp-content/keep.txt', 1000, 10)
        );

        $outOfScope = $this->seedLocalFile('/wp-config.php');
        $this->seedLocalFile('/wp-content/keep.txt');
        $orphan = $this->seedLocalFile('/wp-content/old/orphan.txt');

        [$client, $r] = $this->prepareClient(['/wp-content']);
        $r->getMethod('diff_indexes_and_build_fetch_list')->invoke($client);

        // Out-of-scope file AND its index entry survive.
        $this->assertFileExists($outOfScope);
        $this->assertContains('/wp-config.php', $this->readLocalIndexPaths());
        // In-scope orphan file AND its index entry are deleted.
        $this->assertFileDoesNotExist($orphan);
        $this->assertNotContains('/wp-content/old/orphan.txt', $this->readLocalIndexPaths());
    }
}
