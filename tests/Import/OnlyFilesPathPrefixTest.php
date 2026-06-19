<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

/**
 * --only file-prefix resolution and enumeration (pure, preflight-injected):
 *   - resolve_pull_only_files_with_path_prefixes(): :token: templates / absolute paths → real source
 *     prefixes (sharing --remap's WordPress path token table), with expansion for plugins, mu-plugins, and uploads
 *     directories outside WP_CONTENT_DIR and covered-prefix collapse.
 *   - is_file_path_selected_by_pull_only_files(): per-path membership (no --only ⇒ every file path).
 *   - get_export_directories(): with --only, a *replace* of the export roots.
 * Orthogonal to --remap (--only file prefixes decide what gets pulled, not where it lands).
 */
class OnlyFilesPathPrefixTest extends TestCase
{
    private $tempDir;
    private $stateDir;
    private $fsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/only-files-prefix-' . uniqid();
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

    /** Preflight carrying only the wp paths_urls needed by the --only file-prefix helpers. */
    private function withPaths(array $pathsUrls): \ImportClient
    {
        return $this->client(array('database' => array('wp' => array('paths_urls' => $pathsUrls))));
    }

    public function testResolvePullOnlyFilesPrefixAddsDirectoriesOutsideWpContent(): void
    {
        // Selecting :wp-content: with --only yields WP_CONTENT_DIR plus any plugins,
        // mu-plugins, or uploads directory outside it (uploads here); a nested
        // directory is already covered.
        $c = $this->withPaths(array(
            'content_dir' => '/var/www/html/wp-content',
            'plugins_dir' => '/var/www/html/wp-content/plugins', // nested → not added
            'uploads' => array('basedir' => '/mnt/uploads'),     // outside WP_CONTENT_DIR → added
        ));
        $pull_only_files_with_path_prefixes = $this->call($c, 'resolve_pull_only_files_with_path_prefixes', array(array(':wp-content:')));
        sort($pull_only_files_with_path_prefixes);
        $this->assertSame(array('/mnt/uploads', '/var/www/html/wp-content'), $pull_only_files_with_path_prefixes);
    }

    public function testResolvePullOnlyFilesPrefixCollapsesNestedPrefixes(): void
    {
        // :wp-content:/plugins is nested under :wp-content: → dropped, so the
        // exporter never walks the subtree twice.
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));
        $pull_only_files_with_path_prefixes = $this->call($c, 'resolve_pull_only_files_with_path_prefixes', array(array(':wp-content:', ':wp-content:/plugins')));
        $this->assertSame(array('/var/www/html/wp-content'), $pull_only_files_with_path_prefixes);
    }

    public function testResolveMapsTokensToTheirRealRelocatedLocations(): void
    {
        // A token resolves to its real (possibly relocated) location: the moved
        // plugins dir via :wp-plugins:. A token narrower than wp-content does
        // not add sibling directories outside WP_CONTENT_DIR (no /external/uploads
        // pulled in).
        $c = $this->client(array('database' => array('wp' => array(
            'paths_urls' => array(
                'content_dir' => '/srv/wp-content',
                'plugins_dir' => '/custom/plugins',
                'abspath' => '/var/www/html',
                'uploads' => array('basedir' => '/external/uploads'),
            ),
        ))));
        $this->assertSame(
            array('/custom/plugins/woocommerce'),
            $this->call($c, 'resolve_pull_only_files_with_path_prefixes', array(array(':wp-plugins:/woocommerce')))
        );
    }

    public function testResolvePullOnlyFilesPrefixAcceptsRawAbsolutePath(): void
    {
        // Like --remap, a raw absolute source is taken literally (no tokens).
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));
        $this->assertSame(
            array('/var/custom/data'),
            $this->call($c, 'resolve_pull_only_files_with_path_prefixes', array(array('/var/custom/data')))
        );
    }

    public function testResolvePullOnlyFilesPrefixRejectsBlankSource(): void
    {
        // Strict input hygiene: a blank source (e.g. `--only ""`) is an error,
        // not silently ignored.
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));
        $this->expectException(\InvalidArgumentException::class);
        $this->call($c, 'resolve_pull_only_files_with_path_prefixes', array(array(':wp-content:', '')));
    }

    public function testResolvePullOnlyFilesPrefixRejectsUnavailableToken(): void
    {
        // A token preflight didn't determine yields a clear, preflight-naming
        // error (shared with --remap's resolver).
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('preflight');
        $this->call($c, 'resolve_pull_only_files_with_path_prefixes', array(array(':abspath:/wp-admin')));
    }

    public function testPullOnlyFilesPrefixSelectionDefaultsToTrueAndIsSlashAware(): void
    {
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));
        // No --only: every file path is selected (keeps the diff deleting orphans).
        $this->assertTrue($this->call($c, 'is_file_path_selected_by_pull_only_files', array('/anything/at/all.php')));

        $this->set($c, 'pull_only_files_with_path_prefixes', array('/var/www/html/wp-content'));
        $this->assertTrue($this->call($c, 'is_file_path_selected_by_pull_only_files', array('/var/www/html/wp-content/themes/a.css')));
        $this->assertFalse($this->call($c, 'is_file_path_selected_by_pull_only_files', array('/var/www/html/wp-config.php')));
        // Byte-order sibling must not match the prefix.
        $this->assertFalse($this->call($c, 'is_file_path_selected_by_pull_only_files', array('/var/www/html/wp-content.bak/x')));
    }

    public function testChangingOnlyPrefixesWhileResumingFilesPullIsRejected(): void
    {
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));

        $this->set($c, 'pull_only_files_with_path_prefixes', array('/var/www/html/wp-content/plugins'));
        $original_fingerprint = $this->call($c, 'files_pull_only_fingerprint');

        $this->set($c, 'state', array('files_pull_only_fingerprint' => $original_fingerprint));
        $this->set($c, 'pull_only_files_with_path_prefixes', array('/var/www/html/wp-content/uploads'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot change --only while resuming files-pull');
        $this->call($c, 'assert_files_pull_only_unchanged_while_resuming', array(true));
    }

    public function testChangingOnlyPrefixesAfterCompletedFilesPullIsAllowed(): void
    {
        $c = $this->withPaths(array('content_dir' => '/var/www/html/wp-content'));

        $this->set($c, 'state', array('files_pull_only_fingerprint' => 'different'));
        $this->set($c, 'pull_only_files_with_path_prefixes', array('/var/www/html/wp-content/uploads'));

        $this->call($c, 'assert_files_pull_only_unchanged_while_resuming', array(false));
        $this->addToAssertionCount(1);
    }

    public function testPullOnlyFilesPrefixesReplaceRootsAndIgnoreUnselectedRemap(): void
    {
        // With --only, export roots ARE the selected file prefixes: core/abspath/document_root
        // are dropped, and an unselected --remap source stays inert.
        $c = $this->client(array(
            'wp_detect' => array('roots' => array(array('path' => '/var/www/html'))),
            'runtime' => array('document_root' => '/var/www/html'),
            'database' => array('wp' => array('paths_urls' => array(
                'content_dir' => '/var/www/html/wp-content',
            ))),
        ));
        $this->set($c, 'pull_only_files_with_path_prefixes', array('/var/www/html/wp-content'));
        $this->set($c, 'remap_rules', array(
            '/var/www/html/wp-admin' => '/srv/htdocs/wp-admin',
        ));
        $dirs = $this->call($c, 'get_export_directories');
        $this->assertSame(array('/var/www/html/wp-content'), $dirs);
    }
}
