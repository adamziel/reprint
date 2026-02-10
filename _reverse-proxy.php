<?php
/**
 * Reverse proxy for WordPress Playground CLI.
 *
 * Sits between the browser (localhost:PORT) and Playground CLI (localhost:PLAYGROUND_PORT).
 * Rewrites URLs so the browser sees localhost while WordPress thinks it's on its original domain.
 *
 * Config via environment variables:
 *   PLAYGROUND_PORT  — Playground CLI port (default 9400)
 *   ORIGINAL_URL     — Original site URL (e.g. https://adamadam.blog)
 *   LOCAL_URL        — Local URL (e.g. http://localhost:8881)
 *   WP_INCLUDES_PATH — Path to wp-includes for HTML API loading
 */

// ── Configuration ──────────────────────────────────────────────────

ini_set('display_errors', '0');
error_reporting(0);
$playground_port = getenv('PLAYGROUND_PORT') ?: '9400';
$original_url    = rtrim(getenv('ORIGINAL_URL') ?: '', '/');
$local_url       = rtrim(getenv('LOCAL_URL') ?: '', '/');
$wp_includes     = getenv('WP_INCLUDES_PATH') ?: '';

if (!$original_url || !$local_url) {
    http_response_code(500);
    echo "ORIGINAL_URL and LOCAL_URL environment variables are required.\n";
    exit(1);
}

// Parse original URL components for rewriting variants
$original_parsed = parse_url($original_url);
$original_host   = $original_parsed['host'];
$original_scheme = $original_parsed['scheme'] ?? 'https';

// Build all URL variants we need to replace:
// 1. Full URL with scheme (https://domain.com)
// 2. Opposite scheme variant (http://domain.com)
// 3. Protocol-relative (//domain.com)
$opposite_scheme = ($original_scheme === 'https') ? 'http' : 'https';
$search_urls = [
    $original_url,
    $opposite_scheme . '://' . $original_host,
    '//' . $original_host,
];

$local_parsed = parse_url($local_url);
$replace_urls = [
    $local_url,
    $local_url,
    '//' . $local_parsed['host'] . (isset($local_parsed['port']) ? ':' . $local_parsed['port'] : ''),
];

// ── WordPress HTML API standalone loading ──────────────────────────

$html_api_loaded = false;

if ($wp_includes && is_dir($wp_includes . '/html-api')) {
    // Define WordPress stub functions that the HTML API depends on
    if (!function_exists('__')) {
        function __($text) { return $text; }
    }
    if (!function_exists('_doing_it_wrong')) {
        function _doing_it_wrong() {}
    }
    if (!function_exists('apply_filters')) {
        function apply_filters($hook, $value) { return $value; }
    }
    if (!function_exists('wp_kses_uri_attributes')) {
        function wp_kses_uri_attributes() {
            return [
                'action', 'cite', 'classid', 'codebase', 'data', 'formaction',
                'href', 'icon', 'manifest', 'poster', 'src', 'srcset',
            ];
        }
    }

    require_once $wp_includes . '/../wp-load.php';
    $html_api_loaded = true;
}

// ── URL rewriting helpers ──────────────────────────────────────────

/**
 * Rewrite URLs in an HTML response body.
 * Pass 1: attribute rewriting via WP_HTML_Tag_Processor (if available).
 * Pass 2: string replacement for inline scripts, JSON-LD, style blocks, etc.
 */
function rewrite_html($body) {
    global $html_api_loaded, $search_urls, $replace_urls;

    // Pass 1: attribute-level rewriting with the HTML API
    if ($html_api_loaded && class_exists('WP_HTML_Tag_Processor')) {
        $url_attributes = ['href', 'src', 'action', 'srcset', 'poster', 'data', 'formaction', 'content'];
        $processor = new WP_HTML_Tag_Processor($body);
        while ($processor->next_tag()) {
            foreach ($url_attributes as $attr) {
                $value = $processor->get_attribute($attr);
                if ($value !== null && is_string($value)) {
                    $new_value = str_replace($search_urls, $replace_urls, $value);
                    if ($new_value !== $value) {
                        $processor->set_attribute($attr, $new_value);
                    }
                }
            }
        }
        $body = $processor->get_updated_html();
    }

    // Pass 2: catch remaining URLs in inline scripts, JSON-LD, style blocks, etc.
    $body = str_replace($search_urls, $replace_urls, $body);

    return $body;
}

/**
 * Rewrite URLs in CSS or JS content via string replacement.
 */
function rewrite_text($body) {
    global $search_urls, $replace_urls;
    return str_replace($search_urls, $replace_urls, $body);
}

// ── Proxy request ──────────────────────────────────────────────────

$method       = $_SERVER['REQUEST_METHOD'];
$request_uri  = $_SERVER['REQUEST_URI'];
$target_url   = "http://localhost:{$playground_port}{$request_uri}";

// Build cURL request
$ch = curl_init($target_url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

// Forward request headers, replacing Host with the original domain
$forward_headers = [];
$forward_headers[] = "Host: {$original_host}";

foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0 && $key !== 'HTTP_HOST') {
        $header_name = str_replace('_', '-', substr($key, 5));
        $forward_headers[] = "{$header_name}: {$value}";
    }
}

// Forward Content-Type and Content-Length for POST/PUT
if (isset($_SERVER['CONTENT_TYPE'])) {
    $forward_headers[] = "Content-Type: {$_SERVER['CONTENT_TYPE']}";
}
if (isset($_SERVER['CONTENT_LENGTH'])) {
    $forward_headers[] = "Content-Length: {$_SERVER['CONTENT_LENGTH']}";
}

curl_setopt($ch, CURLOPT_HTTPHEADER, $forward_headers);

// Forward request body for POST/PUT/PATCH
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $body = file_get_contents('php://input');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

// Capture response headers
$response_headers = [];
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$response_headers) {
    $len = strlen($header);
    $parts = explode(':', $header, 2);
    if (count($parts) === 2) {
        $name  = trim($parts[0]);
        $value = trim($parts[1]);
        $response_headers[strtolower($name)] = ['name' => $name, 'value' => $value];
    }
    return $len;
});

$response_body = curl_exec($ch);

if ($response_body === false) {
    http_response_code(502);
    echo "Proxy error: " . curl_error($ch) . "\n";
    curl_close($ch);
    exit(1);
}

$status_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
curl_close($ch);

// ── Forward response ───────────────────────────────────────────────

http_response_code($status_code);

// Forward response headers, rewriting Location for redirects
$skip_headers = ['transfer-encoding', 'content-length', 'content-encoding', 'connection'];
foreach ($response_headers as $lower_name => $header) {
    if (in_array($lower_name, $skip_headers)) {
        continue;
    }
    $value = $header['value'];
    if ($lower_name === 'location') {
        $value = str_replace($search_urls, $replace_urls, $value);
    }
    header("{$header['name']}: {$value}");
}

// ── Rewrite body based on content type ─────────────────────────────

$ct_lower = strtolower($content_type);

if (strpos($ct_lower, 'text/html') !== false) {
    $response_body = rewrite_html($response_body);
} elseif (
    strpos($ct_lower, 'text/css') !== false ||
    strpos($ct_lower, 'application/javascript') !== false ||
    strpos($ct_lower, 'text/javascript') !== false ||
    strpos($ct_lower, 'application/json') !== false
) {
    $response_body = rewrite_text($response_body);
}

// Set correct Content-Length after rewriting
header('Content-Length: ' . strlen($response_body));

echo $response_body;
