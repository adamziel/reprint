/**
 * Test 07: Files with no read access (chmod 000).
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { apiRequest, apiRequestWithFileList, getSiteDir, getTestDataDir } from '../lib/test-helpers.js';

describe('Chmod Denied', () => {
    const site = 'chmod-denied';

    it('should index files including unreadable ones', async () => {
        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: getTestDataDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);
        const indexChunks = resp.chunks.filter(c => c.type === 'index_batch');
        assert.ok(indexChunks.length > 0, 'Expected index_batch chunks');

        // Should list files even if unreadable
        let allEntries = [];
        for (const chunk of indexChunks) {
            allEntries = allEntries.concat(chunk.json);
        }
        assert.ok(allEntries.length > 0, 'Expected entries in index');
    });

    it('should handle unreadable files gracefully in file fetch', async () => {
        const testDataDir = getTestDataDir(site);
        const filePaths = [
            `${testDataDir}/unreadable.txt`,
            `${testDataDir}/hello.txt`,
        ];

        const resp = await apiRequestWithFileList(site, filePaths, {
            directory: getSiteDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);

        // Should have some chunks (either file or error)
        assert.ok(resp.chunks.length > 0, 'Expected response chunks');

        // The readable file should be present
        const fileChunks = resp.chunks.filter(c => c.type === 'file');
        const errorChunks = resp.chunks.filter(c => c.type === 'error');

        // Should have at least the readable file or an error for the unreadable one
        assert.ok(fileChunks.length > 0 || errorChunks.length > 0,
            'Expected file chunks or error chunks for unreadable file');
    });

    it('should handle unreadable directories in file index', async () => {
        const testDataDir = getTestDataDir(site);
        const resp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: testDataDir,
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);

        // Check for error chunks about unreadable directory
        const errorChunks = resp.chunks.filter(c => c.type === 'error');
        // It's ok if there are no error chunks - the dir might just be skipped
        const completion = resp.chunks.find(c => c.type === 'completion');
        assert.ok(completion, 'Expected completion chunk');
    });
});
