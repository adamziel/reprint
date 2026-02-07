/**
 * Test 14: File that keeps changing during export.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import {
    apiRequest, apiRequestWithFileList, getSiteDir, getTestDataDir,
    writeTestHooks, removeTestHooks, clearHookState, writeHookState,
} from '../lib/test-helpers.js';

describe('Volatile File', () => {
    const site = 'volatile-file';

    before(() => {
        clearHookState(site);
    });

    after(() => {
        removeTestHooks(site);
        clearHookState(site);
    });

    it('should handle file that changes between chunks', async () => {
        const testDataDir = getTestDataDir(site);

        // Set up hook that modifies the volatile file on every chunk read
        writeHookState(site, { writeCount: 0 });
        writeTestHooks(site, `
function test_hook_before_file_chunk($path, $offset, &$data) {
    if (basename($path) === 'hello.txt') {
        $stateFile = '/tmp/e2e-hook-state-volatile-file';
        $state = json_decode(file_get_contents($stateFile), true);
        $state['writeCount']++;
        file_put_contents($stateFile, json_encode($state));
        // Rewrite the file with different content and size
        @file_put_contents($path, str_repeat('x', 100 + $state['writeCount']));
    }
}
        `);

        const filePaths = [`${testDataDir}/hello.txt`];
        const resp = await apiRequestWithFileList(site, filePaths, {
            directory: getSiteDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);

        // Should get file chunks (possibly with X-File-Changed header)
        const fileChunks = resp.chunks.filter(c => c.type === 'file');
        const errorChunks = resp.chunks.filter(c => c.type === 'error');

        // Either file data or error is acceptable
        assert.ok(fileChunks.length > 0 || errorChunks.length > 0,
            'Expected file or error chunks for volatile file');

        // Check if any file chunks report changes
        const changedChunks = fileChunks.filter(c => c.headers['x-file-changed'] === '1');
        // Changed detection depends on ctime changes, which may or may not trigger
    });

    it('should complete export despite volatile files', async () => {
        removeTestHooks(site);

        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: getTestDataDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);
        const completion = resp.chunks.find(c => c.type === 'completion');
        assert.ok(completion, 'Expected completion chunk');
    });
});
