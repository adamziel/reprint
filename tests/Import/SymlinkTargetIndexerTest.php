<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\SymlinkTargetIndexer;
use RuntimeException;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class SymlinkTargetIndexerTest extends TestCase
{
    private string $temp_dir;
    private string $remote_index_file;
    private array $downloaded = [];
    private array $saved_states = [];
    private array $audit = [];
    private array $lifecycle = [];
    private array $progress = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir() . '/symlink-target-indexer-' . uniqid('', true);
        mkdir($this->temp_dir, 0755, true);
        $this->remote_index_file = $this->temp_dir . '/remote-index.jsonl';
        $this->downloaded = [];
        $this->saved_states = [];
        $this->audit = [];
        $this->lifecycle = [];
        $this->progress = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->remote_index_file);
        @rmdir($this->temp_dir);
        parent::tearDown();
    }

    public function testIndexesDiscoveredSymlinkTargets(): void
    {
        $this->append_link('/srv/root/link', '/srv/external');
        $this->append_link('/srv/root/covered', '/srv/root/already-covered');
        $this->append_link('/srv/root/intermediate', '/srv/intermediate', true);

        $checkpoint = FilesPullCheckpoint::fresh();
        $checkpoint->index_cursor = 'existing-cursor';

        $this->make_indexer()->discover($checkpoint, ['/srv/root']);

        $this->assertSame(['/srv/external'], $this->downloaded);
        $this->assertNull($checkpoint->index_cursor);
        $this->assertNotEmpty($this->saved_states);
        $this->assertSame('symlink_follow', $this->progress[0]['type']);
        $this->assertStringContainsString('/srv/external', $this->lifecycle[0]);
    }

    public function testServerRejectedTargetIsSkipped(): void
    {
        $this->append_link('/srv/root/rejected', '/srv/rejected');
        $checkpoint = FilesPullCheckpoint::fresh();

        $this->make_indexer([
            '/srv/rejected' => new RuntimeException('dir_outside_root'),
        ])->discover($checkpoint, ['/srv/root']);

        $this->assertSame(['/srv/rejected'], $this->downloaded);
        $this->assertSame('symlink_follow_rejected', $this->progress[1]['type']);
        $this->assertStringContainsString(
            'server rejected /srv/rejected',
            implode("\n", array_column($this->audit, 0)),
        );
    }

    /**
     * @param array<string, RuntimeException> $errors
     */
    private function make_indexer(array $errors = []): SymlinkTargetIndexer
    {
        return new SymlinkTargetIndexer(
            $this->remote_index_file,
            function (string $directory) use ($errors): bool {
                $this->downloaded[] = $directory;
                if (isset($errors[$directory])) {
                    throw $errors[$directory];
                }
                return true;
            },
            function (FilesPullCheckpoint $checkpoint): void {
                $this->saved_states[] = clone $checkpoint;
            },
            fn(): bool => false,
            function (string $message, bool $to_console): void {
                $this->audit[] = [$message, $to_console];
            },
            function (string $message): void {
                $this->lifecycle[] = $message;
            },
            function (array $progress): void {
                $this->progress[] = $progress;
            },
        );
    }

    private function append_link(
        string $path,
        string $target,
        bool $intermediate = false
    ): void {
        file_put_contents(
            $this->remote_index_file,
            json_encode([
                'type' => 'link',
                'path' => base64_encode($path),
                'target' => base64_encode($target),
                'intermediate' => $intermediate,
            ], JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND,
        );
    }
}
