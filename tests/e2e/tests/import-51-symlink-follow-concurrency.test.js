/**
 * Test 51: Symlink-follow concurrency benchmark.
 *
 * Stands up 74 directory symlinks pointing outside the site root, then runs
 * `files-sync --follow-symlinks` twice against an export server that sleeps
 * 5 seconds before responding to every export-API request. The first run uses
 * --symlink-follow-concurrency=1 (sequential, the default), the second uses
 * =5 (rolling window of 5). Both must download every payload; the test logs
 * both wall-clock durations so you can see the speedup.
 *
 * With per-request latency dominating the workload, sequential should take
 * roughly 74×5s ≈ 370s and concurrent-5 should take roughly ⌈74/5⌉×5s ≈ 75s.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, readFileSync, mkdirSync, writeFileSync, symlinkSync,
} from 'node:fs';
import { execSync, spawn } from 'node:child_process';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const EXTERNAL_ROOT = '/srv/e2e-bench-external';
const SYMLINK_COUNT = 74;
const DELAY_SECONDS = 5;
const ROUTER_PATH = '/tmp/many-symlinks-delay-router.php';
// Delay server listens on this port and proxies every export-API request to
// the real nginx vhost on the registered port (UPSTREAM_PORT), sleeping
// DELAY_SECONDS first. We can't just intercept the request locally because
// the export endpoint needs a fully-booted WordPress, which only nginx+
// php-fpm provides.
const DELAY_SERVER_PORT = 18120;
const UPSTREAM_PORT = 8120;

describe('Import: Symlink-follow concurrency benchmark', () => {
    const site = 'many-symlinks-bench';
    let tempDirSequential;
    let tempDirConcurrent;
    let delayServer = null;

    async function startDelayServer() {
        // Router script for `php -S`: sleeps DELAY_SECONDS on every export-API
        // request and then proxies to the real nginx vhost on UPSTREAM_PORT.
        // /health answers immediately so the readiness wait doesn't pay the
        // sleep. Anything else is also proxied (without sleep) so the suite
        // remains a faithful client of the real WordPress site.
        const router = `<?php
$uri = $_SERVER['REQUEST_URI'] ?? '/';
if ($uri === '/health') {
    echo 'ok';
    return true;
}
$is_export_api = isset($_GET['reprint-api']) || isset($_GET['site-export-api']);
if ($is_export_api) {
    sleep(${DELAY_SECONDS});
}
$upstream = 'http://127.0.0.1:${UPSTREAM_PORT}' . $uri;
$ch = curl_init($upstream);
$headers = [];
foreach (getallheaders() as $k => $v) {
    if (strtolower($k) === 'host') continue;
    if (strtolower($k) === 'content-length') continue;
    $headers[] = $k . ': ' . $v;
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 600);
if ($method !== 'GET' && $method !== 'HEAD') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
}
$resp = curl_exec($ch);
if ($resp === false) {
    http_response_code(502);
    echo 'proxy error: ' . curl_error($ch);
    curl_close($ch);
    return true;
}
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$header_str = substr($resp, 0, $header_size);
$body = substr($resp, $header_size);
curl_close($ch);
http_response_code($status);
foreach (explode("\\r\\n", $header_str) as $line) {
    if ($line === '' || stripos($line, 'HTTP/') === 0) continue;
    $lower = strtolower($line);
    if (strpos($lower, 'transfer-encoding:') === 0) continue;
    if (strpos($lower, 'connection:') === 0) continue;
    if (strpos($lower, 'content-length:') === 0) continue;
    header($line, false);
}
echo $body;
return true;
`;
        writeFileSync(ROUTER_PATH, router);

        // -t docroot is required by php -S even though our router proxies
        // every request; point it at the plugin dir like test 31 does.
        const docroot = join(getSiteDir(site), 'wp-content', 'plugins', 'site-export');
        delayServer = spawn(
            'php',
            ['-S', `127.0.0.1:${DELAY_SERVER_PORT}`, '-t', docroot, ROUTER_PATH],
            { stdio: 'ignore' },
        );

        const deadline = Date.now() + 15000;
        while (Date.now() < deadline) {
            if (delayServer.exitCode !== null) {
                throw new Error(`Delay server exited early with code ${delayServer.exitCode}`);
            }
            try {
                const r = await fetch(`http://127.0.0.1:${DELAY_SERVER_PORT}/health`);
                if (r.ok) return;
            } catch (_) {
                await sleep(100);
            }
        }
        throw new Error(`Timed out waiting for delay server on 127.0.0.1:${DELAY_SERVER_PORT}`);
    }

    beforeAll(async () => {
        execSync(`sudo rm -rf ${EXTERNAL_ROOT}`);
        execSync(`sudo mkdir -p ${EXTERNAL_ROOT}`);
        execSync(`sudo chmod 777 ${EXTERNAL_ROOT}`);

        for (let i = 0; i < SYMLINK_COUNT; i++) {
            const dir = join(EXTERNAL_ROOT, `target-${i}`);
            mkdirSync(dir, { recursive: true });
            writeFileSync(join(dir, 'payload.txt'), `payload ${i}\n`);
        }

        execSync(`sudo chown -R nginx:nginx ${EXTERNAL_ROOT}`);
        execSync(`sudo chmod -R 755 ${EXTERNAL_ROOT}`);

        await ensureSite(site, {
            afterCreate: async (siteDir) => {
                const dataDir = join(siteDir, 'test-data');
                mkdirSync(dataDir, { recursive: true });
                for (let i = 0; i < SYMLINK_COUNT; i++) {
                    symlinkSync(
                        join(EXTERNAL_ROOT, `target-${i}`),
                        join(dataDir, `link-${i}`),
                    );
                }
            },
        });

        // Always use the delay server, even if nginx is configured for
        // this port — the whole point of the test is the artificial latency.
        await startDelayServer();

        tempDirSequential = createTempDir('e2e-many-symlinks-seq');
        tempDirConcurrent = createTempDir('e2e-many-symlinks-conc');
    }, 300000);

    afterAll(() => {
        cleanupTempDir(tempDirSequential);
        cleanupTempDir(tempDirConcurrent);
        if (delayServer && delayServer.exitCode === null) {
            delayServer.kill('SIGTERM');
        }
    });

    function importUrl() {
        return `${getSiteUrl(site, DELAY_SERVER_PORT)}&directory=${getSiteDir(site)}`;
    }

    function timeImport(tempDir, concurrency) {
        runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: ['--abort'],
        });

        const start = Date.now();
        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: [
                '--follow-symlinks',
                `--symlink-follow-concurrency=${concurrency}`,
            ],
            // Sequential: 74 × 5s ≈ 370s plus overhead. Give it 12 minutes.
            timeout: 720000,
        });
        const elapsedMs = Date.now() - start;

        assert.equal(result.exitCode, 0,
            `concurrency=${concurrency}: expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        return elapsedMs;
    }

    function assertAllPayloadsDownloaded(tempDir, concurrency) {
        const fsRoot = fsRootDir(tempDir);
        for (let i = 0; i < SYMLINK_COUNT; i++) {
            const payload = join(fsRoot, EXTERNAL_ROOT, `target-${i}`, 'payload.txt');
            assert.ok(existsSync(payload),
                `concurrency=${concurrency}: missing ${payload}`);
            assert.equal(readFileSync(payload, 'utf-8'), `payload ${i}\n`);
        }
    }

    it('benchmarks symlink-follow concurrency=1 vs concurrency=5', () => {
        const seqMs = timeImport(tempDirSequential, 1);
        assertAllPayloadsDownloaded(tempDirSequential, 1);

        const concMs = timeImport(tempDirConcurrent, 5);
        assertAllPayloadsDownloaded(tempDirConcurrent, 5);

        const speedup = (seqMs / concMs).toFixed(2);
        // eslint-disable-next-line no-console
        console.log(
            `\n[symlink-follow-concurrency benchmark] ${SYMLINK_COUNT} symlinks, ${DELAY_SECONDS}s server delay\n` +
            `  concurrency=1: ${seqMs} ms\n` +
            `  concurrency=5: ${concMs} ms\n` +
            `  speedup: ${speedup}x\n`,
        );
    }, 1500000);
});
