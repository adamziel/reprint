/**
 * Site setup module — replaces setup.sh.
 * Provides idempotent site creation for E2E tests.
 */
import { existsSync, readFileSync, writeFileSync, mkdirSync, statSync, openSync, closeSync, unlinkSync, constants } from 'node:fs';
import { execSync, execFileSync } from 'node:child_process';
import { join } from 'node:path';
import { createRequire } from 'node:module';
import { createConnection } from 'mysql2/promise';
import { setTimeout as sleep } from 'node:timers/promises';

const REGISTRY = createRequire(import.meta.url)('../site-registry.json');

const SITE_ROOT = REGISTRY.siteRoot;
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
    // Fast path: already complete
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
            // Double-check after acquiring lock
            if (existsSync(WP_READY)) {
                return;
            }
            console.log(`Downloading WordPress ${WP_VERSION}...`);
            execSync(
                `curl -sfL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" -o "${WP_TARBALL}"`,
                { timeout: 120000 }
            );
            execSync(`rm -rf "${WP_TEMPLATE}"`);
            execSync(`mkdir -p "${WP_TEMPLATE}"`);
            execSync(`tar xzf "${WP_TARBALL}" -C "${WP_TEMPLATE}" --strip-components=1`);
            // Write ready marker after successful extraction
            writeFileSync(WP_READY, `${WP_VERSION}\n`);
            console.log(`WordPress template ready at ${WP_TEMPLATE}`);
        } finally {
            try { unlinkSync(WP_LOCK); } catch (e) {}
        }
    } else {
        // Wait for the other process to finish (poll for ready marker)
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
export async function createSampleDb(dbName) {
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
    ('blogname', 'E2E Test Site', 'yes'),
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
 * Create standard sample test files in a site's test-data directory.
 */
export function createSampleFiles(siteDir) {
    const dataDir = join(siteDir, 'test-data');
    execSync(`sudo mkdir -p "${dataDir}/subdir/nested"`);
    execSync(`echo "Hello World" | sudo tee "${dataDir}/hello.txt" > /dev/null`);
    execSync(`echo "Test file content" | sudo tee "${dataDir}/subdir/test.txt" > /dev/null`);
    execSync(`echo "Nested file" | sudo tee "${dataDir}/subdir/nested/deep.txt" > /dev/null`);
    execSync(`sudo dd if=/dev/urandom of="${dataDir}/binary.bin" bs=1024 count=10 2>/dev/null`);
    execSync(`sudo touch "${dataDir}/empty.txt"`);
    execSync(`printf 'Line 1\\nLine 2\\nLine with tab\\there\\nLine with null\\x00byte\\n' | sudo tee "${dataDir}/special-content.txt" > /dev/null`);
    execSync(`sudo chown -R nginx:nginx "${dataDir}"`);
}

/**
 * Idempotent site creation. Creates WP files, DB, plugin files.
 *
 * Options:
 *   db: 'sample' (default) | 'none' | 'custom'
 *   files: 'sample' (default) | 'none'
 *   customDb: async (dbName, conn) => {} — for custom DB setup
 *   wpConfig: { DB_USER: '...', ... } — override wp-config.php fields
 *   afterCreate: async (siteDir, dbName) => {} — post-creation hook
 */
export async function ensureSite(name, options = {}) {
    const siteDir = join(SITE_ROOT, name);
    const markerPath = join(siteDir, MARKER);

    // Idempotency: skip if already provisioned
    if (existsSync(markerPath)) {
        return;
    }

    const dbName = `e2e_${name.replace(/-/g, '_')}`;
    const secret = `test-secret-${name}`;
    const dbOpt = options.db || 'sample';
    const filesOpt = options.files || 'sample';

    console.log(`  Setting up site: ${name} (db: ${dbName})`);

    // Ensure WP template exists
    await ensureWpTemplate();

    // Ensure site root exists
    execSync(`sudo mkdir -p "${SITE_ROOT}"`);
    execSync(`sudo chown nginx:nginx "${SITE_ROOT}"`);
    execSync(`sudo chmod 755 "${SITE_ROOT}"`);

    // Copy WordPress template
    execSync(`sudo mkdir -p "${siteDir}"`);
    execSync(`sudo cp -a "${WP_TEMPLATE}/." "${siteDir}/"`);

    // Create plugin directories
    execSync(`sudo mkdir -p "${siteDir}/wp-content/plugins/site-export/generic"`);
    execSync(`sudo mkdir -p "${siteDir}/test-data"`);

    // Write wp-config.php
    const wpDbUser = options.wpConfig?.DB_USER || DB_USER;
    const wpDbPass = options.wpConfig?.DB_PASSWORD || DB_PASS;
    const wpDbName = options.wpConfig?.DB_NAME || dbName;
    const wpDbHost = options.wpConfig?.DB_HOST || DB_HOST;
    const wpConfig = `<?php
define('DB_HOST', '${wpDbHost}');
define('DB_NAME', '${wpDbName}');
define('DB_USER', '${wpDbUser}');
define('DB_PASSWORD', '${wpDbPass}');
$table_prefix = 'wp_';
`;
    execSync(`sudo tee "${siteDir}/wp-config.php" > /dev/null <<'WPEOF'\n${wpConfig}\nWPEOF`);

    // Write secret.php
    execSync(`sudo tee "${siteDir}/wp-content/plugins/site-export/secret.php" > /dev/null <<'SEOF'\n<?php return '${secret}';\nSEOF`);

    // Copy plugin source files
    execSync(`sudo cp "${PLUGIN_SRC}/api.php" "${siteDir}/wp-content/plugins/site-export/api.php"`);
    execSync(`sudo cp "${PLUGIN_SRC}/generic/"*.php "${siteDir}/wp-content/plugins/site-export/generic/"`);

    // Create database
    execSync(
        `mysql -u "${DB_USER}" -p"${DB_PASS}" -h "${DB_HOST}" -e "DROP DATABASE IF EXISTS \\\`${dbName}\\\`; CREATE DATABASE \\\`${dbName}\\\`;" 2>/dev/null`
    );

    // Populate database
    if (dbOpt === 'sample') {
        await createSampleDb(dbName);
    } else if (dbOpt === 'custom' && options.customDb) {
        const conn = await createConnection({
            host: DB_HOST,
            user: DB_USER,
            password: DB_PASS,
            database: dbName,
            multipleStatements: true,
        });
        await options.customDb(dbName, conn);
        await conn.end();
    }

    // Create sample files
    if (filesOpt === 'sample') {
        createSampleFiles(siteDir);
    }

    // Set ownership
    execSync(`sudo chown -R nginx:nginx "${siteDir}"`);
    execSync(`sudo chmod -R 755 "${siteDir}"`);

    // Run afterCreate hook
    if (options.afterCreate) {
        await options.afterCreate(siteDir, dbName);
    }

    // Write marker file
    execSync(`sudo touch "${markerPath}"`);
}
