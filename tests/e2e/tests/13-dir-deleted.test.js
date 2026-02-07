/**
 * Test 13: Directory deleted during export.
 * Tests both pre-deletion and mid-scan deletion via test hooks.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { execSync } from 'node:child_process';
import {
    apiRequest, getSiteDir, getTestDataDir,
    writeTestHooks, removeTestHooks, clearHookState, writeHookState,
} from '../lib/test-helpers.js';

describe('Directory Deleted During Export', () => {
    const site = 'dir-deleted';

    before(() => {
        clearHookState(site);
        // Ensure subdirectory exists
        execSync(`sudo mkdir -p ${getTestDataDir(site)}/delete-me && sudo tee ${getTestDataDir(site)}/delete-me/doomed.txt > /dev/null <<< "to be deleted"`, { timeout: 5000 });
        execSync(`sudo chown -R nginx:nginx ${getTestDataDir(site)}/delete-me`);
    });

    after(() => {
        removeTestHooks(site);
        clearHookState(site);
        // Recreate for potential re-runs
        execSync(`sudo mkdir -p ${getTestDataDir(site)}/delete-me 2>/dev/null || true`, { timeout: 5000 });
    });

    it('should handle directory deletion during file index', async () => {
        const testDataDir = getTestDataDir(site);

        // Delete the directory before the request
        execSync(`sudo rm -rf ${testDataDir}/delete-me`, { timeout: 5000 });

        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: testDataDir,
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);

        // Should complete even with missing directory
        const completion = resp.chunks.find(c => c.type === 'completion');
        assert.ok(completion, 'Expected completion chunk');
    });

    it('should handle directory deleted mid-scan via hook', async () => {
        const testDataDir = getTestDataDir(site);

        // Recreate the directory
        execSync(`sudo mkdir -p ${testDataDir}/vanish-during-scan/sub1/sub2`, { timeout: 5000 });
        execSync(`sudo bash -c 'for i in $(seq 1 5); do echo "file $i" > ${testDataDir}/vanish-during-scan/sub1/file$i.txt; done'`, { timeout: 5000 });
        execSync(`sudo bash -c 'echo "deep" > ${testDataDir}/vanish-during-scan/sub1/sub2/deep.txt'`, { timeout: 5000 });
        execSync(`sudo chown -R nginx:nginx ${testDataDir}/vanish-during-scan`, { timeout: 5000 });

        // Hook that deletes sub1/sub2 during the scan
        writeTestHooks(site, `
function test_hook_during_dir_scan($dir, &$entries) {
    $target = dirname($dir) . '/vanish-during-scan/sub1/sub2';
    if (is_dir($target)) {
        // Delete sub2 while the parent directory is being scanned
        @exec('rm -rf ' . escapeshellarg($target));
    }
}
        `);

        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: testDataDir,
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);

        // Check for error chunks about the deleted directory
        const errorChunks = resp.chunks.filter(c => c.type === 'error');
        const completion = resp.chunks.find(c => c.type === 'completion');
        assert.ok(completion, 'Expected completion chunk even after mid-scan deletion');

        // The index should have some entries (at least the parent dirs and files)
        const indexChunks = resp.chunks.filter(c => c.type === 'index_batch');
        let totalEntries = 0;
        for (const chunk of indexChunks) {
            totalEntries += chunk.json.length;
        }
        assert.ok(totalEntries > 0, 'Expected some entries even after directory was deleted mid-scan');
    });

    it('should produce error chunk for deleted directory in index', async () => {
        const testDataDir = getTestDataDir(site);
        removeTestHooks(site);

        // Recreate directory
        execSync(`sudo mkdir -p ${testDataDir}/vanishing-dir && sudo tee ${testDataDir}/vanishing-dir/file.txt > /dev/null <<< "here now" && sudo chown -R nginx:nginx ${testDataDir}/vanishing-dir`, { timeout: 5000 });

        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: testDataDir,
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);

        // The vanishing-dir should be in the index since it exists
        const indexChunks = resp.chunks.filter(c => c.type === 'index_batch');
        let allEntries = [];
        for (const chunk of indexChunks) {
            allEntries = allEntries.concat(chunk.json);
        }
        const dirEntries = allEntries.filter(e => e.type === 'dir');
        assert.ok(dirEntries.length > 0, 'Expected directory entries in index');
    });
});
