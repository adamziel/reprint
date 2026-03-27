<?php
/**
 * Remote upload proxy — route handler implementation.
 *
 * Returns PHP code (as a string) that proxies requests for missing
 * uploaded files from the source site.  During migration the local
 * file tree may be incomplete — files-sync hasn't finished yet.
 * Instead of showing a broken image or a 404, this handler fetches
 * the original from the source site and streams the response
 * (status code, content-type, body) straight to the client.
 *
 * The generated code is a self-contained IIFE that:
 * - Checks $_SERVER['REQUEST_URI'] for paths inside /wp-content/uploads/
 * - Requires STREAMING_REMOTE_SITE_URL to be defined (by the bootstrap)
 * - Returns silently when the file exists locally (let the server serve it)
 * - Uses cURL to fetch from the source and streams the response
 * - Calls exit() — no need to boot WordPress
 */
function remote_upload_proxy_code(): string
{
    return <<<'PHP'
/**
 * Remote upload proxy for in-progress migrations.
 *
 * When a visitor requests an uploaded file that hasn't been synced yet,
 * this handler fetches it from the original source site and streams the
 * response directly to the client — preserving the status code, content
 * type, and body.  Once files-sync finishes and the file exists locally
 * the handler returns silently, letting the web server serve the file.
 */
(function() {
	$remote_site = defined('STREAMING_REMOTE_SITE_URL')
		? STREAMING_REMOTE_SITE_URL
		: '';
	if ($remote_site === '') return;

	// Once files-sync finishes, it writes a marker file whose path is
	// baked into the STREAMING_SYNC_MARKER constant at apply-runtime time.
	// When that marker exists the proxy is no longer needed — all uploads
	// are available locally.  clearstatcache() is needed because php -S is
	// a long-running process and the stat cache may hold stale entries for
	// files created after the server started.
	$marker = defined('STREAMING_SYNC_MARKER') ? STREAMING_SYNC_MARKER : '';
	if ($marker !== '') {
		clearstatcache(true, $marker);
		if (file_exists($marker)) return;
	}

	$uri  = $_SERVER['REQUEST_URI'] ?? '';
	$path = parse_url($uri, PHP_URL_PATH);
	if (!$path) return;

	// Only handle requests inside /wp-content/uploads/
	if (strpos($path, '/wp-content/uploads/') === false) return;

	// If the file already exists locally, let the server handle it.
	// Use DOCUMENT_ROOT (set by both php -S and nginx/fpm) so the
	// handler works regardless of whether WP_CONTENT_DIR is defined
	// in the runtime constants — it isn't for standard layouts where
	// wp-content lives inside ABSPATH.
	$doc_root   = $_SERVER['DOCUMENT_ROOT'] ?? '';
	$local_path = $doc_root . $path;
	if ($doc_root !== '' && file_exists($local_path)) return;

	// Build the remote URL from the source site URL and the request path.
	// Use the full request path so it works regardless of whether the
	// uploads URL matches the default /wp-content/uploads/ layout.
	$remote_url = rtrim($remote_site, '/') . $path;

	if (!function_exists('curl_init')) {
		// No cURL — fall back to a plain 404 so WordPress can handle it
		return;
	}

	$ch = curl_init($remote_url);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);

	// Forward the response status and headers to the client.
	// Skip hop-by-hop headers that don't apply to our response.
	$skip_headers = [
		'transfer-encoding', 'connection', 'keep-alive',
		'proxy-authenticate', 'proxy-authorization',
		'te', 'trailers', 'upgrade',
	];
	$status_sent = false;
	curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $raw) use (&$status_sent, $skip_headers) {
		$trimmed = trim($raw);
		if ($trimmed === '') return strlen($raw);

		// Forward the HTTP status line (e.g. "HTTP/1.1 200 OK")
		if (!$status_sent && strpos($trimmed, 'HTTP/') === 0) {
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			if ($code > 0) {
				http_response_code($code);
			}
			$status_sent = true;
			return strlen($raw);
		}

		// Forward response headers, skipping hop-by-hop ones
		$colon = strpos($trimmed, ':');
		if ($colon !== false) {
			$name = strtolower(trim(substr($trimmed, 0, $colon)));
			if (!in_array($name, $skip_headers, true)) {
				header($trimmed);
			}
		}
		return strlen($raw);
	});

	// Stream the body directly to the client
	curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
		echo $data;
		return strlen($data);
	});

	curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
	curl_exec($ch);

	$errno = curl_errno($ch);
	curl_close($ch);

	// If cURL failed entirely (DNS error, timeout, etc.), fall through
	// to WordPress so it can show its own 404 page.
	if ($errno !== 0) return;

	exit;
})();
PHP;
}
