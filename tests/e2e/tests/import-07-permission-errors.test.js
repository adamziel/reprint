/**
 * Test 07: Permission Errors via import.php
 * Tests chmod-denied and mysql-restricted sites complete gracefully
 * and produce appropriate error chunks in the audit log.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, writeFileSync, mkdirSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    hashDirectory, readAuditLog,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

describe('Import: Permission Errors', () => {
    describe('chmod-denied', () => {
        const site = 'chmod-denied';
        let tempDir;

        beforeAll(async () => {
            await ensureSite(site, {
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
            tempDir = createTempDir('e2e-import-chmod');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        function importUrl() {
            return `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
        }

        it('file sync completes', () => {
            const result = runImporter(importUrl(), tempDir, 'files-sync-initial', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
        });

        it('readable files are downloaded', () => {
            const importedRoot = join(tempDir, 'filesystem-root', getSiteDir(site));
            const hashes = hashDirectory(importedRoot);
            assert.ok(hashes.size > 0, 'Expected at least one readable file downloaded');

            // hello.txt should be present (it's readable)
            assert.ok(
                [...hashes.keys()].some(p => p.includes('hello.txt')),
                'Expected hello.txt to be downloaded'
            );
        });

        it('audit log records error for unreadable files', () => {
            const audit = readAuditLog(tempDir);
            assert.ok(audit.includes('REMOTE ERROR'), 'Expected REMOTE ERROR in audit log');
            assert.ok(audit.includes('type=file_open'), 'Expected type=file_open in audit log');
            assert.ok(audit.includes('unreadable.txt'), 'Expected unreadable.txt mentioned in audit log');
        });
    });

    describe('mysql-restricted', () => {
        const site = 'mysql-restricted';
        let tempDir;

        beforeAll(async () => {
            await ensureSite(site, {
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
            tempDir = createTempDir('e2e-import-mysql-restricted');
        });

        afterAll(() => {
            cleanupTempDir(tempDir);
        });

        function importUrl() {
            return `${getSiteUrl(site)}?directory=${getSiteDir(site)}`;
        }

        it('sql-sync completes', () => {
            const result = runImporter(importUrl(), tempDir, 'sql-sync', {
                secret: getSiteSecret(site),
            });
            assert.equal(result.exitCode, 0, `Expected exit 0\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);

            const sqlFile = join(tempDir, 'db.sql');
            assert.ok(existsSync(sqlFile), 'Expected db.sql to exist');
        });

        it('state shows complete', () => {
            const stateFile = join(tempDir, '.import-state.json');
            const state = JSON.parse(readFileSync(stateFile, 'utf-8'));
            assert.equal(state.status, 'complete');
        });

        it('SQL dump contains both tables', () => {
            const sqlFile = join(tempDir, 'db.sql');
            const sql = readFileSync(sqlFile, 'utf-8');
            assert.ok(sql.includes('wp_options'), 'Expected wp_options table in SQL dump');
            assert.ok(sql.includes('wp_secret_table'), 'Expected wp_secret_table in SQL dump');
        });
    });
});
