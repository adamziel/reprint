<?php
/**
 * Push session API for Reprint Exporter.
 *
 * The target WordPress site owns push sessions. Each session is backed by the
 * same relay directory layout used by the CLI transport so ImportClient remains
 * the single source of truth for target-authored export requests and import
 * sequencing. A local source worker polls this API, executes those requests
 * against the local exporter, and posts responses back into the session.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SITE_EXPORT_PUSH_API_PARAM')) {
    define('SITE_EXPORT_PUSH_API_PARAM', 'reprint-push-api');
}
if (!defined('SITE_EXPORT_PUSH_SESSION_TTL')) {
    define('SITE_EXPORT_PUSH_SESSION_TTL', 86400);
}

function _site_export_push_error(int $code, string $message): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $message, 'code' => $code]);
    exit;
}

function _site_export_handle_push_api_request(array $options = []): void {
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');

    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    $authenticate = $options['authenticate'] ?? '_site_export_default_authenticate';
    $authenticate();

    try {
        $endpoint = isset($_GET['endpoint']) ? (string) $_GET['endpoint'] : '';
        switch ($endpoint) {
            case 'create':
                _site_export_push_send_json(_site_export_push_create_session(_site_export_push_read_json_body()));
                return;

            case 'run':
                _site_export_push_send_json(_site_export_push_run_session(_site_export_push_require_session_id()));
                return;

            case 'claim':
                _site_export_push_send_json(_site_export_push_claim_request(_site_export_push_require_session_id()));
                return;

            case 'response-body':
                _site_export_push_send_json(_site_export_push_store_response_body(
                    _site_export_push_require_session_id(),
                    _site_export_push_require_request_id(),
                    _site_export_push_uploaded_file('body')
                ));
                return;

            case 'response':
                _site_export_push_send_json(_site_export_push_store_response_metadata(
                    _site_export_push_require_session_id(),
                    _site_export_push_read_json_body()
                ));
                return;

            case 'status':
                _site_export_push_send_json(_site_export_push_session_status(_site_export_push_require_session_id()));
                return;

            case 'abort':
                _site_export_push_send_json(_site_export_push_abort_session(_site_export_push_require_session_id()));
                return;
        }

        _site_export_push_error(400, 'Unknown push API endpoint.');
    } catch (Throwable $e) {
        _site_export_push_error(500, $e->getMessage());
    }
}

function _site_export_push_send_json(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function _site_export_push_read_json_body(): array {
    $body = file_get_contents('php://input');
    if ($body === false || trim($body) === '') {
        return [];
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON request body.');
    }
    return $decoded;
}

function _site_export_push_require_session_id(): string {
    $session_id = isset($_GET['session_id']) ? (string) $_GET['session_id'] : '';
    return _site_export_push_validate_id($session_id, 'session_id');
}

function _site_export_push_require_request_id(): string {
    $request_id = isset($_GET['request_id']) ? (string) $_GET['request_id'] : '';
    return _site_export_push_validate_id($request_id, 'request_id');
}

function _site_export_push_validate_id(string $id, string $label): string {
    if ($id === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $id)) {
        throw new RuntimeException("Invalid {$label}.");
    }
    return $id;
}

function _site_export_push_uploaded_file(string $name): string {
    if (empty($_FILES[$name]) || !is_array($_FILES[$name])) {
        throw new RuntimeException("Missing {$name} upload.");
    }
    $tmp_name = $_FILES[$name]['tmp_name'] ?? null;
    if (!is_string($tmp_name) || $tmp_name === '' || !is_uploaded_file($tmp_name)) {
        // Unit tests and some SAPIs cannot mark files as HTTP uploads.
        if (!is_string($tmp_name) || $tmp_name === '' || !is_file($tmp_name)) {
            throw new RuntimeException("Invalid {$name} upload.");
        }
    }
    return $tmp_name;
}

function _site_export_push_create_session(array $payload): array {
    $source_url = isset($payload['source_url']) ? trim((string) $payload['source_url']) : '';
    if ($source_url === '') {
        throw new RuntimeException('source_url is required.');
    }

    $session_id = bin2hex(random_bytes(16));
    $session_dir = _site_export_push_session_dir($session_id);
    foreach ([
        $session_dir,
        $session_dir . '/relay/requests',
        $session_dir . '/relay/responses',
        $session_dir . '/relay/processing',
        $session_dir . '/relay/uploads',
        $session_dir . '/import',
    ] as $dir) {
        _site_export_push_ensure_dir($dir);
    }

    $session = [
        'id' => $session_id,
        'status' => 'created',
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'source_url' => $source_url,
        'command' => isset($payload['command']) ? (string) $payload['command'] : 'pull',
        'options' => isset($payload['options']) && is_array($payload['options']) ? $payload['options'] : [],
    ];
    _site_export_push_write_session($session_id, $session);

    return [
        'ok' => true,
        'session_id' => $session_id,
        'status' => $session['status'],
    ];
}

function _site_export_push_run_session(string $session_id): array {
    $session = _site_export_push_read_session($session_id);
    if (($session['status'] ?? '') === 'aborted') {
        return _site_export_push_session_status($session_id);
    }

    $session['status'] = 'running';
    $session['started_at'] = $session['started_at'] ?? gmdate('c');
    $session['updated_at'] = gmdate('c');
    unset($session['error']);
    _site_export_push_write_session($session_id, $session);

    try {
        _site_export_push_load_importer_runtime();
        _site_export_push_define_importer_streams();

        $session_dir = _site_export_push_session_dir($session_id);
        $client = new ImportClient(
            (string) $session['source_url'],
            $session_dir . '/import',
            _site_export_push_target_fs_root()
        );
        $client->run(_site_export_push_import_options($session, $session_dir));

        $session = _site_export_push_read_session($session_id);
        if (($session['status'] ?? '') !== 'aborted') {
            $session['status'] = $client->exit_code === 0 ? 'complete' : 'partial';
            $session['completed_at'] = gmdate('c');
            $session['updated_at'] = gmdate('c');
            _site_export_push_write_session($session_id, $session);
        }
    } catch (Throwable $e) {
        $session = _site_export_push_read_session($session_id);
        if (($session['status'] ?? '') !== 'aborted') {
            $session['status'] = 'error';
            $session['error'] = $e->getMessage();
            $session['updated_at'] = gmdate('c');
            _site_export_push_write_session($session_id, $session);
        }
    }

    return _site_export_push_session_status($session_id);
}

function _site_export_push_claim_request(string $session_id): array {
    $session = _site_export_push_read_session($session_id);
    if (($session['status'] ?? '') === 'aborted') {
        return ['ok' => true, 'status' => 'aborted', 'request' => null];
    }

    $session_dir = _site_export_push_session_dir($session_id);
    $requests_dir = $session_dir . '/relay/requests';
    $processing_dir = $session_dir . '/relay/processing';
    _site_export_push_requeue_expired_requests($requests_dir, $processing_dir);

    $request_file = _site_export_push_claim_next_file($requests_dir, $processing_dir);
    if ($request_file === null) {
        return [
            'ok' => true,
            'status' => $session['status'] ?? 'created',
            'request' => null,
        ];
    }

    $request = _site_export_push_read_json_file($request_file);
    $uploads = _site_export_push_collect_request_uploads($request, $session_dir . '/relay/uploads');

    return [
        'ok' => true,
        'status' => $session['status'] ?? 'created',
        'request' => $request,
        'uploads' => $uploads,
    ];
}

function _site_export_push_store_response_body(string $session_id, string $request_id, string $tmp_file): array {
    _site_export_push_read_session($session_id);
    $responses_dir = _site_export_push_session_dir($session_id) . '/relay/responses';
    _site_export_push_ensure_dir($responses_dir);

    $target = $responses_dir . '/' . $request_id . '.body';
    $tmp_target = $target . '.tmp';
    if (!copy($tmp_file, $tmp_target)) {
        throw new RuntimeException('Cannot store relay response body.');
    }
    if (!rename($tmp_target, $target)) {
        @unlink($tmp_target);
        throw new RuntimeException('Cannot publish relay response body.');
    }

    return ['ok' => true, 'request_id' => $request_id];
}

function _site_export_push_store_response_metadata(string $session_id, array $response): array {
    _site_export_push_read_session($session_id);
    $request_id = isset($response['request_id']) ? _site_export_push_validate_id((string) $response['request_id'], 'request_id') : '';
    if ($request_id === '') {
        throw new RuntimeException('Response metadata is missing request_id.');
    }

    $session_dir = _site_export_push_session_dir($session_id);
    $responses_dir = $session_dir . '/relay/responses';
    $processing_file = $session_dir . '/relay/processing/' . $request_id . '.json';
    _site_export_push_ensure_dir($responses_dir);

    $body_file = $responses_dir . '/' . $request_id . '.body';
    if (isset($response['body_file'])) {
        if (!is_file($body_file)) {
            throw new RuntimeException('Response metadata arrived before response body.');
        }
        $response['body_file'] = $body_file;
    }

    _site_export_push_write_json_file($responses_dir . '/' . $request_id . '.json', $response);
    if (is_file($processing_file)) {
        @unlink($processing_file);
    }

    return ['ok' => true, 'request_id' => $request_id];
}

function _site_export_push_abort_session(string $session_id): array {
    $session = _site_export_push_read_session($session_id);
    $session['status'] = 'aborted';
    $session['aborted_at'] = gmdate('c');
    $session['updated_at'] = gmdate('c');
    _site_export_push_write_session($session_id, $session);

    $session_dir = _site_export_push_session_dir($session_id);
    foreach (['requests', 'processing'] as $queue) {
        foreach (glob($session_dir . '/relay/' . $queue . '/*.json') ?: [] as $request_file) {
            $request_id = basename($request_file, '.json');
            _site_export_push_write_json_file($session_dir . '/relay/responses/' . $request_id . '.json', [
                'request_id' => $request_id,
                'error' => 'Push session aborted.',
                'created_at' => gmdate('c'),
            ]);
            @unlink($request_file);
        }
    }

    return _site_export_push_session_status($session_id);
}

function _site_export_push_session_status(string $session_id): array {
    $session = _site_export_push_read_session($session_id);
    $session_dir = _site_export_push_session_dir($session_id);
    $import_status = null;
    $status_file = $session_dir . '/import/.import-status.json';
    if (is_file($status_file)) {
        $import_status = _site_export_push_read_json_file($status_file);
    }

    return [
        'ok' => true,
        'session' => $session,
        'import_status' => $import_status,
        'relay' => [
            'requests' => count(glob($session_dir . '/relay/requests/*.json') ?: []),
            'processing' => count(glob($session_dir . '/relay/processing/*.json') ?: []),
            'responses' => count(glob($session_dir . '/relay/responses/*.json') ?: []),
        ],
    ];
}

function _site_export_push_import_options(array $session, string $session_dir): array {
    $options = isset($session['options']) && is_array($session['options']) ? $session['options'] : [];
    $options['command'] = isset($session['command']) ? (string) $session['command'] : 'pull';
    $options['transport'] = 'relay';
    $options['relay_dir'] = $session_dir . '/relay';
    $options['relay_timeout'] = isset($options['relay_timeout']) ? (int) $options['relay_timeout'] : 300;
    $options['fs_root_nonempty_behavior'] = $options['fs_root_nonempty_behavior'] ?? 'overwrite';
    $options['runtime'] = $options['runtime'] ?? 'none';
    $options['start_runtime'] = $options['start_runtime'] ?? 'none';
    $options['remap'] = $options['remap'] ?? [[':abspath:', ':fs-root:']];

    if (defined('DB_NAME') && defined('DB_USER')) {
        $db_host = defined('DB_HOST') ? (string) DB_HOST : '127.0.0.1';
        $host = $db_host;
        $port = 3306;
        if (strpos($db_host, ':') !== false && strpos($db_host, '/') === false) {
            [$host_part, $port_part] = explode(':', $db_host, 2);
            $host = $host_part !== '' ? $host_part : $host;
            $port = is_numeric($port_part) ? (int) $port_part : $port;
        }
        $options['target_engine'] = $options['target_engine'] ?? 'mysql';
        $options['target_host'] = $options['target_host'] ?? $host;
        $options['target_port'] = $options['target_port'] ?? $port;
        $options['target_user'] = $options['target_user'] ?? (string) DB_USER;
        $options['target_pass'] = $options['target_pass'] ?? (defined('DB_PASSWORD') ? (string) DB_PASSWORD : '');
        $options['target_db'] = $options['target_db'] ?? (string) DB_NAME;
    }

    if (function_exists('home_url') && empty($options['new_site_url'])) {
        $options['new_site_url'] = home_url();
    }

    return $options;
}

function _site_export_push_target_fs_root(): string {
    return rtrim(defined('ABSPATH') ? ABSPATH : getcwd(), '/');
}

function _site_export_push_load_importer_runtime(): void {
    if (class_exists('ImportClient')) {
        return;
    }

    $plugin_dir = _site_export_push_plugin_dir();
    $repo_root = dirname($plugin_dir);
    $candidates = [
        $plugin_dir . 'vendor/wp-php-toolkit/reprint-importer/src/import.php',
        $repo_root . '/vendor/wp-php-toolkit/reprint-importer/src/import.php',
        $repo_root . '/packages/reprint-importer/src/import.php',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            require_once $candidate;
            return;
        }
    }

    throw new RuntimeException('Reprint Importer runtime is incomplete. Rebuild the plugin with wp-php-toolkit/reprint-importer.');
}

function _site_export_push_define_importer_streams(): void {
    if (!defined('IMPORTER_WEB_ENTRY')) {
        define('IMPORTER_WEB_ENTRY', true);
    }
    if (!defined('STDOUT')) {
        define('STDOUT', fopen('php://temp', 'w'));
    }
    if (!defined('STDERR')) {
        define('STDERR', fopen('php://temp', 'w'));
    }
    if (!defined('STDIN')) {
        define('STDIN', fopen('php://memory', 'r'));
    }
}

function _site_export_push_collect_request_uploads(array $request, string $uploads_dir): array {
    $post_data = isset($request['post_data']) && is_array($request['post_data']) ? $request['post_data'] : [];
    $uploads = [];
    foreach ($post_data as $field) {
        if (!is_array($field) || ($field['type'] ?? null) !== 'file') {
            continue;
        }
        $upload = isset($field['upload']) ? _site_export_push_validate_id((string) $field['upload'], 'upload') : '';
        $path = $uploads_dir . '/' . $upload;
        if (!is_file($path)) {
            throw new RuntimeException('Relay upload sidecar is missing.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read relay upload sidecar.');
        }
        $uploads[$upload] = base64_encode($contents);
    }
    return $uploads;
}

function _site_export_push_claim_next_file(string $requests_dir, string $processing_dir): ?string {
    _site_export_push_ensure_dir($requests_dir);
    _site_export_push_ensure_dir($processing_dir);

    $files = glob($requests_dir . '/*.json') ?: [];
    sort($files);
    foreach ($files as $file) {
        $claimed = $processing_dir . '/' . basename($file);
        if (@rename($file, $claimed)) {
            return $claimed;
        }
    }
    return null;
}

function _site_export_push_requeue_expired_requests(string $requests_dir, string $processing_dir): void {
    $now = time();
    foreach (glob($processing_dir . '/*.json') ?: [] as $file) {
        $mtime = filemtime($file);
        if ($mtime === false || $now - $mtime < SITE_EXPORT_TIMESTAMP_TOLERANCE) {
            continue;
        }
        @rename($file, $requests_dir . '/' . basename($file));
    }
}

function _site_export_push_read_session(string $session_id): array {
    $file = _site_export_push_session_dir($session_id) . '/session.json';
    if (!is_file($file)) {
        throw new RuntimeException('Push session not found.');
    }
    return _site_export_push_read_json_file($file);
}

function _site_export_push_write_session(string $session_id, array $session): void {
    _site_export_push_write_json_file(_site_export_push_session_dir($session_id) . '/session.json', $session);
}

function _site_export_push_session_dir(string $session_id): string {
    return _site_export_push_base_dir() . '/' . _site_export_push_validate_id($session_id, 'session_id');
}

function _site_export_push_base_dir(): string {
    if (defined('SITE_EXPORT_PUSH_BASE_DIR')) {
        return rtrim(SITE_EXPORT_PUSH_BASE_DIR, '/');
    }
    if (function_exists('wp_upload_dir')) {
        $uploads = wp_upload_dir(null, false);
        if (is_array($uploads) && !empty($uploads['basedir'])) {
            return rtrim((string) $uploads['basedir'], '/') . '/reprint-push';
        }
    }
    return rtrim(_site_export_push_plugin_dir(), '/') . '/push-sessions';
}

function _site_export_push_plugin_dir(): string {
    return defined('SITE_EXPORT_PLUGIN_DIR') ? (string) constant('SITE_EXPORT_PLUGIN_DIR') : __DIR__ . '/';
}

function _site_export_push_ensure_dir(string $dir): void {
    if (is_dir($dir)) {
        return;
    }
    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create directory: {$dir}");
    }
}

function _site_export_push_read_json_file(string $file): array {
    $json = file_get_contents($file);
    if ($json === false) {
        throw new RuntimeException("Cannot read JSON file: {$file}");
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid JSON file: {$file}");
    }
    return $decoded;
}

function _site_export_push_write_json_file(string $file, array $payload): void {
    _site_export_push_ensure_dir(dirname($file));
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('Cannot encode JSON.');
    }
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json . "\n") === false) {
        throw new RuntimeException("Cannot write JSON file: {$file}");
    }
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException("Cannot publish JSON file: {$file}");
    }
}
