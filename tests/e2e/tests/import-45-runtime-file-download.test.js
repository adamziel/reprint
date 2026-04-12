/**
 * Test 45: Runtime file download — completion chunk handling
 *
 * Verifies that when auto_prepend_file points to a real file, preflight
 * downloads it into runtime_files/ without a spurious "Fetch failed" error.
 *
 * The bug: fetch_files_into()'s on_chunk callback didn't handle the
 * "completion" chunk type, so fetch_streaming() always threw
 * "missing completion chunk from server" even though the file was
 * already saved.  The exception was caught and logged as non-fatal,
 * so the download still counted — but the error log was misleading.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir, readAuditLog,
} from '../lib/test-helpers.js';
import { ensureSite, SITE_ROOT } from '../lib/site-setup.js';
import { writeFileSync, mkdirSync } from 'node:fs';
import { execSync } from 'node:child_process';

describe('Import: Runtime file download', () => {
    const site = 'runtime-download';
    let tempDir;

    function importUrlWithDirectory() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    beforeAll(async () => {
        const siteDir = getSiteDir(site);
        const prependPath = join(siteDir, 'scripts', 'env.php');

        await ensureSite(site, {
            afterCreate: async (dir) => {
                // Create the prepend script inside the site directory.
                mkdirSync(join(dir, 'scripts'), { recursive: true });
                writeFileSync(
                    join(dir, 'scripts', 'env.php'),
                    '<?php // auto_prepend stub for e2e test\n',
                );

                // Configure PHP to use this as auto_prepend_file via .user.ini.
                // PHP-FPM reads .user.ini per-directory for PHP_INI_PERDIR settings.
                writeFileSync(
                    join(dir, '.user.ini'),
                    `auto_prepend_file = ${prependPath}\n`,
                );
            },
        });

        tempDir = createTempDir('e2e-runtime-download');

        const result = runImporter(importUrlWithDirectory(), tempDir, 'preflight', {
            secret: getSiteSecret(site),
        });
        assert.equal(result.exitCode, 0,
            `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    afterAll(() => {
        cleanupTempDir(tempDir);
    });

    it('preflight state reports auto_prepend_file path', () => {
        const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
        const iniAll = state.preflight?.data?.runtime?.ini_get_all;
        const prepend = iniAll?.auto_prepend_file ?? '';
        assert.ok(
            prepend.includes('scripts/env.php'),
            `auto_prepend_file should point to scripts/env.php, got: ${prepend}`,
        );
    });

    it('downloads the prepend script into runtime_files/', () => {
        const downloaded = join(tempDir, 'runtime_files', 'scripts', 'env.php');

        // Glob for any file under runtime_files/scripts/ if exact path differs
        assert.ok(
            existsSync(join(tempDir, 'runtime_files')),
            'runtime_files/ directory should exist',
        );

        // The path inside runtime_files mirrors the absolute server path.
        // Find it by checking the audit log for the saved path.
        const log = readAuditLog(tempDir);
        const saveMatch = log.match(/Saved .+ → (.+)/);
        assert.ok(saveMatch, 'Audit log should contain a "Saved" line for the prepend script');

        const savedPath = saveMatch[1];
        assert.ok(
            savedPath.includes('env.php'),
            `Saved path should contain env.php, got: ${savedPath}`,
        );
    });

    it('audit log does NOT contain "Fetch failed"', () => {
        const log = readAuditLog(tempDir);
        assert.ok(
            !log.includes('Fetch failed'),
            'Audit log should not contain "Fetch failed" — the completion chunk should be handled.\n' +
            'Relevant log lines:\n' +
            log.split('\n').filter(l => l.includes('RUNTIME') || l.includes('Fetch') || l.includes('Saved')).join('\n'),
        );
    });
});
