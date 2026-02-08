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
import { ensureSite } from './site-setup.js';
import { execSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { createConnection } from 'mysql2/promise';

const registry = createRequire(import.meta.url)('../site-registry.json');
const SITE_ROOT = registry.siteRoot;

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
        execSync('sudo mkdir -p /tmp/e2e-external-data');
        execSync('echo "External file" | sudo tee /tmp/e2e-external-data/external.txt > /dev/null');
        execSync('sudo chown -R nginx:nginx /tmp/e2e-external-data');
        execSync(`sudo ln -sfn /tmp/e2e-external-data "${siteDir}/test-data/external-link"`);
    },
});

// custom-wp-content: copy plugin to custom-content directory
await ensureSite('custom-wp-content', {
    afterCreate: async (siteDir) => {
        execSync(`sudo mkdir -p "${siteDir}/custom-content/plugins/site-export/generic"`);
        execSync(`sudo cp "${siteDir}/wp-content/plugins/site-export/api.php" "${siteDir}/custom-content/plugins/site-export/api.php"`);
        execSync(`sudo cp "${siteDir}/wp-content/plugins/site-export/generic/"*.php "${siteDir}/custom-content/plugins/site-export/generic/"`);
        execSync(`sudo cp "${siteDir}/wp-content/plugins/site-export/secret.php" "${siteDir}/custom-content/plugins/site-export/secret.php"`);
        execSync(`sudo chown -R nginx:nginx "${siteDir}"`);
    },
});

// emoji-paths: unicode/emoji filenames
await ensureSite('emoji-paths', {
    files: 'none',
    afterCreate: async (siteDir) => {
        const dataDir = `${siteDir}/test-data`;
        execSync(`sudo mkdir -p "${dataDir}/dir-with-dashes"`);
        execSync(`echo "emoji file" | sudo tee "${dataDir}/fire.txt" > /dev/null`);
        execSync(`echo "rocket content" | sudo tee "${dataDir}/rocket-file.txt" > /dev/null`);
        execSync(`echo "spaces" | sudo tee "${dataDir}/file with spaces.txt" > /dev/null`);
        execSync(`echo "dashed" | sudo tee "${dataDir}/dir-with-dashes/inner.txt" > /dev/null`);
        execSync(`printf 'unicode content' | sudo tee "$(printf '${dataDir}/caf\\xc3\\xa9.txt')" > /dev/null`);
        execSync(`printf 'chinese' | sudo tee "$(printf '${dataDir}/\\xe4\\xb8\\xad\\xe6\\x96\\x87.txt')" > /dev/null`);
        execSync(`printf 'emoji content' | sudo tee "$(printf '${dataDir}/\\xf0\\x9f\\x94\\xa5\\xf0\\x9f\\x9a\\x80.txt')" > /dev/null`);
        execSync(`printf 'newline content' | sudo tee "$(printf '${dataDir}/file\\nwith\\nnewlines.txt')" > /dev/null`);
        execSync(`printf 'invalid utf8' | sudo tee "$(printf '${dataDir}/invalid\\xff\\xfeutf8.txt')" > /dev/null`);
        execSync(`sudo chown -R nginx:nginx "${siteDir}"`);
    },
});

// sha1-verify: numbered files + large binary
await ensureSite('sha1-verify', {
    files: 'none',
    afterCreate: async (siteDir) => {
        execSync(`sudo mkdir -p "${siteDir}/test-data/deep/nested/path"`);
        for (let i = 1; i <= 20; i++) {
            execSync(`printf "File content number ${i} with some padding to make it non-trivial\\n" | sudo tee "${siteDir}/test-data/file-${i}.txt" > /dev/null`);
        }
        execSync(`sudo dd if=/dev/urandom of="${siteDir}/test-data/large-binary.bin" bs=1024 count=256 2>/dev/null`);
        execSync(`echo "Deep nested content" | sudo tee "${siteDir}/test-data/deep/nested/path/deep-file.txt" > /dev/null`);
        execSync(`sudo chown -R nginx:nginx "${siteDir}"`);
    },
});

// circular-symlinks
await ensureSite('circular-symlinks', {
    afterCreate: async (siteDir) => {
        execSync(`sudo ln -sfn "${siteDir}/test-data/link-b" "${siteDir}/test-data/link-a"`);
        execSync(`sudo ln -sfn "${siteDir}/test-data/link-a" "${siteDir}/test-data/link-b"`);
        execSync(`sudo ln -sfn "${siteDir}/test-data/self-link" "${siteDir}/test-data/self-link"`);
        execSync(`sudo chown -R nginx:nginx "${siteDir}" 2>/dev/null || true`);
    },
});

// chmod-denied: unreadable files
await ensureSite('chmod-denied', {
    afterCreate: async (siteDir) => {
        execSync(`echo "secret content" | sudo tee "${siteDir}/test-data/unreadable.txt" > /dev/null`);
        execSync(`sudo chmod 000 "${siteDir}/test-data/unreadable.txt"`);
        execSync(`sudo mkdir -p "${siteDir}/test-data/unreadable-dir"`);
        execSync(`echo "inside" | sudo tee "${siteDir}/test-data/unreadable-dir/inside.txt" > /dev/null`);
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
    afterCreate: async () => {
        execSync(
            `mysql -u e2e_admin -pe2e_password -h 127.0.0.1 -e "GRANT SELECT ON e2e_mysql_restricted.* TO 'e2e_restricted'@'localhost' IDENTIFIED BY 'e2e_restricted_pw'; FLUSH PRIVILEGES;" 2>/dev/null || true`
        );
    },
});

// large-directory: 2000 files
await ensureSite('large-directory', {
    files: 'none',
    afterCreate: async (siteDir) => {
        execSync(`sudo mkdir -p "${siteDir}/test-data/many-files"`);
        for (let i = 1; i <= 2000; i++) {
            const num = String(i).padStart(4, '0');
            execSync(`printf "content-${num}" | sudo tee "${siteDir}/test-data/many-files/file-${num}.txt" > /dev/null`);
        }
        execSync(`sudo chown -R nginx:nginx "${siteDir}"`);
    },
});

console.log(`All sites provisioned under ${SITE_ROOT}`);
