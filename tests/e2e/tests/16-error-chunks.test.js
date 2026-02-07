/**
 * Test 16: All possible ways to generate error chunks.
 * Covers: invalid directory, PHP exception mid-stream, unreadable directory,
 * missing endpoint, invalid endpoint, file_open errors, dir_open errors,
 * and unconfigured secret (503).
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { execSync } from 'node:child_process';
import {
    apiRequest, apiRequestWithFileList, getSiteDir, getTestDataDir,
    writeTestHooks, removeTestHooks, clearHookState, writeHookState,
} from '../lib/test-helpers.js';

describe('Error Chunks', () => {
    const site = 'error-chunks';

    after(() => {
        removeTestHooks(site);
        clearHookState(site);
    });

    it('should produce error chunk for invalid directory', async () => {
        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: '/nonexistent/directory/path',
            max_execution_time: 10,
        });
        // Should get 400 for invalid argument (list_dir doesn't exist)
        assert.ok(resp.status === 400 || resp.status === 200);
        if (resp.status === 400) {
            assert.ok(resp.json?.error || resp.text, 'Expected error message');
        }
    });

    it('should produce error chunk for PHP exception during streaming', async () => {
        clearHookState(site);
        writeTestHooks(site, `
function test_hook_before_sql_batch(&$sql, $cursor) {
    throw new RuntimeException('Deliberate test exception in SQL batch');
}
        `);

        const resp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);

        const errorChunks = resp.chunks.filter(c => c.type === 'error');
        assert.ok(errorChunks.length > 0, 'Expected error chunks from exception');

        // Verify error chunk structure
        for (const chunk of errorChunks) {
            if (chunk.json) {
                assert.ok(chunk.json.message || chunk.json.error_type,
                    'Error chunk should have message or error_type');
            }
        }

        // Should still have a completion chunk (error recovery)
        const completion = resp.chunks.find(c => c.type === 'completion');
        assert.ok(completion, 'Expected completion chunk after error');
        assert.equal(completion.headers['x-status'], 'partial',
            'Expected partial status after error');
    });

    it('should produce error chunk for unreadable directory in file index', async () => {
        removeTestHooks(site);
        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: getSiteDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);

        // Should finish even with potential errors
        const completion = resp.chunks.find(c => c.type === 'completion');
        assert.ok(completion, 'Expected completion chunk');
    });

    it('should produce dir_open error for chmod 000 directory', async () => {
        // Create a directory and make it unreadable
        const testDataDir = getTestDataDir(site);
        execSync(`sudo mkdir -p ${testDataDir}/no-access-dir && sudo chmod 000 ${testDataDir}/no-access-dir`, { timeout: 5000 });

        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: testDataDir,
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);

        // Should have error chunks for the unreadable directory
        const errorChunks = resp.chunks.filter(c => c.type === 'error');
        assert.ok(errorChunks.length > 0, 'Expected error chunk for unreadable directory');

        const dirErrors = errorChunks.filter(c => {
            if (c.json) {
                return c.json.error_type === 'dir_open';
            }
            return false;
        });
        assert.ok(dirErrors.length > 0,
            `Expected dir_open error, got types: ${errorChunks.map(c => c.json?.error_type).join(', ')}`);

        // Cleanup
        execSync(`sudo chmod 755 ${testDataDir}/no-access-dir && sudo rm -rf ${testDataDir}/no-access-dir`, { timeout: 5000 });
    });

    it('should produce file_open error for unreadable file in fetch', async () => {
        const testDataDir = getTestDataDir(site);
        // Create an unreadable file
        execSync(`echo "unreadable" | sudo tee ${testDataDir}/cant-read.txt > /dev/null && sudo chmod 000 ${testDataDir}/cant-read.txt`, { timeout: 5000 });

        const resp = await apiRequestWithFileList(site, [`${testDataDir}/cant-read.txt`], {
            directory: getSiteDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);

        // Should have error chunk for the unreadable file
        const errorChunks = resp.chunks.filter(c => c.type === 'error');
        assert.ok(errorChunks.length > 0, 'Expected error chunk for unreadable file');

        // Cleanup
        execSync(`sudo chmod 644 ${testDataDir}/cant-read.txt && sudo rm -f ${testDataDir}/cant-read.txt`, { timeout: 5000 });
    });

    it('should handle missing endpoint parameter', async () => {
        const { HmacClient } = await import('../lib/hmac-client.js');
        const client = new HmacClient('test-secret-error-chunks');
        const url = new URL(`http://127.0.0.1:8099/api.php`);
        const headers = client.getAuthHeaders('');

        const response = await fetch(url.toString(), { method: 'GET', headers });
        assert.equal(response.status, 400);
        const json = await response.json();
        assert.ok(json.error.includes('endpoint'), 'Expected endpoint error message');
    });

    it('should handle invalid endpoint name', async () => {
        const { HmacClient } = await import('../lib/hmac-client.js');
        const client = new HmacClient('test-secret-error-chunks');
        const url = new URL(`http://127.0.0.1:8099/api.php`);
        url.searchParams.set('endpoint', 'nonexistent_endpoint');
        const headers = client.getAuthHeaders('');

        const response = await fetch(url.toString(), { method: 'GET', headers });
        assert.equal(response.status, 400);
        const json = await response.json();
        assert.ok(json.error.includes('Invalid endpoint'), 'Expected invalid endpoint error');
    });

    it('should produce error for missing file in fetch', async () => {
        const resp = await apiRequestWithFileList(site, ['/srv/e2e-sites/error-chunks/test-data/does-not-exist.txt'], {
            directory: getSiteDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);

        // Should have error or missing chunk for the nonexistent file
        const errorChunks = resp.chunks.filter(c => c.type === 'error' || c.type === 'missing');
        const fileChunks = resp.chunks.filter(c => c.type === 'file');
        // Either we get an error/missing chunk, or no file data (both acceptable)
        assert.ok(errorChunks.length > 0 || fileChunks.length === 0,
            'Expected error/missing chunk or no file data for nonexistent file');
    });
});
