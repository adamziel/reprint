<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\FileSync\FilesPullCheckpoint;
use Reprint\Importer\FileSync\FileSyncLocalApplier;
use Reprint\Importer\Filesystem\LocalImportFilesystem;
use Reprint\Importer\Index\IndexStore;
use Reprint\Importer\Observability\NullAuditLogger;
use Reprint\Importer\Observability\NullMachineEventEmitter;
use Reprint\Importer\Output\BufferedImportOutput;
use Reprint\Importer\Protocol\MultipartStreamParser;
use Reprint\Importer\Protocol\StreamingContext;
use Reprint\Importer\Session\VolatileFileTracker;
use Reprint\Importer\Transport\ImportHttpTransport;

require_once __DIR__ . '/../../importer/import.php';

class FileBodyStreamingTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/import-file-body-streaming-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/state', 0755, true);
        mkdir($this->tempDir . '/fs-root', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testFilePartBodiesAreWrittenIncrementally(): void
    {
        $applier = $this->makeApplier();
        $context = new StreamingContext();
        $bodyLengths = [];
        $context->on_chunk = function (array $chunk) use ($applier, $context, &$bodyLengths): void {
            if (($chunk['headers']['x-chunk-type'] ?? '') === 'file') {
                $bodyLengths[] = strlen($chunk['body'] ?? '');
            }
            $applier->handle_file_chunk($chunk, $context);
        };

        $currentChunk = null;
        $handler = $this->makeChunkHandler($context, $currentChunk);
        $parser = new MultipartStreamParser('BOUNDARY', $handler);

        $body = str_repeat('0123456789abcdef', 64 * 1024);
        $multipart = $this->buildMultipart('BOUNDARY', [
            [
                'headers' => [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Length' => (string) strlen($body),
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('/uploads/big.bin'),
                    'X-File-Size' => (string) strlen($body),
                    'X-File-Ctime' => '1234567890',
                    'X-Chunk-Offset' => '0',
                    'X-Chunk-Size' => (string) strlen($body),
                    'X-First-Chunk' => '1',
                    'X-Last-Chunk' => '1',
                ],
                'body' => $body,
            ],
        ]);

        for ($offset = 0; $offset < strlen($multipart); $offset += 8192) {
            $parser->feed(substr($multipart, $offset, 8192));
        }

        $target = $this->tempDir . '/fs-root/uploads/big.bin';
        $this->assertSame($body, file_get_contents($target));

        $nonEmptyBodies = array_values(array_filter(
            $bodyLengths,
            static fn(int $length): bool => $length > 0,
        ));
        $this->assertGreaterThan(1, count($nonEmptyBodies));
        $this->assertLessThan(strlen($body), max($nonEmptyBodies));
    }

    /**
     * The PR's whole point is partial on-disk writes before a part completes.
     * That means a request cut mid-body now leaves bytes already written —
     * the previous behaviour discarded an in-flight buffer instead. The risk
     * surface is double-counting (server resends bytes already on disk) or
     * truncation (resume re-opens with "wb" and wipes the partial file). This
     * test pins the contract: feed a part's first half, simulate a crash,
     * reopen the file in append mode the way download_file_data() does on
     * resume, then feed the second half as a continuation part with
     * x-first-chunk=0. The result must be byte-identical to the source —
     * no gap, no duplication.
     */
    public function testMidFileResumeAppendsRemainingBytesWithoutDuplication(): void
    {
        $body = str_repeat('0123456789abcdef', 64 * 1024); // 1 MiB
        $halfwayPoint = (int) (strlen($body) / 2);
        $firstHalf = substr($body, 0, $halfwayPoint);
        $secondHalf = substr($body, $halfwayPoint);
        $applier = $this->makeApplier();

        // Pass 1: stream the first half. The remote-side cursor would still
        // point at the start of this part because the server never finished
        // sending it — so on resume we re-receive the whole part body. To
        // mimic the *intended* behaviour (server cooperates and skips the
        // already-written prefix), pass 2 sends only the missing tail.
        $context1 = new StreamingContext();
        $context1->on_chunk = function (array $chunk) use ($applier, $context1): void {
            $applier->handle_file_chunk($chunk, $context1);
        };
        $currentChunk1 = null;
        $handler1 = $this->makeChunkHandler($context1, $currentChunk1);
        $parser1 = new MultipartStreamParser('BOUNDARY', $handler1);

        $multipart1 = $this->buildMultipart('BOUNDARY', [
            [
                'headers' => [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Length' => (string) strlen($firstHalf),
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('/uploads/resume.bin'),
                    'X-File-Size' => (string) strlen($body),
                    'X-File-Ctime' => '1234567890',
                    'X-Chunk-Offset' => '0',
                    'X-Chunk-Size' => (string) strlen($firstHalf),
                    'X-First-Chunk' => '1',
                    // No x-last-chunk: the part finishes (parser emits
                    // complete) but the file is still mid-stream.
                    'X-Last-Chunk' => '0',
                ],
                'body' => $firstHalf,
            ],
        ]);
        for ($offset = 0; $offset < strlen($multipart1); $offset += 8192) {
            $parser1->feed(substr($multipart1, $offset, 8192));
        }

        $target = $this->tempDir . '/fs-root/uploads/resume.bin';
        $this->assertFileExists($target);
        $this->assertSame($firstHalf, file_get_contents($target),
            'After pass 1 the on-disk file should hold exactly the first half — no padding, no buffering past the body.');
        $this->assertSame($halfwayPoint, $context1->file_bytes_written,
            'file_bytes_written must reflect actual on-disk bytes; that is the value we will save into state for resume.');

        // Mimic the crash + reopen path from download_file_data():
        // close the in-flight handle, then on the next request reopen the
        // tracked file in append mode using the previously-saved byte count.
        if ($context1->file_handle) {
            fclose($context1->file_handle);
            $context1->file_handle = null;
        }
        $trackedBytes = $context1->file_bytes_written;

        $context2 = new StreamingContext();
        $context2->file_handle = fopen($target, 'ab');
        $context2->file_path = $target;
        $context2->file_ctime = 1234567890;
        $context2->file_bytes_written = $trackedBytes;
        $context2->on_chunk = function (array $chunk) use ($applier, $context2): void {
            $applier->handle_file_chunk($chunk, $context2);
        };
        $currentChunk2 = null;
        $handler2 = $this->makeChunkHandler($context2, $currentChunk2);
        $parser2 = new MultipartStreamParser('BOUNDARY', $handler2);

        // Pass 2: continuation part for the same file. x-first-chunk=0 is the
        // signal that this is a resume, not a fresh open — handle_file_chunk
        // must NOT re-truncate via fopen("wb"), and must NOT skip the body.
        $multipart2 = $this->buildMultipart('BOUNDARY', [
            [
                'headers' => [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Length' => (string) strlen($secondHalf),
                    'X-Chunk-Type' => 'file',
                    'X-File-Path' => base64_encode('/uploads/resume.bin'),
                    'X-File-Size' => (string) strlen($body),
                    'X-File-Ctime' => '1234567890',
                    'X-Chunk-Offset' => (string) $halfwayPoint,
                    'X-Chunk-Size' => (string) strlen($secondHalf),
                    'X-First-Chunk' => '0',
                    'X-Last-Chunk' => '1',
                ],
                'body' => $secondHalf,
            ],
        ]);
        for ($offset = 0; $offset < strlen($multipart2); $offset += 8192) {
            $parser2->feed(substr($multipart2, $offset, 8192));
        }

        $finalContents = file_get_contents($target);
        $this->assertSame(strlen($body), strlen($finalContents),
            'Final file size must equal source size — anything else means duplicated bytes (overlap) or missing bytes (gap).');
        $this->assertSame($body, $finalContents,
            'Final file must be byte-identical to source after mid-file resume.');
    }

    private function makeApplier(): FileSyncLocalApplier
    {
        return new FileSyncLocalApplier(
            new LocalImportFilesystem(
                $this->tempDir . '/fs-root',
                'error',
                new NullAuditLogger(),
            ),
            new IndexStore(
                $this->tempDir . '/state/.import-index.jsonl',
                $this->tempDir . '/state/.import-index-updates.jsonl',
            ),
            new VolatileFileTracker($this->tempDir . '/state/.import-volatile-files.json'),
            new BufferedImportOutput(),
            $this->tempDir . '/fs-root',
            $this->tempDir . '/state/.import-remote-index.jsonl',
            'error',
            true,
            0,
            null,
            null,
            FilesPullCheckpoint::fresh(),
            new NullAuditLogger(),
            new NullMachineEventEmitter(),
        );
    }

    private function makeChunkHandler(StreamingContext $context, &$currentChunk): callable
    {
        return (new ImportHttpTransport(
            new BufferedImportOutput(),
        ))->make_chunk_handler($context, $currentChunk);
    }

    private function buildMultipart(string $boundary, array $parts): string
    {
        $out = '';
        foreach ($parts as $part) {
            $out .= "--{$boundary}\r\n";
            foreach ($part['headers'] as $name => $value) {
                $out .= "{$name}: {$value}\r\n";
            }
            $out .= "\r\n" . $part['body'] . "\r\n";
        }
        $out .= "--{$boundary}--\r\n";
        return $out;
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
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
}
