/**
 * Test 52: Symlink-follow rolling-window error handling and resume.
 *
 * Sets up five external symlink targets and a server-side mu-plugin that
 * forces target-2 to fail with HTTP 502 on its first request only. With
 * --symlink-follow-concurrency=5 the importer dispatches all five in
 * parallel; the four "good" slots succeed and get buffered while slot 2
 * fails and aborts the run. The test then re-runs files-sync without
 * --abort and confirms:
 *
 *   1. The first run exits non-zero.
 *   2. The state directory contains a .symlink-pool sidecar dir holding
 *      jsonl buffers for the slots that completed before the failure.
 *   3. The retry succeeds and ends up with every payload on disk.
 *   4. Only the failing target is re-issued — every other target is
 *      hit exactly once across both runs, proving the rolling-window
 *      watermark really only re-asks for the slot that didn't fulfil.
 */
import { describe, it, beforeAll, afterAll, beforeEach } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, readFileSync, mkdirSync, writeFileSync, symlinkSync, readdirSync,
} from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const EXTERNAL_ROOT = '/srv/e2e-resume-external';
const SYMLINK_COUNT = 5;
const FAILING_INDEX = 2;
const REQUEST_LOG = '/tmp/symlink-resume-target-requests.json';

const isPlaygroundCli = (process.env.PHP_BINARY || '').includes('playground-php');
const describeOrSkip = isPlaygroundCli ? describe.skip : describe;

describeOrSkip('Import: Symlink-follow resume after mid-window failure', () => {
    const site = 'symlink-resume';
    let tempDir;

    function clearRequestLog() {
        try {
            execSync(`sudo rm -f ${REQUEST_LOG} ${REQUEST_LOG}.failed`);
            execSync(`sudo touch ${REQUEST_LOG} ${REQUEST_LOG}.failed`);
            execSync(`sudo chmod 666 ${REQUEST_LOG} ${REQUEST_LOG}.failed`);
        } catch (_) {
            // best effort
        }
    }

    function readRequestCounts() {
        try {
            const raw = readFileSync(REQUEST_LOG, 'utf-8').trim();
            return raw === '' ? {} : JSON.parse(raw);
        } catch (_) {
            return {};
        }
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

                // mu-plugin: count per-target export-API hits and force
                // target-FAILING_INDEX to fail with HTTP 502 on its first
                // request only. The flock'd JSON file persists across
                // process invocations so the test can introspect both
                // request counts and the per-target failure trigger.
                const failingTarget = join(EXTERNAL_ROOT, `target-${FAILING_INDEX}`);
                const muDir = join(siteDir, 'wp-content', 'mu-plugins');
                mkdirSync(muDir, { recursive: true });
                writeFileSync(
                    join(muDir, 'test-symlink-resume-fault.php'),
                    [
                        '<?php',
                        `if (!isset($_GET['reprint-api']) && !isset($_GET['site-export-api'])) { return; }`,
                        `$log_path = ${JSON.stringify(REQUEST_LOG)};`,
                        `$failing_target = ${JSON.stringify(failingTarget)};`,
                        `$list_dir = $_GET['list_dir'] ?? '';`,
                        `if ($list_dir === '') { return; }`,
                        `$decoded = base64_decode($list_dir, true);`,
                        `if ($decoded === false) { $decoded = $list_dir; }`,
                        `$fh = fopen($log_path, 'c+');`,
                        `if ($fh === false) { return; }`,
                        `flock($fh, LOCK_EX);`,
                        `rewind($fh);`,
                        `$raw = stream_get_contents($fh);`,
                        `$counts = ($raw === '' || $raw === false) ? [] : (json_decode($raw, true) ?: []);`,
                        `$counts[$decoded] = ($counts[$decoded] ?? 0) + 1;`,
                        `$current = $counts[$decoded];`,
                        `ftruncate($fh, 0);`,
                        `rewind($fh);`,
                        `fwrite($fh, json_encode($counts));`,
                        `fflush($fh);`,
                        `flock($fh, LOCK_UN);`,
                        `fclose($fh);`,
                        `if ($decoded === $failing_target && $current === 1) {`,
                        `    http_response_code(502);`,
                        `    header('Content-Type: text/plain');`,
                        `    echo 'test-induced 502 for ' . $decoded;`,
                        `    exit;`,
                        `}`,
                        '',
                    ].join('\n'),
                );
            },
        });

        tempDir = createTempDir('e2e-symlink-resume');
    }, 300000);

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    beforeEach(() => {
        // Wipe importer state between scenario blocks below.
        runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: ['--abort'],
            autoResume: false,
        });
        clearRequestLog();
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('first run fails on the middle slot but buffers later slots', () => {
        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: ['--follow-symlinks', '--symlink-follow-concurrency=5'],
            autoResume: false,
            timeout: 120000,
        });
        assert.notEqual(
            result.exitCode, 0,
            `Expected non-zero exit on the failing slot.\nstdout: ${result.stdout}\nstderr: ${result.stderr}`,
        );

        const sidecarDir = join(tempDir, '.symlink-pool');
        assert.ok(existsSync(sidecarDir),
            `Expected sidecar directory ${sidecarDir} to exist after mid-window failure`);
        const sidecars = readdirSync(sidecarDir).filter(n => n.startsWith('slot-'));
        assert.ok(sidecars.length >= 1,
            `Expected at least one buffered sidecar, got: ${JSON.stringify(sidecars)}`);

        // Resume: no --abort, importer should re-issue only the failing
        // slot from its saved cursor and drain the sidecars in order.
        const retry = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: ['--follow-symlinks', '--symlink-follow-concurrency=5'],
            autoResume: false,
            timeout: 120000,
        });
        assert.equal(
            retry.exitCode, 0,
            `Expected resume to succeed.\nstdout: ${retry.stdout}\nstderr: ${retry.stderr}`,
        );

        // All payloads on disk.
        for (let i = 0; i < SYMLINK_COUNT; i++) {
            const payload = join(fsRootDir(tempDir), EXTERNAL_ROOT, `target-${i}`, 'payload.txt');
            assert.ok(existsSync(payload), `Missing payload after resume: ${payload}`);
            assert.equal(readFileSync(payload, 'utf-8'), `payload ${i}\n`);
        }

        // The sidecar dir is cleaned up on a clean completion.
        assert.ok(!existsSync(sidecarDir),
            `Expected ${sidecarDir} to be removed after a successful resume`);

        // Watermark check: every non-failing target was hit exactly once
        // across the two runs. The failing target was hit twice — once for
        // the 502, once for the successful retry.
        const counts = readRequestCounts();
        for (let i = 0; i < SYMLINK_COUNT; i++) {
            const dir = join(EXTERNAL_ROOT, `target-${i}`);
            const got = counts[dir] ?? 0;
            const expected = i === FAILING_INDEX ? 2 : 1;
            assert.equal(got, expected,
                `target-${i}: expected ${expected} request(s), got ${got}. Full counts: ${JSON.stringify(counts)}`);
        }
    }, 300000);
});
