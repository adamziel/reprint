/**
 * Test 51: Symlink-follow concurrency benchmark.
 *
 * Stands up 74 directory symlinks pointing outside the site root, then runs
 * `files-sync --follow-symlinks` twice against a real WordPress export site
 * that has a 5-second sleep injected via an mu-plugin on every export-API
 * request. The first run uses --symlink-follow-concurrency=1 (sequential,
 * the default), the second uses =5 (rolling window of 5). Both must
 * download every payload; the test logs both wall-clock durations so the
 * speedup is visible in the test log.
 *
 * The delay is implemented as an mu-plugin so it runs inside the real
 * WordPress request — earlier attempts to put a `php -S` reverse-proxy in
 * front of nginx broke the exporter's HMAC body-hash check (php-cli-server
 * consumes multipart bodies into $_POST so the proxy could only forward
 * an empty body).
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    existsSync, readFileSync, mkdirSync, writeFileSync, symlinkSync,
} from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const EXTERNAL_ROOT = '/srv/e2e-bench-external';
const SYMLINK_COUNT = 74;
const DELAY_SECONDS = 5;
const INFLIGHT_FILE = '/tmp/many-symlinks-bench-inflight.json';
const MAX_INFLIGHT_FILE = '/tmp/many-symlinks-bench-max-inflight.txt';

describe('Import: Symlink-follow concurrency benchmark', () => {
    const site = 'many-symlinks-bench';
    let tempDirSequential;
    let tempDirConcurrent;

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

                // Drop a mu-plugin that sleeps DELAY_SECONDS on every
                // export-API request and tracks how many requests are
                // in-flight at the same time. mu-plugins load before regular
                // plugins, so the sleep fires before the exporter's
                // ?reprint-api interceptor runs — but after WordPress has
                // already accepted the request body, so HMAC verification
                // sees the unmodified payload.
                //
                // The inflight counter is held in a flock'd JSON file. On
                // entry we increment it and bump a max-observed gauge; on
                // exit we decrement. Once the run finishes, the test reads
                // the max-observed gauge and asserts that the concurrent
                // run actually overlapped requests on the server. This is
                // also the point at which we'd notice if php-fpm only ever
                // hands out one worker — the server has to be able to serve
                // up to 5 requests at a time for the benchmark to mean
                // anything.
                const muDir = join(siteDir, 'wp-content', 'mu-plugins');
                mkdirSync(muDir, { recursive: true });
                writeFileSync(
                    join(muDir, 'test-symlink-bench-delay.php'),
                    [
                        '<?php',
                        `if (!isset($_GET['reprint-api']) && !isset($_GET['site-export-api'])) { return; }`,
                        `$inflight_path = ${JSON.stringify(INFLIGHT_FILE)};`,
                        `$max_path = ${JSON.stringify(MAX_INFLIGHT_FILE)};`,
                        `$bump = function (int $delta) use ($inflight_path, $max_path) {`,
                        `    $fh = fopen($inflight_path, 'c+');`,
                        `    if ($fh === false) { return 0; }`,
                        `    flock($fh, LOCK_EX);`,
                        `    rewind($fh);`,
                        `    $raw = stream_get_contents($fh);`,
                        `    $cur = is_numeric(trim((string) $raw)) ? (int) trim((string) $raw) : 0;`,
                        `    $cur += $delta;`,
                        `    if ($cur < 0) { $cur = 0; }`,
                        `    ftruncate($fh, 0);`,
                        `    rewind($fh);`,
                        `    fwrite($fh, (string) $cur);`,
                        `    fflush($fh);`,
                        `    if ($delta > 0) {`,
                        `        $mh = fopen($max_path, 'c+');`,
                        `        if ($mh !== false) {`,
                        `            flock($mh, LOCK_EX);`,
                        `            rewind($mh);`,
                        `            $mr = stream_get_contents($mh);`,
                        `            $max = is_numeric(trim((string) $mr)) ? (int) trim((string) $mr) : 0;`,
                        `            if ($cur > $max) {`,
                        `                ftruncate($mh, 0);`,
                        `                rewind($mh);`,
                        `                fwrite($mh, (string) $cur);`,
                        `                fflush($mh);`,
                        `            }`,
                        `            flock($mh, LOCK_UN);`,
                        `            fclose($mh);`,
                        `        }`,
                        `    }`,
                        `    flock($fh, LOCK_UN);`,
                        `    fclose($fh);`,
                        `    return $cur;`,
                        `};`,
                        `$bump(1);`,
                        `register_shutdown_function(function () use ($bump) { $bump(-1); });`,
                        `sleep(${DELAY_SECONDS});`,
                        '',
                    ].join('\n'),
                );
            },
        });

        tempDirSequential = createTempDir('e2e-many-symlinks-seq');
        tempDirConcurrent = createTempDir('e2e-many-symlinks-conc');
    }, 300000);

    afterAll(() => {
        cleanupTempDir(tempDirSequential);
        cleanupTempDir(tempDirConcurrent);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    function resetInflightGauge() {
        for (const path of [INFLIGHT_FILE, MAX_INFLIGHT_FILE]) {
            try {
                execSync(`sudo rm -f ${path}`);
                execSync(`sudo touch ${path}`);
                execSync(`sudo chmod 666 ${path}`);
            } catch (_) {
                // best effort
            }
        }
    }

    function readMaxInflight() {
        try {
            const raw = readFileSync(MAX_INFLIGHT_FILE, 'utf-8').trim();
            return raw === '' ? 0 : parseInt(raw, 10);
        } catch (_) {
            return 0;
        }
    }

    function timeImport(tempDir, concurrency) {
        runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: ['--abort'],
        });

        resetInflightGauge();

        const start = Date.now();
        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: [
                '--follow-symlinks',
                `--symlink-follow-concurrency=${concurrency}`,
            ],
            // Sequential: 74 × 5s ≈ 370s plus preflight + index overhead.
            // Give it 12 minutes.
            timeout: 720000,
        });
        const elapsedMs = Date.now() - start;
        const maxInflight = readMaxInflight();

        assert.equal(result.exitCode, 0,
            `concurrency=${concurrency}: expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        return { elapsedMs, maxInflight };
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
        const seq = timeImport(tempDirSequential, 1);
        assertAllPayloadsDownloaded(tempDirSequential, 1);

        const conc = timeImport(tempDirConcurrent, 5);
        assertAllPayloadsDownloaded(tempDirConcurrent, 5);

        const speedup = (seq.elapsedMs / conc.elapsedMs).toFixed(2);
        // eslint-disable-next-line no-console
        console.log(
            `\n[symlink-follow-concurrency benchmark] ${SYMLINK_COUNT} symlinks, ${DELAY_SECONDS}s server delay\n` +
            `  concurrency=1: ${seq.elapsedMs} ms (max in-flight on server: ${seq.maxInflight})\n` +
            `  concurrency=5: ${conc.elapsedMs} ms (max in-flight on server: ${conc.maxInflight})\n` +
            `  speedup: ${speedup}x\n`,
        );

        // Sanity check on the server: with the concurrent client, the
        // mu-plugin should observe more than one request in flight at the
        // same time. If this fails the rolling window is still serializing
        // — either client-side (round-robin instead of curl_multi) or
        // server-side (php-fpm only handing out one worker). We expect
        // the server to be able to serve up to 5 requests at a time.
        assert.ok(
            conc.maxInflight >= 2,
            `concurrency=5: server only ever saw ${conc.maxInflight} request(s) in flight; expected >= 2`,
        );
    }, 1500000);
});
