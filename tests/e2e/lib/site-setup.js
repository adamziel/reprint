/**
 * Site setup module — provides idempotent site creation for E2E tests.
 *
 * Uses Node fs APIs for file operations wherever possible, falling back to
 * execSync only for privileged operations (sudo chown/chmod) and external
 * tools (curl, tar, mysql, wp-cli).
 */
import {
    existsSync, writeFileSync, mkdirSync, readdirSync,
    copyFileSync, symlinkSync, chmodSync, readFileSync,
    openSync, closeSync, unlinkSync, constants,
} from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import { createRequire } from 'node:module';
import { createConnection } from 'mysql2/promise';
import { setTimeout as sleep } from 'node:timers/promises';
import { randomBytes } from 'node:crypto';

const REGISTRY = createRequire(import.meta.url)('../site-registry.json');

/**
 * Copy a file, falling back to read+write if copyFileSync fails with EPERM.
 * Node's copyFileSync uses copy_file_range/sendfile which can fail on some
 * filesystem/ownership combinations even when the destination is writable.
 */
export function safeCopyFile(src, dest) {
    try {
        copyFileSync(src, dest);
    } catch (e) {
        if (e.code === 'EPERM') {
            writeFileSync(dest, readFileSync(src));
        } else {
            throw e;
        }
    }
}

export const SITE_ROOT = REGISTRY.siteRoot;
const DB_HOST = REGISTRY.dbHost;
const DB_USER = REGISTRY.dbUser;
const DB_PASS = REGISTRY.dbPass;
const WP_VERSION = REGISTRY.wpVersion;
const PROJECT_ROOT = join(import.meta.dirname, '..', '..', '..');
const PLUGIN_SRC = join(PROJECT_ROOT, 'wordpress-plugin');
const WP_TARBALL = `/tmp/wordpress-${WP_VERSION}.tar.gz`;
const WP_TEMPLATE = '/tmp/wordpress-template';
const WP_READY = '/tmp/wordpress-template/.wp-ready';
const WP_LOCK = '/tmp/wordpress-template-downloading.lock';
const WP_CLI_PATH = '/tmp/wp-cli.phar';
const MARKER = '.e2e-provisioned';

/**
 * Download and extract WordPress once, caching at /tmp/wordpress-template.
 * Uses a lock file to prevent races when Node runs test files in parallel.
 */
export async function ensureWpTemplate() {
    if (existsSync(WP_READY)) {
        return;
    }

    // Try to acquire lock (atomic via O_EXCL)
    let acquired = false;
    try {
        const fd = openSync(WP_LOCK, constants.O_CREAT | constants.O_EXCL | constants.O_WRONLY);
        closeSync(fd);
        acquired = true;
    } catch (e) {
        // Another process holds the lock — wait for it
    }

    if (acquired) {
        try {
            if (existsSync(WP_READY)) {
                return;
            }
            console.log(`Downloading WordPress ${WP_VERSION}...`);
            execSync(
                `curl -sfL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" -o "${WP_TARBALL}"`,
                { timeout: 120000 }
            );
            execSync(`rm -rf "${WP_TEMPLATE}" && mkdir -p "${WP_TEMPLATE}" && tar xzf "${WP_TARBALL}" -C "${WP_TEMPLATE}" --strip-components=1`);
            writeFileSync(WP_READY, `${WP_VERSION}\n`);
            console.log(`WordPress template ready at ${WP_TEMPLATE}`);
        } finally {
            try { unlinkSync(WP_LOCK); } catch (e) {}
        }
    } else {
        const deadline = Date.now() + 120000;
        while (!existsSync(WP_READY)) {
            if (Date.now() > deadline) {
                throw new Error('Timed out waiting for WordPress template download');
            }
            await sleep(500);
        }
    }
}

/**
 * Write a full wp-config.php that WordPress/WP-CLI can load.
 * Includes wp-settings.php — required by wp core install.
 */
