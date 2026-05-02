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

    public function testFileFetchFallsBackToIdentityWhenAnyPathIsBinary(): void
    {
        // Mixed batches must err on the side of identity: response-level
        // Content-Encoding can't be flipped per part, so a single binary
        // in the list disables gzip for the whole response. This preserves
        // the binary fast path the importer just shipped — the alternative
        // (gzip the whole thing) would re-introduce the buffer-stall
        // behavior we're explicitly avoiding.
        $siteDir = $this->tempDir . '/site';
        mkdir($siteDir, 0755, true);
        $textPath = $siteDir . '/style.css';
        $binaryPath = $siteDir . '/photo.jpg';
        file_put_contents($textPath, str_repeat("body { color: red; }\n", 200));
        file_put_contents($binaryPath, 'pretend-jpeg-bytes');

        $stdout = $this->runFileFetch($siteDir, [$textPath, $binaryPath]);

        $this->assertStringStartsWith('--boundary-', $stdout);
        $this->assertFalse(@gzdecode($stdout), 'mixed batch should fall back to identity');
        $this->assertStringContainsString('body { color: red; }', $stdout);
        $this->assertStringContainsString('pretend-jpeg-bytes', $stdout);
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

    private function runFileFetch(string $siteDir, array $paths): string
    {
        $listPath = $this->tempDir . '/file-list.json';
        file_put_contents($listPath, json_encode($paths, JSON_THROW_ON_ERROR));

        $configPath = $this->tempDir . '/config.json';
        file_put_contents($configPath, json_encode([
            'directory' => $siteDir,
            'file_list_path' => $listPath,
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
