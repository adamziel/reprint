/**
 * Test 39: Runtime files (ini_get_all and auto_prepend/append)
 *
 * Verifies that after preflight:
 * - runtime_files/ directory is created in the state directory
 * - ini_get_all.json is written with the full computed PHP configuration
 * - auto_prepend_file and auto_append_file are reported in preflight data
 *   (and downloaded when accessible, or absent when not set)
 * - Re-running preflight wipes and recreates runtime_files/
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, readdirSync, writeFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir, readAuditLog,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Runtime files', () => {
    const site = 'basic';
    let tempDir;

    function importUrlWithDirectory() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    beforeAll(async () => {
        await ensureSite(site);
        tempDir = createTempDir('e2e-runtime-files');

        const result = runImporter(importUrlWithDirectory(), tempDir, 'preflight', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    it('creates runtime_files/ directory', () => {
        const runtimeDir = join(tempDir, 'runtime_files');
        assert.ok(existsSync(runtimeDir), 'runtime_files/ directory should exist after preflight');
    });

    it('writes ini_get_all.json with valid INI directives', () => {
        const iniPath = join(tempDir, 'runtime_files', 'ini_get_all.json');
        assert.ok(existsSync(iniPath), 'ini_get_all.json should exist');

        const iniData = JSON.parse(readFileSync(iniPath, 'utf-8'));
        assert.equal(typeof iniData, 'object', 'ini_get_all.json should be an object');

        // Every PHP installation has these core directives.
        assert.ok('max_execution_time' in iniData, 'Should contain max_execution_time');
        assert.ok('memory_limit' in iniData, 'Should contain memory_limit');
        assert.ok('error_reporting' in iniData, 'Should contain error_reporting');
        assert.ok('upload_max_filesize' in iniData, 'Should contain upload_max_filesize');

        // ini_get_all(null, false) returns scalar values (local value only),
        // not the detailed {global_value, local_value, access} objects.
        const entry = iniData['max_execution_time'];
        assert.equal(typeof entry, 'string', 'INI values should be strings (local value)');
    });

    it('ini_get_all.json matches preflight state data', () => {
        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        const stateIni = state.preflight?.data?.runtime?.ini_get_all;
        assert.ok(stateIni, 'preflight state should contain runtime.ini_get_all');

        const fileIni = JSON.parse(
            readFileSync(join(tempDir, 'runtime_files', 'ini_get_all.json'), 'utf-8')
        );

        // The file should contain the same directives as the state.
        // State values may be base64-encoded for path fields, but ini_get_all
        // values are not paths so they should match directly.
        const stateKeys = Object.keys(stateIni).sort();
        const fileKeys = Object.keys(fileIni).sort();
        assert.deepEqual(fileKeys, stateKeys, 'ini_get_all.json keys should match state keys');
    });

    it('reports auto_prepend_file and auto_append_file in preflight', () => {
        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        const runtime = state.preflight?.data?.runtime;
        assert.ok(runtime, 'preflight state should contain runtime section');

        // These fields must be present in the runtime section (even if null).
        assert.ok('auto_prepend_file' in runtime, 'runtime should have auto_prepend_file field');
        assert.ok('auto_append_file' in runtime, 'runtime should have auto_append_file field');

        // In the default e2e environment these are typically not set.
        // If they are set, the value should be a base64-encoded path string.
        for (const key of ['auto_prepend_file', 'auto_append_file']) {
            const value = runtime[key];
            if (value !== null) {
                assert.equal(typeof value, 'string', `runtime.${key} should be a string or null`);
                assert.ok(
                    value.startsWith('base64:'),
                    `runtime.${key} should be base64-encoded when set, got: ${value}`
                );
            }
        }
    });

    it('audit log records runtime file activity', () => {
        const log = readAuditLog(tempDir);
        assert.ok(log.includes('RUNTIME FILES'), 'Audit log should mention RUNTIME FILES');
        assert.ok(log.includes('ini_get_all.json'), 'Audit log should mention ini_get_all.json');
    });

    it('wipes runtime_files/ on preflight re-run', () => {
        const runtimeDir = join(tempDir, 'runtime_files');

        // Plant a stale file that should be removed on re-run.
        const staleDir = join(runtimeDir, 'stale');
        mkdirSync(staleDir, { recursive: true });
        writeFileSync(join(staleDir, 'old.ini'), 'should be deleted');
        assert.ok(existsSync(join(staleDir, 'old.ini')), 'Stale file should exist before re-run');

        // Re-run preflight.
        const result = runImporter(importUrlWithDirectory(), tempDir, 'preflight', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

        // Stale file should be gone.
        assert.ok(!existsSync(join(staleDir, 'old.ini')), 'Stale file should be removed after preflight re-run');

        // But ini_get_all.json should be freshly written.
        assert.ok(existsSync(join(runtimeDir, 'ini_get_all.json')), 'ini_get_all.json should exist after re-run');
    });
});
