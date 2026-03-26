/**
 * Test 40: --defer-uploads
 *
 * Tests that --defer-uploads skips uploads during files-sync and only
 * downloads them when the flag is removed on a subsequent run.
 *
 * The remote site has:
 *   - Standard WordPress files (wp-admin, wp-includes, wp-content/themes, etc.)
 *   - Test data files
 *   - Explicit upload files under wp-content/uploads/2024/{01,06}/
 *
 * With --defer-uploads, the importer should:
 *   1. Route uploads to .import-download-list-deferred.jsonl during diff
 *   2. Download non-upload files only (fetch stage)
 *   3. Complete — uploads are NOT downloaded
 *   4. Leave the deferred list on disk
 *
 * Without --defer-uploads on the next run:
 *   5. Detect the deferred list and download uploads (fetch-deferred stage)
 *   6. Clean up the deferred list
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

const UPLOAD_FILES = [
    'wp-content/uploads/2024/01/photo.jpg',
    'wp-content/uploads/2024/01/banner.png',
    'wp-content/uploads/2024/01/document.pdf',
    'wp-content/uploads/2024/06/summer.jpg',
];

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
    // Test: --defer-uploads skips uploads, second run downloads them
    // ------------------------------------------------------------------
    describe('deferred uploads: skip then download', () => {
        let tempDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-defer-uploads-basic');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        it('files-sync with --defer-uploads completes', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                extraArgs: ['--defer-uploads'],
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('state shows complete with defer_uploads persisted', () => {
            const state = JSON.parse(readFileSync(join(tempDir, '.import-state.json'), 'utf-8'));
            assert.equal(state.command, 'files-sync');
            assert.equal(state.status, 'complete');
            assert.equal(state.defer_uploads, true);
        });

        it('upload files are NOT in the docroot yet', () => {
            const importedRoot = join(docrootDir(tempDir), siteDir);
            for (const f of UPLOAD_FILES) {
                assert.ok(!existsSync(join(importedRoot, f)),
                    `Expected upload file to NOT exist yet: ${f}`);
            }
        });

        it('essential files (non-uploads) were downloaded', () => {
            const importedRoot = join(docrootDir(tempDir), siteDir);
            assert.ok(existsSync(join(importedRoot, 'wp-load.php')),
                'Expected wp-load.php to exist');
            assert.ok(existsSync(join(importedRoot, 'wp-config.php')),
                'Expected wp-config.php to exist');
            assert.ok(existsSync(join(importedRoot, 'test-data', 'hello.txt')),
                'Expected test-data/hello.txt to exist');
        });

        it('deferred download list remains on disk', () => {
            const deferredList = join(tempDir, '.import-download-list-deferred.jsonl');
            assert.ok(existsSync(deferredList),
                'Expected deferred download list to remain on disk');
        });

        it('audit log shows uploads were deferred', () => {
            const audit = readAuditLog(tempDir);
            assert.ok(audit.includes('ESSENTIAL FILES COMPLETE'),
                'Expected ESSENTIAL FILES COMPLETE in audit log');
            assert.ok(audit.includes('uploads deferred'),
                'Expected "uploads deferred" in audit log');
        });

        // Now run again without --defer-uploads to download the uploads
        it('re-running without --defer-uploads downloads the uploads', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('upload files now exist in the docroot', () => {
            const importedRoot = join(docrootDir(tempDir), siteDir);
            for (const f of UPLOAD_FILES) {
                assert.ok(existsSync(join(importedRoot, f)),
                    `Expected upload file to exist after second run: ${f}`);
            }
        });

        it('deferred download list was cleaned up', () => {
            const deferredList = join(tempDir, '.import-download-list-deferred.jsonl');
            assert.ok(!existsSync(deferredList),
                'Expected deferred download list to be cleaned up after uploads downloaded');
        });

        it('all files match source', () => {
            const importedRoot = join(docrootDir(tempDir), siteDir);
            assertTreesMatch(siteDir, importedRoot);
        });
    });

    // ------------------------------------------------------------------
    // Test: --defer-uploads survives resume cycles (essential files only)
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

        it('uploads were NOT downloaded', () => {
            const importedRoot = join(docrootDir(tempDir), siteDir);
            for (const f of UPLOAD_FILES) {
                assert.ok(!existsSync(join(importedRoot, f)),
                    `Expected upload file to NOT exist: ${f}`);
            }
        });

        it('deferred list remains on disk', () => {
            assert.ok(existsSync(join(tempDir, '.import-download-list-deferred.jsonl')),
                'Expected deferred download list to remain');
        });
    });

    // ------------------------------------------------------------------
    // Test: without --defer-uploads, everything downloads in one shot
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

        it('uploads were downloaded normally', () => {
            const importedRoot = join(docrootDir(tempDir), siteDir);
            for (const f of UPLOAD_FILES) {
                assert.ok(existsSync(join(importedRoot, f)),
                    `Expected upload file to exist: ${f}`);
            }
        });
    });

    // ------------------------------------------------------------------
    // Test: --defer-uploads added mid-flight re-splits the download list
    // ------------------------------------------------------------------
    describe('--defer-uploads added mid-flight re-splits download list', () => {
        let tempDir;

        beforeAll(() => {
            tempDir = createTempDir('e2e-defer-uploads-mid-flight');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        it('starts without --defer-uploads, stops early, resumes with it', () => {
            // Run files-sync WITHOUT --defer-uploads. Use --max-exec=1 to
            // stop early in the fetch stage with uploads still in the main list.
            const firstRun = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                extraArgs: ['--max-exec=1'],
                autoResume: false,
            });
            assert.ok(
                firstRun.exitCode === 0 || firstRun.exitCode === 2,
                `Expected exit 0 or 2, got ${firstRun.exitCode}\nstderr: ${firstRun.stderr}`,
            );

            // Resume WITH --defer-uploads — the list should be re-split and
            // the importer should complete without downloading uploads.
            const resumed = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
                extraArgs: ['--defer-uploads'],
            });
            assert.equal(resumed.exitCode, 0,
                `Expected exit 0\nstderr: ${resumed.stderr}\nstdout: ${resumed.stdout}`);
        });

        it('audit log shows the download list was re-split', () => {
            const audit = readAuditLog(tempDir);
            assert.ok(audit.includes('re-splitting download list'),
                'Expected "re-splitting download list" in audit log');
        });

        it('uploads were NOT downloaded (deferred)', () => {
            const importedRoot = join(docrootDir(tempDir), siteDir);
            for (const f of UPLOAD_FILES) {
                assert.ok(!existsSync(join(importedRoot, f)),
                    `Expected upload file to NOT exist: ${f}`);
            }
        });

        it('deferred list remains on disk', () => {
            assert.ok(existsSync(join(tempDir, '.import-download-list-deferred.jsonl')),
                'Expected deferred download list to remain');
        });

        // Now download the uploads
        it('re-running without --defer-uploads downloads them', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0,
                `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('all files match source after uploading deferred files', () => {
            const importedRoot = join(docrootDir(tempDir), siteDir);
            assertTreesMatch(siteDir, importedRoot);
        });
    });
});
