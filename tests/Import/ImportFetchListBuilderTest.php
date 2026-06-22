<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\DownloadList;
use Reprint\Importer\FileSync\FetchListBuilder;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\Port\FilesPullCheckpointStore;
use Reprint\Importer\FileSync\Port\LocalFileChangePlanner;
use Reprint\Importer\FileSync\Port\ProgressTicker;
use Reprint\Importer\FileSync\Port\ShutdownToken;
use Reprint\Importer\Index\IndexStore;
use Reprint\Importer\Observability\NullAuditLogger;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

final class ImportFetchListBuilderTest extends TestCase
{
    private string $temp_dir;
    private array $deleted = [];
    private array $skip_paths = [];
    private array $skipped = [];
    private array $diffs = [];
    private array $audit = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir() . '/import-fetch-list-builder-' . uniqid('', true);
        mkdir($this->temp_dir, 0755, true);
        $this->deleted = [];
        $this->skip_paths = [];
        $this->skipped = [];
        $this->diffs = [];
        $this->audit = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temp_dir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->temp_dir);
        parent::tearDown();
    }

    public function testBuildsDownloadListAndDeletesLocalEntriesMissingRemotely(): void
    {
        $local_index = $this->temp_dir . '/index.jsonl';
        $remote_index = $this->temp_dir . '/remote.jsonl';
        $download_list = $this->temp_dir . '/download.jsonl';
        $skipped_list = $this->temp_dir . '/skipped.jsonl';

        file_put_contents($local_index, implode('', [
            $this->index_line('/a.txt', 1, 10),
            $this->index_line('/b.txt', 2, 20),
        ]));
        file_put_contents($remote_index, implode('', [
            $this->index_line('/b.txt', 3, 30),
            $this->index_line('/c.txt', 4, 40),
        ]));

        $builder = $this->make_builder($local_index);
        $this->assertTrue($builder->build(
            FilesPullCheckpoint::fresh(),
            $remote_index,
            $local_index,
            $download_list,
            $skipped_list,
            'none',
            null,
        ));

        $this->assertSame(['/a.txt'], $this->deleted);
        $this->assertSame(['/b.txt', '/c.txt'], $this->read_download_list($download_list));
        $this->assertNotEmpty($this->diffs);
    }

    public function testEssentialFilesFilterWritesUploadsToSkippedList(): void
    {
        $local_index = $this->temp_dir . '/index.jsonl';
        $remote_index = $this->temp_dir . '/remote.jsonl';
        $download_list = $this->temp_dir . '/download.jsonl';
        $skipped_list = $this->temp_dir . '/skipped.jsonl';

        file_put_contents($local_index, '');
        file_put_contents($remote_index, implode('', [
            $this->index_line('/wp-content/themes/flavor/style.css', 1, 10),
            $this->index_line('/wp-content/uploads/photo.jpg', 1, 20),
        ]));

        $builder = $this->make_builder($local_index);
        $this->assertTrue($builder->build(
            FilesPullCheckpoint::fresh(),
            $remote_index,
            $local_index,
            $download_list,
            $skipped_list,
            'essential-files',
            '/wp-content/uploads/',
        ));

        $this->assertSame(['/wp-content/themes/flavor/style.css'], $this->read_download_list($download_list));
        $this->assertSame(['/wp-content/uploads/photo.jpg'], $this->read_download_list($skipped_list));
    }

    public function testPreserveLocalSkipPolicyKeepsPathOutOfDownloadList(): void
    {
        $local_index = $this->temp_dir . '/index.jsonl';
        $remote_index = $this->temp_dir . '/remote.jsonl';
        $download_list = $this->temp_dir . '/download.jsonl';
        $skipped_list = $this->temp_dir . '/skipped.jsonl';

        file_put_contents($local_index, '');
        file_put_contents($remote_index, $this->index_line('/existing.txt', 1, 10));
        $this->skip_paths = ['/existing.txt'];

        $builder = $this->make_builder($local_index);
        $this->assertTrue($builder->build(
            FilesPullCheckpoint::fresh(),
            $remote_index,
            $local_index,
            $download_list,
            $skipped_list,
            'none',
            null,
        ));

        $this->assertSame([], $this->read_download_list($download_list));
        $this->assertSame(['/existing.txt'], $this->skipped);
    }

    private function make_builder(string $local_index): FetchListBuilder
    {
        $store = new IndexStore(
            $local_index,
            $this->temp_dir . '/updates.jsonl',
        );

        return new FetchListBuilder(
            $store,
            new class($this->deleted, $this->skip_paths, $this->skipped) implements LocalFileChangePlanner {
                private array $deleted;
                private array $skip_paths;
                private array $skipped;

                public function __construct(array &$deleted, array &$skip_paths, array &$skipped)
                {
                    $this->deleted = &$deleted;
                    $this->skip_paths = &$skip_paths;
                    $this->skipped = &$skipped;
                }

                public function delete_local_file_path(string $path): void
                {
                    $this->deleted[] = $path;
                }

                public function should_skip_for_preserve_local(string $path): ?string
                {
                    return in_array($path, $this->skip_paths, true)
                        ? "skip {$path}"
                        : null;
                }

                public function emit_skip_progress(string $path): void
                {
                    $this->skipped[] = $path;
                }
            },
            new class($this->diffs) implements FilesPullCheckpointStore {
                private array $diffs;

                public function __construct(array &$diffs)
                {
                    $this->diffs = &$diffs;
                }

                public function get(): FilesPullCheckpoint
                {
                    return FilesPullCheckpoint::fresh();
                }

                public function save(FilesPullCheckpoint $checkpoint): void
                {
                    $this->diffs[] = $checkpoint->diff_state();
                }
            },
            new class implements ShutdownToken {
                public function is_shutdown_requested(): bool
                {
                    return false;
                }
            },
            new class implements ProgressTicker {
                public function tick(): void
                {
                }
            },
            new NullAuditLogger(),
        );
    }

    private function index_line(
        string $path,
        int $ctime,
        int $size,
        string $type = 'file'
    ): string {
        return json_encode([
            'path' => base64_encode($path),
            'ctime' => $ctime,
            'size' => $size,
            'type' => $type,
        ], JSON_UNESCAPED_SLASHES) . "\n";
    }

    private function read_download_list(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $paths = [];
        $handle = fopen($path, 'r');
        while (($line = fgets($handle)) !== false) {
            $decoded = DownloadList::read_path($line);
            if ($decoded !== null) {
                $paths[] = $decoded;
            }
        }
        fclose($handle);
        return $paths;
    }
}
