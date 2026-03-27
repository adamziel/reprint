/**
 * Test 41: Remote upload proxy
 *
 * When files-sync hasn't finished yet, requests for uploaded files that
 * don't exist locally should be proxied from the source site.  This test
 * verifies the remote-upload-proxy route handler by:
 *
 * 1. Importing with --filter=essential-files (skips uploads)
 * 2. Running apply-runtime to generate runtime.php with the proxy handler
 * 3. Starting a php -S server with the generated runtime
 * 4. Requesting missing uploads → proxied from the source nginx site
 * 5. Requesting locally existing files → served from disk
 * 6. Requesting non-existent uploads → 404 forwarded from source
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { existsSync, readFileSync, mkdirSync, writeFileSync, unlinkSync } from 'node:fs';
import { execFileSync, spawn } from 'node:child_process';
import { join } from 'node:path';
import { createConnection } from 'node:net';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    fsRootDir, IMPORTER_PATH,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';
import { randomBytes } from 'node:crypto';

// Known upload files created on the source site.
const UPLOAD_FILES = {
    'wp-content/uploads/2024/01/photo.jpg': 4096,
    'wp-content/uploads/2024/01/document.pdf': 1024,
};

// Port for the target PHP built-in server (not in site-registry).
const TARGET_PORT = 8200;

describe('Import: Remote upload proxy', () => {
    const site = 'remote-proxy';
    let siteDir;
    let tempDir;
    let outputDir;
    let effectiveRoot;
    let phpServer = null;

    // Content bytes keyed by relative path, so we can compare later.
    const sourceContent = {};

    beforeAll(async () => {
        await ensureSite(site, {
            afterCreate: async (remoteSiteDir) => {
                // Create upload files with deterministic random content.
                for (const [relPath, size] of Object.entries(UPLOAD_FILES)) {
                    const absPath = join(remoteSiteDir, relPath);
                    mkdirSync(join(absPath, '..'), { recursive: true });
                    const content = randomBytes(size);
                    writeFileSync(absPath, content);
                    sourceContent[relPath] = content;
                }
            },
        });
        siteDir = getSiteDir(site);

        tempDir = createTempDir('e2e-remote-proxy');
        outputDir = join(tempDir, 'runtime');
        mkdirSync(outputDir, { recursive: true });

        // 1. Run preflight to capture source site metadata.
        const preflight = runImporter(importUrl(), tempDir, 'preflight', {
            secret: getSiteSecret(site),
        });
        assert.equal(preflight.exitCode, 0,
            `preflight failed:\n${preflight.stderr}`);

        // 2. Run files-sync with --filter=essential-files so uploads
        //    are NOT downloaded — this is the scenario the proxy covers.
        const filesSync = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: ['--filter=essential-files'],
        });
        assert.equal(filesSync.exitCode, 0,
            `files-sync failed:\n${filesSync.stderr}`);

        // 3. Generate runtime config with the proxy handler.
        //    apply-runtime doesn't take a URL argument (it's a local-only
        //    command), so we call it directly instead of via runImporter.
        let applyRuntime;
        try {
            const result = execFileSync('php', [
                IMPORTER_PATH,
                'apply-runtime',
                `--state-dir=${tempDir}`,
                `--fs-root=${fsRootDir(tempDir)}`,
                '--runtime=php-builtin',
                `--output-dir=${outputDir}`,
            ], { encoding: 'utf-8', timeout: 60000, maxBuffer: 50 * 1024 * 1024 });
            applyRuntime = { stdout: result, stderr: '', exitCode: 0 };
        } catch (e) {
            applyRuntime = { stdout: e.stdout || '', stderr: e.stderr || '', exitCode: e.status || 1 };
        }
        assert.equal(applyRuntime.exitCode, 0,
            `apply-runtime failed:\n${applyRuntime.stderr}\n${applyRuntime.stdout}`);

        // 4. Start the PHP built-in server with the generated runtime.
        const runtimePath = join(outputDir, 'runtime.php');
        assert.ok(existsSync(runtimePath), 'runtime.php should exist');

        // The effective document root: fs-root + remote document root.
        // Read from the state to find the same root apply-runtime used.
        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        const remoteDocRoot = state.preflight?.data?.runtime?.document_root ?? '';
        effectiveRoot = remoteDocRoot
            ? join(fsRootDir(tempDir), remoteDocRoot)
            : fsRootDir(tempDir);

        // Ensure the document root exists — `php -S` exits with code 1
        // if the -t directory is missing.  It may not exist when
        // files-sync had nothing to write inside it.
        mkdirSync(effectiveRoot, { recursive: true });

        // Capture stderr from the start so we have diagnostics if
        // the server exits early.
        let phpStderr = '';
        phpServer = spawn('php', [
            '-S', `127.0.0.1:${TARGET_PORT}`,
            '-t', effectiveRoot,
            runtimePath,
        ], { stdio: ['ignore', 'pipe', 'pipe'] });
        phpServer.stderr.on('data', (d) => { phpStderr += d; });

        // Wait for the server to accept TCP connections (don't send an
        // HTTP request — that would run the router and try to boot
        // WordPress, which isn't set up for the target).
        const deadline = Date.now() + 15000;
        let ready = false;
        while (Date.now() < deadline) {
            if (phpServer.exitCode !== null) {
                await sleep(100); // let stderr drain
                throw new Error(
                    `PHP server exited early with code ${phpServer.exitCode}\nstderr: ${phpStderr}`
                );
            }
            try {
                await new Promise((resolve, reject) => {
                    const socket = createConnection(TARGET_PORT, '127.0.0.1');
                    socket.setTimeout(500);
                    socket.once('connect', () => { socket.destroy(); resolve(); });
                    socket.once('error', reject);
                    socket.once('timeout', () => { socket.destroy(); reject(new Error('timeout')); });
                });
                ready = true;
                break;
            } catch (_) {
                // retry
            }
            await sleep(200);
        }
        if (!ready) {
            throw new Error(
                `Timed out waiting for PHP server on port ${TARGET_PORT}\nstderr: ${phpStderr}`
            );
        }
    }, 120000);

    afterAll(() => {
        if (phpServer && phpServer.exitCode === null) {
            phpServer.kill('SIGTERM');
        }
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${siteDir}`;
    }

    function targetUrl(path) {
        return `http://127.0.0.1:${TARGET_PORT}${path}`;
    }

    // ------------------------------------------------------------------
    // Verify setup: uploads were NOT downloaded locally
    // ------------------------------------------------------------------
    it('uploads are not in the local fs-root', () => {
        const root = fsRootDir(tempDir);
        for (const relPath of Object.keys(UPLOAD_FILES)) {
            const local = join(root, siteDir, relPath);
            assert.ok(!existsSync(local),
                `Upload should NOT exist locally: ${relPath}`);
        }
    });

    it('runtime.php contains STREAMING_REMOTE_SITE_URL', () => {
        const runtime = readFileSync(join(outputDir, 'runtime.php'), 'utf-8');
        assert.ok(runtime.includes('STREAMING_REMOTE_SITE_URL'),
            'Expected STREAMING_REMOTE_SITE_URL constant in runtime.php');
    });

    it('runtime.php contains the remote upload proxy handler', () => {
        const runtime = readFileSync(join(outputDir, 'runtime.php'), 'utf-8');
        assert.ok(runtime.includes('Remote upload proxy'),
            'Expected remote upload proxy handler in runtime.php');
    });

    // ------------------------------------------------------------------
    // Proxy behaviour
    // ------------------------------------------------------------------
    it('proxies a missing upload from the source site', async () => {
        const path = '/wp-content/uploads/2024/01/photo.jpg';
        const res = await fetch(targetUrl(path));
        assert.equal(res.status, 200,
            `Expected 200 for proxied upload, got ${res.status}`);

        const body = Buffer.from(await res.arrayBuffer());
        const expected = sourceContent['wp-content/uploads/2024/01/photo.jpg'];
        assert.ok(expected, 'Test setup should have source content for photo.jpg');
        assert.equal(body.length, expected.length,
            `Body length mismatch: got ${body.length}, expected ${expected.length}`);
        assert.ok(body.equals(expected),
            'Proxied body should match the source file content');
    });

    it('proxies a different file type (PDF)', async () => {
        const path = '/wp-content/uploads/2024/01/document.pdf';
        const res = await fetch(targetUrl(path));
        assert.equal(res.status, 200,
            `Expected 200 for proxied PDF, got ${res.status}`);

        const body = Buffer.from(await res.arrayBuffer());
        const expected = sourceContent['wp-content/uploads/2024/01/document.pdf'];
        assert.equal(body.length, expected.length,
            'Proxied PDF body length should match source');
    });

    it('forwards 404 for uploads that do not exist on the source', async () => {
        const path = '/wp-content/uploads/2099/01/nonexistent.jpg';
        const res = await fetch(targetUrl(path));
        assert.equal(res.status, 404,
            `Expected 404 for missing source upload, got ${res.status}`);
    });

    it('serves locally existing files without proxying', async () => {
        // Write a file directly into the document root so it exists locally.
        const localContent = Buffer.from('local-file-content-xyz');
        const localDir = join(effectiveRoot, 'wp-content', 'uploads', 'local-test');
        mkdirSync(localDir, { recursive: true });
        writeFileSync(join(localDir, 'test.txt'), localContent);

        const res = await fetch(targetUrl('/wp-content/uploads/local-test/test.txt'));
        assert.equal(res.status, 200,
            `Expected 200 for local file, got ${res.status}`);

        const body = Buffer.from(await res.arrayBuffer());
        assert.ok(body.equals(localContent),
            'Should serve the local file content, not proxy from source');
    });

    it('stops proxying once the sync-complete marker exists', async () => {
        // The remote-upload-proxy checks for a .streaming-uploads-synced
        // marker file whose path is baked into runtime.php as the
        // STREAMING_SYNC_MARKER constant (set to fs-root + marker name).
        // When it exists, the proxy returns early and the request falls
        // through to the normal server routing.  Verify by checking the
        // response body — it must NOT match the source file content.
        const markerPath = join(fsRootDir(tempDir), '.streaming-uploads-synced');
        writeFileSync(markerPath, '');
        try {
            const relPath = 'wp-content/uploads/2024/01/photo.jpg';
            const res = await fetch(targetUrl('/' + relPath));
            const body = Buffer.from(await res.arrayBuffer());
            const expected = sourceContent[relPath];
            assert.ok(expected, 'Test setup should have source content for photo.jpg');
            assert.ok(!body.equals(expected),
                'Response body should NOT match the source file — proxy should be disabled');
        } finally {
            // Remove the marker so subsequent tests still exercise the proxy.
            unlinkSync(markerPath);
        }
    });

    it('does not proxy requests outside /wp-content/uploads/', async () => {
        // Write a file outside wp-content/uploads/ and verify the handler
        // doesn't intercept it — the server serves it as a normal static
        // file instead.
        const content = Buffer.from('not-an-upload');
        writeFileSync(join(effectiveRoot, 'static-test.txt'), content);

        const res = await fetch(targetUrl('/static-test.txt'));
        assert.equal(res.status, 200,
            `Expected 200 for static file, got ${res.status}`);
        const body = Buffer.from(await res.arrayBuffer());
        assert.ok(body.equals(content),
            'Static file outside uploads should be served directly');
    });
});
