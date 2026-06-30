/**
 * Test 53: Plugin push relay API binding
 *
 * Verifies the PHAR/client side can fulfill target-owned relay requests over the
 * real WordPress plugin push API entry points.
 */
import { describe, it, beforeEach, afterEach } from 'vitest';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { createHash, createHmac, randomBytes } from 'node:crypto';
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { createServer } from 'node:net';

const PROJECT_ROOT = join(import.meta.dirname, '..', '..', '..');
const IMPORTER_PATH = process.env.IMPORTER_PATH || join(PROJECT_ROOT, 'importer', 'import.php');
const PHP_BINARY = process.env.PHP_BINARY || 'php';
const SERVER_PHP_BINARY = process.env.SERVER_PHP_BINARY || 'php';
const TARGET_SECRET = 'target-secret';
const RELAY_SOURCE_TIMEOUT_MS = 60000;

describe('Import: Plugin Push Relay API', { timeout: 90000 }, () => {
    let tempDir;
    let server;
    let serverOutput = '';
    let baseUrl;

    beforeEach(async () => {
        serverOutput = '';
        tempDir = mkdtempSync(join(tmpdir(), 'reprint-push-relay-e2e-'));
        mkdirSync(join(tempDir, 'source', 'wp-content'), { recursive: true });
        mkdirSync(join(tempDir, 'target'), { recursive: true });
        writeFileSync(join(tempDir, 'source', 'wp-content', 'file.txt'), 'source-file');
        writeFileSync(join(tempDir, 'secret.php'), `<?php return '${TARGET_SECRET}';\n`);
        writeFileSync(join(tempDir, 'router.php'), routerPhp(tempDir));
        const port = await getFreePort();
        baseUrl = `http://127.0.0.1:${port}/`;
        server = spawn(SERVER_PHP_BINARY, ['-S', `127.0.0.1:${port}`, join(tempDir, 'router.php')], {
            stdio: ['ignore', 'pipe', 'pipe'],
            env: {
                ...process.env,
                // The plugin run request blocks while the source worker calls
                // back into this same test server, so a single built-in-server
                // worker would deadlock the relay loop. Keep the fixture on the
                // native PHP server even when PHP_BINARY points at Playground,
                // whose wrapper cannot expose PHP_CLI_SERVER_WORKERS here.
                PHP_CLI_SERVER_WORKERS: process.env.PHP_CLI_SERVER_WORKERS || '8',
            },
        });
        server.stderr.on('data', (chunk) => {
            serverOutput += chunk.toString();
        });
        await waitForServer(baseUrl, 5000);
    });

    afterEach(() => {
        if (server && server.exitCode === null) {
            server.kill('SIGTERM');
        }
        if (tempDir && existsSync(tempDir)) {
            rmSync(tempDir, { recursive: true, force: true });
        }
    });

    it('source worker polls the plugin API and posts an exporter JSON response', async () => {
        const session = await createPluginSession();
        writeRelayRequest(session.session_id, {
            protocol: 1,
            request_id: 'req-preflight',
            kind: 'json',
            endpoint: 'preflight',
            cursor: null,
            params: {},
            post_data: null,
        });

        runRelaySource(session.session_id);

        const response = readRelayResponse(session.session_id, 'req-preflight');
        assert.equal(response.request_id, 'req-preflight');
        assert.equal(response.endpoint, 'preflight');
        assert.equal(response.kind, 'json');
        assert.equal(response.json_result.json.ok, true);
        const status = await callPushApi('status', { sessionId: session.session_id });
        assert.equal(status.relay.responses, 1);
    });

    it('streams request upload sidecars and response bodies through the plugin API', async () => {
        const session = await createPluginSession();
        const uploadName = 'req-file-file-list.upload';
        writeRelayRequest(session.session_id, {
            protocol: 1,
            request_id: 'req-file',
            kind: 'stream',
            endpoint: 'file_fetch',
            cursor: null,
            params: { directory: [join(tempDir, 'source')] },
            post_data: {
                file_list: {
                    type: 'file',
                    upload: uploadName,
                    name: 'file_list',
                    mime: 'application/json',
                    size: 2,
                },
            },
        });
        writeRelayUpload(session.session_id, uploadName, JSON.stringify([
            join(tempDir, 'source', 'wp-content', 'file.txt'),
        ]));

        runRelaySource(session.session_id);

        const response = readRelayResponse(session.session_id, 'req-file');
        assert.equal(response.request_id, 'req-file');
        assert.equal(response.endpoint, 'file_fetch');
        assert.equal(response.kind, 'stream');
        assert.equal(
            readFileSync(join(sessionDir(session.session_id), 'relay', 'responses', 'req-file.body'), 'utf-8'),
            `file-list:${JSON.stringify([join(tempDir, 'source', 'wp-content', 'file.txt')])}`
        );
    });

    it('run endpoint authors relay requests fulfilled by the source worker', async () => {
        const session = await createPluginSession({ command: 'preflight' });
        const runPromise = callPushApi('run', { sessionId: session.session_id });

        await waitForRelayRequest(session.session_id, runPromise);
        runRelaySource(session.session_id);

        const status = await runPromise;
        assert.equal(status.session.status, 'complete');
        assert.equal(status.import_status.status, 'complete');
        assert.equal(status.relay.responses, 1);
    });

    async function createPluginSession({ command = 'pull', options = {} } = {}) {
        return await callPushApi('create', {
            body: {
                source_url: `${baseUrl}?reprint-api`,
                command,
                options,
            },
        });
    }

    function writeRelayRequest(sessionId, request) {
        writeFileSync(
            join(sessionDir(sessionId), 'relay', 'requests', `${request.request_id}.json`),
            JSON.stringify(request)
        );
    }

    function writeRelayUpload(sessionId, uploadName, body) {
        writeFileSync(join(sessionDir(sessionId), 'relay', 'uploads', uploadName), body);
    }

    function readRelayResponse(sessionId, requestId) {
        const responsePath = join(sessionDir(sessionId), 'relay', 'responses', `${requestId}.json`);
        assert.ok(existsSync(responsePath), `Expected plugin response metadata at ${responsePath}\n${serverOutput}`);
        return JSON.parse(readFileSync(responsePath, 'utf-8'));
    }

    async function waitForRelayRequest(sessionId, runPromise) {
        const deadline = Date.now() + 5000;
        const requestsDir = join(sessionDir(sessionId), 'relay', 'requests');
        const processingDir = join(sessionDir(sessionId), 'relay', 'processing');
        while (Date.now() < deadline) {
            const hasRequest = [requestsDir, processingDir].some((dir) => {
                return existsSync(dir) && readdirSync(dir).some((name) => name.endsWith('.json'));
            });
            if (hasRequest) {
                return;
            }
            const runStatus = await Promise.race([
                runPromise.then((status) => ({ status }), (error) => ({ error })),
                new Promise((resolve) => setTimeout(() => resolve(null), 50)),
            ]);
            if (runStatus !== null) {
                throw new Error(
                    'Plugin run finished before authoring a relay request: ' +
                    JSON.stringify(runStatus)
                );
            }
        }
        throw new Error(`Timed out waiting for a plugin-authored relay request\n${serverOutput}`);
    }

    function sessionDir(sessionId) {
        return join(tempDir, 'push-sessions', sessionId);
    }

    function runRelaySource(sessionId) {
        execFileSync(PHP_BINARY, [
            IMPORTER_PATH,
            'relay-source',
            `${baseUrl}?reprint-api`,
            `--relay-url=${baseUrl}?reprint-push-api`,
            `--relay-session=${sessionId}`,
            `--relay-secret=${TARGET_SECRET}`,
            `--state-dir=${join(tempDir, 'state')}`,
            `--fs-root=${join(tempDir, 'state', 'fs-root')}`,
            `--relay-allow-path=${join(tempDir, 'source')}`,
            '--relay-idle-timeout=1',
        ], {
            timeout: RELAY_SOURCE_TIMEOUT_MS,
            encoding: 'utf-8',
            maxBuffer: 5 * 1024 * 1024,
            env: { ...process.env },
        });
    }

    async function callPushApi(endpoint, { sessionId, body } = {}) {
        const requestBody = body === undefined ? '' : JSON.stringify(body);
        const url = new URL(baseUrl);
        url.searchParams.set('reprint-push-api', '1');
        url.searchParams.set('endpoint', endpoint);
        if (sessionId) {
            url.searchParams.set('session_id', sessionId);
        }
        const headers = {
            Accept: 'application/json',
            ...hmacHeaders(requestBody),
        };
        const response = await fetch(url, {
            method: body === undefined ? 'GET' : 'POST',
            headers: body === undefined
                ? headers
                : { ...headers, 'Content-Type': 'application/json' },
            body: body === undefined ? undefined : requestBody,
        });
        const text = await response.text();
        let json;
        try {
            json = JSON.parse(text);
        } catch (error) {
            throw new Error(`Invalid push API JSON from ${endpoint}: ${text}`);
        }
        assert.equal(response.status, 200, text);
        assert.equal(json.ok, true, text);
        return json;
    }
});

