<?php
/**
 * Plugin Name: Site Export
 * Plugin URI: https://github.com/WordPress/playground-tools
 * Description: Exposes a site export API with HMAC-authenticated endpoints for database and file synchronization.
 * Version: 1.0.0
 * Author: WordPress Contributors
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SITE_EXPORT_VERSION', '1.0.0');
define('SITE_EXPORT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SITE_EXPORT_SECRET_FILE', SITE_EXPORT_PLUGIN_DIR . 'secret.php');

/**
 * Maximum age of a request timestamp in seconds.
 * Requests older than this are rejected to prevent replay attacks.
 */
define('SITE_EXPORT_TIMESTAMP_TOLERANCE', 300);

/**
 * Get the query arg name used by the default front-controller route.
 */
function _site_export_get_api_query_arg(): string {
    $query_arg = apply_filters('site_export_api_query_arg', 'site-export-api');

    if (!is_string($query_arg) || $query_arg === '') {
        return 'site-export-api';
    }

    return $query_arg;
}

/**
 * Determine whether the current request should be handled by the export API.
 */
function _site_export_is_api_request(): bool {
    $query_arg = _site_export_get_api_query_arg();
    $is_api_request = isset($_GET[$query_arg]);

    return (bool) apply_filters('site_export_is_api_request', $is_api_request, $query_arg);
}

/**
 * Get the API URL shown in the admin UI.
 */
function _site_export_get_api_url(): string {
    $query_arg = _site_export_get_api_query_arg();
    $api_url = home_url('/?' . rawurlencode($query_arg));
    $filtered_url = apply_filters('site_export_api_url', $api_url, $query_arg);

    if (!is_string($filtered_url) || $filtered_url === '') {
        return $api_url;
    }

    return $filtered_url;
}

/**
 * Get the path to the shared secret file.
 */
function _site_export_get_secret_file(): string {
    $secret_file = apply_filters('site_export_secret_file', SITE_EXPORT_SECRET_FILE);

    if (!is_string($secret_file) || $secret_file === '') {
        return SITE_EXPORT_SECRET_FILE;
    }

    return $secret_file;
}

/**
 * Get the request authorization callback.
 *
 * The callback must return:
 * - null or true on success
 * - a string or WP_Error on failure
 */
function _site_export_get_authorization_callback() {
    $default_callback = '_site_export_authorize_request_with_secret';
    $callback = apply_filters('site_export_authorization_callback', $default_callback);

    if (!is_callable($callback)) {
        return $default_callback;
    }

    return $callback;
}

/**
 * Check whether the plugin is using its default secret-based authorization.
 */
function _site_export_uses_default_secret_authorization(): bool {
    return _site_export_get_authorization_callback() === '_site_export_authorize_request_with_secret';
}

/**
 * Determine whether the admin UI should be registered.
 *
 * By default, the UI is enabled only for the built-in secret/HMAC flow.
 */
function _site_export_is_ui_enabled(): bool {
    $enabled = _site_export_uses_default_secret_authorization();

    return (bool) apply_filters('site_export_enable_ui', $enabled);
}

// Intercept export API requests as early as possible.
// WordPress loads plugin files before firing `plugins_loaded`,
// so this runs before almost anything else in the WordPress stack.
if (_site_export_is_api_request()) {
    _site_export_handle_api_request();
    exit;
}

// Register the settings page.
require_once __DIR__ . '/wordpress/site-export.php';

/**
 * Handle an export API request.
 *
 * WordPress is already loaded at this point — DB credentials, $table_prefix,
 * and the database layer (including the SQLite db.php drop-in when present)
 * are all available.
 */
function _site_export_handle_api_request(): void {
    // Revert WordPress error display settings (wp_debug_mode may
    // have enabled display_errors based on WP_DEBUG_DISPLAY).
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');

    // Clear any output buffering WordPress started.
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Allow CORS requests for WordPress Playground integration.
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: *');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header("Allow: GET, POST, OPTIONS");
        exit;
    }

    // Buffer output so stray warnings don't corrupt the JSON response.
    ob_start();

    // Clear PHP's stat and realpath caches to ensure fresh filesystem state.
    // PHP-FPM workers cache realpath() results for 120 seconds across requests.
    // If the same worker handles both an initial file_index scan and a delta scan
    // within that window, stale cached paths can cause wrong type information
    // (e.g., a symlink that was replaced by a directory still resolves as the
    // old symlink target). This is cheap and prevents non-deterministic failures.
    clearstatcache(true);

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

    // -- Authenticate --
    $auth_error = _site_export_authorize_request();
    if ($auth_error !== null) {
        _site_export_error(403, $auth_error);
    }

    // -- Set up defaults for export.php --
    if (!isset($_GET['directory']) && !isset($_POST['directory'])) {
        $_GET['directory'] = ABSPATH;
    }

    // export.php has its own SECRET_KEY guard — satisfy it since we already
    // verified the request via HMAC above.
    define('SECRET_KEY', 'hmac_authenticated');
    $_GET['SECRET_KEY'] = SECRET_KEY;

    require_once __DIR__ . '/generic/export.php';

    // -- Dispatch --
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

        $budget = new ResourceBudget(
            microtime(true),
            $max_execution_time,
            $max_memory,
            $memory_threshold,
        );

        switch ($endpoint) {
            case 'file_index':
                endpoint_file_index($config, $budget);
                break;

            case 'file_fetch':
                endpoint_file_fetch($config, $budget);
                break;

            case 'sql_chunk':
                endpoint_sql_chunk($config, $budget);
                break;

            case 'db_index':
                endpoint_db_index($config, $budget);
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
}

/** Sends a JSON error response and terminates. */
function _site_export_error(int $code, string $message): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message, 'code' => $code]);
    exit;
}

/**
 * Authorize the current request using the configured callback.
 */
function _site_export_authorize_request(): ?string {
    $callback = _site_export_get_authorization_callback();
    $result = call_user_func($callback, [
        'secret_file' => _site_export_get_secret_file(),
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    ]);

    if ($result === null || $result === true) {
        return null;
    }

    if ($result instanceof WP_Error) {
        return $result->get_error_message();
    }

    if (is_string($result) && $result !== '') {
        return $result;
    }

    if ($result === false) {
        return 'Authorization failed.';
    }

    return 'Invalid Site Export authorization result.';
}

/**
 * Default authorization callback using the shared secret file and HMAC.
 *
 * @param array<string, mixed> $context Request context.
 */
function _site_export_authorize_request_with_secret(array $context): ?string {
    $secret_file = $context['secret_file'] ?? _site_export_get_secret_file();

    if (!is_string($secret_file) || $secret_file === '') {
        $secret_file = _site_export_get_secret_file();
    }

    if (!file_exists($secret_file)) {
        return 'Export not configured. Please configure the shared secret in WordPress admin under Tools > Site Export.';
    }

    $secret = require $secret_file;
    if (empty($secret) || !is_string($secret)) {
        return 'Invalid secret configuration. Please reconfigure in WordPress admin.';
    }

    return _site_export_verify_hmac($secret);
}

/**
 * Reads a request header by name, trying both Apache (getallheaders) and
 * CGI/FastCGI ($_SERVER HTTP_ prefix) conventions.
 */
function _site_export_get_header(string $name): ?string {
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
function _site_export_verify_hmac(string $secret): ?string {
    $signature = _site_export_get_header('X-Auth-Signature');
    $nonce = _site_export_get_header('X-Auth-Nonce');
    $timestamp = _site_export_get_header('X-Auth-Timestamp');
    $content_hash = _site_export_get_header('X-Auth-Content-Hash');

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
