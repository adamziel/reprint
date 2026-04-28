/**
 * Test 51: Symlink-follow concurrency benchmark.
 *
 * Sets up a site with many directory symlinks pointing to external targets
 * outside the site root, then runs `files-sync --follow-symlinks` twice:
 * once with --symlink-follow-concurrency=1 (sequential, the default) and
 * once with --symlink-follow-concurrency=5 (rolling window of 5).
 *
 * The test asserts both runs complete and download every external file,
 * and prints both wall-clock durations so you can see the speedup. Per-link
 * latency dominates this workload (each link is a directory with one tiny
 * file), so a rolling window of 5 should finish materially faster.
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
const SYMLINK_COUNT = 30;

describe('Import: Symlink-follow concurrency benchmark', () => {
    const site = 'many-symlinks-bench';
    let tempDirSequential;
    let tempDirConcurrent;
    let fallbackApiServer = null;

    async function ensureApiReachable() {
        const apiUrl = getSiteUrl(site);
        try {
            await fetch(apiUrl, { method: 'GET' });
            return;
        } catch (_) {
            // Same fallback pattern as import-31-follow-symlinks.test.js:
            // when the configured port isn't exposed, fall back to PHP's
            // built-in server pointed at the plugin directory.
        }

        const fsRoot = join(getSiteDir(site), 'wp-content', 'plugins', 'site-export');
        fallbackApiServer = spawn('php', ['-S', '127.0.0.1:8120', '-t', fsRoot], {
            stdio: 'ignore',
        });

        const deadline = Date.now() + 15000;
        while (Date.now() < deadline) {
            if (fallbackApiServer.exitCode !== null) {
                throw new Error(`Fallback API server exited early with code ${fallbackApiServer.exitCode}`);
            }
            try {
                await fetch(apiUrl, { method: 'GET' });
                return;
            } catch (_) {
                await sleep(100);
            }
        }
        throw new Error('Timed out waiting for many-symlinks-bench API server on 127.0.0.1:8120');
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
        await ensureApiReachable();

        tempDirSequential = createTempDir('e2e-many-symlinks-seq');
        tempDirConcurrent = createTempDir('e2e-many-symlinks-conc');
    }, 300000);

    afterAll(() => {
        cleanupTempDir(tempDirSequential);
        cleanupTempDir(tempDirConcurrent);
        if (fallbackApiServer && fallbackApiServer.exitCode === null) {
            fallbackApiServer.kill('SIGTERM');
        }
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
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
            timeout: 300000,
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
            `\n[symlink-follow-concurrency benchmark] ${SYMLINK_COUNT} symlinks\n` +
            `  concurrency=1: ${seqMs} ms\n` +
            `  concurrency=5: ${concMs} ms\n` +
            `  speedup: ${speedup}x\n`,
        );
    }, 600000);
});
