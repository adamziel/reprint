<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\IntermediateSymlinkRecreator;
use Reprint\Importer\Filesystem\LocalImportFilesystem;
use Reprint\Importer\Observability\AuditLogger;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class IntermediateSymlinkRecreatorTest extends TestCase
{
    private string $temp_dir;
    private string $index_file;
    private array $audit = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir() . '/intermediate-symlink-' . uniqid('', true);
        mkdir($this->temp_dir, 0755, true);
        $this->index_file = $this->temp_dir . '/remote-index.jsonl';
        $this->audit = [];
    }

    protected function tearDown(): void
    {
        $this->remove_path($this->temp_dir);
        parent::tearDown();
    }

    public function testCreatesIntermediateSymlink(): void
    {
        mkdir($this->root_path() . '/wordpress', 0755, true);
        $this->append_index_entry('/srv/wordpress', '../wordpress');

        $created = $this->make_recreator()->recreate($this->index_file);

        $link = $this->root_path() . '/srv/wordpress';
        $this->assertSame(1, $created);
        $this->assertTrue(is_link($link));
        $this->assertSame('../wordpress', readlink($link));
        $this->assertContains(
            ['INTERMEDIATE SYMLINK: /srv/wordpress -> ../wordpress', false],
            $this->audit,
        );
    }

    public function testRejectsEscapingIntermediateSymlinkTarget(): void
    {
        $this->append_index_entry('/srv/e2e-via', '../../outside');

        $created = $this->make_recreator()->recreate($this->index_file);

        $this->assertSame(0, $created);
        $this->assertFalse(is_link($this->root_path() . '/srv/e2e-via'));
        $this->assertStringContainsString(
            'escapes filesystem root',
            implode("\n", array_column($this->audit, 0)),
        );
    }

    private function make_recreator(): IntermediateSymlinkRecreator
    {
        return new IntermediateSymlinkRecreator(
            new LocalImportFilesystem(
                $this->root_path(),
                'error',
                new IntermediateSymlinkRecreatorTestAuditLogger($this->audit),
            ),
            new IntermediateSymlinkRecreatorTestAuditLogger($this->audit),
        );
    }

    private function append_index_entry(string $path, string $target): void
    {
        file_put_contents(
            $this->index_file,
            json_encode([
                'type' => 'link',
                'intermediate' => true,
                'path' => base64_encode($path),
                'target' => base64_encode($target),
            ]) . "\n",
            FILE_APPEND,
        );
    }

    private function root_path(): string
    {
        return $this->temp_dir . '/fs-root';
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

final class IntermediateSymlinkRecreatorTestAuditLogger implements AuditLogger
{
    private $audit;

    public function __construct(array &$audit)
    {
        $this->audit =& $audit;
    }

    public function record(string $message, bool $to_console = true): void
    {
        $this->audit[] = [$message, $to_console];
    }

    public function path(): string
    {
        return '';
    }
}
