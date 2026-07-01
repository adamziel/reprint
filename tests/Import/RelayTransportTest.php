<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

class RelayTransportTest extends TestCase
{
    private string $tempDir;
    private \ImportClient $client;
    private \ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/relay-transport-test-' . uniqid();
        mkdir($this->tempDir . '/state', 0755, true);
        mkdir($this->tempDir . '/fs-root', 0755, true);
        $this->client = new \ImportClient('http://source.test/?reprint-api', $this->tempDir . '/state', $this->tempDir . '/fs-root');
        $this->reflection = new \ReflectionClass($this->client);
        $this->reflection->getProperty('state')->setValue($this->client, []);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    public function testRelaySourceRejectsUnknownEndpoints(): void
    {
        $validate = $this->reflection->getMethod('validate_relay_source_request');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Relay source rejected endpoint 'wp_admin'");

        $validate->invoke($this->client, 'wp_admin', 'json', []);
    }

    public function testRelaySourceRejectsUnexpectedEndpointParams(): void
    {
        $validate = $this->reflection->getMethod('validate_relay_source_request');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Relay source rejected parameter 'callback'");

        $validate->invoke($this->client, 'db_index', 'stream', ['callback' => 'phpinfo']);
    }

    public function testRelayRequestParamsExcludeExporterEntrypointQuery(): void
    {
        $build = $this->reflection->getMethod('build_export_request_from_url');

        $request = $build->invoke(
            $this->client,
            'http://source.test/?reprint-api&endpoint=file_index&directory%5B0%5D=%2Fsrv%2Fsite',
            null,
            'stream',
            null,
            null,
        );

        $this->assertSame('file_index', $request['endpoint']);
        $this->assertSame(['/srv/site'], $request['params']['directory']);
        $this->assertArrayNotHasKey('reprint-api', $request['params']);
    }

    public function testRelaySourceAllowsImporterGeneratedParams(): void
    {
        $this->reflection->getProperty('relay_source_allowed_paths')->setValue($this->client, ['/srv/site']);
        $validate = $this->reflection->getMethod('validate_relay_source_request');
        [$fileFetchPostData, $uploadsDir] = $this->createRelayFileListPayload([
            '/srv/site/wp-content/themes/theme/style.css',
        ]);

        $validate->invoke($this->client, 'file_index', 'stream', [
            'batch_size' => 500,
            'directory' => ['/srv/site/wp-content'],
            'follow_symlinks' => 1,
            'include_caches' => 1,
            'list_dir' => '/srv/site/wp-content',
            'max_execution_time' => 5,
            'memory_threshold' => 0.8,
        ]);
        $validate->invoke($this->client, 'file_fetch', 'stream', [
            'chunk_size' => 1048576,
            'directory' => ['/srv/site/wp-content'],
            'max_execution_time' => 5,
            'memory_threshold' => 0.8,
        ], $fileFetchPostData, $uploadsDir);
        $validate->invoke($this->client, 'sql_chunk', 'stream', [
            'db_query_time_limit' => 2,
            'db_unbuffered' => 1,
            'fragments_per_batch' => 25,
            'max_allowed_packet' => 1048576,
            'max_execution_time' => 5,
            'memory_threshold' => 0.8,
            'skip_rows' => 10,
        ]);
        $validate->invoke($this->client, 'db_index', 'stream', [
            'tables_per_batch' => 1000,
        ]);

        $this->addToAssertionCount(1);
    }

