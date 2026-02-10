/**
 * Reverse proxy for WordPress Playground CLI.
 *
 * Sits between the browser (localhost:PORT) and Playground CLI (localhost:PLAYGROUND_PORT).
 * Rewrites URLs so the browser sees localhost while WordPress thinks it's on its original domain.
 *
 * Environment variables:
 *   PLAYGROUND_PORT  — Playground CLI port (default 9400)
 *   ORIGINAL_URL     — Original site URL (e.g. https://adamadam.blog)
 *   LOCAL_PORT       — Local proxy port (e.g. 8882)
 */

import http from 'node:http';

const playgroundPort = process.env.PLAYGROUND_PORT || '9400';
const originalUrl = (process.env.ORIGINAL_URL || '').replace(/\/$/, '');
const localPort = process.env.LOCAL_PORT || '8882';

if (!originalUrl) {
	console.error('ORIGINAL_URL environment variable is required.');
	process.exit(1);
}

const localUrl = `http://localhost:${localPort}`;
const parsed = new URL(originalUrl);
const originalHost = parsed.hostname;
const originalScheme = parsed.protocol.replace(':', '');
const oppositeScheme = originalScheme === 'https' ? 'http' : 'https';

// All URL variants to search/replace, ordered from most specific to least
const replacements = [
	[originalUrl, localUrl],
	[`${oppositeScheme}://${originalHost}`, localUrl],
	[`//${originalHost}`, `//${`localhost:${localPort}`}`],
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

const server = http.createServer((req, res) => {
	proxyRequest(req.url, req, res);
});

function proxyRequest(path, req, res) {
	const options = {
		hostname: 'localhost',
		port: playgroundPort,
		path,
		method: req.method,
		headers: {
			...req.headers,
			host: originalHost,
		},
	};

	const proxyReq = http.request(options, (proxyRes) => {
		// If a thumbnail 404s, retry with the original (unsized) filename
		if (proxyRes.statusCode === 404 && thumbnailSuffixRe.test(path)) {
			// Consume the 404 response body so the socket is freed
			proxyRes.resume();
			const fallbackPath = path.replace(thumbnailSuffixRe, '$1');
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

server.listen(localPort, 'localhost', () => {
	console.log(`Reverse proxy listening on ${localUrl}`);
	console.log(`  → Playground CLI on localhost:${playgroundPort}`);
	console.log(`  → Rewriting ${originalUrl} → ${localUrl}`);
});
