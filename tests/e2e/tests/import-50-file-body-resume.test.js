/**
 * Test 50: Mid-file resume after a body-stream cutoff.
 *
 * Specifically guards the contract introduced by the "stream file parts
 * directly to disk" change: now that bytes hit the local file before a
 * multipart part finishes, a request cut mid-body leaves a partially-
 * written file on disk. The importer must resume that exact file —
 * not start over (truncation) and not append duplicates (overlap) —
 * and the server-side cursor must cooperate by skipping the bytes the
 * importer already has.
 *
 * Setup: a 2 MiB random binary file. With --file-chunk-max=262144, the
 * file is sliced into eight chunks. A test_hook_before_file_chunk hook
 * exits PHP on the second chunk, which forces the failure mid-file
 * (the first chunk has been written, the file is incomplete).
 *
 * After removing the hook, files-sync resumes and completes. Final
 * assertion: SHA-256 of the imported file equals the source.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, statSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { createHash, randomBytes } from 'node:crypto';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    writeTestHooks, removeTestHooks,
    writeHookState, clearHookState,
    fsRootDir,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Mid-file Body Resume', { timeout: 180000 }, () => {
    const site = 'file-body-resume';
    const fileRel = 'test-data/big-random.bin';
    const fileSize = 2 * 1024 * 1024;
    let tempDir;
    let sourceSha256;
    let sourceFilePath;

    beforeAll(async () => {
        // Pre-generate the file in Node so we know its SHA before it
        // ever lands on the test site. Random content so we'd notice
        // any duplicated or skipped bytes — repetitive content can mask
        // overlap because the first half and the second half look the
        // same.
        const bytes = randomBytes(fileSize);
        sourceSha256 = createHash('sha256').update(bytes).digest('hex');

        await ensureSite(site, {
            files: 'none',
            afterCreate: async (siteDir) => {
                sourceFilePath = join(siteDir, fileRel);
                const dir = join(siteDir, 'test-data');
                // Use sudo via execSync — the site dir is owned by nginx
                // by the time afterCreate runs in some site-setup paths,
                // but for newly-created sites Node still has write access.
                // Falling back to writeFileSync covers the common case.
                writeFileSync(sourceFilePath, bytes);
            },
        });

        tempDir = createTempDir('e2e-mid-file-resume');
        clearHookState(site);
    });

    afterAll(() => {
        removeTestHooks(site);
        clearHookState(site);
        cleanupTempDir(tempDir);
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('first run crashes mid-file on the second body chunk', () => {
        // Hook exits the moment we're asked for any chunk past offset 0
        // for a given file. That's "first chunk written, second chunk
        // would have been written next" — exactly the mid-file-body
        // failure mode the streaming change introduces.
        writeTestHooks(site, [
            'function test_hook_before_file_chunk($path, $offset, &$data) {',
            '    if ($offset > 0) {',
            '        exit(1);',
            '    }',
            '}',
        ].join('\n'));

        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: [
                '--file-chunk-start=262144',
                '--file-chunk-max=262144',
            ],
        });
        assert.notEqual(result.exitCode, 0,
            `Expected first run to fail due to mid-file exit\nstdout: ${result.stdout}\nstderr: ${result.stderr}`);
    });

    it('partial file is on disk and smaller than source', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        const localPath = join(importedRoot, fileRel);
        assert.ok(existsSync(localPath),
            'Expected the partially-downloaded file to exist on disk; the streaming change should have flushed the first chunk before the crash');
        const partialSize = statSync(localPath).size;
        assert.ok(partialSize > 0 && partialSize < fileSize,
            `Expected a partial file (0 < size < ${fileSize}), got ${partialSize}`);
    });

    it('state records current_file and current_file_bytes for resume', () => {
        const stateFile = join(tempDir, '.import-state.json');
        assert.ok(existsSync(stateFile), 'Expected import state file to exist');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.ok(state.current_file, 'Expected state.current_file to be set after a mid-file crash');
        assert.ok(typeof state.current_file_bytes === 'number' && state.current_file_bytes > 0,
            `Expected state.current_file_bytes > 0, got ${state.current_file_bytes}`);
    });

    it('resume completes after removing the hook', () => {
        removeTestHooks(site);

        const result = runImporter(importUrl(), tempDir, 'files-sync', {
            secret: getSiteSecret(site),
            extraArgs: [
                '--file-chunk-start=262144',
                '--file-chunk-max=262144',
            ],
        });
        assert.equal(result.exitCode, 0,
            `Expected resume to complete\nstdout: ${result.stdout}\nstderr: ${result.stderr}`);

        const stateFile = join(tempDir, '.import-state.json');
        const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
        assert.equal(state.status, 'complete', `Expected status=complete after resume, got ${state.status}`);
    });

    it('resumed file matches source byte-for-byte (no gap, no duplication)', () => {
        const importedRoot = join(fsRootDir(tempDir), getSiteDir(site));
        const localPath = join(importedRoot, fileRel);
        const localSize = statSync(localPath).size;
        // Size mismatch is the smoking gun for either a gap (size < fileSize)
        // or duplicated bytes from the first pass (size > fileSize). We
        // assert size first so the failure message is clearer than a hash
        // diff would be.
        assert.equal(localSize, fileSize,
            `Resumed file size mismatch — gap or duplication. Expected ${fileSize}, got ${localSize}`);

        const localSha = createHash('sha256').update(readFileSync(localPath)).digest('hex');
        assert.equal(localSha, sourceSha256,
            'Resumed file content must hash identically to the source — any difference means the resume produced wrong bytes');
    });
});
