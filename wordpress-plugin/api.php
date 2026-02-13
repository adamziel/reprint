<?php
/**
 * Standalone Site Export API endpoint.
 *
 * This file handles export requests WITHOUT loading WordPress.
 * It performs HMAC authentication and delegates to the export library.
 */

// Buffer output so stray warnings don't corrupt the JSON response
if (!ob_get_level()) {
    ob_start();
}

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    $error = [
        'error' => "PHP Error: $errstr",
        'file' => $errfile,
        'line' => $errline,
        'type' => $errno,
    ];
    error_log('Site Export API error: ' . json_encode($error));
    http_response_code(500);
    @header('Content-Type: application/json');
    echo json_encode($error);
    exit(1);
});

set_exception_handler(function ($e) {
    $error = [
        'error' => get_class($e) . ': ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];
    error_log('Site Export API exception: ' . json_encode($error));
    http_response_code(500);
    @header('Content-Type: application/json');
    echo json_encode($error);
    exit(1);
});

/**
 * Maximum age of a request timestamp in seconds.
 * Requests older than this are rejected to prevent replay attacks.
 */
define('SITE_EXPORT_TIMESTAMP_TOLERANCE', 300);

/** Sends a JSON error response and terminates. */
function site_export_error(int $code, string $message): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message, 'code' => $code]);
    exit;
}

/**
 * Reads a request header by name, trying both Apache (getallheaders) and
 * CGI/FastCGI ($_SERVER HTTP_ prefix) conventions.
 */
function site_export_get_header(string $name): ?string {
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
    }

    $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$server_key])) {
        return $_SERVER[$server_key];
    }

    return null;
}

/**
 * Verify HMAC authentication.
 *
 * The signature covers a SHA-256 hash of the request body rather than
 * the raw bytes.  This sidesteps the problem that libcurl generates
 * multipart boundaries internally so the client can't predict the exact
 * byte stream — but it CAN hash the logical content before encoding.
 *
 * Signature = HMAC-SHA256(nonce + timestamp + SHA256(body), secret)
 *
 * The client sends X-Auth-Content-Hash = SHA256(body).  The server
 * independently hashes what it received and checks both that the hash
 * matches AND that the HMAC is valid.
 */
function site_export_verify_hmac(string $secret): ?string {
    $signature = site_export_get_header('X-Auth-Signature');
    $nonce = site_export_get_header('X-Auth-Nonce');
    $timestamp = site_export_get_header('X-Auth-Timestamp');
    $content_hash = site_export_get_header('X-Auth-Content-Hash');

    if (empty($signature)) {
        return 'Missing X-Auth-Signature header';
    }
    if (empty($nonce)) {
        return 'Missing X-Auth-Nonce header';
    }
    if (empty($timestamp)) {
        return 'Missing X-Auth-Timestamp header';
    }
    if (empty($content_hash)) {
        return 'Missing X-Auth-Content-Hash header';
    }

    if (!is_numeric($timestamp)) {
        return 'Invalid timestamp format';
    }

    $request_time = (float) $timestamp;
    $current_time = microtime(true);
    $time_diff = abs($current_time - $request_time);

    if ($time_diff > SITE_EXPORT_TIMESTAMP_TOLERANCE) {
        return sprintf(
            'Request timestamp expired. Difference: %.2f seconds, max allowed: %d seconds',
            $time_diff,
            SITE_EXPORT_TIMESTAMP_TOLERANCE
        );
    }

    if (strlen($nonce) < 16) {
        return 'Nonce must be at least 16 characters';
    }

    $message = $nonce . $timestamp . $content_hash;
    $expected = hash_hmac('sha256', $message, $secret);

    if (!hash_equals($expected, $signature)) {
        return 'HMAC signature verification failed';
    }

    // Now verify that the content hash matches what was actually received.
    // For multipart/form-data, php://input is empty — PHP consumes it
    // into $_FILES — so we hash the uploaded file contents instead.
    if (!empty($_FILES)) {
        $received_body = '';
        ksort($_FILES);
        foreach ($_FILES as $file_info) {
            if (is_uploaded_file($file_info['tmp_name'])) {
                $received_body .= file_get_contents($file_info['tmp_name']);
            }
        }
    } else {
        $received_body = file_get_contents('php://input') ?: '';
    }

    $received_hash = hash('sha256', $received_body);
    if (!hash_equals($content_hash, $received_hash)) {
        return 'Content hash mismatch: body was modified in transit';
    }

    return null; // Success
}