function writeFullWpConfig(siteDir, dbHost, dbName, dbUser, dbPass) {
    writeFileSync(join(siteDir, 'wp-config.php'), `<?php
define('DB_HOST', '${dbHost}');
define('DB_NAME', '${dbName}');
define('DB_USER', '${dbUser}');
define('DB_PASSWORD', '${dbPass}');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('AUTH_KEY',         'e2e-test-key-1');
define('SECURE_AUTH_KEY',  'e2e-test-key-2');
define('LOGGED_IN_KEY',    'e2e-test-key-3');
define('NONCE_KEY',        'e2e-test-key-4');
define('AUTH_SALT',        'e2e-test-salt-1');
define('SECURE_AUTH_SALT', 'e2e-test-salt-2');
define('LOGGED_IN_SALT',   'e2e-test-salt-3');
define('NONCE_SALT',       'e2e-test-salt-4');
$table_prefix = 'wp_';
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
`);
}

/**
 * Write the minimal wp-config.php used by the export plugin.
 * Does NOT load wp-settings.php — the plugin reads DB creds directly.
 */
function writeMinimalWpConfig(siteDir, dbHost, dbName, dbUser, dbPass) {
    writeFileSync(join(siteDir, 'wp-config.php'), `<?php
define('DB_HOST', '${dbHost}');
define('DB_NAME', '${dbName}');
define('DB_USER', '${dbUser}');
define('DB_PASSWORD', '${dbPass}');
$table_prefix = 'wp_';
`);
}

/**
 * Run wp core install to create all real WordPress tables.
 */
function wpCoreInstall(siteDir, siteUrl, siteName) {
    execSync(
        `php ${WP_CLI_PATH} core install` +
        ` --path=${JSON.stringify(siteDir)}` +
        ` --url=${JSON.stringify(siteUrl)}` +
        ` --title=${JSON.stringify('E2E: ' + siteName)}` +
        ` --admin_user=admin` +
        ` --admin_password=password` +
        ` --admin_email=admin@example.com` +
        ` --skip-email`,
        { timeout: 60000, stdio: 'pipe' }
    );
}

/**
 * Create standard sample test files using Node fs APIs.
 */
export function createSampleFiles(siteDir) {
    const dataDir = join(siteDir, 'test-data');
    mkdirSync(join(dataDir, 'subdir', 'nested'), { recursive: true });
    writeFileSync(join(dataDir, 'hello.txt'), 'Hello World\n');
    writeFileSync(join(dataDir, 'subdir', 'test.txt'), 'Test file content\n');
    writeFileSync(join(dataDir, 'subdir', 'nested', 'deep.txt'), 'Nested file\n');
    writeFileSync(join(dataDir, 'binary.bin'), randomBytes(10240));
    writeFileSync(join(dataDir, 'empty.txt'), '');
    writeFileSync(join(dataDir, 'special-content.txt'), 'Line 1\\nLine 2\\nLine with tab\\there\\nLine with null\\x00byte\\n');
}

/**
 * Idempotent site creation. Creates WP files, DB via wp core install, plugin files.
 *
 * Options:
 *   db: 'standard' (default) | 'none' | 'custom'
 *   files: 'sample' (default) | 'none'
 *   customDb: async (dbName, conn) => {} — adds extra tables on top of real WP tables
 *   wpConfig: { DB_USER: '...', ... } — override wp-config.php creds AFTER install
 *   afterCreate: async (siteDir, dbName) => {} — post-creation hook (dir is writable)
 *   afterPermissions: async (siteDir) => {} — runs after final chown/chmod (for chmod 000 etc.)
 */
