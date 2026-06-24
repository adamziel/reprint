<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('SITE_EXPORT_PLUGIN_DIR')) {
    define('SITE_EXPORT_PLUGIN_DIR', sys_get_temp_dir() . '/site-export-push-plugin-' . getmypid() . '/');
}
if (!defined('SITE_EXPORT_PUSH_BASE_DIR')) {
    define('SITE_EXPORT_PUSH_BASE_DIR', sys_get_temp_dir() . '/site-export-push-sessions-' . getmypid());
}

require_once __DIR__ . '/../reprint-exporter-wp/push.php';

final class SiteExportPushSessionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->recursiveDelete(SITE_EXPORT_PUSH_BASE_DIR);
        if (!is_dir(SITE_EXPORT_PLUGIN_DIR)) {
            mkdir(SITE_EXPORT_PLUGIN_DIR, 0755, true);
        }
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        $_FILES = [];
        $this->recursiveDelete(SITE_EXPORT_PUSH_BASE_DIR);
        parent::tearDown();
    }

    public function testPluginOwnedSessionClaimsTargetAuthoredRequestWithUploadSidecar(): void
    {
        $created = _site_export_push_create_session([
            'source_url' => 'http://local.test/?reprint-api',
        ]);
        $sessionId = $created['session_id'];
        $sessionDir = _site_export_push_session_dir($sessionId);

        file_put_contents(
            $sessionDir . '/relay/uploads/req-test-file-list.json',
            json_encode(['/srv/source/wp-content/themes/theme/style.css'])
        );
        _site_export_push_write_json_file($sessionDir . '/relay/requests/req-test.json', [
            'protocol' => 1,
            'request_id' => 'req-test',
            'kind' => 'stream',
            'endpoint' => 'file_fetch',
            'params' => ['directory' => ['/srv/source/wp-content/themes']],
            'post_data' => [
                'file_list' => [
                    'type' => 'file',
                    'upload' => 'req-test-file-list.json',
                    'name' => 'file_list',
                    'mime' => 'application/json',
                    'size' => 52,
                ],
            ],
        ]);

        $claim = _site_export_push_claim_request($sessionId);

        $this->assertTrue($claim['ok']);
        $this->assertSame('req-test', $claim['request']['request_id']);
        $this->assertArrayHasKey('req-test-file-list.json', $claim['uploads']);
        $this->assertFileDoesNotExist($sessionDir . '/relay/requests/req-test.json');
        $this->assertFileExists($sessionDir . '/relay/processing/req-test.json');
    }

    public function testResponseBodyIsStoredBeforeMetadataUnblocksImporter(): void
    {
        $created = _site_export_push_create_session([
            'source_url' => 'http://local.test/?reprint-api',
        ]);
        $sessionId = $created['session_id'];
        $sessionDir = _site_export_push_session_dir($sessionId);
        _site_export_push_write_json_file($sessionDir . '/relay/processing/req-body.json', [
            'request_id' => 'req-body',
        ]);
        $body = SITE_EXPORT_PUSH_BASE_DIR . '/body.bin';
        file_put_contents($body, "multipart-body");

        _site_export_push_store_response_body($sessionId, 'req-body', $body);
        _site_export_push_store_response_metadata($sessionId, [
            'request_id' => 'req-body',
            'endpoint' => 'file_fetch',
            'kind' => 'stream',
            'body_file' => '/source/tmp/body',
            'http_code' => 200,
        ]);

        $metadata = _site_export_push_read_json_file($sessionDir . '/relay/responses/req-body.json');
        $this->assertSame($sessionDir . '/relay/responses/req-body.body', $metadata['body_file']);
        $this->assertSame('multipart-body', file_get_contents($metadata['body_file']));
        $this->assertFileDoesNotExist($sessionDir . '/relay/processing/req-body.json');
    }

    public function testAbortWritesErrorsForPendingAndProcessingRequests(): void
    {
        $created = _site_export_push_create_session([
            'source_url' => 'http://local.test/?reprint-api',
        ]);
        $sessionId = $created['session_id'];
        $sessionDir = _site_export_push_session_dir($sessionId);
        _site_export_push_write_json_file($sessionDir . '/relay/requests/req-pending.json', ['request_id' => 'req-pending']);
        _site_export_push_write_json_file($sessionDir . '/relay/processing/req-processing.json', ['request_id' => 'req-processing']);

        $status = _site_export_push_abort_session($sessionId);

        $this->assertSame('aborted', $status['session']['status']);
        $this->assertSame('Push session aborted.', _site_export_push_read_json_file($sessionDir . '/relay/responses/req-pending.json')['error']);
        $this->assertSame('Push session aborted.', _site_export_push_read_json_file($sessionDir . '/relay/responses/req-processing.json')['error']);
        $this->assertFileDoesNotExist($sessionDir . '/relay/requests/req-pending.json');
        $this->assertFileDoesNotExist($sessionDir . '/relay/processing/req-processing.json');
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