/** Walks up from the plugin directory looking for wp-config.php. */
function site_export_find_wp_root(): ?string {
    $dir = __DIR__;

    for ($i = 0; $i < 10; $i++) {
        if (file_exists($dir . '/wp-config.php')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    return null;
}

$secret_file = __DIR__ . '/secret.php';
if (!file_exists($secret_file)) {
    site_export_error(503, 'Export not configured. Please configure the shared secret in WordPress admin under Tools > Site Export.');
}

$secret = require $secret_file;
if (empty($secret) || !is_string($secret)) {
    site_export_error(503, 'Invalid secret configuration. Please reconfigure in WordPress admin.');
}

$auth_error = site_export_verify_hmac($secret);
if ($auth_error !== null) {
    site_export_error(403, $auth_error);
}

$wp_root = site_export_find_wp_root();

if (!isset($_GET['directory']) && !isset($_POST['directory']) && $wp_root !== null) {
    $_GET['directory'] = $wp_root;
}

// export.php has its own SECRET_KEY guard — satisfy it since we already
// verified the request via HMAC above.
define('SECRET_KEY', 'hmac_authenticated');
$_GET['SECRET_KEY'] = SECRET_KEY;

$export_php = __DIR__ . '/generic/export.php';

if (!file_exists($export_php)) {
    site_export_error(500, 'Export library not found at: ' . $export_php);
}

require_once $export_php;

try {
    $config = parse_http_config();

    if (!isset($config['cursor'])) {
        $config['cursor'] = $_SERVER['HTTP_X_EXPORT_CURSOR'] ?? null;
    }

    if (isset($config['cursor']) && $config['cursor'] !== '' && $config['cursor'] !== null) {
        $cursor_b64 = $config['cursor'];
        $cursor_json = base64_decode($cursor_b64, true);

        if ($cursor_json === false) {
            throw new InvalidArgumentException(
                'Cursor must be base64-encoded. Received invalid base64: ' . substr($cursor_b64, 0, 50)
            );
        }

        $cursor_data = json_decode($cursor_json, true);
        if ($cursor_data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException(
                'Cursor must be valid JSON after base64 decoding. JSON error: ' . json_last_error_msg()
            );
        }

        $config['cursor'] = $cursor_json;
    }

    $endpoint = $config['endpoint'] ?? null;
    if (!$endpoint) {
        throw new InvalidArgumentException(
            "endpoint parameter is required. Valid endpoints: 'file_index', 'file_fetch', 'sql_chunk', 'db_index', 'preflight'"
        );
    }

    $max_execution_time = (int) ($config['max_execution_time'] ?? 5);
    $memory_threshold = (float) ($config['memory_threshold'] ?? 0.8);

    $max_execution_time = require_int_range(
        'max_execution_time',
        $max_execution_time,
        1,
        60
    );

    $memory_threshold = require_float_range(
        'memory_threshold',
        $memory_threshold,
        0.1,
        0.95
    );

    $memory_limit = ini_get('memory_limit');
    if ($memory_limit === '-1') {
        $max_memory = PHP_INT_MAX;
    } else {
        $max_memory = parse_size($memory_limit);
    }

    $script_start = microtime(true);

    switch ($endpoint) {
        case 'file_index':
            endpoint_file_index($config, $script_start, $max_execution_time, $max_memory, $memory_threshold);
            break;

        case 'file_fetch':
            endpoint_file_fetch($config, $script_start, $max_execution_time, $max_memory, $memory_threshold);
            break;

        case 'sql_chunk':
            endpoint_sql_chunk($config, $script_start, $max_execution_time, $max_memory, $memory_threshold);
            break;

        case 'db_index':
            endpoint_db_index($config, $script_start, $max_execution_time, $max_memory, $memory_threshold);
            break;

        case 'preflight':
            endpoint_preflight($config);
            break;

        default:
            throw new InvalidArgumentException(
                "Invalid endpoint: '{$endpoint}'. Valid endpoints: 'file_index', 'file_fetch', 'sql_chunk', 'db_index', 'preflight'"
            );
    }
} catch (Exception $e) {
    if (!headers_sent()) {
        http_response_code(400);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
}
