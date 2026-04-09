<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FileIndexDedupTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/file-index-dedup-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testFileIndexSkipsExtraRootsThatAreParentsOfThePrimaryRoot(): void
    {
        $docroot = $this->tempDir . '/srv/htdocs';
        mkdir($docroot, 0755, true);
        file_put_contents($docroot . '/site.txt', 'site');
        file_put_contents($this->tempDir . '/parent-only.txt', 'parent');
        $docrootReal = realpath($docroot);
        $tempDirReal = realpath($this->tempDir);

        $this->assertNotFalse($docrootReal);
        $this->assertNotFalse($tempDirReal);

        $paths = $this->entryPaths(
            $this->runFileIndexEntries(
            [$docroot, $this->tempDir],
            $docroot,
            ),
        );

        $this->assertContains($docrootReal . '/site.txt', $paths);
        $this->assertNotContains(
            $tempDirReal . '/parent-only.txt',
            $paths,
            'The index should not expose files from a parent root that only re-enters the primary root',
        );
    }

    public function testFileIndexSkipsAtomicOverlapRootsAlreadyReachableInsideDocumentRoot(): void
    {
        $docroot = $this->tempDir . '/srv/htdocs';
        $wordpressRoot = $this->tempDir . '/wordpress';

        mkdir($docroot, 0755, true);
        mkdir($wordpressRoot . '/wp-admin', 0755, true);
        file_put_contents($docroot . '/index.php', 'index');
        file_put_contents($wordpressRoot . '/wp-admin/admin.php', 'admin');
        symlink($wordpressRoot, $docroot . '/wordpress');

        $docrootReal = realpath($docroot);
        $wordpressReal = realpath($wordpressRoot);

        $this->assertNotFalse($docrootReal);
        $this->assertNotFalse($wordpressReal);

        $entries = $this->runFileIndexEntries(
            [$docroot, $wordpressRoot],
            $docroot,
            ['document_root' => $docroot],
        );

        $this->assertTrue(
            $this->hasEntry($entries, $docrootReal . '/wordpress', 'link'),
            'The docroot symlink entry should still be emitted',
        );
        $this->assertFalse(
            $this->hasTarget($entries, $wordpressReal),
            'Duplicate Atomic overlap roots should not be advertised as follow-up targets',
        );
        $this->assertFalse(
            $this->hasPathPrefix($entries, $wordpressReal . '/'),
            'Files under duplicate Atomic overlap roots should not be indexed separately',
        );
    }

    public function testFileIndexSkipsAtomicRecursiveReentryTargetsAndFollowUpRoots(): void
    {
        $docroot = $this->tempDir . '/srv/htdocs';
        $srvRoot = $this->tempDir . '/srv';

        mkdir($docroot, 0755, true);
        file_put_contents($docroot . '/wp-config.php', 'config');
        symlink($srvRoot, $docroot . '/srv');

        $docrootReal = realpath($docroot);
        $srvReal = realpath($srvRoot);

        $this->assertNotFalse($docrootReal);
        $this->assertNotFalse($srvReal);

        $entries = $this->runFileIndexEntries(
            [$docroot],
            $docroot,
            ['document_root' => $docroot],
        );

        $this->assertTrue(
            $this->hasEntry($entries, $docrootReal . '/srv', 'link'),
            'The docroot re-entry symlink should still be emitted',
        );
        $this->assertFalse(
            $this->hasTarget($entries, $srvReal),
            'Recursive Atomic re-entry targets should not be advertised for follow-up indexing',
        );

        $followUpEntries = $this->runFileIndexEntries(
            [$docroot],
            $srvRoot,
            ['document_root' => $docroot],
        );

        $this->assertSame(
            [],
            $followUpEntries,
            'Follow-up indexing of a recursive Atomic re-entry root should return no entries',
        );
    }

    public function testFileIndexPreservesExternalTargetsOnNonAtomicLayouts(): void
    {
        $docroot = $this->tempDir . '/site';
        $contentRoot = $this->tempDir . '/external-content';

        mkdir($docroot, 0755, true);
        mkdir($contentRoot . '/plugins', 0755, true);
        file_put_contents($contentRoot . '/plugins/plugin.php', 'plugin');
        symlink($contentRoot, $docroot . '/wp-content');

        $contentReal = realpath($contentRoot);
        $this->assertNotFalse($contentReal);

        $entries = $this->runFileIndexEntries(
            [$docroot, $contentRoot],
            $docroot,
            ['document_root' => $docroot],
        );

        $this->assertTrue(
            $this->hasTarget($entries, $contentReal),
            'Non-Atomic external targets should still be advertised for follow-up indexing',
        );
        $this->assertTrue(
            $this->hasPathPrefix($entries, $contentReal . '/'),
            'Non-Atomic external roots should still be indexed when explicitly requested',
        );
    }

    /**
     * @param string[] $directories
     * @param array<string, mixed> $extraConfig
     * @return array<int, array<string, mixed>>
     */
    private function runFileIndexEntries(
        array $directories,
        string $listDir,
        array $extraConfig = [],
    ): array
    {
        $configPath = $this->tempDir . '/config.json';
        $config = array_merge(
            [
                'directory' => $directories,
                'list_dir' => $listDir,
                'follow_symlinks' => true,
                'batch_size' => 1000,
            ],
            $extraConfig,
        );
        file_put_contents(
            $configPath,
            json_encode($config, JSON_THROW_ON_ERROR),
        );

        $scriptPath = $this->tempDir . '/run-file-index.php';
        file_put_contents(
            $scriptPath,
            sprintf(
                <<<'PHP'
<?php
declare(strict_types=1);
define('SECRET_KEY', 'test-secret');
$_GET['SECRET_KEY'] = 'test-secret';
require_once %s;
$config = json_decode(file_get_contents(%s), true, 512, JSON_THROW_ON_ERROR);
$budget = new ResourceBudget(microtime(true), 10, 128 * 1024 * 1024, 0.9);
endpoint_file_index($config, $budget);
PHP,
                var_export(dirname(__DIR__) . '/packages/streaming-exporter/src/export.php', true),
                var_export($configPath, true),
            ),
        );

        $command = sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($scriptPath));
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes);
        $this->assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, "file_index should exit cleanly.\nstderr: {$stderr}");

        $decoded = gzdecode($stdout);
        $this->assertNotFalse($decoded, 'Expected gzip-compressed multipart response');

        $boundaryEnd = strpos($decoded, "\r\n");
        $this->assertNotFalse($boundaryEnd, 'Expected multipart boundary in response');
        $boundary = substr($decoded, 2, $boundaryEnd - 2);
        $parts = explode('--' . $boundary, $decoded);
        $entries = [];

        foreach ($parts as $part) {
            if (!str_contains($part, 'X-Chunk-Type: index_batch')) {
                continue;
            }
            $sections = explode("\r\n\r\n", $part, 2);
            if (count($sections) !== 2) {
                continue;
            }
            $body = trim($sections[1]);
            if ($body === '') {
                continue;
            }
            $batch = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            foreach ($batch as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (!isset($entry['path']) || !is_string($entry['path'])) {
                    continue;
                }
                $decodedPath = base64_decode($entry['path'], true);
                $this->assertNotFalse($decodedPath, 'Expected base64-encoded path');
                $decodedEntry = [
                    'path' => $decodedPath,
                    'type' => $entry['type'] ?? null,
                ];
                if (isset($entry['target']) && is_string($entry['target'])) {
                    $decodedTarget = base64_decode($entry['target'], true);
                    $this->assertNotFalse($decodedTarget, 'Expected base64-encoded target');
                    $decodedEntry['target'] = $decodedTarget;
                }
                if (!empty($entry['intermediate'])) {
                    $decodedEntry['intermediate'] = true;
                }
                $entries[] = $decodedEntry;
            }
        }

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return string[]
     */
    private function entryPaths(array $entries): array
    {
        return array_map(
            static fn(array $entry): string => (string) ($entry['path'] ?? ''),
            $entries,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function hasEntry(array $entries, string $path, string $type): bool
    {
        foreach ($entries as $entry) {
            if (($entry['path'] ?? null) === $path && ($entry['type'] ?? null) === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function hasTarget(array $entries, string $target): bool
    {
        foreach ($entries as $entry) {
            if (($entry['target'] ?? null) === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function hasPathPrefix(array $entries, string $prefix): bool
    {
        foreach ($entries as $entry) {
            $path = $entry['path'] ?? null;
            if (is_string($path) && str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
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
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveDelete($path);
                continue;
            }
            unlink($path);
        }
        rmdir($dir);
    }
}