    public function testRelaySourceRejectsPostDataForNonFileFetchEndpoints(): void
    {
        $this->reflection->getProperty('relay_source_allowed_paths')->setValue($this->client, ['/srv/site']);
        $validate = $this->reflection->getMethod('validate_relay_source_request');
        [$postData, $uploadsDir] = $this->createRelayFileListPayload(['/srv/site/wp-content/themes/theme/style.css']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Relay source rejected POST data for endpoint 'file_index'");

        $validate->invoke($this->client, 'file_index', 'stream', [
            'directory' => ['/srv/site/wp-content/themes'],
        ], $postData, $uploadsDir);
    }

    public function testRelaySourceRejectsFilePathsOutsideLocalAllowlist(): void
    {
        $this->reflection->getProperty('relay_source_allowed_paths')->setValue($this->client, ['/srv/site/wp-content/themes']);
        $validate = $this->reflection->getMethod('validate_relay_source_request');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside the local allowlist');

        $validate->invoke($this->client, 'file_index', 'stream', [
            'directory' => ['/srv/site/wp-content/uploads'],
        ]);
    }

    public function testRelaySourceAllowsFilePathsInsideLocalAllowlist(): void
    {
        $this->reflection->getProperty('relay_source_allowed_paths')->setValue($this->client, ['/srv/site/wp-content/themes']);
        $validate = $this->reflection->getMethod('validate_relay_source_request');

        $validate->invoke($this->client, 'file_index', 'stream', [
            'directory' => ['/srv/site/wp-content/themes/twentytwentyfive'],
            'list_dir' => '/srv/site/wp-content/themes',
        ]);

        $this->addToAssertionCount(1);
    }

    public function testRelaySourceRejectsFileFetchListPathsOutsideLocalAllowlist(): void
    {
        $this->reflection->getProperty('relay_source_allowed_paths')->setValue($this->client, ['/srv/site/wp-content/themes']);
        $validate = $this->reflection->getMethod('validate_relay_source_request');
        [$postData, $uploadsDir] = $this->createRelayFileListPayload([
            '/srv/site/wp-content/uploads/private.txt',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('file_fetch file_list path outside the local allowlist');

        $validate->invoke($this->client, 'file_fetch', 'stream', [
            'directory' => ['/srv/site/wp-content/themes'],
        ], $postData, $uploadsDir);
    }

    public function testRelaySourceRejectsUnexpectedFileFetchPostFields(): void
    {
        $this->reflection->getProperty('relay_source_allowed_paths')->setValue($this->client, ['/srv/site']);
        $validate = $this->reflection->getMethod('validate_relay_source_request');
        [$postData, $uploadsDir] = $this->createRelayFileListPayload(['/srv/site/wp-content/themes/theme/style.css']);
        $postData['extra'] = ['type' => 'value', 'value' => 'unexpected'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected POST fields');

        $validate->invoke($this->client, 'file_fetch', 'stream', [
            'directory' => ['/srv/site/wp-content/themes'],
        ], $postData, $uploadsDir);
    }

    public function testRelaySourceRejectsOversizedFileFetchList(): void
    {
        $this->reflection->getProperty('relay_source_allowed_paths')->setValue($this->client, ['/srv/site']);
        $validate = $this->reflection->getMethod('validate_relay_source_request');
        $uploadsDir = $this->tempDir . '/relay/uploads-' . uniqid();
        mkdir($uploadsDir, 0755, true);
        $upload = 'oversized-file-list.upload';
        $path = $uploadsDir . '/' . $upload;
        $handle = fopen($path, 'wb');
        $this->assertIsResource($handle);
        fseek($handle, 8 * 1024 * 1024);
        fwrite($handle, 'x');
        fclose($handle);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('oversized file_fetch file_list');

        $validate->invoke($this->client, 'file_fetch', 'stream', [
            'directory' => ['/srv/site'],
        ], [
            'file_list' => [
                'type' => 'file',
                'upload' => $upload,
                'name' => 'file_list',
                'mime' => 'application/json',
            ],
        ], $uploadsDir);
    }

    public function testRelayUploadPayloadsUseSidecarFilesInsteadOfJsonContents(): void
    {
        $uploadsDir = $this->tempDir . '/relay/uploads';
        mkdir($uploadsDir, 0755, true);
        $sourceFile = $this->tempDir . '/file-list.json';
        file_put_contents($sourceFile, json_encode(['/srv/site/wp-content/themes/theme/style.css']));

        $serialize = $this->reflection->getMethod('serialize_relay_post_data');
        $payload = $serialize->invoke($this->client, 'req-test', $uploadsDir, [
            'file_list' => new \CURLFile($sourceFile, 'application/json', 'file_list'),
        ]);

        $this->assertArrayHasKey('upload', $payload['file_list']);
        $this->assertArrayNotHasKey('contents', $payload['file_list']);
        $this->assertSame(file_get_contents($sourceFile), file_get_contents($uploadsDir . '/' . $payload['file_list']['upload']));
    }

    public function testRelayJsonMetadataAcceptsBinaryBodyPreview(): void
    {
        $write = $this->reflection->getMethod('write_json_file_atomically');
        $path = $this->tempDir . '/relay-response.json';

        $write->invoke($this->client, $path, [
            'request_id' => 'req-binary-preview',
            'body_preview' => "\xff",
        ]);

        $decoded = json_decode(file_get_contents($path), true);
        $this->assertSame('req-binary-preview', $decoded['request_id']);
        $this->assertSame("�", $decoded['body_preview']);
    }

    public function testRemoteRelayResponseBodyUploadUsesRawPostBody(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required to start the local capture server.');
        }

        $recordFile = $this->tempDir . '/capture.json';
        $router = $this->tempDir . '/capture-router.php';
        file_put_contents($router, <<<'PHP'
<?php
$body = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];
$content_hash = $headers['X-Auth-Content-Hash'] ?? ($_SERVER['HTTP_X_AUTH_CONTENT_HASH'] ?? '');
file_put_contents(getenv('RECORD_FILE'), json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
    'content_length' => isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null,
    'content_hash' => $content_hash,
    'body_hash' => hash('sha256', $body),
    'files' => count($_FILES),
]));
header('Content-Type: application/json');
echo json_encode(['ok' => true]);
PHP);

        $bodyFile = $this->tempDir . '/response.body';
        file_put_contents($bodyFile, str_repeat('raw-response-body', 1024));
        $port = $this->findFreePort();
        $process = $this->startPhpServer($port, $router, $recordFile);

        try {
            $this->reflection->getProperty('relay_url')->setValue(
                $this->client,
                "http://127.0.0.1:{$port}/?reprint-push-api",
            );
            $this->reflection->getProperty('relay_session')->setValue($this->client, 'session-test');
            $this->reflection->getProperty('relay_hmac_client')->setValue(
                $this->client,
                new \Site_Export_HMAC_Client('secret'),
            );

            $upload = $this->reflection->getMethod('relay_api_upload_response_body');
            $upload->invoke($this->client, 'req-test', $bodyFile);

            $record = json_decode(file_get_contents($recordFile), true);
            $this->assertSame('POST', $record['method']);
            $this->assertSame('application/octet-stream', $record['content_type']);
            $this->assertSame(filesize($bodyFile), $record['content_length']);
            $this->assertSame(hash_file('sha256', $bodyFile), $record['content_hash']);
            $this->assertSame(hash_file('sha256', $bodyFile), $record['body_hash']);
            $this->assertSame(0, $record['files']);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function testExpiredProcessingRequestsAreRequeued(): void
    {
        $requestsDir = $this->tempDir . '/relay/requests';
        $processingDir = $this->tempDir . '/relay/processing';
        mkdir($requestsDir, 0755, true);
        mkdir($processingDir, 0755, true);
        $processingFile = $processingDir . '/req-old.json';
        file_put_contents($processingFile, '{}');
        touch($processingFile, time() - 600);
        $this->reflection->getProperty('relay_timeout')->setValue($this->client, 1);

        $claim = $this->reflection->getMethod('claim_next_relay_request');
        $claimed = $claim->invoke($this->client, $requestsDir, $processingDir);

        $this->assertSame($processingDir . '/req-old.json', $claimed);
        $this->assertFileDoesNotExist($requestsDir . '/req-old.json');
    }

    public function testRelayRequestActivityUsesProcessingHeartbeat(): void
    {
        $requestsDir = $this->tempDir . '/relay/requests';
        $processingDir = $this->tempDir . '/relay/processing';
        mkdir($requestsDir, 0755, true);
        mkdir($processingDir, 0755, true);
        $requestFile = $requestsDir . '/req-heartbeat.json';
        $processingFile = $processingDir . '/req-heartbeat.json';
        file_put_contents($requestFile, '{}');
        file_put_contents($processingFile, '{}');
        touch($requestFile, time() - 600);
        touch($processingFile, time() - 10);

        $lastActivity = $this->reflection->getMethod('relay_request_last_activity')->invoke(
            $this->client,
            $requestFile,
            $processingFile,
        );

        $this->assertSame(filemtime($processingFile), $lastActivity);
    }

    public function testRelayResponsePublishingKeepsFirstMetadataAndBody(): void
    {
        $responsesDir = $this->tempDir . '/relay/responses';
        mkdir($responsesDir, 0755, true);
        $firstBody = $this->tempDir . '/first.body';
        $lateBody = $this->tempDir . '/late.body';
        file_put_contents($firstBody, 'first');
        file_put_contents($lateBody, 'late');
        $publish = $this->reflection->getMethod('publish_relay_response_once');

        $firstPublished = $publish->invoke($this->client, $responsesDir, 'req-once', [
            'request_id' => 'req-once',
            'body_file' => $firstBody,
        ]);
        $latePublished = $publish->invoke($this->client, $responsesDir, 'req-once', [
            'request_id' => 'req-once',
            'body_file' => $lateBody,
        ]);

        $metadata = json_decode(file_get_contents($responsesDir . '/req-once.json'), true);
        $this->assertTrue($firstPublished);
        $this->assertFalse($latePublished);
        $this->assertSame($firstBody, $metadata['body_file']);
    }

    public function testHeartbeatTouchDoesNotCreateMissingLease(): void
    {
        $lease = $this->tempDir . '/relay/processing/missing.json';
        $touch = $this->reflection->getMethod('touch_existing_file');

        $this->assertFalse($touch->invoke($this->client, $lease));
        $this->assertFileDoesNotExist($lease);
    }

    private function createRelayFileListPayload(array $paths): array
    {
        $uploadsDir = $this->tempDir . '/relay/uploads-' . uniqid();
        mkdir($uploadsDir, 0755, true);
        $sourceFile = $this->tempDir . '/file-list-' . uniqid() . '.json';
        file_put_contents($sourceFile, json_encode($paths));

        $serialize = $this->reflection->getMethod('serialize_relay_post_data');
        $payload = $serialize->invoke($this->client, 'req-test-' . uniqid(), $uploadsDir, [
            'file_list' => new \CURLFile($sourceFile, 'application/json', 'file_list'),
        ]);

        return [$payload, $uploadsDir];
    }

    private function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($socket)) {
            throw new \RuntimeException("Cannot find free port: {$errstr}");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    private function startPhpServer(int $port, string $router, string $recordFile)
    {
        $command = sprintf(
            '%s -d display_startup_errors=0 -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg($router),
        );
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->tempDir . '/server.out', 'a'],
            2 => ['file', $this->tempDir . '/server.err', 'a'],
        ], $pipes, $this->tempDir, [
            'RECORD_FILE' => $recordFile,
        ]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start PHP capture server.');
        }

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if (is_resource($socket)) {
                fclose($socket);
                return $process;
            }
            usleep(100000);
        }

        proc_terminate($process);
        proc_close($process);
        throw new \RuntimeException('PHP capture server did not start.');
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
            is_dir($path) && !is_link($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }
}