export async function ensureSite(name, options = {}) {
    const siteDir = join(SITE_ROOT, name);
    const markerPath = join(siteDir, MARKER);

    if (existsSync(markerPath)) {
        return;
    }

    const dbName = `e2e_${name.replace(/-/g, '_')}`;
    const secret = `test-secret-${name}`;
    const dbOpt = options.db || 'standard';
    const filesOpt = options.files || 'sample';
    const port = REGISTRY.sites[name]?.port;
    const siteUrl = port ? `http://127.0.0.1:${port}` : 'http://127.0.0.1';

    console.log(`  Setting up site: ${name} (db: ${dbName})`);

    await ensureWpTemplate();

    // Remove old site dir (clean slate), create fresh, copy WP template
    execSync(`sudo rm -rf "${siteDir}"`);
    execSync(`sudo mkdir -p "${SITE_ROOT}" && sudo mkdir -p "${siteDir}"`);
    execSync(`sudo cp -a "${WP_TEMPLATE}/." "${siteDir}/"`);
    execSync(`sudo chmod -R 777 "${siteDir}"`);

    // Create directories (writable now, no sudo needed)
    mkdirSync(join(siteDir, 'wp-content', 'plugins', 'site-export', 'generic'), { recursive: true });
    mkdirSync(join(siteDir, 'test-data'), { recursive: true });

    // Write full wp-config.php with admin creds (needed for wp core install)
    writeFullWpConfig(siteDir, DB_HOST, dbName, DB_USER, DB_PASS);

    // Write secret.php
    writeFileSync(
        join(siteDir, 'wp-content', 'plugins', 'site-export', 'secret.php'),
        `<?php return '${secret}';\n`
    );

    // Copy plugin source files
    safeCopyFile(
        join(PLUGIN_SRC, 'api.php'),
        join(siteDir, 'wp-content', 'plugins', 'site-export', 'api.php')
    );
    for (const f of readdirSync(join(PLUGIN_SRC, 'generic')).filter(f => f.endsWith('.php'))) {
        safeCopyFile(
            join(PLUGIN_SRC, 'generic', f),
            join(siteDir, 'wp-content', 'plugins', 'site-export', 'generic', f)
        );
    }

    // Create database and run wp core install
    if (dbOpt !== 'none') {
        const adminConn = await createConnection({
            host: DB_HOST, user: DB_USER, password: DB_PASS, multipleStatements: true,
        });
        await adminConn.query(`DROP DATABASE IF EXISTS \`${dbName}\`; CREATE DATABASE \`${dbName}\``);
        await adminConn.end();

        // wp core install creates all real WP tables (wp_options, wp_posts, etc.)
        wpCoreInstall(siteDir, siteUrl, name);

        // Run customDb hook to add extra tables on top of real WP
        if (options.customDb) {
            const conn = await createConnection({
                host: DB_HOST, user: DB_USER, password: DB_PASS,
                database: dbName, multipleStatements: true,
            });
            await options.customDb(dbName, conn);
            await conn.end();
        }
    }

    // Rewrite wp-config.php: minimal version for the export plugin
    // (the full config was only needed for wp core install above)
    if (options.wpConfig) {
        const wpDbUser = options.wpConfig.DB_USER || DB_USER;
        const wpDbPass = options.wpConfig.DB_PASSWORD || DB_PASS;
        const wpDbName = options.wpConfig.DB_NAME || dbName;
        const wpDbHost = options.wpConfig.DB_HOST || DB_HOST;
        writeMinimalWpConfig(siteDir, wpDbHost, wpDbName, wpDbUser, wpDbPass);
    } else {
        writeMinimalWpConfig(siteDir, DB_HOST, dbName, DB_USER, DB_PASS);
    }

    // Create sample files (pure Node fs)
    if (filesOpt === 'sample') {
        createSampleFiles(siteDir);
    }

    // Run afterCreate hook (dir is still writable — use Node fs, not exec)
    if (options.afterCreate) {
        await options.afterCreate(siteDir, dbName);
    }

    // Set final ownership and permissions
    execSync(`sudo chown -R nginx:nginx "${siteDir}" && sudo chmod -R 755 "${siteDir}"`);

    // Run afterPermissions hook (for operations that need to happen after chown/chmod,
    // e.g. chmod 000 for permission-denied tests)
    if (options.afterPermissions) {
        await options.afterPermissions(siteDir, dbName);
    }

    // Write marker
    execSync(`sudo touch "${markerPath}"`);
}