function hmacHeaders(body) {
    const nonce = randomBytes(16).toString('hex');
    const timestamp = (Date.now() / 1000).toFixed(6);
    const contentHash = createHash('sha256').update(body).digest('hex');
    const signature = createHmac('sha256', TARGET_SECRET)
        .update(nonce + timestamp + contentHash)
        .digest('hex');
    return {
        'X-Auth-Signature': signature,
        'X-Auth-Nonce': nonce,
        'X-Auth-Timestamp': timestamp,
        'X-Auth-Content-Hash': contentHash,
    };
}

function routerPhp(root) {
    const encodedRoot = JSON.stringify(root);
    const encodedProjectRoot = JSON.stringify(PROJECT_ROOT);
    return `<?php
$root = ${encodedRoot};
$projectRoot = ${encodedProjectRoot};
$source = $root . '/source';
define('ABSPATH', $root . '/target/');
define('SITE_EXPORT_PLUGIN_DIR', $projectRoot . '/reprint-exporter-wp/');
define('SITE_EXPORT_SECRET_FILE', $root . '/secret.php');
define('SITE_EXPORT_PUSH_BASE_DIR', $root . '/push-sessions');
define('SITE_EXPORT_PUSH_DEV_IMPORTER_RUNTIME', $projectRoot . '/packages/reprint-importer/src/import.php');
require_once $projectRoot . '/packages/reprint-exporter/src/class-hmac-client.php';
require_once $projectRoot . '/packages/reprint-exporter/src/class-hmac-server.php';
require_once $projectRoot . '/reprint-exporter-wp/lib.php';
require_once $projectRoot . '/reprint-exporter-wp/push.php';

$endpoint = $_GET['endpoint'] ?? '';
if (isset($_GET['reprint-api'])) {
    if ($endpoint === 'file_fetch') {
        header('Content-Type: application/octet-stream');
        $fileList = $_FILES['file_list']['tmp_name'] ?? null;
        echo 'file-list:' . ($fileList ? file_get_contents($fileList) : '');
        return;
    }
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'runtime' => ['document_root' => $source],
        'database' => ['wp' => ['paths_urls' => [
            'abspath' => $source,
            'content_dir' => $source . '/wp-content',
        ]]],
        'wp_detect' => ['roots' => [['path' => $source]]],
    ]);
    return;
}
if (isset($_GET[SITE_EXPORT_PUSH_API_PARAM])) {
    _site_export_handle_push_api_request();
    return;
}
http_response_code(404);
echo 'not found';
`;
}

async function getFreePort() {
    return await new Promise((resolve, reject) => {
        const server = createServer();
        server.listen(0, '127.0.0.1', () => {
            const address = server.address();
            const port = address && typeof address === 'object' ? address.port : null;
            server.close(() => port ? resolve(port) : reject(new Error('No port assigned')));
        });
        server.on('error', reject);
    });
}

async function waitForServer(baseUrl, timeoutMs) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        try {
            await fetch(baseUrl);
            return;
        } catch (error) {
            await new Promise((resolve) => setTimeout(resolve, 50));
        }
    }
    throw new Error('Timed out waiting for PHP server to start');
}
