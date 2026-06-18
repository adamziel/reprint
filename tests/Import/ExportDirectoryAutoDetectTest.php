<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Session\ExportDirectoryResolver;

require_once __DIR__ . '/../../importer/import.php';

/**
 * Verify that get_export_directories() auto-includes directories from
 * auto_prepend_file and auto_append_file INI values when they point
 * outside the WordPress roots.
 */
class ExportDirectoryAutoDetectTest extends TestCase
{
    private function preflight(array $overrides = []): array
    {
        $defaults = [
            'runtime' => [
                'document_root' => '/srv/htdocs',
                'ini_get_all' => [],
            ],
            'database' => [
                'wp' => [
                    'siteurl' => 'https://source.example',
                    'home' => 'https://source.example',
                    'paths_urls' => [
                        'abspath' => '/srv/htdocs/',
                        'content_dir' => '/srv/htdocs/wp-content',
                        'uploads' => [
                            'baseurl' => 'https://source.example/wp-content/uploads',
                        ],
                        'home_url' => 'https://source.example',
                        'site_url' => 'https://source.example',
                    ],
                ],
            ],
            'wp_detect' => [
                'roots' => [
                    ['path' => '/srv/htdocs'],
                ],
            ],
        ];

        return array_replace_recursive($defaults, $overrides);
    }

    private function getExportDirectories(array $preflight_overrides = [], ?string $extra_directory = null): array
    {
        return ExportDirectoryResolver::export_directories(
            $this->preflight($preflight_overrides),
            $extra_directory,
        );
    }

    public function testAutoIncludesAutoPrependFileDirectory(): void
    {
        $dirs = $this->getExportDirectories([
            'runtime' => [
                'ini_get_all' => [
                    'auto_prepend_file' => '/scripts/env.php',
                ],
            ],
        ]);

        $this->assertContains('/scripts', $dirs);
    }

    public function testAutoIncludesAutoAppendFileDirectory(): void
    {
        $dirs = $this->getExportDirectories([
            'runtime' => [
                'ini_get_all' => [
                    'auto_append_file' => '/logging/tracker.php',
                ],
            ],
        ]);

        $this->assertContains('/logging', $dirs);
    }

    public function testDoesNotDuplicateDirectoryAlreadyCoveredByRoots(): void
    {
        // auto_prepend_file points inside a directory that is already a
        // wp_detect root — it should not be added again.
        $dirs = $this->getExportDirectories([
            'runtime' => [
                'ini_get_all' => [
                    'auto_prepend_file' => '/srv/htdocs/wp-config.php',
                ],
            ],
        ]);

        // /srv/htdocs is already a root, so the directory derived from
        // auto_prepend_file (/srv/htdocs) should not be added twice.
        $count = array_count_values($dirs);
        $this->assertSame(1, $count['/srv/htdocs'] ?? 0);
    }

    public function testIgnoresEmptyAutoPrependFile(): void
    {
        $dirs = $this->getExportDirectories([
            'runtime' => [
                'ini_get_all' => [
                    'auto_prepend_file' => '',
                ],
            ],
        ]);

        // Only the wp_detect root should be present.
        $this->assertSame(['/srv/htdocs'], $dirs);
    }

    public function testIgnoresRelativeAutoPrependPath(): void
    {
        $dirs = $this->getExportDirectories([
            'runtime' => [
                'ini_get_all' => [
                    'auto_prepend_file' => 'relative/env.php',
                ],
            ],
        ]);

        $this->assertSame(['/srv/htdocs'], $dirs);
    }

    public function testIgnoresRootSlashDirectory(): void
    {
        $dirs = $this->getExportDirectories([
            'runtime' => [
                'ini_get_all' => [
                    'auto_prepend_file' => '/env.php',
                ],
            ],
        ]);

        // dirname('/env.php') = '/' — should not be added.
        $this->assertNotContains('/', $dirs);
    }

    public function testIncludesBothPrependAndAppendDirectories(): void
    {
        $dirs = $this->getExportDirectories([
            'runtime' => [
                'ini_get_all' => [
                    'auto_prepend_file' => '/scripts/env.php',
                    'auto_append_file' => '/logging/tracker.php',
                ],
            ],
        ]);

        $this->assertContains('/scripts', $dirs);
        $this->assertContains('/logging', $dirs);
    }
}
