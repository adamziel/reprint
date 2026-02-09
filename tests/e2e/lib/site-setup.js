/**
 * Site setup module — provides idempotent site creation for E2E tests.
 *
 * Uses Node fs APIs for file operations wherever possible, falling back to
 * execSync only for privileged operations (sudo chown/chmod) and external
 * tools (curl, tar, mysql).
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
 * Create the standard sample database tables.
 */
export async function createSampleDb(dbName, siteName = 'unknown') {
    const conn = await createConnection({
        host: DB_HOST,
        user: DB_USER,
        password: DB_PASS,
        database: dbName,
        multipleStatements: true,
    });

    await conn.query(`
CREATE TABLE IF NOT EXISTS wp_options (
    option_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    option_name VARCHAR(191) NOT NULL DEFAULT '',
    option_value LONGTEXT NOT NULL,
    autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
    UNIQUE KEY option_name (option_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO wp_options (option_name, option_value, autoload) VALUES
    ('siteurl', 'http://localhost', 'yes'),
    ('home', 'http://localhost', 'yes'),
    ('blogname', 'E2E: ${siteName}', 'yes'),
    ('blogdescription', 'Just another test site', 'yes'),
    ('active_plugins', 'a:0:{}', 'yes');

CREATE TABLE IF NOT EXISTS wp_posts (
    ID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_author BIGINT UNSIGNED NOT NULL DEFAULT 0,
    post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
    post_date_gmt DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
    post_content LONGTEXT NOT NULL,
    post_title TEXT NOT NULL,
    post_excerpt TEXT NOT NULL,
    post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
    post_name VARCHAR(200) NOT NULL DEFAULT '',
    post_type VARCHAR(20) NOT NULL DEFAULT 'post',
    KEY post_name (post_name(191)),
    KEY post_type_status (post_type, post_status, post_date, ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_name, post_type) VALUES
    (1, NOW(), UTC_TIMESTAMP(), 'Hello World content with <b>HTML</b> and special chars: &amp; "quotes" ''apostrophes''', 'Hello World', '', 'publish', 'hello-world', 'post'),
    (1, NOW(), UTC_TIMESTAMP(), 'Second post with unicode: \u00e9\u00e0\u00fc \u2713 \ud83d\ude00', 'Unicode Post', '', 'publish', 'unicode-post', 'post'),
    (1, NOW(), UTC_TIMESTAMP(), CONCAT('Binary test: ', CHAR(0 USING binary), CHAR(1 USING binary)), 'Binary Post', '', 'draft', 'binary-post', 'post');

CREATE TABLE IF NOT EXISTS wp_usermeta (
    umeta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    meta_key VARCHAR(255) DEFAULT NULL,
    meta_value LONGTEXT,
    KEY user_id (user_id),
    KEY meta_key (meta_key(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES
    (1, 'nickname', 'admin'),
    (1, 'first_name', 'Test'),
    (1, 'last_name', 'User'),
    (1, 'wp_capabilities', 'a:1:{s:13:"administrator";b:1;}');
    `);

    await conn.end();
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
 * Idempotent site creation. Creates WP files, DB, plugin files.
 *
 * Options:
 *   db: 'sample' (default) | 'none' | 'custom'
 *   files: 'sample' (default) | 'none'
 *   customDb: async (dbName, conn) => {} — for custom DB setup
 *   wpConfig: { DB_USER: '...', ... } — override wp-config.php fields
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
    const dbOpt = options.db || 'sample';
    const filesOpt = options.files || 'sample';

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

    // Write wp-config.php
    const wpDbUser = options.wpConfig?.DB_USER || DB_USER;
    const wpDbPass = options.wpConfig?.DB_PASSWORD || DB_PASS;
    const wpDbName = options.wpConfig?.DB_NAME || dbName;
    const wpDbHost = options.wpConfig?.DB_HOST || DB_HOST;
    writeFileSync(join(siteDir, 'wp-config.php'), `<?php
define('DB_HOST', '${wpDbHost}');
define('DB_NAME', '${wpDbName}');
define('DB_USER', '${wpDbUser}');
define('DB_PASSWORD', '${wpDbPass}');
$table_prefix = 'wp_';
`);

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

    // Create database using mysql2 (no exec needed)
    const adminConn = await createConnection({
        host: DB_HOST, user: DB_USER, password: DB_PASS, multipleStatements: true,
    });
    await adminConn.query(`DROP DATABASE IF EXISTS \`${dbName}\`; CREATE DATABASE \`${dbName}\``);
    await adminConn.end();

    // Populate database
    if (dbOpt === 'sample') {
        await createSampleDb(dbName, name);
    } else if (dbOpt === 'custom' && options.customDb) {
        const conn = await createConnection({
            host: DB_HOST, user: DB_USER, password: DB_PASS,
            database: dbName, multipleStatements: true,
        });
        await options.customDb(dbName, conn);
        await conn.end();
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
