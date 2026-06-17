<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * --remap: resolving SRC TGT pairs into remap rules, with detached-component
 * expansion driven by the source's preflight paths_urls.
 */
class RemapResolveTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fsRoot;
    private $root; // realpath of fsRoot (targets are rooted under it)

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/remap-resolve-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/srv/htdocs';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
        $this->root = realpath($this->fsRoot);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->tempDir);
        parent::tearDown();
    }

    private function rrm(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $p = "$d/$i";
            (is_dir($p) && !is_link($p)) ? $this->rrm($p) : unlink($p);
        }
        rmdir($d);
    }

    private function call($c, string $m, array $a = array())
    {
        return (new \ReflectionClass($c))->getMethod($m)->invoke($c, ...$a);
    }

    private function set($c, string $p, $v): void
    {
        (new \ReflectionClass($c))->getProperty($p)->setValue($c, $v);
    }

    private function get($c, string $p)
    {
        return (new \ReflectionClass($c))->getProperty($p)->getValue($c);
    }

    private function client(array $pathsUrls): \ImportClient
    {
        $c = new \ImportClient('https://src.example/export.php', $this->stateDir, $this->fsRoot);
        $this->set($c, 'state', array('preflight' => array('data' => array(
            'database' => array('wp' => array('paths_urls' => $pathsUrls)),
        ))));
        return $c;
    }

    public function testWpContentRemapsToFullTargetUnderDocroot(): void
    {
        $c = $this->client(array('content_dir' => '/var/www/html/wp-content'));
        $rules = $this->call($c, 'resolve_remap', array(array(array('wp-content', 'wp-content'))));
        $this->assertCount(1, $rules);
        $this->assertSame(
            $this->root . '/wp-content',
            $rules['/var/www/html/wp-content']
        );
    }

    public function testAbsoluteTargetContainingDocrootIsTreatedAsRelative(): void
    {
        // A target pasted as a full absolute path under the docroot resolves
        // the same as the docroot-relative form (no double-nesting).
        $c = $this->client(array('content_dir' => '/var/www/html/wp-content'));
        $rules = $this->call($c, 'resolve_remap', array(array(
            array('wp-content', $this->root . '/some/where'),
        )));
        $this->assertSame(
            $this->root . '/some/where',
            $rules['/var/www/html/wp-content']
        );
    }

    public function testSurroundingSlashesOnSourceAndTargetAreIgnored(): void
    {
        // Leading/trailing slashes on either endpoint are trimmed — no rsync-style
        // folder-vs-contents distinction; both endpoints are explicitly named.
        $c = $this->client(array('content_dir' => '/var/www/html/wp-content'));
        $rules = $this->call($c, 'resolve_remap', array(array(array('/wp-content/plugins/', '/wp-content/plugins/'))));
        $this->assertCount(1, $rules);
        $this->assertSame(
            $this->root . '/wp-content/plugins',
            $rules['/var/www/html/wp-content/plugins']
        );
    }

    public function testDetachedComponentsExpandUnderTarget(): void
    {
        // Source keeps plugins inside wp-content (nested → no extra rule), but
        // uploads and mu-plugins outside it (detached → routed under TGT).
        $c = $this->client(array(
            'content_dir' => '/var/www/html/wp-content',
            'plugins_dir' => '/var/www/html/wp-content/plugins',
            'mu_plugins_dir' => '/opt/muplugins',
            'uploads' => array('basedir' => '/mnt/blogs/uploads'),
        ));
        $rules = $this->call($c, 'resolve_remap', array(array(array('wp-content', 'wp-content'))));
        $byTarget = array_flip($rules);
        $this->assertCount(3, $rules);
        $this->assertSame('/var/www/html/wp-content', $byTarget[$this->root . '/wp-content']);
        $this->assertSame('/mnt/blogs/uploads', $byTarget[$this->root . '/wp-content/uploads']);
        $this->assertSame('/opt/muplugins', $byTarget[$this->root . '/wp-content/mu-plugins']);
        // plugins is nested under content_dir → covered by the wp-content rule.
        $this->assertArrayNotHasKey($this->root . '/wp-content/plugins', $byTarget);
    }

    public function testComponentSrcResolvesViaPreflight(): void
    {
        $c = $this->client(array(
            'content_dir' => '/srv/wp-content',
            'plugins_dir' => '/custom/plugins',
        ));
        $rules = $this->call($c, 'resolve_remap', array(array(
            array('wp-content/plugins/woocommerce', 'wp-content/plugins/woocommerce'),
        )));
        $this->assertCount(1, $rules);
        $this->assertArrayHasKey('/custom/plugins/woocommerce', $rules);
    }

    public function testManualComponentRemapOverridesWholeTreeExpansion(): void
    {
        // Detached plugins explicitly sent elsewhere; a whole wp-content remap
        // must NOT also route them under its own target. Manual flag listed
        // FIRST to prove order independence.
        $c = $this->client(array(
            'content_dir' => '/var/www/html/wp-content',
            'plugins_dir' => '/detached/plugins', // detached → would otherwise expand
        ));
        $rules = $this->call($c, 'resolve_remap', array(array(
            array('wp-content/plugins', 'special-plugins'),
            array('wp-content', 'wp-content'),
        )));
        // The detached plugins source maps once — to the manual target, not the
        // whole-tree expansion (a single key per source guarantees no double-route).
        $this->assertArrayHasKey('/detached/plugins', $rules);
        $this->assertSame($this->root . '/special-plugins', $rules['/detached/plugins']);
    }

    public function testNonWpContentSrcResolvesRelativeToAbspath(): void
    {
        // wp-admin / core / arbitrary paths are not restricted — they resolve
        // relative to the source's ABSPATH.
        $c = $this->client(array(
            'abspath' => '/var/www/html',
            'content_dir' => '/var/www/html/wp-content',
        ));
        $rules = $this->call($c, 'resolve_remap', array(array(array('wp-admin', 'wp-admin'))));
        $this->assertCount(1, $rules);
        $this->assertSame(
            $this->root . '/wp-admin',
            $rules['/var/www/html/wp-admin']
        );
    }

    public function testDocrootTargetPlacesAtDocrootRoot(): void
    {
        // Target == the docroot (whether given absolutely or as an empty
        // relative) means: place the source at the docroot root itself.
        $c = $this->client(array('content_dir' => '/var/www/html/wp-content'));

        $rules = $this->call($c, 'resolve_remap', array(array(array('wp-content', $this->root))));
        $this->assertSame($this->root, $rules['/var/www/html/wp-content']);

        $rules = $this->call($c, 'resolve_remap', array(array(array('wp-content', ''))));
        $this->assertSame($this->root, $rules['/var/www/html/wp-content']);
    }
}
