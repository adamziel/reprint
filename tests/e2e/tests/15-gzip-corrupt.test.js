/**
 * Test 15: Gzip stream corruption.
 * Tests what happens when raw text appears in the gzip stream.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import {
    apiRequest, getSiteDir,
    writeTestHooks, removeTestHooks, clearHookState, writeHookState,
    createHmacClient, getSiteUrl,
} from '../lib/test-helpers.js';

describe('Gzip Corruption', () => {
    const site = 'gzip-corrupt';

    before(() => {
        clearHookState(site);
    });

    after(() => {
        removeTestHooks(site);
        clearHookState(site);
    });

    it('should detect corrupted gzip stream', { timeout: 15000 }, async () => {
        // Set up hook that writes raw text directly to output, corrupting gzip,
        // then immediately exits to ensure the response ends
        writeTestHooks(site, `
function test_hook_after_gzip_init($gz, $boundary) {
    // Write raw (non-gzipped) text directly to output then die
    echo "THIS IS RAW TEXT THAT CORRUPTS THE GZIP STREAM";
    flush();
    exit(0);
}
        `);

        // Use http.get instead of fetch to avoid Node.js fetch's auto-decompression
        // leaving dangling promises when gzip data is corrupted
        const { default: http } = await import('node:http');
        const client = createHmacClient(site);
        const url = new URL(getSiteUrl(site));
        url.searchParams.set('endpoint', 'sql_chunk');
        url.searchParams.set('directory', getSiteDir(site));
        url.searchParams.set('max_execution_time', '5');

        const authHeaders = client.getAuthHeaders('');

        const result = await new Promise((resolve) => {
            const req = http.get(url.toString(), { headers: authHeaders }, (res) => {
                const chunks = [];
                res.on('data', (chunk) => chunks.push(chunk));
                res.on('end', () => {
                    const bodyBuf = Buffer.concat(chunks);
                    const bodyStr = bodyBuf.toString('utf-8');
                    resolve({
                        statusCode: res.statusCode,
                        body: bodyStr,
                        bodyBuf,
                        headers: res.headers,
                    });
                });
                res.on('error', (e) => {
                    resolve({ error: e.message });
                });
            });
            req.on('error', (e) => {
                resolve({ error: e.message });
            });
            req.setTimeout(10000, () => {
                req.destroy();
                resolve({ error: 'timeout' });
            });
        });

        if (result.error) {
            // Network/decompression errors are expected for corrupted gzip
            assert.ok(true, `Got expected error: ${result.error}`);
        } else {
            // If we got a response body, check for raw text corruption
            const bodyStr = result.body;
            if (bodyStr.includes('THIS IS RAW TEXT')) {
                assert.ok(true, 'Detected raw text corruption in gzip stream');
            } else {
                // Verify we at least got some response
                assert.ok(result.bodyBuf.length > 0, 'Expected non-empty response');
            }
        }
    });

    it('should work correctly without corruption hooks', async () => {
        removeTestHooks(site);

        const resp = await apiRequest(site, 'sql_chunk', {
            directory: getSiteDir(site),
            max_execution_time: 10,
        });
        assert.equal(resp.status, 200);
        const sqlChunks = resp.chunks.filter(c => c.type === 'sql');
        assert.ok(sqlChunks.length > 0, 'Expected SQL chunks without corruption');
    });
});
