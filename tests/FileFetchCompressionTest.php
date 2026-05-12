<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FileFetchCompressionTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/file-fetch-compression-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testFileFetchUsesIdentityForBinaryPaths(): void
    {
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $filePath = $siteDir . '/photo.jpg';
        file_put_contents($filePath, 'pretend-jpeg-bytes');

        $stdout = $this->runFileFetch($siteDir, [$filePath]);

        $this->assertStringStartsWith('--boundary-', $stdout);
        $this->assertFalse(@gzdecode($stdout), 'binary file_fetch should not be gzip framed');
        $this->assertStringContainsString('pretend-jpeg-bytes', $stdout);
    }

    public function testFileFetchUsesGzipForTextOnlyPaths(): void
    {
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $filePath = $siteDir . '/style.css';
        // Use repetitive content so gzip output is unambiguously smaller than input.
        file_put_contents($filePath, str_repeat("body { color: red; }\n", 200));

        $stdout = $this->runFileFetch($siteDir, [$filePath]);

        // gzip framing means the response starts with the deflate magic bytes,
        // not the multipart boundary — the boundary lives inside the
        // compressed stream.
        $this->assertSame("\x1f\x8b", substr($stdout, 0, 2), 'text-only file_fetch should be gzip framed');
        $decoded = gzdecode($stdout);
        $this->assertNotFalse($decoded, 'gzip body should decode');
        $this->assertStringStartsWith('--boundary-', $decoded);
        $this->assertStringContainsString('body { color: red; }', $decoded);
    }

    public function testFileFetchUsesPerPartGzipForMixedBatches(): void
    {
        // Mixed batches keep the HTTP response identity, but gzip the
        // compressible file part body. That avoids spending CPU deflating
        // already-compressed media while preserving the text-file wire win.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $textPath = $siteDir . '/style.css';
        $binaryPath = $siteDir . '/photo.jpg';
        file_put_contents($textPath, str_repeat("body { color: red; }\n", 200));
        file_put_contents($binaryPath, str_repeat('pretend-jpeg-bytes', 128 * 1024));

        $stdout = $this->runFileFetch($siteDir, [$textPath, $binaryPath]);

        $this->assertStringStartsWith('--boundary-', $stdout);
        $this->assertFalse(@gzdecode($stdout), 'mixed file_fetch should not use response-level gzip');

        $textPart = $this->findFilePart($stdout, $textPath);
        $this->assertSame('gzip', $textPart['headers']['x-body-encoding'] ?? null);
        $this->assertSame('body { color: red; }', substr((string) gzdecode($textPart['body']), 0, 20));

        $binaryPart = $this->findFilePart($stdout, $binaryPath);
        $this->assertArrayNotHasKey('x-body-encoding', $binaryPart['headers']);
        $this->assertStringStartsWith('pretend-jpeg-bytes', $binaryPart['body']);
    }

    public function testFileFetchKeepsResponseGzipForMixedBatchesWithoutCapability(): void
    {
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $textPath = $siteDir . '/style.css';
        $binaryPath = $siteDir . '/photo.jpg';
        file_put_contents($textPath, str_repeat("body { color: red; }\n", 200));
        file_put_contents($binaryPath, 'pretend-jpeg-bytes');

        $stdout = $this->runFileFetch($siteDir, [$textPath, $binaryPath], false);

        $this->assertSame("\x1f\x8b", substr($stdout, 0, 2));
        $decoded = gzdecode($stdout);
        $this->assertNotFalse($decoded);
        $this->assertStringContainsString('body { color: red; }', $decoded);
        $this->assertStringContainsString('pretend-jpeg-bytes', $decoded);
        $this->assertStringNotContainsString('X-Body-Encoding: gzip', $decoded);
    }

    public function testFileFetchKeepsIdentityWhenAllPathsAreBinary(): void
    {
        // Pure-binary batch — no compressible files at all. Must stay
        // identity; otherwise we'd burn server CPU re-deflating already
        // compressed image bytes for ~0 % wire reduction.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $jpg = $siteDir . '/photo.jpg';
        $png = $siteDir . '/icon.png';
        $mp4 = $siteDir . '/clip.mp4';
        file_put_contents($jpg, 'jpeg-blob');
        file_put_contents($png, 'png-blob');
        file_put_contents($mp4, 'mp4-blob');

        $stdout = $this->runFileFetch($siteDir, [$jpg, $png, $mp4]);

        $this->assertStringStartsWith('--boundary-', $stdout);
        $this->assertFalse(@gzdecode($stdout), 'all-binary batch must stay identity');
        $this->assertStringContainsString('jpeg-blob', $stdout);
        $this->assertStringContainsString('png-blob', $stdout);
        $this->assertStringContainsString('mp4-blob', $stdout);
    }

    public function testFileFetchUsesPerPartGzipWhenOnlyOneFileIsCompressible(): void
    {
        // Edge case: many binary files + one readme. Only the readme part
        // should be encoded; media parts stay raw.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $paths = [];
        for ($i = 0; $i < 8; $i++) {
            $p = $siteDir . sprintf('/blob-%d.jpg', $i);
            file_put_contents($p, str_repeat('binary-' . $i, 32 * 1024));
            $paths[] = $p;
        }
        $readme = $siteDir . '/README';
        file_put_contents($readme, str_repeat("Welcome to the plugin.\n", 100));
        $paths[] = $readme;

        $stdout = $this->runFileFetch($siteDir, $paths);
        $this->assertStringStartsWith('--boundary-', $stdout);
        $readmePart = $this->findFilePart($stdout, $readme);
        $this->assertSame('gzip', $readmePart['headers']['x-body-encoding'] ?? null);
        $this->assertStringContainsString('Welcome to the plugin.', (string) gzdecode($readmePart['body']));
        $this->assertStringStartsWith('binary-0', $this->findFilePart($stdout, $paths[0])['body']);
        $this->assertStringStartsWith('binary-7', $this->findFilePart($stdout, $paths[7])['body']);
    }

    public function testFileFetchUsesPerPartGzipWithUnknownTextAndKnownBinary(): void
    {
        // Mixed: an unknown-extension file with text bytes (sniffer says
        // text) plus a known-binary jpeg. The unknown-text part gets gzip.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $unknownText = $siteDir . '/config.neon';
        file_put_contents($unknownText, str_repeat("services: { foo: Foo\\Bar }\n", 100));
        $jpg = $siteDir . '/photo.jpg';
        file_put_contents($jpg, str_repeat('jpeg-blob', 128 * 1024));

        $stdout = $this->runFileFetch($siteDir, [$unknownText, $jpg]);
        $this->assertStringStartsWith('--boundary-', $stdout);
        $unknownTextPart = $this->findFilePart($stdout, $unknownText);
        $this->assertSame('gzip', $unknownTextPart['headers']['x-body-encoding'] ?? null);
        $this->assertStringContainsString('services:', (string) gzdecode($unknownTextPart['body']));
        $this->assertStringStartsWith('jpeg-blob', $this->findFilePart($stdout, $jpg)['body']);
    }

    public function testFileFetchKeepsIdentityWithUnknownBinaryAndKnownBinary(): void
    {
        // No compressible file in the batch — even with unknown extensions,
        // sniffer says binary → no gzip.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $unknownBin = $siteDir . '/blob.weirdext';
        file_put_contents($unknownBin, "\x00\xff\x01\xfe" . random_bytes(2048));
        $jpg = $siteDir . '/photo.jpg';
        file_put_contents($jpg, 'jpeg-blob');

        $stdout = $this->runFileFetch($siteDir, [$unknownBin, $jpg]);
        $this->assertStringStartsWith('--boundary-', $stdout);
        $this->assertFalse(@gzdecode($stdout), 'no compressible file → identity');
    }

    public function testFileFetchUsesPerPartGzipWithExtensionlessTextAndImage(): void
    {
        // .htaccess + a screenshot — common theme/plugin distribution shape.
        // Only the text part should be gzip encoded.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $ht = $siteDir . '/.htaccess';
        file_put_contents($ht, str_repeat("RewriteRule ^index\\.php$ - [L]\n", 200));
        $png = $siteDir . '/screenshot.png';
        file_put_contents($png, str_repeat('pretend-png-bytes', 128 * 1024));

        $stdout = $this->runFileFetch($siteDir, [$ht, $png]);
        $this->assertStringStartsWith('--boundary-', $stdout);
        $htPart = $this->findFilePart($stdout, $ht);
        $this->assertSame('gzip', $htPart['headers']['x-body-encoding'] ?? null);
        $this->assertStringContainsString('RewriteRule', (string) gzdecode($htPart['body']));
        $this->assertStringStartsWith('pretend-png-bytes', $this->findFilePart($stdout, $png)['body']);
    }

    public function testFileFetchKeepsResponseGzipForTextHeavySmallMixedBatches(): void
    {
        // Many small text files plus a tiny binary file are better as one gzip
        // response: the shared dictionary beats thousands of isolated gzip
        // members, and the binary side is too small to matter.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $paths = [];
        for ($i = 0; $i < 200; $i++) {
            $p = $siteDir . sprintf('/snippet-%03d.php', $i);
            file_put_contents($p, str_repeat("<?php echo 'hello';\n", 100));
            $paths[] = $p;
        }
        $tinyBinary = $siteDir . '/tiny-logo.jpg';
        file_put_contents($tinyBinary, str_repeat('tiny-jpeg', 1024));
        $paths[] = $tinyBinary;

        $stdout = $this->runFileFetch($siteDir, $paths);

        $this->assertSame("\x1f\x8b", substr($stdout, 0, 2));
        $decoded = gzdecode($stdout);
        $this->assertNotFalse($decoded);
        $this->assertStringContainsString("<?php echo 'hello';", $decoded);
        $this->assertStringNotContainsString('X-Body-Encoding: gzip', $decoded);
    }

    public function testFileFetchEmptyListStaysIdentity(): void
    {
        // Empty path list — nothing to compress → identity. (The endpoint
        // itself probably won't accept this, but the heuristic must.)
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        require_once __DIR__ . '/../packages/reprint-exporter/src/export.php';
        $this->assertFalse(file_fetch_paths_should_gzip([]));
    }

    public function testFileFetchHeuristicDirectly(): void
    {
        // Direct unit test of the heuristic — broader coverage than the
        // in-process file_fetch tests above.
        require_once __DIR__ . '/../packages/reprint-exporter/src/export.php';

        // Single-file cases.
        $this->assertTrue(file_fetch_paths_should_gzip(['a.php']),                     'single text');
        $this->assertFalse(file_fetch_paths_should_gzip(['photo.jpg']),                'single binary');
        $this->assertTrue(file_fetch_paths_should_gzip(['.htaccess']),                 'single extensionless');

        // Multi-file without file-part capability keeps old response-gzip behavior.
        $this->assertTrue(file_fetch_paths_should_gzip(['a.php', 'b.jpg']),            'text + binary');
        $this->assertTrue(file_fetch_paths_should_gzip(['a.jpg', 'b.css', 'c.mp4']),   'majority binary, one text');
        $this->assertFalse(file_fetch_paths_should_gzip(['a.jpg', 'b.png', 'c.mp4']),  'all binary');

        $this->assertSame('response-gzip', file_fetch_compression_mode(['a.php', 'b.css']));
        $this->assertSame('response-gzip', file_fetch_compression_mode(['a.php', 'b.jpg']));
        $this->assertSame('file-parts', file_fetch_compression_mode(['a.php', 'b.jpg'], true));
        $this->assertSame('identity', file_fetch_compression_mode(['a.jpg', 'b.png']));

        // Bad input — defensive.
        $this->assertFalse(file_fetch_paths_should_gzip([null]),                       /** @phpstan-ignore-line */
            'non-string entry rejects the batch');
        $this->assertFalse(file_fetch_paths_should_gzip([42]),                         /** @phpstan-ignore-line */
            'numeric entry rejects the batch');
    }

    public function testMixedBatchHeuristicUsesMeasuredSizesWhenDirectoriesAreKnown(): void
    {
        require_once __DIR__ . '/../packages/reprint-exporter/src/export.php';

        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);

        $largeText = $siteDir . '/large.css';
        $largeBinary = $siteDir . '/large.jpg';
        file_put_contents($largeText, str_repeat('body { color: red; }', 64 * 1024));
        file_put_contents($largeBinary, str_repeat('jpeg-blob', 256 * 1024));
        $this->assertSame(
            'file-parts',
            file_fetch_compression_mode(['large.css', 'large.jpg'], true, [$siteDir])
        );

        $smallBinary = $siteDir . '/small.jpg';
        file_put_contents($smallBinary, str_repeat('jpeg-blob', 1024));
        $this->assertSame(
            'response-gzip',
            file_fetch_compression_mode(['large.css', 'small.jpg'], true, [$siteDir])
        );
    }

    public function testUnknownExtensionWithTextContentGetsGzipped(): void
    {
        // A made-up text format the whitelist doesn't know about. The byte
        // sniffer should rescue it: first 64 bytes are clean ASCII, no NULs,
        // valid UTF-8.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $filePath = $siteDir . '/config.neon';
        file_put_contents($filePath, str_repeat("services: { foo: Foo\\Bar }\n", 200));

        $stdout = $this->runFileFetch($siteDir, [$filePath]);

        $this->assertSame("\x1f\x8b", substr($stdout, 0, 2), 'unknown text extension should sniff as text and gzip');
        $decoded = gzdecode($stdout);
        $this->assertNotFalse($decoded);
        $this->assertStringContainsString('services:', $decoded);
    }

    public function testUnknownExtensionWithBinaryContentStaysIdentity(): void
    {
        // Same unknown extension, but the bytes are binary (NULs + high-bit
        // junk). The sniffer should reject and the response should be identity.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $filePath = $siteDir . '/blob.weirdext';
        file_put_contents($filePath, "\x00\xff\x01\xfe" . random_bytes(2048));

        $stdout = $this->runFileFetch($siteDir, [$filePath]);

        $this->assertStringStartsWith('--boundary-', $stdout);
        $this->assertFalse(@gzdecode($stdout), 'unknown binary extension should sniff as binary and stay identity');
    }

    public function testFileFetchTreatsExtensionlessFilesAsCompressible(): void
    {
        // .htaccess, LICENSE, README, etc. — pathinfo() reports an empty
        // extension for these, but they're text files in practice, so we
        // gzip them. Important specifically for WordPress: `.htaccess`
        // ships in nearly every site and is plain text.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $filePath = $siteDir . '/.htaccess';
        file_put_contents($filePath, str_repeat("RewriteRule ^index\\.php$ - [L]\n", 200));

        $stdout = $this->runFileFetch($siteDir, [$filePath]);

        $this->assertSame("\x1f\x8b", substr($stdout, 0, 2), '.htaccess should be gzip framed');
        $decoded = gzdecode($stdout);
        $this->assertNotFalse($decoded);
        $this->assertStringContainsString('RewriteRule', $decoded);
    }

    /**
     * @dataProvider pathExtensionCases
     */
    public function testPathExtensionClassifier(string $path, bool $expected): void
    {
        require_once __DIR__ . '/../packages/reprint-exporter/src/export.php';
        $this->assertSame($expected, path_extension_is_compressible($path), "classifier for $path");
    }

    public static function pathExtensionCases(): array
    {
        return [
            'php source' => ['/site/wp-content/plugins/foo/foo.php', true],
            'js source' => ['/site/script.js', true],
            'css' => ['/site/style.css', true],
            'json config' => ['/site/composer.json', true],
            'sql dump' => ['/site/dump.sql', true],
            'pot translation' => ['/site/lang/en.pot', true],
            'svg vector' => ['/site/logo.svg', true],
            '.htaccess no extension' => ['/site/.htaccess', true],
            'README' => ['/site/README', true],
            'jpeg upload' => ['/site/uploads/photo.jpg', false],
            'png upload' => ['/site/uploads/icon.png', false],
            'webp upload' => ['/site/uploads/banner.webp', false],
            'mp4 video' => ['/site/uploads/clip.mp4', false],
            'mp3 audio' => ['/site/uploads/podcast.mp3', false],
            'woff2 font' => ['/site/fonts/inter.woff2', false],
            'pdf doc' => ['/site/uploads/brochure.pdf', false],
            'zip archive' => ['/site/backup.zip', false],
            'random binary' => ['/site/uploads/blob.bin', false],
            'gzipped already' => ['/site/dump.sql.gz', false],
            // Case-insensitive — uploads with screaming extensions are real.
            'uppercase JPG' => ['/site/uploads/PHOTO.JPG', false],
            'mixed-case Css' => ['/site/Style.Css', true],
        ];
    }

    private function findFilePart(string $multipart, string $path): array
    {
        foreach ($this->parseMultipart($multipart) as $part) {
            if (($part['headers']['x-chunk-type'] ?? '') !== 'file') {
                continue;
            }
            $partPath = base64_decode($part['headers']['x-file-path'] ?? '', true);
            if ($partPath === $path) {
                return $part;
            }
        }
        $this->fail("File part not found for {$path}");
    }

    private function parseMultipart(string $multipart): array
    {
        $lineEnd = strpos($multipart, "\n");
        $this->assertNotFalse($lineEnd, 'multipart response should start with a boundary line');
        $boundaryLine = rtrim(substr($multipart, 0, $lineEnd), "\r\n");
        $this->assertStringStartsWith('--boundary-', $boundaryLine);

        $boundary = substr($boundaryLine, 2);
        $parts = [];
        foreach (explode('--' . $boundary, $multipart) as $segment) {
            $segment = ltrim($segment, "\r\n");
            if ($segment === '' || strncmp($segment, '--', 2) === 0) {
                continue;
            }
            $headerEnd = strpos($segment, "\r\n\r\n");
            $this->assertNotFalse($headerEnd, 'multipart part should contain a header/body separator');
            $headerBlock = substr($segment, 0, $headerEnd);
            $body = substr($segment, $headerEnd + 4);
            if (substr($body, -2) === "\r\n") {
                $body = substr($body, 0, -2);
            }

            $headers = [];
            foreach (explode("\r\n", $headerBlock) as $line) {
                $colon = strpos($line, ':');
                if ($colon === false) {
                    continue;
                }
                $headers[strtolower(substr($line, 0, $colon))] = ltrim(substr($line, $colon + 1));
            }
            $parts[] = [
                'headers' => $headers,
                'body' => $body,
            ];
        }

        return $parts;
    }

    private function runFileFetch(string $siteDir, array $paths, bool $filePartGzip = true): string
    {
        $listPath = $this->tempDir . '/file-list.json';
        file_put_contents($listPath, json_encode($paths, JSON_THROW_ON_ERROR));

        $configPath = $this->tempDir . '/config.json';
        file_put_contents($configPath, json_encode([
            'directory' => $siteDir,
            'file_list_path' => $listPath,
            'file_part_gzip' => $filePartGzip,
        ], JSON_THROW_ON_ERROR));

        $scriptPath = $this->tempDir . '/run-file-fetch.php';
        file_put_contents(
            $scriptPath,
            sprintf(
                <<<'PHP'
<?php
declare(strict_types=1);
require_once %s;
$config = json_decode(file_get_contents(%s), true, 512, JSON_THROW_ON_ERROR);
$budget = new ResourceBudget(microtime(true), 10, 128 * 1024 * 1024, 0.9);
endpoint_file_fetch($config, $budget);
PHP,
                var_export(dirname(__DIR__) . '/packages/reprint-exporter/src/export.php', true),
                var_export($configPath, true),
            ),
        );

        $command = sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($scriptPath));
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes);
        $this->assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, "file_fetch should exit cleanly.\nstderr: {$stderr}");

        return $stdout;
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
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveDelete($path);
                continue;
            }
            unlink($path);
        }
        rmdir($dir);
    }
}
