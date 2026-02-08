/**
 * Test 06: Symlinks via import.php
 * Tests that symlinks-outside and circular-symlinks sites sync properly.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    hashDirectory,
    assertFileCount, assertSiteMirror,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Symlinks', () => {
    describe('symlinks-outside', () => {
        const site = 'symlinks-outside';
        let tempDir;

        before(async () => {
            await ensureSite(site, {
                afterCreate: async (siteDir) => {
                    const { execSync } = await import('node:child_process');
                    execSync('sudo mkdir -p /tmp/e2e-external-data');
                    execSync('echo "External file" | sudo tee /tmp/e2e-external-data/external.txt > /dev/null');
                    execSync('sudo chown -R nginx:nginx /tmp/e2e-external-data');
                    execSync(`sudo ln -sfn /tmp/e2e-external-data "${siteDir}/test-data/external-link"`);
                },
            });
            tempDir = createTempDir('e2e-import-symlinks');
        });

        after(() => {
            cleanupTempDir(tempDir);
        });

        function importUrl() {
            return `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
        }

        it('file sync completes', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync-initial', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('regular files are downloaded', () => {
            const importedRoot = join(tempDir, 'filesystem-root', getSiteDir(site));
            const hashes = hashDirectory(importedRoot);
            assert.ok(hashes.size > 0, 'Expected at least one regular file');
        });

        it('indexed at least 3000 files from remote', () => {
            assertFileCount(tempDir);
        });

        it('imported files form a valid WordPress site mirror', () => {
            assertSiteMirror(join(tempDir, 'filesystem-root', getSiteDir(site)));
        });

        it('sync completed without error despite symlinks', () => {
            const stateFile = join(tempDir, '.import-state.json');
            assert.ok(existsSync(stateFile), 'Expected .import-state.json to exist');
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            assert.equal(state.status, 'complete', 'Expected status to be complete');
        });
    });

    describe('circular-symlinks', () => {
        const site = 'circular-symlinks';
        let tempDir;

        before(async () => {
            await ensureSite(site, {
                afterCreate: async (siteDir) => {
                    const { execSync } = await import('node:child_process');
                    execSync(`sudo ln -sfn "${siteDir}/test-data/link-b" "${siteDir}/test-data/link-a"`);
                    execSync(`sudo ln -sfn "${siteDir}/test-data/link-a" "${siteDir}/test-data/link-b"`);
                    execSync(`sudo ln -sfn "${siteDir}/test-data/self-link" "${siteDir}/test-data/self-link"`);
                    execSync(`sudo chown -R nginx:nginx "${siteDir}" 2>/dev/null || true`);
                },
            });
            tempDir = createTempDir('e2e-import-circular');
        });

        after(() => {
            cleanupTempDir(tempDir);
        });

        function importUrl() {
            return `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
        }

        it('file sync completes within timeout (no infinite loop)', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync-initial', {
                secret: getSiteSecret(site),
                timeout: 60000,
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('regular files are downloaded', () => {
            const importedRoot = join(tempDir, 'filesystem-root', getSiteDir(site));
            const hashes = hashDirectory(importedRoot);
            assert.ok(hashes.size > 0, 'Expected at least one regular file');
        });
    });
});
