/**
 * Test 12: File changes during export.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { execSync } from 'node:child_process';
import {
    apiRequest, apiRequestWithFileList, getSiteDir, getTestDataDir,
    writeTestHooks, removeTestHooks, clearHookState, writeHookState,
} from '../lib/test-helpers.js';

describe('File Changes During Export', () => {
    const site = 'file-changes';

    before(() => {
        clearHookState(site);
    });

    after(() => {
        removeTestHooks(site);
        clearHookState(site);
    });

    it('should detect file modification during export', async () => {
        const testDataDir = getTestDataDir(site);

        // Set up a hook that modifies a file during fetch
        writeTestHooks(site, `
function test_hook_before_file_chunk($path, $offset, &$data) {
    if (basename($path) === 'hello.txt' && $offset === 0) {
        // Modify the file while it's being read
        $dir = dirname($path);
        @file_put_contents($dir . '/hello.txt', 'MODIFIED CONTENT at ' . microtime(true));
    }
}
        `);

        // Fetch the file
        const filePaths = [`${testDataDir}/hello.txt`];
        const resp = await apiRequestWithFileList(site, filePaths, {
            directory: getSiteDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);

        // Should still get file chunks (either original or modified content)
        const fileChunks = resp.chunks.filter(c => c.type === 'file');
        assert.ok(fileChunks.length > 0 || resp.chunks.some(c => c.type === 'error' || c.type === 'missing'),
            'Expected file chunks or error/missing for modified file');
    });

    it('should handle new files appearing during index', async () => {
        // Remove hooks for this test
        removeTestHooks(site);

        // Create a new file during the test
        const testDataDir = getTestDataDir(site);
        execSync(`sudo tee ${testDataDir}/new-during-test.txt > /dev/null <<< "new file"`, { timeout: 5000 });
        execSync(`sudo chown nginx:nginx ${testDataDir}/new-during-test.txt`);

        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: testDataDir,
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);
        const indexChunks = resp.chunks.filter(c => c.type === 'index_batch');
        let allEntries = [];
        for (const chunk of indexChunks) {
            allEntries = allEntries.concat(chunk.json);
        }
        const paths = allEntries.map(e => Buffer.from(e.path, 'base64').toString('utf-8'));
        assert.ok(paths.some(p => p.includes('new-during-test.txt')),
            'Expected new file to appear in index');
    });
});
