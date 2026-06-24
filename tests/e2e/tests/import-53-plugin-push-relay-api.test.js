/**
 * Test 53: Plugin push relay API binding
 *
 * Verifies the PHAR/client side can fulfill target-owned relay requests over an
 * HTTP push API, which is the binding used by the WordPress plugin session API.
 */
import { describe, it, beforeEach, afterEach } from 'vitest';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { createServer } from 'node:net';

const PROJECT_ROOT = join(import.meta.dirname, '..', '..', '..');
const IMPORTER_PATH = process.env.IMPORTER_PATH || join(PROJECT_ROOT, 'importer', 'import.php');
const PHP_BINARY = process.env.PHP_BINARY || 'php';

describe('Import: Plugin Push Relay API', { timeout: 60000 }, () => {
    let tempDir;
    let server;
    let serverOutput = '';
    let baseUrl;

    beforeEach(async () => {
        tempDir = mkdtempSync(join(tmpdir(), 'reprint-push-relay-e2e-'));
        mkdirSync(join(tempDir, 'source', 'wp-content'), { recursive: true });
        writeFileSync(join(tempDir, 'request.json'), JSON.stringify({
            protocol: 1,
            request_id: 'req-preflight',
            kind: 'json',
            endpoint: 'preflight',
            cursor: null,
            params: {},
            post_data: null,
        }));
        writeFileSync(join(tempDir, 'router.php'), routerPhp(tempDir));
        const port = await getFreePort();
        baseUrl = `http://127.0.0.1:${port}/`;
        server = spawn(PHP_BINARY, ['-S', `127.0.0.1:${port}`, join(tempDir, 'router.php')], {
            stdio: ['ignore', 'pipe', 'pipe'],
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

    it('source worker polls target push API and posts the exporter response', () => {
        execFileSync(PHP_BINARY, [
            IMPORTER_PATH,
            'relay-source',
            `${baseUrl}?reprint-api`,
            `--relay-url=${baseUrl}?reprint-push-api`,
            '--relay-session=e2e-session',
            '--relay-secret=target-secret',
            `--state-dir=${join(tempDir, 'state')}`,
            `--fs-root=${join(tempDir, 'state', 'fs-root')}`,
            `--relay-allow-path=${join(tempDir, 'source')}`,
            '--relay-idle-timeout=10',
        ], {
            timeout: 30000,
            encoding: 'utf-8',
            maxBuffer: 5 * 1024 * 1024,
            env: { ...process.env },
        });

        const responsePath = join(tempDir, 'response.json');
        assert.ok(existsSync(responsePath), `Expected source worker to post ${responsePath}\n${serverOutput}`);
        const response = JSON.parse(readFileSync(responsePath, 'utf-8'));
        assert.equal(response.request_id, 'req-preflight');
        assert.equal(response.endpoint, 'preflight');
        assert.equal(response.kind, 'json');
        assert.equal(response.json_result.json.ok, true);
    });
});

function routerPhp(root) {
    const encodedRoot = JSON.stringify(root);
    return `<?php
$root = ${encodedRoot};
$source = $root . '/source';
$endpoint = $_GET['endpoint'] ?? '';
if (isset($_GET['reprint-api'])) {
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
if (!isset($_GET['reprint-push-api'])) {
    http_response_code(404);
    return;
}
header('Content-Type: application/json');
if ($endpoint === 'claim') {
    if (is_file($root . '/response.json')) {
        echo json_encode(['ok' => true, 'status' => 'complete', 'request' => null]);
        return;
    }
    if (is_file($root . '/request.json')) {
        $request = json_decode(file_get_contents($root . '/request.json'), true);
        rename($root . '/request.json', $root . '/processing.json');
        echo json_encode(['ok' => true, 'status' => 'running', 'request' => $request, 'uploads' => new stdClass()]);
        return;
    }
    echo json_encode(['ok' => true, 'status' => 'running', 'request' => null]);
    return;
}
if ($endpoint === 'response') {
    $body = file_get_contents('php://input');
    file_put_contents($root . '/response.json', $body);
    @unlink($root . '/processing.json');
    echo json_encode(['ok' => true]);
    return;
}
echo json_encode(['ok' => false, 'error' => 'unexpected endpoint']);
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
