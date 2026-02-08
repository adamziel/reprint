#!/usr/bin/env node
/**
 * Pre-provision all test sites serially.
 *
 * Tests also call ensureSite() in their before() hooks, but that's a no-op
 * when the site is already provisioned (idempotent via .e2e-provisioned marker).
 * Pre-provisioning avoids contention when the test runner runs files in parallel.
 *
 * Usage:  node lib/provision-all.js
 */
import { ensureSite, SITE_ROOT } from './site-setup.js';
import { writeFileSync, mkdirSync, copyFileSync, readdirSync, symlinkSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import { randomBytes } from 'node:crypto';

// Standard sites (default sample DB + files)
for (const name of [
    'basic', 'file-changes', 'dir-deleted', 'volatile-file',
    'hmac-errors', 'http-errors', 'request-cutoff', 'gzip-corrupt',
    'buffered', 'error-chunks', 'import-failures',
]) {
    await ensureSite(name);
}

// symlinks-outside: create external symlink
await ensureSite('symlinks-outside', {
    afterCreate: async (siteDir) => {
        // External dir may be nginx-owned from a previous run
        execSync('sudo rm -rf /tmp/e2e-external-data');
        mkdirSync('/tmp/e2e-external-data', { recursive: true });
        writeFileSync('/tmp/e2e-external-data/external.txt', 'External file\n');
        symlinkSync('/tmp/e2e-external-data', join(siteDir, 'test-data', 'external-link'));
    },
    afterPermissions: async () => {
        execSync('sudo chown -R nginx:nginx /tmp/e2e-external-data');
    },
});

// custom-wp-content: copy plugin to custom-content directory
await ensureSite('custom-wp-content', {
    afterCreate: async (siteDir) => {
        const customPlugin = join(siteDir, 'custom-content', 'plugins', 'site-export', 'generic');
        const srcPlugin = join(siteDir, 'wp-content', 'plugins', 'site-export');
        mkdirSync(customPlugin, { recursive: true });
        copyFileSync(join(srcPlugin, 'api.php'), join(customPlugin, '..', 'api.php'));
        for (const f of readdirSync(join(srcPlugin, 'generic')).filter(f => f.endsWith('.php'))) {
            copyFileSync(join(srcPlugin, 'generic', f), join(customPlugin, f));
        }
        copyFileSync(join(srcPlugin, 'secret.php'), join(customPlugin, '..', 'secret.php'));
    },
});

// emoji-paths: unicode/emoji filenames (Node handles UTF-8 natively)
await ensureSite('emoji-paths', {
    files: 'none',
    afterCreate: async (siteDir) => {
        const dataDir = join(siteDir, 'test-data');
        mkdirSync(join(dataDir, 'dir-with-dashes'), { recursive: true });
        writeFileSync(join(dataDir, 'fire.txt'), 'emoji file');
        writeFileSync(join(dataDir, 'rocket-file.txt'), 'rocket content');
        writeFileSync(join(dataDir, 'file with spaces.txt'), 'spaces');
        writeFileSync(join(dataDir, 'dir-with-dashes', 'inner.txt'), 'dashed');
        writeFileSync(join(dataDir, 'caf\u00e9.txt'), 'unicode content');
        writeFileSync(join(dataDir, '\u4e2d\u6587.txt'), 'chinese');
        writeFileSync(join(dataDir, '\u{1F525}\u{1F680}.txt'), 'emoji content');
        writeFileSync(join(dataDir, 'file\nwith\nnewlines.txt'), 'newline content');
        // Invalid UTF-8 filename: use Buffer for the path
        const invalidPath = Buffer.concat([
            Buffer.from(join(dataDir, 'invalid')),
            Buffer.from([0xff, 0xfe]),
            Buffer.from('utf8.txt'),
        ]);
        writeFileSync(invalidPath, 'invalid utf8');
    },
});

// sha1-verify: numbered files + large binary
await ensureSite('sha1-verify', {
    files: 'none',
    afterCreate: async (siteDir) => {
        const dataDir = join(siteDir, 'test-data');
        mkdirSync(join(dataDir, 'deep', 'nested', 'path'), { recursive: true });
        for (let i = 1; i <= 20; i++) {
            writeFileSync(join(dataDir, `file-${i}.txt`), `File content number ${i} with some padding to make it non-trivial\n`);
        }
        writeFileSync(join(dataDir, 'large-binary.bin'), randomBytes(256 * 1024));
        writeFileSync(join(dataDir, 'deep', 'nested', 'path', 'deep-file.txt'), 'Deep nested content\n');
    },
});

// circular-symlinks
await ensureSite('circular-symlinks', {
    afterCreate: async (siteDir) => {
        const dataDir = join(siteDir, 'test-data');
        symlinkSync(join(dataDir, 'link-b'), join(dataDir, 'link-a'));
        symlinkSync(join(dataDir, 'link-a'), join(dataDir, 'link-b'));
        symlinkSync(join(dataDir, 'self-link'), join(dataDir, 'self-link'));
    },
});

// chmod-denied: unreadable files
await ensureSite('chmod-denied', {
    afterCreate: async (siteDir) => {
        const dataDir = join(siteDir, 'test-data');
        writeFileSync(join(dataDir, 'unreadable.txt'), 'secret content');
        mkdirSync(join(dataDir, 'unreadable-dir'), { recursive: true });
        writeFileSync(join(dataDir, 'unreadable-dir', 'inside.txt'), 'inside');
    },
    afterPermissions: async (siteDir) => {
        execSync(`sudo chmod 000 "${siteDir}/test-data/unreadable.txt"`);
        execSync(`sudo chmod 000 "${siteDir}/test-data/unreadable-dir"`);
    },
});

// mysql-restricted: restricted user + custom tables
await ensureSite('mysql-restricted', {
    db: 'custom',
    wpConfig: {
        DB_USER: 'e2e_restricted',
        DB_PASSWORD: 'e2e_restricted_pw',
        DB_NAME: 'e2e_mysql_restricted',
    },
    customDb: async (dbName, conn) => {
        await conn.query(`
CREATE TABLE wp_options (
    option_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    option_name VARCHAR(191) NOT NULL,
    option_value LONGTEXT NOT NULL,
    autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
    UNIQUE KEY option_name (option_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO wp_options (option_name, option_value) VALUES ('siteurl', 'http://localhost');
CREATE TABLE wp_secret_table (
    id INT PRIMARY KEY,
    secret_data TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO wp_secret_table VALUES (1, 'top secret');
        `);
    },
    afterPermissions: async () => {
        execSync(
            `mysql -u e2e_admin -pe2e_password -h 127.0.0.1 -e "GRANT SELECT ON e2e_mysql_restricted.* TO 'e2e_restricted'@'localhost' IDENTIFIED BY 'e2e_restricted_pw'; FLUSH PRIVILEGES;" 2>/dev/null || true`
        );
    },
});

// large-directory: 2000 files
await ensureSite('large-directory', {
    files: 'none',
    afterCreate: async (siteDir) => {
        const manyDir = join(siteDir, 'test-data', 'many-files');
        mkdirSync(manyDir, { recursive: true });
        for (let i = 1; i <= 2000; i++) {
            const num = String(i).padStart(4, '0');
            writeFileSync(join(manyDir, `file-${num}.txt`), `content-${num}`);
        }
    },
});

console.log(`All sites provisioned under ${SITE_ROOT}`);
