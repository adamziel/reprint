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

    private function makeClient(string $uploadsBasedir, string $uploadsBaseurl): \ImportClient
    {
        $client = new \ImportClient('http://fake.url', $this->stateDir, $this->fsRoot);
        $client->state = $client->default_state();
        $client->state['preflight'] = [
            'data' => [
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

    private function startServer(string $docroot): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$socket) {
            $this->fail("Failed to allocate test server port: {$errstr}");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr($name, ':'), 1);

        $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($docroot);
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
