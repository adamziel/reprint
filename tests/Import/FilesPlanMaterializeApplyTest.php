<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

class FilesPlanMaterializeApplyTest extends TestCase
{
    private string $tempDir;
    private string $stateDir;
    private string $fsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/reprint-plan-materialize-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/files';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
        $this->writeState();
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->tempDir);
        parent::tearDown();
    }

    public function testFilesPlanClassifiesPolicyAndWritability(): void
    {
        $targetRoot = $this->tempDir . '/target';
        mkdir($targetRoot . '/wp-content/plugins/foo', 0755, true);
        $this->writeIndex('.import-remote-index.jsonl', [
            ['/var/www/html/index.php', 2, 1, 'file'],
            ['/var/www/html/robots.txt', 2, 1, 'file'],
            ['/var/www/html/wp-admin/admin.php', 2, 5, 'file'],
            ['/var/www/html/wp-content/cache/item.txt', 2, 1, 'file'],
            ['/var/www/html/wp-content/mu-plugins/mu.php', 2, 1, 'file'],
            ['/var/www/html/wp-content/plugins/foo', 2, 0, 'dir'],
            ['/var/www/html/wp-content/plugins/foo/a.php', 2, 10, 'file'],
        ]);
        $this->writeIndex('.import-index.jsonl', [
            ['/var/www/html/wp-content/plugins/foo/a.php', 1, 8, 'file'],
            ['/var/www/html/wp-content/plugins/foo/old.php', 1, 1, 'file'],
        ]);
        $selected = $this->tempDir . '/selected.jsonl';

        $plan = $this->runJsonCommand([
            'command' => 'files-plan',
            'target_root' => $targetRoot,
            'selected_files' => $selected,
        ]);

        $this->assertSame(8, $plan['summary']['total']);
        $this->assertSame(1, $plan['summary']['blocked']);
        $byPath = [];
        foreach ($plan['files'] as $file) {
            $byPath[$file['relative_path']] = $file;
        }

        $this->assertSame('loose-php', $byPath['index.php']['classification']['area']);
        $this->assertSame('warning', $byPath['index.php']['policy']['status']);
        $this->assertSame('document-root', $byPath['robots.txt']['classification']['area']);
        $this->assertFalse($byPath['robots.txt']['selected']);
        $this->assertSame('not-applied-by-staged-files', $byPath['robots.txt']['selection_reason']);
        $this->assertSame('wordpress-core', $byPath['wp-admin/admin.php']['classification']['area']);
        $this->assertFalse($byPath['wp-admin/admin.php']['selected']);
        $this->assertSame('wordpress-core', $byPath['wp-admin/admin.php']['policy']['reason']);
        $this->assertSame('wp-content-other', $byPath['wp-content/cache/item.txt']['classification']['area']);
        $this->assertFalse($byPath['wp-content/cache/item.txt']['selected']);
        $this->assertSame('not-applied-by-staged-files', $byPath['wp-content/cache/item.txt']['selection_reason']);
        $this->assertSame('mu-plugin', $byPath['wp-content/mu-plugins/mu.php']['classification']['area']);
        $this->assertFalse($byPath['wp-content/mu-plugins/mu.php']['selected']);
        $this->assertSame('not-applied-by-staged-files', $byPath['wp-content/mu-plugins/mu.php']['selection_reason']);
        $this->assertSame('plugin', $byPath['wp-content/plugins/foo']['classification']['area']);
        $this->assertFalse($byPath['wp-content/plugins/foo']['selected']);
        $this->assertSame('directory-not-applied-by-staged-files', $byPath['wp-content/plugins/foo']['selection_reason']);
        $this->assertSame('modified', $byPath['wp-content/plugins/foo/a.php']['status']);
        $this->assertSame('plugin', $byPath['wp-content/plugins/foo/a.php']['classification']['area']);
        $this->assertSame('deleted', $byPath['wp-content/plugins/foo/old.php']['status']);
        $this->assertFalse($byPath['wp-content/plugins/foo/old.php']['selected']);
        $this->assertSame('delete-not-applied-by-staged-files', $byPath['wp-content/plugins/foo/old.php']['selection_reason']);
        $selectedLines = file($selected, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(2, $selectedLines);
        $this->assertSame('index.php', json_decode($selectedLines[0], true)['relative_path']);
        $this->assertSame('wp-content/plugins/foo/a.php', json_decode($selectedLines[1], true)['relative_path']);
    }

    public function testFilesPlanChecksPluginComponentSwapWritability(): void
    {
        $targetRoot = $this->tempDir . '/target-non-writable-component';
        mkdir($targetRoot . '/wp-content/plugins/foo', 0755, true);
        file_put_contents($targetRoot . '/wp-content/plugins/foo/a.php', 'old-plugin');
        $this->writeIndex('.import-remote-index.jsonl', [
            ['/var/www/html/wp-content/plugins/foo/a.php', 2, 10, 'file'],
        ]);
        $this->writeIndex('.import-index.jsonl', [
            ['/var/www/html/wp-content/plugins/foo/a.php', 1, 8, 'file'],
        ]);

        chmod($targetRoot . '/wp-content/plugins', 0555);
        try {
            if (is_writable($targetRoot . '/wp-content/plugins')) {
                $this->markTestSkipped('Current user can write to chmod 0555 directories.');
            }
            $selected = $this->tempDir . '/non-writable-component-selected.jsonl';
            $plan = $this->runJsonCommand([
                'command' => 'files-plan',
                'target_root' => $targetRoot,
                'selected_files' => $selected,
            ]);
        } finally {
            chmod($targetRoot . '/wp-content/plugins', 0755);
        }

        $entry = $plan['files'][0];
        $this->assertSame('wp-content/plugins/foo/a.php', $entry['relative_path']);
        $this->assertFalse($entry['selected']);
        $this->assertSame('non-writable', $entry['selection_reason']);
        $this->assertSame('parent-not-writable', $entry['writability']['reason']);
        $this->assertSame($targetRoot . '/wp-content/plugins', $entry['writability']['path']);
        $this->assertSame('', file_get_contents($selected));
    }

    public function testFilesPlanCachesPluginComponentWritability(): void
    {
        $targetRoot = $this->tempDir . '/target-cache';
        mkdir($targetRoot . '/wp-content/plugins/foo', 0755, true);
        file_put_contents($targetRoot . '/wp-content/plugins/foo/a.php', 'old-plugin');
        $client = new \ImportClient('http://example.test/?reprint-api', $this->stateDir, $this->fsRoot);
        $method = new \ReflectionMethod($client, 'inspect_files_plan_apply_writability');
        $method->setAccessible(true);
        $cache = [];

        $first = $method->invokeArgs($client, [
            $targetRoot,
            'wp-content/plugins/foo/a.php',
            ['area' => 'plugin'],
            'update',
            &$cache,
        ]);
        $this->removeRecursive($targetRoot . '/wp-content/plugins');
        $second = $method->invokeArgs($client, [
            $targetRoot,
            'wp-content/plugins/foo/b.php',
            ['area' => 'plugin'],
            'update',
            &$cache,
        ]);

        $this->assertSame($first, $second);
    }

    public function testMaterializeDocrootCopiesRealFilesFromFsRootLayout(): void
    {
        mkdir($this->fsRoot . '/var/www/html/wp-admin', 0755, true);
        mkdir($this->fsRoot . '/var/www/html/wp-content/plugins/foo', 0755, true);
        file_put_contents($this->fsRoot . '/var/www/html/index.php', '<?php // index');
        file_put_contents($this->fsRoot . '/var/www/html/wp-admin/admin.php', 'admin');
        file_put_contents($this->fsRoot . '/var/www/html/wp-content/plugins/foo/a.php', 'plugin');
        file_put_contents($this->fsRoot . '/var/www/html/wp-content/plugins/foo/unselected.php', 'unselected');
        symlink('a.php', $this->fsRoot . '/var/www/html/wp-content/plugins/foo/linked.php');
        $selected = $this->tempDir . '/materialize-selected.jsonl';
        $this->writeSelectedManifest($selected, [
            ['/var/www/html/index.php', 'index.php', 'update', 'file'],
            ['/var/www/html/wp-content/plugins/foo', 'wp-content/plugins/foo', 'update', 'dir'],
            ['/var/www/html/wp-content/plugins/foo/a.php', 'wp-content/plugins/foo/a.php', 'update', 'file'],
            ['/var/www/html/wp-content/plugins/foo/linked.php', 'wp-content/plugins/foo/linked.php', 'update', 'file'],
        ]);

        $materialized = $this->tempDir . '/site';
        $result = $this->runJsonCommand([
            'command' => 'materialize-docroot',
            'materialize_to' => $materialized,
            'force' => true,
            'symlink_mode' => 'copy-target',
            'selected_files' => $selected,
        ]);

        $this->assertSame('complete', $result['status']);
        $this->assertFileExists($materialized . '/index.php');
        $this->assertFileDoesNotExist($materialized . '/wp-admin/admin.php');
        $this->assertFileExists($materialized . '/wp-content/plugins/foo/a.php');
        $this->assertFalse(is_link($materialized . '/wp-content/plugins/foo/a.php'));
        $this->assertFalse(is_link($materialized . '/wp-content/plugins/foo/linked.php'));
        $this->assertSame('plugin', file_get_contents($materialized . '/wp-content/plugins/foo/a.php'));
        $this->assertSame('plugin', file_get_contents($materialized . '/wp-content/plugins/foo/linked.php'));
        $this->assertFileDoesNotExist($materialized . '/wp-content/plugins/foo/unselected.php');
    }

    public function testApplyStagedFilesSwapsPluginThemeAndLoosePhpWithBoundedJournal(): void
    {
        $staged = $this->tempDir . '/staged';
        $target = $this->tempDir . '/target';
        mkdir($staged . '/wp-content/plugins/foo', 0755, true);
        mkdir($staged . '/wp-content/themes/bar', 0755, true);
        mkdir($staged . '/wp-content/uploads/2026/06', 0755, true);
        mkdir($target . '/wp-content/plugins/foo', 0755, true);
        mkdir($target . '/wp-content/themes/bar', 0755, true);
        mkdir($target . '/wp-content/uploads/2026/06', 0755, true);
        file_put_contents($staged . '/wp-content/plugins/foo/a.php', 'new-plugin');
        file_put_contents($staged . '/wp-content/plugins/foo/unselected.php', 'new-unselected-plugin-file');
        file_put_contents($staged . '/wp-content/themes/bar/style.css', 'new-theme');
        file_put_contents($staged . '/wp-content/uploads/2026/06/image.jpg', 'new-image');
        file_put_contents($staged . '/index.php', '<?php // new');
        file_put_contents($target . '/wp-content/plugins/foo/a.php', 'old-plugin');
        file_put_contents($target . '/wp-content/plugins/foo/live-only.php', 'keep-live-plugin-file');
        file_put_contents($target . '/wp-content/plugins/foo/unselected.php', 'old-unselected-plugin-file');
        file_put_contents($target . '/wp-content/themes/bar/style.css', 'old-theme');
        file_put_contents($target . '/wp-content/uploads/2026/06/old.jpg', 'old-image');
        file_put_contents($target . '/index.php', '<?php // old');
        $selected = $this->tempDir . '/apply-selected.jsonl';
        $this->writeSelectedManifest($selected, [
            ['/var/www/html/wp-content/plugins/foo/a.php', 'wp-content/plugins/foo/a.php', 'update', 'file'],
            ['/var/www/html/wp-content/themes/bar/style.css', 'wp-content/themes/bar/style.css', 'update', 'file'],
            ['/var/www/html/wp-content/uploads/2026/06/image.jpg', 'wp-content/uploads/2026/06/image.jpg', 'update', 'file'],
            ['/var/www/html/index.php', 'index.php', 'update', 'file'],
        ]);

        $journal = $this->stateDir . '/apply.json';
        $result = $this->runJsonCommand([
            'command' => 'apply-staged-files',
            'staged_root' => $staged,
            'target_root' => $target,
            'apply_journal' => $journal,
            'maintenance_file' => $target . '/.maintenance',
            'selected_files' => $selected,
        ]);

        $this->assertSame('complete', $result['status']);
        $this->assertSame(4, $result['operations']);
        $this->assertSame('new-plugin', file_get_contents($target . '/wp-content/plugins/foo/a.php'));
        $this->assertSame('keep-live-plugin-file', file_get_contents($target . '/wp-content/plugins/foo/live-only.php'));
        $this->assertSame('old-unselected-plugin-file', file_get_contents($target . '/wp-content/plugins/foo/unselected.php'));
        $this->assertSame('new-theme', file_get_contents($target . '/wp-content/themes/bar/style.css'));
        $this->assertSame('new-image', file_get_contents($target . '/wp-content/uploads/2026/06/image.jpg'));
        $this->assertSame('old-image', file_get_contents($target . '/wp-content/uploads/2026/06/old.jpg'));
        $this->assertSame('<?php // new', file_get_contents($target . '/index.php'));
        $this->assertFileDoesNotExist($journal);
        $this->assertFileDoesNotExist($target . '/.maintenance');
    }

    public function testApplyStagedFilesResumesInterruptedDirectorySwap(): void
    {
        $staged = $this->tempDir . '/staged-resume';
        $target = $this->tempDir . '/target-resume';
        mkdir($staged . '/wp-content/plugins/foo', 0755, true);
        mkdir($target . '/wp-content/plugins/foo.new', 0755, true);
        mkdir($target . '/wp-content/plugins/foo.bak', 0755, true);
        file_put_contents($staged . '/wp-content/plugins/foo/a.php', 'new-plugin');
        file_put_contents($target . '/wp-content/plugins/foo.new/a.php', 'new-plugin');
        file_put_contents($target . '/wp-content/plugins/foo.bak/a.php', 'old-plugin');
        $journal = $this->stateDir . '/resume-apply.json';
        file_put_contents($journal, json_encode([
            'phase' => 'swapping',
            'operation' => [
                'type' => 'swap_directory',
                'source' => $staged . '/wp-content/plugins/foo',
                'target' => $target . '/wp-content/plugins/foo',
                'prepared' => $target . '/wp-content/plugins/foo.new',
                'backup' => $target . '/wp-content/plugins/foo.bak',
            ],
        ]));

        $result = $this->runJsonCommand([
            'command' => 'apply-staged-files',
            'staged_root' => $staged,
            'target_root' => $target,
            'apply_journal' => $journal,
            'maintenance_file' => $target . '/.maintenance',
        ]);

        $this->assertSame('complete', $result['status']);
        $this->assertTrue($result['journal_recovered']);
        $this->assertSame('new-plugin', file_get_contents($target . '/wp-content/plugins/foo/a.php'));
        $this->assertFileDoesNotExist($target . '/wp-content/plugins/foo.new');
        $this->assertFileDoesNotExist($target . '/wp-content/plugins/foo.bak');
        $this->assertFileDoesNotExist($target . '/.maintenance');
    }

    public function testApplyStagedFilesRejectsResumeWhenSelectionNoLongerContainsJournaledOperation(): void
    {
        $staged = $this->tempDir . '/staged-resume-changed';
        $target = $this->tempDir . '/target-resume-changed';
        mkdir($staged . '/wp-content/plugins/foo', 0755, true);
        mkdir($target . '/wp-content/plugins/foo.new', 0755, true);
        mkdir($target . '/wp-content/plugins/foo.bak', 0755, true);
        file_put_contents($staged . '/wp-content/plugins/foo/a.php', 'new-plugin');
        file_put_contents($staged . '/index.php', '<?php // changed selection');
        file_put_contents($target . '/wp-content/plugins/foo.new/a.php', 'new-plugin');
        file_put_contents($target . '/wp-content/plugins/foo.bak/a.php', 'old-plugin');
        $journal = $this->stateDir . '/resume-changed-apply.json';
        file_put_contents($journal, json_encode([
            'phase' => 'swapping',
            'operation' => [
                'type' => 'swap_directory',
                'source' => $staged . '/wp-content/plugins/foo',
                'target' => $target . '/wp-content/plugins/foo',
                'prepared' => $target . '/wp-content/plugins/foo.new',
                'backup' => $target . '/wp-content/plugins/foo.bak',
            ],
        ]));
        $selected = $this->tempDir . '/resume-changed-selected.jsonl';
        $this->writeSelectedManifest($selected, [
            ['/var/www/html/index.php', 'index.php', 'update', 'file'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('journaled operation is not selected');
        $this->runJsonCommand([
            'command' => 'apply-staged-files',
            'staged_root' => $staged,
            'target_root' => $target,
            'apply_journal' => $journal,
            'selected_files' => $selected,
        ]);
    }

    public function testApplyStagedFilesRejectsSelectedDirectoriesAndUnsupportedPaths(): void
    {
        $staged = $this->tempDir . '/staged-reject';
        $target = $this->tempDir . '/target-reject';
        mkdir($staged . '/wp-content/plugins/foo', 0755, true);
        mkdir($target . '/wp-content/plugins/foo', 0755, true);
        file_put_contents($staged . '/wp-content/plugins/foo/unselected.php', 'new-unselected');
        file_put_contents($target . '/wp-content/plugins/foo/unselected.php', 'old-unselected');
        $selectedDirectory = $this->tempDir . '/apply-selected-dir.jsonl';
        $this->writeSelectedManifest($selectedDirectory, [
            ['/var/www/html/wp-content/plugins/foo', 'wp-content/plugins/foo', 'update', 'dir'],
        ]);

        try {
            $this->runJsonCommand([
                'command' => 'apply-staged-files',
                'staged_root' => $staged,
                'target_root' => $target,
                'selected_files' => $selectedDirectory,
            ]);
            $this->fail('Expected selected directory rejection.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('directory entries', $e->getMessage());
        }
        $this->assertSame('old-unselected', file_get_contents($target . '/wp-content/plugins/foo/unselected.php'));

        $selectedUnsupported = $this->tempDir . '/apply-selected-unsupported.jsonl';
        $this->writeSelectedManifest($selectedUnsupported, [
            ['/var/www/html/robots.txt', 'robots.txt', 'update', 'file'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot apply yet');
        $this->runJsonCommand([
            'command' => 'apply-staged-files',
            'staged_root' => $staged,
            'target_root' => $target,
            'selected_files' => $selectedUnsupported,
        ]);
    }

    private function runJsonCommand(array $options): array
    {
        $client = new \ImportClient('http://example.test/?reprint-api', $this->stateDir, $this->fsRoot);
        ob_start();
        try {
            $client->run($options);
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, $output);
        return $decoded;
    }

    private function writeState(): void
    {
        file_put_contents($this->stateDir . '/.import-state.json', json_encode([
            'preflight' => [
                'data' => [
                    'database' => [
                        'wp' => [
                            'paths_urls' => [
                                'abspath' => '/var/www/html',
                                'wp_admin_path' => '/var/www/html/wp-admin',
                                'wp_includes_path' => '/var/www/html/wp-includes',
                                'content_dir' => '/var/www/html/wp-content',
                                'plugins_dir' => '/var/www/html/wp-content/plugins',
                                'mu_plugins_dir' => '/var/www/html/wp-content/mu-plugins',
                                'uploads' => [
                                    'basedir' => '/var/www/html/wp-content/uploads',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]));
    }

    private function writeIndex(string $name, array $rows): void
    {
        $lines = '';
        foreach ($rows as [$path, $ctime, $size, $type]) {
            $lines .= json_encode([
                'path' => base64_encode($path),
                'ctime' => $ctime,
                'size' => $size,
                'type' => $type,
            ]) . "\n";
        }
        file_put_contents($this->stateDir . '/' . $name, $lines);
    }

    private function writeSelectedManifest(string $path, array $rows): void
    {
        $lines = '';
        foreach ($rows as [$sourcePath, $relativePath, $operation, $type]) {
            $lines .= json_encode([
                'path' => $sourcePath,
                'relative_path' => $relativePath,
                'operation' => $operation,
                'type' => $type,
                'selected' => true,
            ]) . "\n";
        }
        file_put_contents($path, $lines);
    }

    private function removeRecursive(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->removeRecursive($path . '/' . $entry);
            }
            rmdir($path);
            return;
        }
        unlink($path);
    }
}
