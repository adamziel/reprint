<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/import.php';

class PublicUploadDownloadTest extends TestCase
{
    private string $tempDir;
    private string $stateDir;
    private string $fsRoot;
    private $serverProcess = null;
    private array $serverPipes = [];
    private $allProxy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/public-upload-download-test-' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);

        $this->allProxy = getenv('ALL_PROXY');
        putenv('ALL_PROXY=');
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        if ($this->allProxy === false) {
            putenv('ALL_PROXY');
        } else {
            putenv('ALL_PROXY=' . $this->allProxy);
        }
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testDownloadFilesFromListDownloadsPublicUploadDirectly(): void
    {
        $sourceRoot = $this->tempDir . '/source-uploads';
        mkdir($sourceRoot . '/2024/05', 0755, true);
        $contents = str_repeat('direct upload body ', 128);
        file_put_contents($sourceRoot . '/2024/05/space photo.txt', $contents);
        $baseurl = $this->startServer($sourceRoot);

        $remotePath = '/var/www/html/wp-content/uploads/2024/05/space photo.txt';
        $listFile = $this->writeDownloadList([
            [
                'path' => base64_encode($remotePath),
                'ctime' => 1700000000,
                'size' => strlen($contents),
                'type' => 'file',
            ],
        ]);

        $client = $this->makeClient('/var/www/html/wp-content/uploads/', $baseurl);
        $reflection = new \ReflectionClass($client);
        $complete = $reflection->getMethod('download_files_from_list')
            ->invoke($client, $listFile, 'fetch');

        $this->assertTrue($complete);
        $this->assertSame(
            $contents,
            file_get_contents($this->fsRoot . $remotePath),
        );
        $this->assertSame(filesize($listFile), $client->state['fetch']['offset']);
        $this->assertNull($client->state['fetch']['batch_file']);

        $index = $this->readIndex();
        $this->assertCount(1, $index);
        $this->assertSame($remotePath, $index[0]['path']);
        $this->assertSame(strlen($contents), $index[0]['size']);
    }

    public function testPrepareFetchBatchFallsBackWhenPublicUploadDownloadFails(): void
    {
        $sourceRoot = $this->tempDir . '/empty-source-uploads';
        mkdir($sourceRoot, 0755, true);
        $baseurl = $this->startServer($sourceRoot);

        $remotePath = '/var/www/html/wp-content/uploads/2024/05/missing.jpg';
        $listFile = $this->writeDownloadList([
            [
                'path' => base64_encode($remotePath),
                'ctime' => 1700000000,
                'size' => 123,
                'type' => 'file',
            ],
        ]);

        $client = $this->makeClient('/var/www/html/wp-content/uploads/', $baseurl);
        $batch = (new \ReflectionClass($client))->getMethod('prepare_fetch_batch')
            ->invoke($client, $listFile, 0);

        $this->assertNotNull($batch);
        $this->assertSame(1, $batch['entries']);
        $this->assertSame(1, $batch['fallback_entries']);
        $this->assertSame([$remotePath], json_decode(file_get_contents($batch['file']), true));
        $this->assertFileDoesNotExist($this->fsRoot . $remotePath);
        @unlink($batch['file']);
    }

