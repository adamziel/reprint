/**
 * Test 42: Custom Site Export route and authorization.
 *
 * Verifies that the Site Export plugin can:
 * - move the API off the default `?site-export-api` query arg
 * - authorize requests via a custom callback instead of HMAC
 */
import { describe, it, beforeAll } from 'vitest';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { ensureSite } from '../lib/site-setup.js';
import { getSiteDir, getSiteUrl } from '../lib/test-helpers.js';

describe('Import: Custom Site Export route and auth', () => {
    const site = 'custom-api-auth';

    beforeAll(async () => {
        await ensureSite(site, {
            afterCreate: async (siteDir) => {
                const muPluginsDir = join(siteDir, 'wp-content', 'mu-plugins');
                mkdirSync(muPluginsDir, { recursive: true });
                writeFileSync(
                    join(muPluginsDir, 'site-export-custom-auth.php'),
                    `<?php
add_filter('site_export_api_url', function () {
    return home_url('/wp/v2/streaming-export');
});

add_filter('site_export_authorization_callback', function () {
    return 'site_export_test_authorize_super_admin';
});

function site_export_test_authorize_super_admin() {
    require_once ABSPATH . WPINC . '/pluggable.php';

    if (is_super_admin()) {
        return null;
    }

    return 'Current user is not a super admin.';
}
`
                );
            },
        });
    });

    function customApiUrl() {
        return new URL('/wp/v2/streaming-export', new URL(getSiteUrl(site)).origin);
    }

    function createAdminCookie() {
        const allowRoot = process.getuid?.() === 0 ? ['--allow-root'] : [];
        const output = execFileSync(
            'php',
            [
                '/tmp/wp-cli.phar',
                'eval',
                'echo LOGGED_IN_COOKIE . "=" . wp_generate_auth_cookie(get_user_by("login", "admin")->ID, time() + 3600, "logged_in");',
                `--path=${getSiteDir(site)}`,
                ...allowRoot,
            ],
            { encoding: 'utf8' }
        );

        return output.trim();
    }

    async function preflightRequest(headers = {}) {
        const url = customApiUrl();
        url.searchParams.set('endpoint', 'preflight');
        url.searchParams.set('directory', getSiteDir(site));

        const response = await fetch(url, { headers });
        const contentType = response.headers.get('content-type') || '';

        return {
            status: response.status,
            contentType,
            body: contentType.includes('application/json')
                ? await response.json()
                : await response.text(),
        };
    }

    it('does not treat the default query-arg route as the export API anymore', async () => {
        const response = await fetch(`${getSiteUrl(site)}&endpoint=preflight&directory=${encodeURIComponent(getSiteDir(site))}`);
        const contentType = response.headers.get('content-type') || '';
        const body = await response.text();

        assert.equal(response.status, 200, `Expected 200 homepage response, got ${response.status}`);
        assert.ok(contentType.includes('text/html'), `Expected HTML response, got ${contentType}`);
        assert.match(body, /custom-api-auth/i, 'Expected the normal site homepage, not the export API');
    });

    it('rejects unauthenticated requests on the custom route', async () => {
        const response = await preflightRequest();

        assert.equal(response.status, 403, `Expected 403, got ${response.status}`);
        assert.equal(response.body.error, 'Current user is not a super admin.');
    });

    it('allows requests from a super admin on the custom route', async () => {
        const response = await preflightRequest({
            Cookie: createAdminCookie(),
        });

        assert.equal(response.status, 200, `Expected 200, got ${response.status}`);
        assert.equal(response.body.ok, true, `Expected ok=true, got ${JSON.stringify(response.body)}`);
    });
});
