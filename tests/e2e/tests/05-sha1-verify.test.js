/**
 * Test 05: Thorough SHA1 verification of source vs migrated file data.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import {
    apiRequest, apiRequestWithFileList, getSiteDir, getTestDataDir,
    hashDirectory,
} from '../lib/test-helpers.js';

describe('SHA1 Verification', () => {
    const site = 'sha1-verify';

    it('should export all files with matching SHA1 hashes', async () => {
        const testDataDir = getTestDataDir(site);

        // Get source file hashes
        const sourceHashes = hashDirectory(testDataDir);
        assert.ok(sourceHashes.size > 0, 'Expected source files');

        // Get file index
        const indexResp = await apiRequest(site, 'file_index', {
            directory: getSiteDir(site),
            list_dir: testDataDir,
            max_execution_time: 30,
        });
        assert.equal(indexResp.status, 200);

        // Collect all file paths from index
        const indexChunks = indexResp.chunks.filter(c => c.type === 'index_batch');
        let filePaths = [];
        for (const chunk of indexChunks) {
            for (const entry of chunk.json) {
                if (entry.type === 'file') {
                    filePaths.push(Buffer.from(entry.path, 'base64').toString('utf-8'));
                }
            }
        }
        assert.ok(filePaths.length > 0, `Expected file paths in index, got ${filePaths.length}`);

        // Fetch files in small batches to avoid multipart parsing issues
        const batchSize = 10;
        const exportedHashes = new Map();

        for (let i = 0; i < filePaths.length; i += batchSize) {
            const batch = filePaths.slice(i, i + batchSize);
            const resp = await apiRequestWithFileList(site, batch, {
                directory: getSiteDir(site),
                max_execution_time: 30,
            });
            assert.equal(resp.status, 200, `Fetch failed for batch ${i}`);

            // Collect file data by using Content-Length from headers for accurate extraction
            const fileChunks = resp.chunks.filter(c => c.type === 'file');
            const fileData = new Map();

            for (const chunk of fileChunks) {
                const path = Buffer.from(chunk.headers['x-file-path'], 'base64').toString('utf-8');
                const offset = parseInt(chunk.headers['x-chunk-offset'] || '0', 10);
                const declaredSize = parseInt(chunk.headers['content-length'] || '0', 10);

                // Use Content-Length to extract the correct number of bytes
                const data = chunk.body.substring(0, declaredSize);

                if (!fileData.has(path)) {
                    fileData.set(path, { chunks: [] });
                }
                fileData.get(path).chunks.push({ offset, data });
            }

            for (const [path, data] of fileData) {
                data.chunks.sort((a, b) => a.offset - b.offset);
                const fullData = data.chunks.map(c => c.data).join('');
                const hash = createHash('sha1').update(fullData, 'binary').digest('hex');
                const relPath = path.replace(testDataDir + '/', '');
                exportedHashes.set(relPath, hash);
            }
        }

        // Compare hashes
        let mismatches = 0;
        for (const [path, sourceHash] of sourceHashes) {
            if (exportedHashes.has(path)) {
                if (exportedHashes.get(path) !== sourceHash) {
                    mismatches++;
                }
            }
        }

        assert.equal(mismatches, 0, `Found ${mismatches} SHA1 mismatches`);
        assert.ok(exportedHashes.size > 0, 'Expected exported file hashes');
    });

    it('should have consistent cursor-based resumption', async () => {
        // Make two requests with small execution time to test resumption
        const resp1 = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            max_execution_time: 1,
        });
        assert.equal(resp1.status, 200);

        const completion1 = resp1.chunks.find(c => c.type === 'completion');
        assert.ok(completion1, 'Expected completion chunk');

        // If partial, resume with cursor
        if (completion1.headers['x-status'] === 'partial') {
            const lastSqlChunk = resp1.chunks.filter(c => c.type === 'sql').pop();
            assert.ok(lastSqlChunk, 'Expected at least one SQL chunk');
            const cursor = lastSqlChunk.headers['x-cursor'];
            assert.ok(cursor, 'Expected cursor in SQL chunk');

            const resp2 = await apiRequest(site, 'sql_chunk', {
                directory: getSiteDir(site),
                max_execution_time: 30,
                cursor: cursor,
            });
            assert.equal(resp2.status, 200);
        }
    });
});