    public function testDownloadFilesFromListResumesPublicUploadWithHttpRange(): void
    {
        $sourceRoot = $this->tempDir . '/range-source-uploads';
        mkdir($sourceRoot . '/2024/05', 0755, true);
        $contents = str_repeat('resumable public upload body ', 256);
        file_put_contents($sourceRoot . '/2024/05/resume-photo.txt', $contents);
        $rangeLog = $this->tempDir . '/range.log';
        $baseurl = $this->startRangeServer($sourceRoot, $rangeLog);

        $ctime = 1700000000;
        $remotePath = '/var/www/html/wp-content/uploads/2024/05/resume-photo.txt';
        $listFile = $this->writeDownloadList([
            [
                'path' => base64_encode($remotePath),
                'ctime' => $ctime,
                'size' => strlen($contents),
                'type' => 'file',
            ],
        ]);

        $localPath = realpath($this->fsRoot) . $remotePath;
        $tmpPath = $localPath . '.reprint-public-download';
        mkdir(dirname($tmpPath), 0755, true);
        $resumeFrom = 37;
        file_put_contents($tmpPath, substr($contents, 0, $resumeFrom));

        $client = $this->makeClient('/var/www/html/wp-content/uploads/', $baseurl);
        $client->state['public_upload'] = [
            'path' => $remotePath,
            'url' => $baseurl . '/2024/05/resume-photo.txt',
            'local_path' => $localPath,
            'tmp_path' => $tmpPath,
            'ctime' => $ctime,
            'size' => strlen($contents),
            'bytes' => $resumeFrom,
        ];

        $complete = (new \ReflectionClass($client))->getMethod('download_files_from_list')
            ->invoke($client, $listFile, 'fetch');

        $this->assertTrue($complete);
        $this->assertSame($contents, file_get_contents($localPath));
        $this->assertFileDoesNotExist($tmpPath);
        $this->assertNull($client->state['public_upload']['path']);
        $this->assertSame(filesize($listFile), $client->state['fetch']['offset']);
        $this->assertStringContainsString(
            'bytes=' . $resumeFrom . '-',
            file_get_contents($rangeLog),
            file_get_contents($this->stateDir . '/.import-audit.log'),
        );
    }

    public function testPrepareFetchBatchResumesUnsupportedPublicRangeViaFileFetchOffset(): void
    {
        $sourceRoot = $this->tempDir . '/no-range-source-uploads';
        mkdir($sourceRoot . '/2024/05', 0755, true);
        $contents = str_repeat('fallback resume body ', 256);
        $sourceFile = $sourceRoot . '/2024/05/resume-photo.txt';
        file_put_contents($sourceFile, $contents);
        clearstatcache(true, $sourceFile);
        $ctime = filectime($sourceFile);
        $rangeLog = $this->tempDir . '/no-range.log';
        $remoteUploads = '/var/www/html/wp-content/uploads/';
        $baseurl = $this->startNoRangeHashServer($sourceRoot, $rangeLog, $remoteUploads);

        $remotePath = $remoteUploads . '2024/05/resume-photo.txt';
        $listFile = $this->writeDownloadList([
            [
                'path' => base64_encode($remotePath),
                'ctime' => $ctime,
                'size' => strlen($contents),
                'type' => 'file',
            ],
        ]);

        $localPath = realpath($this->fsRoot) . $remotePath;
        $tmpPath = $localPath . '.reprint-public-download';
        mkdir(dirname($tmpPath), 0755, true);
        $resumeFrom = 37;
        file_put_contents($tmpPath, substr($contents, 0, $resumeFrom));

        $client = $this->makeClient($remoteUploads, $baseurl, $baseurl);
        $client->state['public_upload'] = [
            'path' => $remotePath,
            'url' => $baseurl . '/2024/05/resume-photo.txt',
            'local_path' => $localPath,
            'tmp_path' => $tmpPath,
            'ctime' => $ctime,
            'size' => strlen($contents),
            'bytes' => $resumeFrom,
        ];

        $batch = (new \ReflectionClass($client))->getMethod('prepare_fetch_batch')
            ->invoke($client, $listFile, 0);

        $this->assertNotNull($batch);
        $this->assertSame(1, $batch['entries']);
        $this->assertSame(1, $batch['fallback_entries']);
        $this->assertSame([$remotePath], json_decode(file_get_contents($batch['file']), true));
        $this->assertSame($localPath, $batch['current_file']);
        $this->assertSame($resumeFrom, $batch['current_file_bytes']);
        $this->assertSame(substr($contents, 0, $resumeFrom), file_get_contents($localPath));
        $this->assertFileDoesNotExist($tmpPath);
        $this->assertStringContainsString('bytes=' . $resumeFrom . '-', file_get_contents($rangeLog));

        $cursor = json_decode(base64_decode($batch['cursor']), true);
        $this->assertSame('streaming', $cursor['phase']);
        $this->assertSame($remotePath, base64_decode($cursor['path']));
        $this->assertSame($ctime, $cursor['ctime']);
        $this->assertSame($resumeFrom, $cursor['bytes']);
        @unlink($batch['file']);
    }

