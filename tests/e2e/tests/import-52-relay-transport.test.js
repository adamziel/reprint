/**
 * Test 52: Relay export transport
 *
 * Verifies that the importer can keep its target-controlled request flow while
 * a source-side worker initiates the actual connection to the exporter. This is
 * the transport shape needed for Studio push when the local source can reach the
 * remote target but the remote target cannot directly reach localhost.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import {
    createTempDir,
    cleanupTempDir,
    getSiteUrl,
    getSiteSecret,
    getSiteDir,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const PROJECT_ROOT = join(import.meta.dirname, '..', '..', '..');
const IMPORTER_PATH = process.env.IMPORTER_PATH || join(PROJECT_ROOT, 'importer', 'import.php');
const PHP_BINARY = process.env.PHP_BINARY || 'php';
const RELAY_WAIT_TIMEOUT_SECONDS = 120;
const TARGET_PROCESS_TIMEOUT_MS = 150000;

describe('Import: Relay Transport', { timeout: 300000 }, () => {
    const site = 'basic';
    let tempDir;
    let relayDir;
    let workerStateDir;
    let worker;
    let workerOutput = '';

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-relay-target');
        relayDir = createTempDir('e2e-relay-dir');
        workerStateDir = createTempDir('e2e-relay-source');
    });

    afterAll(() => {
        stopRelayWorker();
        cleanupTempDir(tempDir);
        cleanupTempDir(relayDir);
        cleanupTempDir(workerStateDir);
    });

    function startRelayWorker() {
        stopRelayWorker();
        workerOutput = '';
        worker = spawn(PHP_BINARY, [
            IMPORTER_PATH,
            'relay-source',
            getSiteUrl(site),
            `--state-dir=${workerStateDir}`,
            `--fs-root=${join(workerStateDir, 'fs-root')}`,
            `--secret=${getSiteSecret(site)}`,
            `--relay-dir=${relayDir}`,
            `--relay-allow-path=${getSiteDir(site)}`,
            '--relay-idle-timeout=120',
            '--verbose',
        ], {
            stdio: ['ignore', 'pipe', 'pipe'],
            env: { ...process.env },
        });
        // The worker is intentionally chatty while polling. Drain its pipes so
        // a full stdout/stderr buffer cannot block the source side and make the
        // target look like it timed out waiting for a relay response.
        const captureWorkerOutput = (chunk) => {
            workerOutput = (workerOutput + chunk.toString()).slice(-20000);
        };
        worker.stdout.on('data', captureWorkerOutput);
        worker.stderr.on('data', captureWorkerOutput);
    }

    function stopRelayWorker() {
        if (worker && worker.exitCode === null) {
            worker.kill('SIGTERM');
        }
    }

    function runTarget(command, extraArgs = []) {
        const args = [
            IMPORTER_PATH,
            command,
            getSiteUrl(site),
            `--state-dir=${tempDir}`,
            `--fs-root=${fsRootDir(tempDir)}`,
            `--secret=${getSiteSecret(site)}`,
            '--transport=relay',
            `--relay-dir=${relayDir}`,
            // Full e2e runs execute many PHP versions in parallel. Keep the
            // target wait longer than the source worker's ordinary request
            // latency so this test exercises relay correctness, not CI load.
            `--relay-timeout=${RELAY_WAIT_TIMEOUT_SECONDS}`,
            ...extraArgs,
        ];
        startRelayWorker();
        try {
            return execFileSync(PHP_BINARY, args, {
                timeout: TARGET_PROCESS_TIMEOUT_MS,
                encoding: 'utf-8',
                env: { ...process.env },
                maxBuffer: 20 * 1024 * 1024,
            });
        } catch (error) {
            error.message += `\n\nRelay source output:\n${workerOutput}\n\nRelay files:\n${relayFilesListing()}`;
            throw error;
        } finally {
            stopRelayWorker();
        }
    }

    function relayFilesListing() {
        try {
            return execFileSync('find', [relayDir, '-maxdepth', '2', '-type', 'f', '-print'], {
                encoding: 'utf-8',
            });
        } catch (error) {
            return `Unable to list relay files: ${error.message}`;
        }
    }

    it('runs preflight through the relay source worker', () => {
        const output = runTarget('preflight');
        assert.match(output, /"ok"\s*:\s*true/, 'Expected relayed preflight to report ok');

        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        assert.equal(state.preflight.http_code, 200);
        assert.equal(state.preflight.data.ok, true);
    });

    it('pulls selected files through target-authored relay requests', () => {
        const sourceDir = getSiteDir(site);
        const selectedPath = join(sourceDir, 'test-data');
        const output = runTarget('files-pull', [`--only=${selectedPath}`]);
        assert.match(output, /files-pull complete/, 'Expected relayed files-pull to complete');

        const importedHello = join(
            fsRootDir(tempDir),
            relative('/', join(sourceDir, 'test-data', 'hello.txt'))
        );
        assert.ok(existsSync(importedHello), `Expected ${importedHello} to exist`);
        assert.equal(
            readFileSync(importedHello, 'utf-8'),
            readFileSync(join(sourceDir, 'test-data', 'hello.txt'), 'utf-8')
        );
    });

    it('pulls the database through target-authored relay requests', () => {
        const output = runTarget('db-pull');
        assert.match(output, /db-pull complete/, 'Expected relayed db-pull to complete');

        const sqlFile = join(tempDir, 'db.sql');
        assert.ok(existsSync(sqlFile), 'Expected relayed db.sql to exist');
        const sql = readFileSync(sqlFile, 'utf-8');
        assert.ok(sql.includes('CREATE TABLE'), 'Expected CREATE TABLE in relayed db.sql');
        assert.ok(sql.includes('INSERT INTO'), 'Expected INSERT INTO in relayed db.sql');
    });

    it('leaves auditable relay request and response records', () => {
        const requestsDir = join(relayDir, 'requests');
        const responsesDir = join(relayDir, 'responses');
        assert.ok(existsSync(requestsDir), 'Expected relay requests directory');
        assert.ok(existsSync(responsesDir), 'Expected relay responses directory');

        // The worker moves completed requests into processing/ and removes them
        // after writing the response metadata. Response records are the durable
        // audit trail for this file-backed proof transport.
        const responseRecords = execFileSync('find', [responsesDir, '-name', '*.json', '-type', 'f'], {
            encoding: 'utf-8',
        }).trim().split('\n').filter(Boolean);
        assert.ok(responseRecords.length >= 3, 'Expected at least preflight, file, and database request responses');
    });
});
