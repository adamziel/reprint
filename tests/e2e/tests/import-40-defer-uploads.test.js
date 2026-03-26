/**
 * Test 40: --defer-uploads
 *
 * Tests that the --defer-uploads flag splits the file download into two
 * stages: essential files first (code, config, themes, plugins), then
 * uploads (the media library) second.
 *
 * The remote site has:
 *   - Standard WordPress files (wp-admin, wp-includes, wp-content/themes, etc.)
 *   - Test data files
 *   - Explicit upload files under wp-content/uploads/2024/01/
 *
 * With --defer-uploads, the importer should:
 *   1. Route uploads to .import-download-list-deferred.jsonl during diff
 *   2. Download non-upload files first (fetch stage)
 *   3. Transition to fetch-deferred stage (state file shows stage="fetch-deferred")
 *   4. Download uploads (fetch-deferred stage)
 *   5. Complete successfully with all files on disk
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    assertTreesMatch, readAuditLog,
    docrootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';
import { mkdirSync, writeFileSync } from 'node:fs';
import { randomBytes } from 'node:crypto';

describe('Import: --defer-uploads', () => {
    const site = 'defer-uploads';
    let siteDir;

    beforeAll(async () => {
        await ensureSite(site, {
            afterCreate: async (remoteSiteDir) => {
                // Create upload files that should be deferred
                const uploadsDir = join(remoteSiteDir, 'wp-content', 'uploads', '2024', '01');
                mkdirSync(uploadsDir, { recursive: true });
                writeFileSync(join(uploadsDir, 'photo.jpg'), randomBytes(4096));
                writeFileSync(join(uploadsDir, 'banner.png'), randomBytes(2048));
                writeFileSync(join(uploadsDir, 'document.pdf'), randomBytes(1024));

                const uploadsDir2 = join(remoteSiteDir, 'wp-content', 'uploads', '2024', '06');
                mkdirSync(uploadsDir2, { recursive: true });
                writeFileSync(join(uploadsDir2, 'summer.jpg'), randomBytes(3072));
            },
        });
        siteDir = getSiteDir(site);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${siteDir}`;
    }

    // ------------------------------------------------------------------
    // Test: basic --defer-uploads completes and produces correct files
    // ------------------------------------------------------------------
    describe('basic deferred uploads flow', () => {
        let tempDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-defer-uploads-basic');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        it('files-sync with --defer-uploads completes successfully', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                extraArgs: ['--defer-uploads'],
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('state file shows complete with defer_uploads persisted', () => {
            const stateFile = join(tempDir, '.import-state.json');
            assert.ok(existsSync(stateFile), 'Expected .import-state.json to exist');
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            assert.equal(state.command, 'files-sync');
            assert.equal(state.status, 'complete');
            assert.equal(state.defer_uploads, true,
                'Expected defer_uploads to be persisted in state');
        });

        it('all files were downloaded (essential + uploads)', () => {
            const importedRoot = join(docrootDir(tempDir), siteDir);
            assert.ok(existsSync(importedRoot), `Expected ${importedRoot} to exist`);
            assertTreesMatch(siteDir, importedRoot);
        });

        it('upload files exist in the docroot', () => {
            const importedRoot = join(docrootDir(tempDir), siteDir);
            const uploadFiles = [
                'wp-content/uploads/2024/01/photo.jpg',
                'wp-content/uploads/2024/01/banner.png',
                'wp-content/uploads/2024/01/document.pdf',
                'wp-content/uploads/2024/06/summer.jpg',
            ];
            for (const f of uploadFiles) {
                assert.ok(existsSync(join(importedRoot, f)),
                    `Expected upload file to exist: ${f}`);
            }
        });

        it('audit log shows deferred uploads activity', () => {
            const audit = readAuditLog(tempDir);
            assert.ok(audit.includes('DEFER-UPLOADS'),
                'Expected DEFER-UPLOADS entry in audit log');
            assert.ok(audit.includes('ESSENTIAL FILES COMPLETE'),
                'Expected ESSENTIAL FILES COMPLETE entry in audit log');
        });

        it('deferred download list was cleaned up after completion', () => {
            const deferredList = join(tempDir, '.import-download-list-deferred.jsonl');
            assert.ok(!existsSync(deferredList),
                'Expected deferred download list to be cleaned up after completion');
        });
    });

    // ------------------------------------------------------------------
    // Test: the two-stage transition is recorded in the audit log
    //
    // The audit log entry "ESSENTIAL FILES COMPLETE | transitioning to
    // deferred uploads" proves that the pipeline went through the fetch
    // stage, completed it, and entered the fetch-deferred stage — even
    // when the site is small enough for both stages to finish in a
    // single invocation.
    // ------------------------------------------------------------------
    describe('two-stage transition is recorded', () => {
        let tempDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-defer-uploads-transition');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        it('files-sync completes and audit log proves two-stage flow', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                extraArgs: ['--defer-uploads'],
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

            const audit = readAuditLog(tempDir);

            // The audit log must contain the transition marker, proving
            // the pipeline completed the essential files fetch and then
            // entered the deferred uploads stage.
            assert.ok(audit.includes('ESSENTIAL FILES COMPLETE'),
                'Expected ESSENTIAL FILES COMPLETE in audit log — proves two-stage transition');

            // The deferred download list should have been created and then cleaned up.
            assert.ok(audit.includes('deferred fetch complete'),
                'Expected "deferred fetch complete" in audit log — proves deferred stage ran');
        });
    });

    // ------------------------------------------------------------------
    // Test: --defer-uploads survives resume cycles
    // ------------------------------------------------------------------
    describe('--defer-uploads survives resume', () => {
        let tempDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-defer-uploads-resume');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        it('completes with forced resume via --max-exec=3', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                extraArgs: ['--defer-uploads', '--max-exec=3'],
                timeout: 120000,
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('state preserves defer_uploads across resume cycles', () => {
            const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
            assert.equal(state.defer_uploads, true);
            assert.equal(state.status, 'complete');
        });

        it('all files including uploads were downloaded', () => {
            const importedRoot = join(docrootDir(tempDir), siteDir);
            assertTreesMatch(siteDir, importedRoot);
        });
    });

    // ------------------------------------------------------------------
    // Test: without --defer-uploads, no deferred list is created
    // ------------------------------------------------------------------
    describe('without --defer-uploads, no deferred list', () => {
        let tempDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-defer-uploads-disabled');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        it('files-sync without --defer-uploads completes', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('no deferred download list was created', () => {
            const deferredList = join(tempDir, '.import-download-list-deferred.jsonl');
            assert.ok(!existsSync(deferredList),
                'Expected no deferred download list without --defer-uploads');
        });

        it('state does not have defer_uploads set', () => {
            const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
            assert.ok(!state.defer_uploads,
                'Expected defer_uploads to be false/absent in state');
        });
    });
});
