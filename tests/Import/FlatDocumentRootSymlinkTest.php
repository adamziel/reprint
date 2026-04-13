<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

/**
 * Tests that flat-document-root creates symlinks using host paths
 * (--host-fs-root / --host-flatten-to) so they resolve on the host
 * filesystem, not just inside the PHP WASM VFS.
 */
class FlatDocumentRootSymlinkTest extends TestCase
{
    private $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $raw = sys_get_temp_dir() . '/flat-docroot-symlink-test-' . uniqid();
        mkdir($raw, 0755, true);
        $this->tempDir = realpath($raw);
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
        $items = scandir($dir);
        foreach ($items as $item) {
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

    private function callFlattenPlaceSymlink(
        \ImportClient $client,
        string $source,
        string $target,
        bool $force,
        array $symlinkPathMap
    ): void {
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('flatten_place_symlink');
        $created = 0;
        $refreshed = 0;
        $forced = 0;
        $args = [$source, $target, $force, &$created, &$refreshed, &$forced, $symlinkPathMap];
        $method->invokeArgs($client, $args);
    }

    /**
     * Symlinks are computed using host paths, not VFS paths.
     */
    public function testSymlinkUsesHostPaths(): void
    {
        $vfsDocroot = $this->tempDir . '/vfs/docroot';
        $vfsFlat = $this->tempDir . '/vfs/flat';
        mkdir($vfsDocroot . '/srv/htdocs/wp-admin', 0755, true);
        mkdir($vfsFlat, 0755, true);

        $hostRaw = $this->tempDir . '/host/.studio/imports/abc/raw';
        $hostSite = $this->tempDir . '/host/Studio/mysite';
        mkdir($hostRaw, 0755, true);
        mkdir($hostSite, 0755, true);

        $client = new \ImportClient('http://fake.url', $this->tempDir . '/state', $vfsDocroot);

        $this->callFlattenPlaceSymlink(
            $client,
            $vfsDocroot . '/srv/htdocs/wp-admin',
            $vfsFlat . '/wp-admin',
            false,
            [$vfsDocroot => $hostRaw, $vfsFlat => $hostSite]
        );

        $this->assertTrue(is_link($vfsFlat . '/wp-admin'));
        $target = readlink($vfsFlat . '/wp-admin');

        $this->assertStringNotContainsString('vfs', $target);
        $this->assertStringContainsString('.studio/imports/abc/raw/srv/htdocs/wp-admin', $target);
    }

    /**
     * Nested symlinks (e.g. wp-content/themes/flavor) use host paths too.
     */
    public function testNestedSymlinkUsesHostPaths(): void
    {
        $vfsDocroot = $this->tempDir . '/vfs/docroot';
        $vfsFlat = $this->tempDir . '/vfs/flat';
        mkdir($vfsDocroot . '/tmp/__wp__/wp-content/themes/flavor', 0755, true);
        mkdir($vfsFlat . '/wp-content/themes', 0755, true);

        $hostRaw = $this->tempDir . '/host/dot-studio/imports/abc/raw';
        $hostSite = $this->tempDir . '/host/Studio/mysite';
        mkdir($hostRaw, 0755, true);
        mkdir($hostSite, 0755, true);

        $client = new \ImportClient('http://fake.url', $this->tempDir . '/state', $vfsDocroot);

        $this->callFlattenPlaceSymlink(
            $client,
            $vfsDocroot . '/tmp/__wp__/wp-content/themes/flavor',
            $vfsFlat . '/wp-content/themes/flavor',
            false,
            [$vfsDocroot => $hostRaw, $vfsFlat => $hostSite]
        );

        $this->assertTrue(is_link($vfsFlat . '/wp-content/themes/flavor'));
        $target = readlink($vfsFlat . '/wp-content/themes/flavor');

        $this->assertStringNotContainsString('vfs', $target);
        $this->assertStringContainsString('dot-studio/imports/abc/raw', $target);
    }

    /**
     * The host-relative symlink actually resolves to the correct file.
     */
    public function testSymlinkResolvesOnHost(): void
    {
        $vfsDocroot = $this->tempDir . '/vfs/docroot';
        $vfsFlat = $this->tempDir . '/vfs/flat';
        $hostRaw = $this->tempDir . '/host/raw';
        $hostSite = $this->tempDir . '/host/site';

        mkdir($vfsDocroot . '/wp-admin', 0755, true);
        file_put_contents($vfsDocroot . '/wp-admin/index.php', '<?php // wp-admin');
        mkdir($vfsFlat, 0755, true);
        mkdir($hostRaw . '/wp-admin', 0755, true);
        file_put_contents($hostRaw . '/wp-admin/index.php', '<?php // wp-admin');
        mkdir($hostSite, 0755, true);

        $client = new \ImportClient('http://fake.url', $this->tempDir . '/state', $vfsDocroot);

        $this->callFlattenPlaceSymlink(
            $client,
            $vfsDocroot . '/wp-admin',
            $vfsFlat . '/wp-admin',
            false,
            [$vfsDocroot => $hostRaw, $vfsFlat => $hostSite]
        );

        // Recreate the symlink at the host site location (simulates VFS mount)
        $linkValue = readlink($vfsFlat . '/wp-admin');
        symlink($linkValue, $hostSite . '/wp-admin');

        $resolved = realpath($hostSite . '/wp-admin');
        $this->assertNotFalse($resolved, 'Host symlink should resolve');
        $this->assertEquals(realpath($hostRaw . '/wp-admin'), $resolved);
        $this->assertEquals('<?php // wp-admin', file_get_contents($hostSite . '/wp-admin/index.php'));
    }
}
