<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * --only scope resolution and enumeration (pure, preflight-injected):
 *   - resolve_scope(): :token: templates / absolute paths → real source
 *     prefixes (sharing --remap's source token table), with detached-component
 *     expansion for wp-content and covered-prefix collapse.
 *   - in_scope(): per-path membership (unscoped ⇒ everything in scope).
 *   - get_export_directories(): under scope, a *replace* of the export roots.
 * Orthogonal to --remap (scope = what gets pulled, not where it lands).
 */
class OnlyScopeTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/only-scope-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/srv/htdocs';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse(glob($this->tempDir . '/*') ?: array()) as $p) {
            is_dir($p) ? @rmdir($p) : @unlink($p);
        }
        @rmdir($this->stateDir);
        @rmdir($this->fsRoot);
        @rmdir($this->tempDir . '/srv');
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    private function call($c, string $m, array $a = array())
    {
        return (new \ReflectionClass($c))->getMethod($m)->invoke($c, ...$a);
    }

    private function set($c, string $p, $v): void
    {
        (new \ReflectionClass($c))->getProperty($p)->setValue($c, $v);
    }

    private function client(array $preflightData): \ImportClient
    {
        $c = new \ImportClient('https://src.example/export.php', $this->stateDir, $this->fsRoot);
        $this->set($c, 'state', array('preflight' => array('data' => $preflightData)));
        $this->set($c, 'audit_log', $this->tempDir . '/audit.log');
        return $c;
    }

    /** Preflight carrying only the wp paths_urls (for resolve_scope/in_scope). */
    private function withPaths(array $pathsUrls): \ImportClient
    {
        return $this->client(array('database' => array('wp' => array('paths_urls' => $pathsUrls))));
    }

    public function testResolveScopeAddsDetachedComponentsForWpContent(): void
    {
        // Scoping :wp-content: yields content_dir plus any component detached
        // from it (uploads here); a nested component is already covered.
        $c = $this->withPaths(array(
            'content_dir' => '/var/www/html/wp-content',
            'plugins_dir' => '/var/www/html/wp-content/plugins', // nested → not added
            'uploads' => array('basedir' => '/mnt/uploads'),     // detached → added
        ));
        $scope = $this->call($c, 'resolve_scope', array(array(':wp-content:')));
        sort($scope);
        $this->assertSame(array('/mnt/uploads', '/var/www/html/wp-content'), $scope);
    }

    public function testResolveScopeCollapsesNestedPrefixes(): void
    {
        // :wp-content:/plugins is nested under :wp-content: → dropped, so the
        // exporter never walks the subtree twice.
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));
        $scope = $this->call($c, 'resolve_scope', array(array(':wp-content:', ':wp-content:/plugins')));
        $this->assertSame(array('/var/www/html/wp-content'), $scope);
    }

    public function testResolveMapsTokensToTheirRealRelocatedLocations(): void
    {
        // A token resolves to its real (possibly relocated) location: the moved
        // plugins dir via :wp-plugins:. A component token narrower than
        // wp-content does not expand detached components (no /detached/uploads
        // pulled in).
        $c = $this->client(array('database' => array('wp' => array(
            'paths_urls' => array(
                'content_dir' => '/srv/wp-content',
                'plugins_dir' => '/custom/plugins',
                'abspath' => '/var/www/html',
                'uploads' => array('basedir' => '/detached/uploads'),
            ),
        ))));
        $this->assertSame(
            array('/custom/plugins/woocommerce'),
            $this->call($c, 'resolve_scope', array(array(':wp-plugins:/woocommerce')))
        );
    }

    public function testResolveScopeAcceptsRawAbsolutePath(): void
    {
        // Like --remap, a raw absolute source is taken literally (no tokens).
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));
        $this->assertSame(
            array('/var/custom/data'),
            $this->call($c, 'resolve_scope', array(array('/var/custom/data')))
        );
    }

    public function testResolveScopeRejectsBlankSource(): void
    {
        // Strict input hygiene: a blank source (e.g. from a trailing comma in
        // `--only=:wp-content:,`) is an error, not silently ignored.
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));
        $this->expectException(\InvalidArgumentException::class);
        $this->call($c, 'resolve_scope', array(array(':wp-content:', '')));
    }

    public function testResolveScopeRejectsUnavailableToken(): void
    {
        // A token preflight didn't determine yields a clear, preflight-naming
        // error (shared with --remap's resolver).
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('preflight');
        $this->call($c, 'resolve_scope', array(array(':abspath:/wp-admin')));
    }

    public function testInScopeIsUnscopedTrueAndOtherwiseSlashAware(): void
    {
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));
        // No --only: everything is in scope (keeps the diff deleting orphans).
        $this->assertTrue($this->call($c, 'in_scope', array('/anything/at/all.php')));

        $this->set($c, 'scope', array('/var/www/html/wp-content'));
        $this->assertTrue($this->call($c, 'in_scope', array('/var/www/html/wp-content/themes/a.css')));
        $this->assertFalse($this->call($c, 'in_scope', array('/var/www/html/wp-config.php')));
        // Byte-order sibling must not match the prefix.
        $this->assertFalse($this->call($c, 'in_scope', array('/var/www/html/wp-content.bak/x')));
    }

    public function testScopedEnumerationReplacesRootsAndIgnoresOutOfScopeRemap(): void
    {
        // Under scope, export roots ARE the scope: core/abspath/document_root
        // are dropped, and an out-of-scope --remap source stays inert.
        $c = $this->client(array(
            'wp_detect' => array('roots' => array(array('path' => '/var/www/html'))),
            'runtime' => array('document_root' => '/var/www/html'),
            'database' => array('wp' => array('paths_urls' => array(
                'content_dir' => '/var/www/html/wp-content',
            ))),
        ));
        $this->set($c, 'scope', array('/var/www/html/wp-content'));
        $this->set($c, 'remap_rules', array(
            '/var/www/html/wp-admin' => '/srv/htdocs/wp-admin',
        ));
        $dirs = $this->call($c, 'get_export_directories');
        $this->assertSame(array('/var/www/html/wp-content'), $dirs);
    }
}
