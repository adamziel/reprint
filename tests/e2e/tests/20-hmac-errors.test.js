/**
 * Test 20: HMAC authentication error cases.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { HmacClient } from '../lib/hmac-client.js';
import { getSiteDir, getSiteSecret, getSiteUrl } from '../lib/test-helpers.js';

describe('HMAC Authentication Errors', () => {
    const site = 'hmac-errors';

    it('should reject request with no auth headers', async () => {
        const url = new URL(getSiteUrl(site));
        url.searchParams.set('endpoint', 'preflight');

        const response = await fetch(url.toString());
        assert.equal(response.status, 403);
        const json = await response.json();
        assert.ok(json.error.includes('Missing X-Auth-Signature'),
            `Expected signature error, got: ${json.error}`);
    });

    it('should reject request with wrong secret', async () => {
        const wrongClient = new HmacClient('wrong-secret-totally-invalid');
        const url = new URL(getSiteUrl(site));
        url.searchParams.set('endpoint', 'preflight');

        const headers = wrongClient.getAuthHeaders('');
        const response = await fetch(url.toString(), { headers });
        assert.equal(response.status, 403);
        const json = await response.json();
        assert.ok(json.error.includes('HMAC signature verification failed'),
            `Expected HMAC failure, got: ${json.error}`);
    });

    it('should reject request with expired timestamp', async () => {
        const client = new HmacClient(getSiteSecret(site));
        const url = new URL(getSiteUrl(site));
        url.searchParams.set('endpoint', 'preflight');

        // Create headers with timestamp from 10 minutes ago (exceeds 300s tolerance)
        const nonce = client.generateNonce();
        const expiredTimestamp = ((Date.now() / 1000) - 600).toFixed(6);
        const contentHash = client.sha256('');
        const signature = client.computeSignature(nonce, expiredTimestamp, contentHash);

        const headers = {
            'X-Auth-Signature': signature,
            'X-Auth-Nonce': nonce,
            'X-Auth-Timestamp': expiredTimestamp,
            'X-Auth-Content-Hash': contentHash,
        };

        const response = await fetch(url.toString(), { headers });
        assert.equal(response.status, 403);
        const json = await response.json();
        assert.ok(json.error.includes('timestamp expired'),
            `Expected timestamp error, got: ${json.error}`);
    });

    it('should reject request with missing nonce', async () => {
        const client = new HmacClient(getSiteSecret(site));
        const url = new URL(getSiteUrl(site));
        url.searchParams.set('endpoint', 'preflight');

        const headers = client.getAuthHeaders('');
        delete headers['X-Auth-Nonce'];

        const response = await fetch(url.toString(), { headers });
        assert.equal(response.status, 403);
        const json = await response.json();
        assert.ok(json.error.includes('Missing X-Auth-Nonce'),
            `Expected nonce error, got: ${json.error}`);
    });

    it('should reject request with short nonce', async () => {
        const client = new HmacClient(getSiteSecret(site));
        const url = new URL(getSiteUrl(site));
        url.searchParams.set('endpoint', 'preflight');

        const headers = client.getAuthHeaders('');
        headers['X-Auth-Nonce'] = 'short'; // Less than 16 chars

        const response = await fetch(url.toString(), { headers });
        assert.equal(response.status, 403);
        const json = await response.json();
        assert.ok(json.error.includes('Nonce must be at least 16'),
            `Expected nonce length error, got: ${json.error}`);
    });

    it('should reject request with missing timestamp', async () => {
        const client = new HmacClient(getSiteSecret(site));
        const url = new URL(getSiteUrl(site));
        url.searchParams.set('endpoint', 'preflight');

        const headers = client.getAuthHeaders('');
        delete headers['X-Auth-Timestamp'];

        const response = await fetch(url.toString(), { headers });
        assert.equal(response.status, 403);
        const json = await response.json();
        assert.ok(json.error.includes('Missing X-Auth-Timestamp'),
            `Expected timestamp error, got: ${json.error}`);
    });

    it('should reject request with tampered content hash', async () => {
        const client = new HmacClient(getSiteSecret(site));
        const url = new URL(getSiteUrl(site));
        url.searchParams.set('endpoint', 'preflight');

        const headers = client.getAuthHeaders('');
        // Tamper with the content hash
        headers['X-Auth-Content-Hash'] = 'a'.repeat(64);

        const response = await fetch(url.toString(), { headers });
        assert.equal(response.status, 403);
        const json = await response.json();
        // Either HMAC fails or content hash mismatch
        assert.ok(
            json.error.includes('HMAC signature') || json.error.includes('Content hash'),
            `Expected auth error, got: ${json.error}`
        );
    });

    it('should reject request with tampered signature', async () => {
        const client = new HmacClient(getSiteSecret(site));
        const url = new URL(getSiteUrl(site));
        url.searchParams.set('endpoint', 'preflight');

        const headers = client.getAuthHeaders('');
        headers['X-Auth-Signature'] = 'b'.repeat(64);

        const response = await fetch(url.toString(), { headers });
        assert.equal(response.status, 403);
        const json = await response.json();
        assert.ok(json.error.includes('HMAC signature verification failed'),
            `Expected HMAC failure, got: ${json.error}`);
    });

    it('should accept valid request with correct HMAC', async () => {
        const client = new HmacClient(getSiteSecret(site));
        const url = new URL(getSiteUrl(site));
        url.searchParams.set('endpoint', 'preflight');
        url.searchParams.set('directory', getSiteDir(site));

        const headers = client.getAuthHeaders('');
        const response = await fetch(url.toString(), { headers });
        assert.equal(response.status, 200, 'Valid HMAC should be accepted');
    });
});
