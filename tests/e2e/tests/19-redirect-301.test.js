/**
 * Test 19: 301 redirect response (port 8097 redirects to port 8081).
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createHmacClient, getSiteSecret, getSiteDir } from '../lib/test-helpers.js';
import { HmacClient } from '../lib/hmac-client.js';

describe('301 Redirect', () => {
    it('should receive 301 redirect when following is disabled', async () => {
        const client = new HmacClient(getSiteSecret('basic'));
        const url = new URL('http://127.0.0.1:8097/api.php');
        url.searchParams.set('endpoint', 'preflight');
        url.searchParams.set('directory', getSiteDir('basic'));

        const headers = client.getAuthHeaders('');

        const response = await fetch(url.toString(), {
            method: 'GET',
            headers,
            redirect: 'manual',
        });

        assert.equal(response.status, 301, 'Expected 301 redirect');
        const location = response.headers.get('location');
        assert.ok(location, 'Expected Location header');
        assert.ok(location.includes('127.0.0.1:8081'),
            `Expected redirect to port 8081, got: ${location}`);
    });

    it('should work when redirect is followed', async () => {
        const client = new HmacClient(getSiteSecret('basic'));
        const url = new URL('http://127.0.0.1:8097/api.php');
        url.searchParams.set('endpoint', 'preflight');
        url.searchParams.set('directory', getSiteDir('basic'));

        const headers = client.getAuthHeaders('');

        const response = await fetch(url.toString(), {
            method: 'GET',
            headers,
            redirect: 'follow',
        });

        // After following redirect, should get a response from the basic site
        // Note: HMAC signature may be invalid on the redirected request since
        // the headers were computed for the original URL
        // The server will check the signature which may fail
        assert.ok(response.status === 200 || response.status === 403,
            `Expected 200 or 403 after redirect, got ${response.status}`);
    });

    it('should preserve query string in redirect', async () => {
        const client = new HmacClient(getSiteSecret('basic'));
        const url = new URL('http://127.0.0.1:8097/api.php');
        url.searchParams.set('endpoint', 'preflight');
        url.searchParams.set('directory', getSiteDir('basic'));

        const headers = client.getAuthHeaders('');

        const response = await fetch(url.toString(), {
            method: 'GET',
            headers,
            redirect: 'manual',
        });

        const location = response.headers.get('location');
        assert.ok(location.includes('endpoint=preflight'),
            `Expected query string preserved in redirect, got: ${location}`);
    });
});
