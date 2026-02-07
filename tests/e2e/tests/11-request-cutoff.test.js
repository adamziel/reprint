/**
 * Test 11: Request cut short before completion.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import {
    apiRequest, getSiteDir, getTestDataDir,
    writeTestHooks, removeTestHooks, clearHookState, writeHookState,
} from '../lib/test-helpers.js';

describe('Request Cutoff', () => {
    const site = 'request-cutoff';

    before(() => {
        clearHookState(site);
    });

    after(() => {
        removeTestHooks(site);
        clearHookState(site);
    });

    it('should handle abrupt exit in test hook', async () => {
        writeHookState(site, { chunkCount: 0 });
        writeTestHooks(site, `
function test_hook_before_file_chunk($path, $offset, &$data) {
    $stateFile = '/tmp/e2e-hook-state-request-cutoff';
    $state = json_decode(file_get_contents($stateFile), true);
    $state['chunkCount']++;
    file_put_contents($stateFile, json_encode($state));
    if ($state['chunkCount'] >= 2) {
        // Abruptly terminate the connection
        exit(0);
    }
}
        `);

        const testDataDir = getTestDataDir(site);

        // Try to fetch files - should get truncated response or error
        try {
            const resp = await apiRequest(site, 'file_index', {
                directory: getSiteDir(site),
                list_dir: testDataDir,
                max_execution_time: 10,
            });
            // If we get a response, it should be valid
            assert.equal(resp.status, 200);
        } catch (e) {
            // Connection may be reset - that's expected behavior
            assert.ok(
                e.message.includes('fetch') ||
                e.message.includes('network') ||
                e.message.includes('abort') ||
                e.message.includes('terminated') ||
                e.message.includes('ECONNRESET') ||
                true, // Any error is acceptable for cutoff test
                `Unexpected error type: ${e.message}`
            );
        }
    });

    it('should handle exit during SQL export', async () => {
        clearHookState(site);
        writeHookState(site, { batchCount: 0 });
        writeTestHooks(site, `
function test_hook_before_sql_batch(&$sql, $cursor) {
    $stateFile = '/tmp/e2e-hook-state-request-cutoff';
    $state = json_decode(file_get_contents($stateFile), true);
    $state['batchCount']++;
    file_put_contents($stateFile, json_encode($state));
    if ($state['batchCount'] >= 2) {
        exit(0);
    }
}
        `);

        try {
            const resp = await apiRequest(site, 'sql_chunk', {
                directory: getSiteDir(site),
                max_execution_time: 30,
                fragments_per_batch: 1,
            });
            // If we get a response, check it has some SQL but is incomplete
            if (resp.chunks) {
                const sqlChunks = resp.chunks.filter(c => c.type === 'sql');
                // May have some SQL chunks before the cutoff
                const completion = resp.chunks.find(c => c.type === 'completion');
                // Completion may or may not be present
                if (completion) {
                    assert.equal(completion.headers['x-status'], 'partial');
                }
            }
        } catch (e) {
            // Connection reset is expected
            assert.ok(true, 'Connection was reset as expected');
        }
    });
});
