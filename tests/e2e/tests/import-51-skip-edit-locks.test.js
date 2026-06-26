/**
 * Test 51: SQL row skip protocol — post edit locks
 *
 * Pulls SQL from a source WordPress site with a non-default table prefix into
 * a separate MySQL database. The source data includes rows that exercise the
 * boundaries of the default _edit_lock exclusion: only rows in the source
 * site's prefixed postmeta table whose meta_key is exactly _edit_lock should
 * disappear. Similar rows in other tables, similarly named keys, NULL keys,
 * and values that merely look like edit locks must survive the transfer.
 */
import { describe, it, beforeAll, afterAll } from 'vitest';
import assert from 'node:assert/strict';
import {
    runImporter, createTempDir, cleanupTempDir,
    getSiteUrl, getSiteSecret, getSiteDir,
    createMysqlConnection, getDbName,
} from '../lib/test-helpers.js';
import { ensureSite } from '../lib/site-setup.js';

const TABLE_PREFIX = 'rp_';
const TEST_POST_ID = 99001;

function plainRows(rows) {
    return rows.map(row => ({ ...row }));
}

describe('Import: skip remote post edit locks', { timeout: 300000 }, () => {
    const site = 'edit-locks';
    const importDb = 'e2e_skip_edit_locks_51';
    let tempDir;

    beforeAll(async () => {
        await ensureSite(site, {
            tablePrefix: TABLE_PREFIX,
            customDb: async (_dbName, conn) => {
                await conn.query(
                    `INSERT INTO ${TABLE_PREFIX}postmeta (post_id, meta_key, meta_value) VALUES
                    (?, '_edit_lock', '1781114164:51814349'),
                    (?, '_edit_lock', '1781114165:51814350'),
                    (?, '_edit_last', '51814349'),
                    (?, '_edit_lock_extra', 'should-stay'),
                    (?, '_thumbnail_id', '42'),
                    (?, NULL, 'null-key-stays'),
                    (?, 'not_lock', '1781114164:51814349')`,
                    [
                        TEST_POST_ID,
                        TEST_POST_ID + 1,
                        TEST_POST_ID,
                        TEST_POST_ID,
                        TEST_POST_ID,
                        TEST_POST_ID,
                        TEST_POST_ID,
                    ],
                );

                await conn.query(
                    `CREATE TABLE wp_postmeta (
                        meta_id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                        post_id BIGINT UNSIGNED NOT NULL,
                        meta_key VARCHAR(255),
                        meta_value LONGTEXT
                    )`,
                );
                await conn.query(
                    `INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
                    VALUES (?, '_edit_lock', 'unprefixed-table-stays')`,
                    [TEST_POST_ID],
                );

                await conn.query(
                    `CREATE TABLE ${TABLE_PREFIX}othermeta (
                        id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                        meta_key VARCHAR(255),
                        meta_value LONGTEXT
                    )`,
                );
                await conn.query(
                    `INSERT INTO ${TABLE_PREFIX}othermeta (meta_key, meta_value)
                    VALUES ('_edit_lock', 'different-table-stays')`,
                );
            },
        });

        tempDir = createTempDir('e2e-skip-edit-locks');
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.query(`CREATE DATABASE \`${importDb}\``);
        await conn.end();
    });

    afterAll(async () => {
        cleanupTempDir(tempDir);
        const conn = await createMysqlConnection();
        await conn.query(`DROP DATABASE IF EXISTS \`${importDb}\``);
        await conn.end();
    });

    function importUrl() {
        return `${getSiteUrl(site)}&directory=${getSiteDir(site)}`;
    }

    it('source fixture has edit locks in the prefixed postmeta table and lookalikes elsewhere', async () => {
        const conn = await createMysqlConnection(getDbName(site));
        try {
            const [[prefixedLocks]] = await conn.query(
                `SELECT COUNT(*) AS count FROM ${TABLE_PREFIX}postmeta WHERE post_id IN (?, ?) AND meta_key = '_edit_lock'`,
                [TEST_POST_ID, TEST_POST_ID + 1],
            );
            assert.equal(Number(prefixedLocks.count), 2);

            const [[unprefixedLocks]] = await conn.query(
                `SELECT COUNT(*) AS count FROM wp_postmeta WHERE post_id = ? AND meta_key = '_edit_lock'`,
                [TEST_POST_ID],
            );
            assert.equal(Number(unprefixedLocks.count), 1);

            const [[otherTableLocks]] = await conn.query(
                `SELECT COUNT(*) AS count FROM ${TABLE_PREFIX}othermeta WHERE meta_key = '_edit_lock'`,
            );
            assert.equal(Number(otherTableLocks.count), 1);
        } finally {
            await conn.end();
        }
    });

    it('db-download imports everything except prefixed postmeta _edit_lock rows', () => {
        const result = runImporter(importUrl(), tempDir, 'db-download', {
            secret: getSiteSecret(site),
            timeout: 120000,
            wallTimeout: 300000,
            extraArgs: [
                '--sql-output=mysql',
                '--mysql-host=127.0.0.1',
                '--mysql-user=e2e_admin',
                '--mysql-password=e2e_password',
                `--mysql-database=${importDb}`,
                // Force the dump across multiple sql_chunk requests so every
                // resumed request must carry the same skip_rows protocol.
                '--max-exec=1',
                '--sql-fragments-start=25',
                '--sql-fragments-max=25',
            ],
        });
        assert.equal(result.exitCode, 0,
            `Expected db-download exit 0, got ${result.exitCode}\nstderr: ${result.stderr}\nstdout: ${result.stdout}`);
    });

    it('omits only the exact _edit_lock rows from the source-prefixed postmeta table', async () => {
        const conn = await createMysqlConnection(importDb);
        try {
            const [postmetaRows] = await conn.query(
                `SELECT meta_key, meta_value FROM ${TABLE_PREFIX}postmeta
                WHERE post_id = ?
                ORDER BY meta_id`,
                [TEST_POST_ID],
            );
            assert.deepEqual(plainRows(postmetaRows), [
                { meta_key: '_edit_last', meta_value: '51814349' },
                { meta_key: '_edit_lock_extra', meta_value: 'should-stay' },
                { meta_key: '_thumbnail_id', meta_value: '42' },
                { meta_key: null, meta_value: 'null-key-stays' },
                { meta_key: 'not_lock', meta_value: '1781114164:51814349' },
            ]);

            const [[remainingPrefixedLocks]] = await conn.query(
                `SELECT COUNT(*) AS count FROM ${TABLE_PREFIX}postmeta
                WHERE post_id IN (?, ?) AND meta_key = '_edit_lock'`,
                [TEST_POST_ID, TEST_POST_ID + 1],
            );
            assert.equal(Number(remainingPrefixedLocks.count), 0);
        } finally {
            await conn.end();
        }
    });

    it('does not apply the postmeta skip rule to unprefixed or non-postmeta tables', async () => {
        const conn = await createMysqlConnection(importDb);
        try {
            const [unprefixedRows] = await conn.query(
                `SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = ?`,
                [TEST_POST_ID],
            );
            assert.deepEqual(plainRows(unprefixedRows), [
                { meta_key: '_edit_lock', meta_value: 'unprefixed-table-stays' },
            ]);

            const [otherTableRows] = await conn.query(
                `SELECT meta_key, meta_value FROM ${TABLE_PREFIX}othermeta`,
            );
            assert.deepEqual(plainRows(otherTableRows), [
                { meta_key: '_edit_lock', meta_value: 'different-table-stays' },
            ]);
        } finally {
            await conn.end();
        }
    });
});
