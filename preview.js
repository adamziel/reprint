#!/usr/bin/env node
/**
 * Boot a previously-exported WordPress site locally using Playground CLI.
 *
 * Auto-detects the site layout (standard WordPress vs wp.com __wp__ layout),
 * creates a local database, imports the SQL dump, patches wp-config.php,
 * resets the admin password, launches Playground CLI, and runs a reverse
 * proxy that rewrites URLs so the browser sees localhost while WordPress
 * thinks it's on its original domain.
 *
 * Usage:
 *   node preview.js --state-dir=DIR --docroot=DIR [--url=<original-url>] [--port=<port>]
 *
 * Environment variables (all optional):
 *   DB_HOST          MySQL host          (default: 127.0.0.1)
 *   DB_USER          MySQL user          (default: root)
 *   DB_PASS          MySQL password      (default: my-secret-pw)
 *   PLAYGROUND_PORT  Playground CLI port  (default: 9400)
 *   WP_ADMIN_PASS    Admin password reset (default: qweasd)
 */

import { execSync, execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';

// ── CLI args ────────────────────────────────────────────────────────

const args = process.argv.slice(2);
let stateDir = null;
let docroot = null;
let urlOverride = null;
let port = null;

for (const arg of args) {
	if (arg.startsWith('--state-dir=')) {
		stateDir = arg.slice('--state-dir='.length);
	} else if (arg.startsWith('--docroot=')) {
		docroot = arg.slice('--docroot='.length);
	} else if (arg.startsWith('--url=')) {
		urlOverride = arg.slice('--url='.length);
	} else if (arg.startsWith('--port=')) {
		port = arg.slice('--port='.length);
	}
}

if (!stateDir || !docroot) {
	console.error('Usage: node preview.js --state-dir=DIR --docroot=DIR [--url=<original-url>] [--port=<port>]');
	process.exit(1);
}

stateDir = path.resolve(stateDir);
docroot = path.resolve(docroot);

const DB_HOST = process.env.DB_HOST || '127.0.0.1';
const DB_USER = process.env.DB_USER || 'root';
const DB_PASS = process.env.DB_PASS || 'my-secret-pw';
const PLAYGROUND_PORT = process.env.PLAYGROUND_PORT || '9400';
const WP_ADMIN_PASS = process.env.WP_ADMIN_PASS || 'qweasd';
const LOCAL_PORT = port || process.env.BOOT_PORT || '8882';
const LOCAL_URL = `http://localhost:${LOCAL_PORT}`;
const SCRIPT_DIR = path.dirname(new URL(import.meta.url).pathname);

// ── Helpers ─────────────────────────────────────────────────────────

function mysqlExec(sql, db) {
	const args = ['-h', DB_HOST, '-u', DB_USER, `-p${DB_PASS}`];
	if (db) args.push('-D', db);
	args.push('-e', sql);
	execFileSync('mysql', args, { stdio: ['pipe', 'pipe', 'pipe'] });
}

function mysqlQuery(sql, db) {
	const args = ['-h', DB_HOST, '-u', DB_USER, `-p${DB_PASS}`, `-D${db}`, '-N', '-e', sql];
	return execFileSync('mysql', args, {
		encoding: 'utf-8', stdio: ['pipe', 'pipe', 'pipe']
	}).trim();
}

function findFile(root, name, maxDepth = 6) {
	// Breadth-first search up to maxDepth
	const queue = [{ dir: root, depth: 0 }];
	while (queue.length) {
		const { dir, depth } = queue.shift();
		if (depth > maxDepth) continue;
		let entries;
		try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch { continue; }
		for (const e of entries) {
			if (e.name === name) return path.join(dir, e.name);
			if (e.isDirectory()) queue.push({ dir: path.join(dir, e.name), depth: depth + 1 });
		}
	}
	return null;
}

function findDir(root, name, maxDepth = 6) {
	const queue = [{ dir: root, depth: 0 }];
	while (queue.length) {
		const { dir, depth } = queue.shift();
		if (depth > maxDepth) continue;
		let entries;
		try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch { continue; }
		for (const e of entries) {
			if (e.isDirectory() && e.name === name) return path.join(dir, e.name);
			if (e.isDirectory()) queue.push({ dir: path.join(dir, e.name), depth: depth + 1 });
		}
	}
	return null;
}

// ── Layout detection ────────────────────────────────────────────────

if (!fs.existsSync(docroot)) {
	console.error(`Error: docroot not found: ${docroot}`);
	process.exit(1);
}

// Detect layout: standard WP (wp-config.php in a normal dir) vs wp.com
// (__wp__ contains core, wp-config.php lives in the parent htdocs dir).
// Check for standard layout first — a standard WP install might contain
// a stray __wp__ directory inside a plugin or cache.
const wpConfigFile = findFile(docroot, 'wp-config.php');
const wpCoreDir = findDir(docroot, '__wp__');
const isWpcom = !!wpCoreDir && (!wpConfigFile || path.dirname(wpConfigFile) === path.dirname(wpCoreDir));

let wpRoot;     // standard: dir containing wp-config.php; wpcom: __wp__ dir
let wpHtdocs;   // wpcom only: parent of __wp__ (contains wp-config.php, wp-content)
let wpCliPath;  // path to wp-settings.php parent (for wp-cli --path)

if (isWpcom) {
	wpHtdocs = path.dirname(wpCoreDir);
	wpRoot = wpCoreDir;
	wpCliPath = wpCoreDir;
	console.log('Detected wp.com layout (__wp__ directory found)');
	console.log(`  htdocs: ${wpHtdocs}`);
	console.log(`  core:   ${wpCoreDir}`);
} else if (wpConfigFile) {
	wpRoot = path.dirname(wpConfigFile);
	wpCliPath = wpRoot;
	console.log('Detected standard WordPress layout');
	console.log(`  root: ${wpRoot}`);
} else {
	console.error(`Error: Could not detect WordPress layout under ${docroot}`);
	console.error('  No wp-config.php or __wp__ directory found.');
	process.exit(1);
}

// ── 1. Create database ─────────────────────────────────────────────

const dirBasename = path.basename(stateDir);
const dbName = `import_${dirBasename}`;
console.log(`\nCreating database ${dbName}...`);
mysqlExec(`DROP DATABASE IF EXISTS \`${dbName}\`; CREATE DATABASE \`${dbName}\`;`);

// ── 2. Import SQL ───────────────────────────────────────────────────

const sqlFile = path.join(stateDir, 'db.sql');
if (!fs.existsSync(sqlFile)) {
	console.error(`Error: ${sqlFile} not found.`);
	process.exit(1);
}
console.log(`Importing ${sqlFile}...`);
execFileSync('mysql', ['-h', DB_HOST, '-u', DB_USER, `-p${DB_PASS}`, `-D${dbName}`], {
	input: fs.readFileSync(sqlFile),
	stdio: ['pipe', 'pipe', 'pipe'],
});

// ── 3. Read original URL ────────────────────────────────────────────
// Detect the table prefix — not always "wp_". Find the *_options table.

const optionsTable = mysqlQuery(
	"SHOW TABLES LIKE '%_options';",
	dbName
);
if (!optionsTable) {
	console.error('Error: No *_options table found in the imported database.');
	process.exit(1);
}
const tablePrefix = optionsTable.replace(/_options$/, '_');
console.log(`Table prefix: ${tablePrefix}`);

let originalUrl = urlOverride;
if (!originalUrl) {
	originalUrl = mysqlQuery(
		`SELECT option_value FROM ${optionsTable} WHERE option_name = 'siteurl' LIMIT 1;`,
		dbName
	);
}
if (!originalUrl) {
	console.error('Error: Could not determine original site URL. Use --url=...');
	process.exit(1);
}
originalUrl = originalUrl.replace(/\/$/, '');
console.log(`Original site URL: ${originalUrl}`);

// ── 4. Write _boot-overrides.php ────────────────────────────────────

const overridesDir = isWpcom ? wpHtdocs : wpRoot;
const overridesFile = path.join(overridesDir, '_boot-overrides.php');

if (isWpcom) {
	// wp.com wp-config.php doesn't define DB constants — the hosting
	// environment injects them. We provide them here for local use.
	fs.writeFileSync(overridesFile, `<?php
/**
 * Auto-prepend overrides for local booting (wpcom layout).
 * Generated by preview.js — safe to delete.
 */

// DB credentials (wpcom doesn't put these in wp-config.php)
define('DB_NAME', '${dbName}');
define('DB_USER', '${DB_USER}');
define('DB_PASSWORD', '${DB_PASS}');
define('DB_HOST', '${DB_HOST}');
define('DB_CHARSET', 'binary');
// define('DB_CHARSET', 'latin1');
define('DB_COLLATE', '');
`);
} else {
	fs.writeFileSync(overridesFile, `<?php
/**
 * Auto-prepend overrides for local booting.
 * Generated by preview.js — safe to delete.
 */

// Override database credentials to point at the local import database
define('SITE_EXPORT_DB_OVERRIDE', true);
$_site_export_db_name = '${dbName}';
$_site_export_db_user = '${DB_USER}';
$_site_export_db_pass = '${DB_PASS}';
$_site_export_db_host = '${DB_HOST}';
`);
}

// ── 5. Patch wp-config.php ──────────────────────────────────────────

const wpConfigPath = isWpcom
	? path.join(wpHtdocs, 'wp-config.php')
	: path.join(wpRoot, 'wp-config.php');

let wpConfig = fs.readFileSync(wpConfigPath, 'utf-8');

if (!wpConfig.includes('_boot-overrides.php')) {
	console.log('Patching wp-config.php to load overrides...');

	// Insert require right after opening <?php
	const needle = '<?php';
	const pos = wpConfig.indexOf(needle);
	if (pos !== -1) {
		const insert =
			needle + '\n' +
			'// Boot overrides for local testing (added by preview.js)\n' +
			"if (file_exists(__DIR__ . '/_boot-overrides.php')) { require __DIR__ . '/_boot-overrides.php'; }\n";
		wpConfig = insert + wpConfig.slice(pos + needle.length);
	}

	if (!isWpcom) {
		// For standard layout, rewrite DB_* defines to use override vars
		wpConfig = wpConfig.replace(
			/define\s*\(\s*['"]DB_NAME['"]\s*,\s*['"][^'"]*['"]\s*\)/,
			`define('DB_NAME', isset($_site_export_db_name) ? $_site_export_db_name : '${dbName}')`
		);
		wpConfig = wpConfig.replace(
			/define\s*\(\s*['"]DB_USER['"]\s*,\s*['"][^'"]*['"]\s*\)/,
			`define('DB_USER', isset($_site_export_db_user) ? $_site_export_db_user : '${DB_USER}')`
		);
		wpConfig = wpConfig.replace(
			/define\s*\(\s*['"]DB_PASSWORD['"]\s*,\s*['"][^'"]*['"]\s*\)/,
			`define('DB_PASSWORD', isset($_site_export_db_pass) ? $_site_export_db_pass : '${DB_PASS}')`
		);
		wpConfig = wpConfig.replace(
			/define\s*\(\s*['"]DB_HOST['"]\s*,\s*['"][^'"]*['"]\s*\)/,
			`define('DB_HOST', isset($_site_export_db_host) ? $_site_export_db_host : '${DB_HOST}')`
		);
	}

	fs.writeFileSync(wpConfigPath, wpConfig);
}

// ── 6. Reset admin password ─────────────────────────────────────────

const wpCliPhar = path.join(SCRIPT_DIR, 'wp-cli.phar');
if (!fs.existsSync(wpCliPhar)) {
	console.log('Downloading wp-cli.phar...');
	execSync(
		`curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar --output-dir ${JSON.stringify(SCRIPT_DIR)}`,
		{ stdio: 'pipe' }
	);
}

console.log('Resetting admin password...');
let adminUser;
try {
	adminUser = execSync(
		`php ${JSON.stringify(wpCliPhar)} --path=${JSON.stringify(wpCliPath)} user list --role=administrator --field=user_login --format=csv`,
		{ encoding: 'utf-8', stdio: ['pipe', 'pipe', 'pipe'] }
	).trim().split('\n')[0];
} catch {
	adminUser = '';
}
if (!adminUser) {
	console.log("Warning: Could not find an administrator user. Trying 'admin'...");
	adminUser = 'admin';
}
try {
	execSync(
		`php ${JSON.stringify(wpCliPhar)} --path=${JSON.stringify(wpCliPath)} user update ${JSON.stringify(adminUser)} --user_pass=${JSON.stringify(WP_ADMIN_PASS)} --skip-email`,
		{ stdio: ['pipe', 'pipe', 'pipe'] }
	);
} catch {
	console.log('Warning: Could not reset admin password via wp-cli.');
}
console.log(`Admin user: ${adminUser} / password: ${WP_ADMIN_PASS}`);

// ── 7. Launch Playground CLI ────────────────────────────────────────

console.log(`\nStarting Playground CLI on port ${PLAYGROUND_PORT}...`);

const mountArgs = isWpcom
	? [
		`--mount=${wpCoreDir}:/wordpress`,
		`--mount=${wpHtdocs}/wp-content:/wordpress/wp-content`,
		`--mount=${wpHtdocs}/wp-config.php:/wordpress/wp-config.php`,
		`--mount=${wpHtdocs}/_boot-overrides.php:/wordpress/_boot-overrides.php`,
	]
	: [`--mount=${wpRoot}:/wordpress`];

const playgroundProc = spawn('npx', [
	'@wp-playground/cli@latest', 'server',
	'--skip-sqlite-setup',
	'--wordpress-install-mode=do-not-attempt-installing',
	...mountArgs,
	`--site-url=${originalUrl}`,
	`--port=${PLAYGROUND_PORT}`,
], { stdio: 'inherit', shell: true });

// Clean up on exit
function cleanup() {
	console.log('\nStopping Playground...');
	playgroundProc.kill();
}
process.on('SIGINT', () => { cleanup(); process.exit(0); });
process.on('SIGTERM', () => { cleanup(); process.exit(0); });
playgroundProc.on('exit', () => process.exit(0));

// ── 8. Wait for Playground ──────────────────────────────────────────

console.log('Waiting for Playground CLI to become ready...');
await waitForPlayground(PLAYGROUND_PORT, 60);
console.log('Playground is ready.');
console.log(`\nAdmin login: ${LOCAL_URL}/wp-admin/`);
console.log('Press Ctrl+C to stop.\n');

// ── 9. Reverse proxy ───────────────────────────────────────────────
// Inline version of _reverse-proxy.mjs — rewrites URLs from the original
// domain to localhost so the browser sees local links while WordPress
// thinks it's on its real domain.

const parsed = new URL(originalUrl);
const originalHost = parsed.hostname;
const originalScheme = parsed.protocol.replace(':', '');
const oppositeScheme = originalScheme === 'https' ? 'http' : 'https';

// All URL variants to search/replace, ordered from most specific to least
const replacements = [
	[originalUrl, LOCAL_URL],
	[`${oppositeScheme}://${originalHost}`, LOCAL_URL],
	[`//${originalHost}`, `//localhost:${LOCAL_PORT}`],
];

/**
 * Naive and oversimplified URL rewriting. It's only good enough for a quick
 * clickthrough the site to see if the pages got imported correctly.
 */
function rewriteUrls(text) {
	for (const [search, replace] of replacements) {
		text = text.replaceAll(search, replace);
	}
	return text;
}

function shouldRewriteBody(contentType) {
	if (!contentType) return false;
	const ct = contentType.toLowerCase();
	return (
		ct.includes('text/html') ||
		ct.includes('text/css') ||
		ct.includes('application/javascript') ||
		ct.includes('text/javascript') ||
		ct.includes('application/json')
	);
}

// WordPress thumbnail suffix pattern, e.g. image-768x768.jpeg → image.jpeg
const thumbnailSuffixRe = /-\d+x\d+(\.\w+)$/;

function proxyRequest(proxyPath, req, res) {
	const options = {
		hostname: 'localhost',
		port: PLAYGROUND_PORT,
		path: proxyPath,
		method: req.method,
		headers: {
			...req.headers,
			host: originalHost,
		},
	};

	const proxyReq = http.request(options, (proxyRes) => {
		// If a thumbnail 404s, retry with the original (unsized) filename
		if (proxyRes.statusCode === 404 && thumbnailSuffixRe.test(proxyPath)) {
			// Consume the 404 response body so the socket is freed
			proxyRes.resume();
			const fallbackPath = proxyPath.replace(thumbnailSuffixRe, '$1');
			proxyRequest(fallbackPath, req, res);
			return;
		}
		const contentType = proxyRes.headers['content-type'] || '';
		const rewrite = shouldRewriteBody(contentType);

		// Rewrite Location headers for redirects
		if (proxyRes.headers['location']) {
			proxyRes.headers['location'] = rewriteUrls(proxyRes.headers['location']);
		}

		if (!rewrite) {
			// Pass through binary/other responses unchanged
			res.writeHead(proxyRes.statusCode, proxyRes.headers);
			proxyRes.pipe(res);
			return;
		}

		// Buffer text responses for URL rewriting
		const chunks = [];
		proxyRes.on('data', (chunk) => chunks.push(chunk));
		proxyRes.on('end', () => {
			let body = Buffer.concat(chunks).toString('utf-8');
			body = rewriteUrls(body);

			// Remove transfer-encoding since we're sending the full body
			const headers = { ...proxyRes.headers };
			delete headers['transfer-encoding'];
			delete headers['content-encoding'];
			headers['content-length'] = Buffer.byteLength(body);

			res.writeHead(proxyRes.statusCode, headers);
			res.end(body);
		});
	});

	proxyReq.on('error', (err) => {
		console.error(`Proxy error: ${err.message}`);
		res.writeHead(502);
		res.end(`Proxy error: ${err.message}\n`);
	});

	req.pipe(proxyReq);
}

const server = http.createServer((req, res) => {
	proxyRequest(req.url, req, res);
});

server.listen(LOCAL_PORT, 'localhost', () => {
	console.log(`Reverse proxy listening on ${LOCAL_URL}`);
	console.log(`  → Playground CLI on localhost:${PLAYGROUND_PORT}`);
	console.log(`  → Rewriting ${originalUrl} → ${LOCAL_URL}`);
});

// ── Utility ─────────────────────────────────────────────────────────

function waitForPlayground(playgroundPort, timeoutSeconds) {
	return new Promise((resolve, reject) => {
		const start = Date.now();
		const interval = setInterval(() => {
			const req = http.get(`http://localhost:${playgroundPort}/`, (res) => {
				res.resume();
				clearInterval(interval);
				resolve();
			});
			req.on('error', () => {
				if (Date.now() - start > timeoutSeconds * 1000) {
					clearInterval(interval);
					reject(new Error(`Playground did not become ready within ${timeoutSeconds}s`));
				}
			});
			req.end();
		}, 1000);
	});
}
