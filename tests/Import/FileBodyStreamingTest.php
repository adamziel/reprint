<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

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
        $client = new \ImportClient(
            'http://fake.url',
            $this->tempDir . '/state',
            $this->tempDir . '/fs-root',
        );
        $reflection = new \ReflectionClass($client);
        $reflection->getProperty('is_tty')->setValue($client, true);
        $reflection->getProperty('state')->setValue($client, []);

        $handleFileChunk = $reflection->getMethod('handle_file_chunk');
        $context = new \StreamingContext();
        $bodyLengths = [];
        $context->on_chunk = function (array $chunk) use ($client, $handleFileChunk, $context, &$bodyLengths): void {
            if (($chunk['headers']['x-chunk-type'] ?? '') === 'file') {
                $bodyLengths[] = strlen($chunk['body'] ?? '');
            }
            $handleFileChunk->invoke($client, $chunk, $context);
        };

        $currentChunk = null;
        $makeHandler = $reflection->getMethod('make_chunk_handler');
        $handler = $makeHandler->invokeArgs($client, [$context, &$currentChunk]);
        $parser = new \MultipartStreamParser('BOUNDARY', $handler);

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
