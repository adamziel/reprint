<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Index\IndexStore;
use Reprint\Importer\Session\ImportAbortHandler;
use Reprint\Importer\Session\ImportPaths;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class ImportAbortHandlerTest extends TestCase
{
    private string $state_dir;
    private array $audit = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->state_dir = sys_get_temp_dir() . '/import-abort-handler-' . uniqid('', true);
        mkdir($this->state_dir, 0755, true);
        $this->audit = [];
    }

    protected function tearDown(): void
    {
        $this->remove_path($this->state_dir);
        parent::tearDown();
    }

    public function testDbPullAbortClearsFilesAndPreservesCrossCommandState(): void
    {
        $paths = new ImportPaths($this->state_dir);
        file_put_contents($paths->sql_file(), 'sql');
        file_put_contents($paths->table_stats_file(), '{}');
        file_put_contents($paths->domains_file(), '[]');

        $state = [
            'command' => 'db-pull',
            'status' => 'complete',
            'preflight' => ['data' => ['ok' => true]],
            'version' => '1.2.3',
            'webhost' => 'wpcloud',
            'follow_symlinks' => false,
            'fs_root_nonempty_behavior' => 'preserve-local',
            'max_allowed_packet' => 123,
            'pull' => ['stage' => 'db-pull'],
        ];

        $next = $this->make_handler($paths)->abort($state, 'db-pull', 'file');

        $this->assertFileDoesNotExist($paths->sql_file());
        $this->assertFileDoesNotExist($paths->table_stats_file());
        $this->assertFileDoesNotExist($paths->domains_file());
        $this->assertNull($next['command']);
        $this->assertNull($next['status']);
        $this->assertSame($state['preflight'], $next['preflight']);
        $this->assertSame('1.2.3', $next['version']);
        $this->assertSame('wpcloud', $next['webhost']);
        $this->assertFalse($next['follow_symlinks']);
        $this->assertSame('preserve-local', $next['fs_root_nonempty_behavior']);
        $this->assertSame(123, $next['max_allowed_packet']);
        $this->assertSame($state['pull'], $next['pull']);
        $this->assertStringContainsString(
            'abort db-pull',
            implode("\n", array_column($this->audit, 0)),
        );
    }

    private function make_handler(ImportPaths $paths): ImportAbortHandler
    {
        return new ImportAbortHandler(
            $paths,
            new IndexStore(
                $paths->index_file(),
                $paths->index_updates_file(),
                function (string $message): void {
                    $this->audit[] = [$message, true];
                },
            ),
            function (string $message, bool $to_console = true): void {
                $this->audit[] = [$message, $to_console];
            },
        );
    }

    private function remove_path(string $path): bool
    {
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }
        if (is_link($path) || is_file($path)) {
            return unlink($path);
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!$this->remove_path($path . '/' . $entry)) {
                return false;
            }
        }
        return rmdir($path);
    }
}