    private function makeClient(string $uploadsBasedir, string $uploadsBaseurl, ?string $remoteUrl = null): \ImportClient
    {
        $client = new \ImportClient($remoteUrl ?? 'http://fake.url', $this->stateDir, $this->fsRoot);
        $client->state = $client->default_state();
        $root = rtrim($uploadsBasedir, '/');
        $needle = '/wp-content/uploads';
        $pos = strpos($root, $needle);
        $root = $pos === false ? dirname($root) : substr($root, 0, $pos);
        $client->state['preflight'] = [
            'data' => [
                'wp_detect' => [
                    'roots' => [
                        ['path' => $root],
                    ],
                ],
                'database' => [
                    'wp' => [
                        'paths_urls' => [
                            'uploads' => [
                                'basedir' => $uploadsBasedir,
                                'baseurl' => $uploadsBaseurl,
                            ],
                        ],
                    ],
                ],
                'limits' => [
                    'max_request_bytes' => 4 * 1024 * 1024,
                ],
            ],
            'http_code' => 200,
        ];
        return $client;
    }

    private function writeDownloadList(array $entries): string
    {
        $listFile = $this->stateDir . '/download-list.jsonl';
        $handle = fopen($listFile, 'w');
        foreach ($entries as $entry) {
            fwrite($handle, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n");
        }
        fclose($handle);
        return $listFile;
    }

    private function readIndex(): array
    {
        $indexFile = $this->stateDir . '/.import-index.jsonl';
        $entries = [];
        foreach (file($indexFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);
            $entry['path'] = base64_decode($entry['path']);
            $entries[] = $entry;
        }
        return $entries;
    }

    private function startRangeServer(string $docroot, string $rangeLog): string
    {
        $router = $this->tempDir . '/range-router.php';
        $serverRoot = $this->tempDir . '/range-server-root';
        mkdir($serverRoot, 0755, true);
        $routerSource = strtr(
            <<<'PHP'
<?php
$uriPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if ($uriPath === '/__ready__') {
    http_response_code(204);
    return;
}

$docroot = realpath(__DOCROOT__);
$file = realpath($docroot . '/' . ltrim($uriPath, '/'));
if (
    $docroot === false ||
    $file === false ||
    strpos($file, $docroot . DIRECTORY_SEPARATOR) !== 0 ||
    !is_file($file)
) {
    http_response_code(404);
    return;
}

$range = $_SERVER['HTTP_RANGE'] ?? '';
file_put_contents(__RANGE_LOG__, $range . PHP_EOL, FILE_APPEND);

$size = filesize($file);
$start = 0;
$status = 200;
if (preg_match('/^bytes=(\d+)-$/', $range, $matches)) {
    $start = (int) $matches[1];
    if ($start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        return;
    }
    $status = 206;
}

http_response_code($status);
header('Accept-Ranges: bytes');
header('Content-Type: application/octet-stream');
header('Content-Length: ' . ($size - $start));
if ($status === 206) {
    header('Content-Range: bytes ' . $start . '-' . ($size - 1) . '/' . $size);
}

$handle = fopen($file, 'rb');
fseek($handle, $start);
fpassthru($handle);
fclose($handle);
PHP
            ,
            [
                '__DOCROOT__' => var_export($docroot, true),
                '__RANGE_LOG__' => var_export($rangeLog, true),
            ],
        );
        file_put_contents($router, $routerSource);
        return $this->startServer($serverRoot, $router);
    }

    private function startNoRangeHashServer(string $docroot, string $rangeLog, string $remoteUploads): string
    {
        $router = $this->tempDir . '/no-range-router.php';
        $serverRoot = $this->tempDir . '/no-range-server-root';
        mkdir($serverRoot, 0755, true);
        $routerSource = strtr(
            <<<'PHP'
<?php
$docroot = realpath(__DOCROOT__);
$remoteUploads = rtrim(__REMOTE_UPLOADS__, '/') . '/';

if (($_GET['endpoint'] ?? '') === 'file_prefix_hash') {
    header('Content-Type: application/json');
    $remotePath = base64_decode($_GET['path'] ?? '', true);
    $bytes = (int) ($_GET['bytes'] ?? 0);
    $expectedSize = (int) ($_GET['size'] ?? -1);
    $expectedCtime = (int) ($_GET['ctime'] ?? -1);
    if (!is_string($remotePath) || strpos($remotePath, $remoteUploads) !== 0) {
        echo json_encode(['ok' => false, 'reason' => 'bad_path']);
        return;
    }
    $relative = substr($remotePath, strlen($remoteUploads));
    $file = realpath($docroot . '/' . $relative);
    if ($file === false || strpos($file, $docroot . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
        echo json_encode(['ok' => false, 'reason' => 'missing']);
        return;
    }
    clearstatcache(true, $file);
    if (filesize($file) !== $expectedSize || filectime($file) !== $expectedCtime || $bytes <= 0) {
        echo json_encode(['ok' => false, 'reason' => 'metadata_mismatch']);
        return;
    }
    $handle = fopen($file, 'rb');
    $hash = hash_init('md5');
    $remaining = $bytes;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(1024 * 1024, $remaining));
        $remaining -= strlen($chunk);
        hash_update($hash, $chunk);
    }
    fclose($handle);
    echo json_encode([
        'ok' => $remaining === 0,
        'algorithm' => 'md5',
        'bytes' => $bytes,
        'hash' => hash_final($hash),
        'size' => $expectedSize,
        'ctime' => $expectedCtime,
    ]);
    return;
}

$uriPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if ($uriPath === '/__ready__') {
    http_response_code(204);
    return;
}

$file = realpath($docroot . '/' . ltrim($uriPath, '/'));
if (
    $docroot === false ||
    $file === false ||
    strpos($file, $docroot . DIRECTORY_SEPARATOR) !== 0 ||
    !is_file($file)
) {
    http_response_code(404);
    return;
}

$range = $_SERVER['HTTP_RANGE'] ?? '';
file_put_contents(__RANGE_LOG__, $range . PHP_EOL, FILE_APPEND);

http_response_code(200);
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($file));
readfile($file);
PHP
            ,
            [
                '__DOCROOT__' => var_export($docroot, true),
                '__RANGE_LOG__' => var_export($rangeLog, true),
                '__REMOTE_UPLOADS__' => var_export($remoteUploads, true),
            ],
        );
        file_put_contents($router, $routerSource);
        return $this->startServer($serverRoot, $router);
    }

    private function startServer(string $docroot, ?string $router = null): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$socket) {
            $this->fail("Failed to allocate test server port: {$errstr}");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr($name, ':'), 1);

        $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($docroot);
        if ($router !== null) {
            $cmd .= ' ' . escapeshellarg($router);
        }
        $this->serverProcess = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $this->serverPipes,
        );
        if (!is_resource($this->serverProcess)) {
            $this->fail('Failed to start PHP test server');
        }

        $baseurl = 'http://127.0.0.1:' . $port;
        for ($i = 0; $i < 30; $i++) {
            $ch = curl_init($baseurl . '/__ready__');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_TIMEOUT => 1,
            ]);
            curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode > 0) {
                return $baseurl;
            }
            usleep(100000);
        }

        $this->fail('PHP test server did not become ready');
    }

    private function stopServer(): void
    {
        foreach ($this->serverPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->serverPipes = [];

        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }
        $this->serverProcess = null;
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
