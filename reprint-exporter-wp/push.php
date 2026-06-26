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
if (!defined('SITE_EXPORT_PUSH_REQUEST_LEASE')) {
    define('SITE_EXPORT_PUSH_REQUEST_LEASE', 300);
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

    try {
        $endpoint = isset($_GET['endpoint']) ? (string) $_GET['endpoint'] : '';
        if ($endpoint === 'response-body') {
            $claimed_content_hash = _site_export_push_authenticate_claimed_content_hash($authenticate);
            $body_file = _site_export_push_request_body_file('body');
            try {
                if ($claimed_content_hash !== null) {
                    _site_export_push_verify_body_file_hash($body_file['path'], $claimed_content_hash);
                }
                _site_export_push_send_json(_site_export_push_store_response_body(
                    _site_export_push_require_session_id(),
                    _site_export_push_require_request_id(),
                    $body_file['path']
                ));
            } finally {
                if ($body_file['cleanup']) {
                    @unlink($body_file['path']);
                }
            }
            return;
        }

        $authenticate();
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

            case 'request-upload':
                _site_export_push_stream_request_upload(
                    _site_export_push_require_session_id(),
                    _site_export_push_require_upload_id()
                );
                return;

            case 'heartbeat':
                _site_export_push_send_json(_site_export_push_heartbeat_request(
                    _site_export_push_require_session_id(),
                    _site_export_push_read_json_body()
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

function _site_export_push_require_upload_id(): string {
    $upload_id = isset($_GET['upload']) ? (string) $_GET['upload'] : '';
    return _site_export_push_validate_id($upload_id, 'upload');
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

function _site_export_push_request_body_file(string $name): array {
    if (!empty($_FILES[$name]) && is_array($_FILES[$name])) {
        return [
            'path' => _site_export_push_uploaded_file($name),
            'cleanup' => false,
        ];
    }

    $tmp_file = tempnam(sys_get_temp_dir(), 'reprint-push-body-');
    if ($tmp_file === false) {
        throw new RuntimeException('Cannot create temporary relay response body.');
    }

    $input = fopen('php://input', 'rb');
    $output = fopen($tmp_file, 'wb');
    if (!is_resource($input) || !is_resource($output)) {
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($output)) {
            fclose($output);
        }
        @unlink($tmp_file);
        throw new RuntimeException('Cannot read relay response body.');
    }

    $copied = false;
    try {
        if (stream_copy_to_stream($input, $output) === false) {
            throw new RuntimeException('Cannot stream relay response body.');
        }
        $copied = true;
    } finally {
        fclose($input);
        fclose($output);
        if (!$copied) {
            @unlink($tmp_file);
        }
    }

    return [
        'path' => $tmp_file,
        'cleanup' => true,
    ];
}

function _site_export_push_authenticate_claimed_content_hash(callable $authenticate): ?string {
    if ($authenticate !== '_site_export_default_authenticate') {
        $authenticate();
        return null;
    }

    return _site_export_push_verify_signed_content_hash();
}

function _site_export_push_verify_body_file_hash(string $body_file, string $claimed_content_hash): void {
    if (!hash_equals($claimed_content_hash, _site_export_push_hash_file_streaming($body_file))) {
        _site_export_push_error(403, 'Content hash mismatch: body was modified in transit');
    }
}

function _site_export_push_verify_signed_content_hash(): string {
    $server = _site_export_push_hmac_server();
    $verification = method_exists($server, 'verify_global_signed_content_hash')
        ? $server->verify_global_signed_content_hash()
        : [
            'error' => 'Reprint Exporter runtime is incomplete. Rebuild the plugin with the latest wp-php-toolkit/reprint-exporter.',
            'content_hash' => null,
        ];
    if (!empty($verification['error'])) {
        _site_export_push_error(403, (string) $verification['error']);
    }
    if (empty($verification['content_hash']) || !is_string($verification['content_hash'])) {
        _site_export_push_error(403, 'Missing X-Auth-Content-Hash header');
    }
    return $verification['content_hash'];
}

function _site_export_push_hmac_server(): Site_Export_HMAC_Server {
    if (!class_exists('Site_Export_HMAC_Server') && function_exists('_site_export_load_exporter_runtime')) {
        _site_export_load_exporter_runtime();
    }
    if (!class_exists('Site_Export_HMAC_Server')) {
        _site_export_push_error(503, 'Reprint Exporter runtime is incomplete. Run composer install in reprint-exporter-wp or rebuild the release package.');
    }
    if (!function_exists('_site_export_has_secret_file') || !function_exists('_site_export_get_option_secret')) {
        _site_export_push_error(503, 'Reprint Exporter authentication runtime is incomplete.');
    }

    $secret = _site_export_push_shared_secret();
    return new Site_Export_HMAC_Server($secret, SITE_EXPORT_TIMESTAMP_TOLERANCE);
}

function _site_export_push_shared_secret(): string {
    if (_site_export_has_secret_file()) {
        $secret = _site_export_get_file_secret();
        if (empty($secret)) {
            _site_export_push_error(503, 'Invalid secret.php configuration. Please remove it or replace it with a valid shared secret.');
        }
    } else {
        $secret = _site_export_get_option_secret();
    }

    if (empty($secret) || !is_string($secret)) {
        _site_export_push_error(503, 'Export not configured. Please configure the shared secret in WordPress admin under Tools > Reprint Exporter.');
    }
    return $secret;
}

function _site_export_push_hash_file_streaming(string $path): string {
    $input = fopen($path, 'rb');
    if (!is_resource($input)) {
        throw new RuntimeException("Cannot open file for hashing: {$path}");
    }
    $context = hash_init('sha256');
    try {
        while (!feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException("Cannot read file for hashing: {$path}");
            }
            if ($chunk !== '') {
                hash_update($context, $chunk);
            }
        }
    } finally {
        fclose($input);
    }
    return hash_final($context);
}

function _site_export_push_normalize_command($command): string {
    if (!is_string($command) || $command === '') {
        throw new RuntimeException('Push command must be a non-empty string.');
    }
    $aliases = [
        'files-pull' => 'pull-files',
        'db-pull' => 'pull-db',
    ];
    $command = $aliases[$command] ?? $command;
    $allowed = [
        'pull' => true,
        'pull-files' => true,
        'pull-db' => true,
        'preflight' => true,
        'files-index' => true,
        'files-download' => true,
        'db-index' => true,
        'db-download' => true,
        'db-apply' => true,
    ];
    if (!isset($allowed[$command])) {
        throw new RuntimeException("Unsupported push command: {$command}.");
    }
    return $command;
}

function _site_export_push_normalize_options($options): array {
    if ($options === null) {
        return [];
    }
    if (!is_array($options)) {
        throw new RuntimeException('Push options must be a JSON object.');
    }

    $allowed = [
        'db_query_time_limit' => true,
        'db_unbuffered' => true,
        'deactivate_host_plugins' => true,
        'extra_directory' => true,
        'filter' => true,
        'follow_symlinks' => true,
        'fragments_per_batch' => true,
        'fs_root_nonempty_behavior' => true,
        'include_caches' => true,
        'max_allowed_packet' => true,
        'max_execution_time' => true,
        'memory_threshold' => true,
        'new_site_url' => true,
        'old_site_url' => true,
        'only' => true,
        'relay_timeout' => true,
        'remap' => true,
        'runtime' => true,
        'skip_create_table' => true,
        'start_runtime' => true,
        'tables_per_batch' => true,
    ];
    foreach (array_keys($options) as $key) {
        if (!is_string($key) || !isset($allowed[$key])) {
            throw new RuntimeException("Unsupported push option: {$key}.");
        }
    }
    return $options;
}

function _site_export_push_create_session(array $payload): array {
    $source_url = isset($payload['source_url']) ? trim((string) $payload['source_url']) : '';
    if ($source_url === '') {
        throw new RuntimeException('source_url is required.');
    }
    $command = _site_export_push_normalize_command($payload['command'] ?? 'pull');
    $options = _site_export_push_normalize_options($payload['options'] ?? []);

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
        'command' => $command,
        'options' => $options,
    ];
    _site_export_push_write_session($session_id, $session);

    return [
        'ok' => true,
        'session_id' => $session_id,
        'status' => $session['status'],
    ];
}

function _site_export_push_run_session(string $session_id): array {
    $session_dir = _site_export_push_session_dir($session_id);
    $run_lock = _site_export_push_acquire_lock($session_dir . '/run.lock', false);
    if ($run_lock === null) {
        _site_export_push_mutate_session($session_id, function (array $session): array {
            if (($session['status'] ?? '') !== 'running' && ($session['status'] ?? '') !== 'aborted') {
                $session['status'] = 'running';
                $session['updated_at'] = gmdate('c');
            }
            return $session;
        });
        return _site_export_push_session_status($session_id);
    }

    try {
        $session = _site_export_push_mutate_session($session_id, function (array $session): array {
            if (($session['status'] ?? '') !== 'aborted') {
                $session['status'] = 'running';
                $session['started_at'] = $session['started_at'] ?? gmdate('c');
                $session['updated_at'] = gmdate('c');
                unset($session['error'], $session['import_status_snapshot']);
            }
            return $session;
        });
        if (($session['status'] ?? '') === 'aborted') {
            return _site_export_push_session_status($session_id);
        }

        $target_fs_root = _site_export_push_target_fs_root();
        $import_options = _site_export_push_import_options($session, $session_dir);
        $import_state_id = _site_export_push_import_state_id($session, $import_options, $target_fs_root);
        $import_state_dir = _site_export_push_import_state_dir($import_state_id);
        _site_export_push_ensure_dir($import_state_dir);
        _site_export_push_mutate_session($session_id, function (array $session) use ($import_state_id): array {
            $session['import_state_id'] = $import_state_id;
            $session['updated_at'] = gmdate('c');
            return $session;
        });

        $state_lock = null;
        try {
            $state_lock = _site_export_push_acquire_lock($import_state_dir . '/state.lock', false);
            if ($state_lock === null) {
                throw new RuntimeException('Another push session is already using this target import state.');
            }
            @unlink($import_state_dir . '/.import-status.json');

            $output_buffer_started = ob_start();
            try {
                _site_export_push_load_importer_runtime();
                _site_export_push_define_importer_streams();

                $client = new ImportClient(
                    (string) $session['source_url'],
                    $import_state_dir,
                    $target_fs_root
                );
                foreach (_site_export_push_import_command_sequence((string) $import_options['command']) as $command) {
                    $command_options = $import_options;
                    $command_options['command'] = $command;
                    $client->run($command_options);
                    if ((int) $client->exit_code !== 0) {
                        break;
                    }
                }
            } finally {
                if ($output_buffer_started) {
                    ob_end_clean();
                }
            }

            $run_result = _site_export_push_importer_run_result($import_state_dir, (int) $client->exit_code);
            $import_status_snapshot = _site_export_push_read_import_status($import_state_dir);
            _site_export_push_mutate_session(
                $session_id,
                function (array $session) use ($run_result, $import_status_snapshot): array {
                    if (($session['status'] ?? '') !== 'aborted') {
                        $session['status'] = $run_result['status'];
                        if (isset($run_result['error'])) {
                            $session['error'] = $run_result['error'];
                        }
                        if ($import_status_snapshot !== null) {
                            $session['import_status_snapshot'] = $import_status_snapshot;
                        }
                        $session['completed_at'] = gmdate('c');
                        $session['updated_at'] = gmdate('c');
                    }
                    return $session;
                }
            );
        } catch (Throwable $e) {
            $import_status_snapshot = _site_export_push_read_import_status($import_state_dir);
            _site_export_push_mutate_session(
                $session_id,
                function (array $session) use ($e, $import_status_snapshot): array {
                    if (($session['status'] ?? '') !== 'aborted') {
                        $session['status'] = 'error';
                        $session['error'] = $e->getMessage();
                        if ($import_status_snapshot !== null) {
                            $session['import_status_snapshot'] = $import_status_snapshot;
                        }
                        $session['updated_at'] = gmdate('c');
                    }
                    return $session;
                }
            );
        } finally {
            _site_export_push_release_lock($state_lock);
        }
    } finally {
        _site_export_push_release_lock($run_lock);
    }

    return _site_export_push_session_status($session_id);
}

function _site_export_push_import_command_sequence(string $command): array {
    // In plugin-owned push, pull-db means "push the database to this target".
    // The importer's pull-db command only downloads db.sql, so the target must
    // apply it in the same session to complete the database-only push.
    if ($command === 'pull-db') {
        return ['pull-db', 'db-apply'];
    }
    return [$command];
}

function _site_export_push_importer_run_result(string $import_state_dir, int $exit_code): array {
    if ($exit_code === 0) {
        return ['status' => 'complete'];
    }
    if ($exit_code === 2) {
        return ['status' => 'partial'];
    }

    $error = "Reprint Importer exited with code {$exit_code}.";
    $import_status = _site_export_push_read_import_status($import_state_dir);
    if ($import_status !== null) {
        foreach (['error', 'message'] as $key) {
            if (!empty($import_status[$key]) && is_string($import_status[$key])) {
                $error = $import_status[$key];
                break;
            }
        }
    }

    return [
        'status' => 'error',
        'error' => $error,
    ];
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

    $request_id = basename($request_file, '.json');
    // Abort may happen after the first status read but before the queue rename.
    // Convert that claimed request into an abort response so the importer does
    // not wait forever for work the source worker should no longer execute.
    $session = _site_export_push_read_session($session_id);
    if (($session['status'] ?? '') === 'aborted') {
        _site_export_push_publish_response_metadata_once($session_dir . '/relay/responses', $request_id, [
            'request_id' => $request_id,
            'error' => 'Push session aborted.',
            'created_at' => gmdate('c'),
        ]);
        @unlink($request_file);
        return ['ok' => true, 'status' => 'aborted', 'request' => null];
    }

    $request = _site_export_push_read_json_file($request_file);
    $uploads = _site_export_push_list_request_uploads($request, $session_dir . '/relay/uploads');

    return [
        'ok' => true,
        'status' => $session['status'] ?? 'created',
        'request' => $request,
        'uploads' => $uploads,
    ];
}

function _site_export_push_stream_request_upload(string $session_id, string $upload_id): void {
    _site_export_push_read_session($session_id);
    $path = _site_export_push_session_dir($session_id) . '/relay/uploads/' . $upload_id;
    if (!is_file($path)) {
        throw new RuntimeException('Relay upload sidecar is missing.');
    }
    $size = filesize($path);
    if ($size === false) {
        throw new RuntimeException('Cannot stat relay upload sidecar.');
    }

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . $size);
    $input = fopen($path, 'rb');
    if (!is_resource($input)) {
        throw new RuntimeException('Cannot open relay upload sidecar.');
    }
    try {
        while (!feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('Cannot read relay upload sidecar.');
            }
            if ($chunk !== '') {
                echo $chunk;
            }
        }
    } finally {
        fclose($input);
    }
    exit;
}

function _site_export_push_heartbeat_request(string $session_id, array $payload): array {
    $request_id = isset($payload['request_id']) ? _site_export_push_validate_id((string) $payload['request_id'], 'request_id') : '';
    if ($request_id === '') {
        throw new RuntimeException('Heartbeat metadata is missing request_id.');
    }

    $session_dir = _site_export_push_session_dir($session_id);
    $processing_file = $session_dir . '/relay/processing/' . $request_id . '.json';

    return _site_export_push_with_session_lock($session_id, function (array $session) use (
        $session_dir,
        $processing_file,
        $request_id
    ): array {
        if (($session['status'] ?? '') === 'aborted') {
            _site_export_push_publish_response_metadata_once($session_dir . '/relay/responses', $request_id, [
                'request_id' => $request_id,
                'error' => 'Push session aborted.',
                'created_at' => gmdate('c'),
            ]);
            if (is_file($processing_file)) {
                @unlink($processing_file);
            }
            return ['ok' => true, 'request_id' => $request_id, 'status' => 'aborted'];
        }

        if (!_site_export_push_touch_existing_file($processing_file)) {
            return [
                'ok' => false,
                'request_id' => $request_id,
                'error' => 'Relay request is no longer processing.',
            ];
        }

        return [
            'ok' => true,
            'request_id' => $request_id,
            'status' => $session['status'] ?? 'created',
        ];
    });
}

function _site_export_push_store_response_body(string $session_id, string $request_id, string $tmp_file): array {
    $session = _site_export_push_read_session($session_id);
    if (($session['status'] ?? '') === 'aborted') {
        return ['ok' => true, 'request_id' => $request_id, 'ignored' => true, 'status' => 'aborted'];
    }
    $responses_dir = _site_export_push_session_dir($session_id) . '/relay/responses';
    _site_export_push_ensure_dir($responses_dir);

    $published = _site_export_push_publish_response_body_once($responses_dir, $request_id, $tmp_file);

    return ['ok' => true, 'request_id' => $request_id, 'published' => $published];
}

function _site_export_push_store_response_metadata(string $session_id, array $response): array {
    $request_id = isset($response['request_id']) ? _site_export_push_validate_id((string) $response['request_id'], 'request_id') : '';
    if ($request_id === '') {
        throw new RuntimeException('Response metadata is missing request_id.');
    }

    $session_dir = _site_export_push_session_dir($session_id);
    $responses_dir = $session_dir . '/relay/responses';
    $processing_file = $session_dir . '/relay/processing/' . $request_id . '.json';
    _site_export_push_ensure_dir($responses_dir);

    // The session status check and response publish must be ordered with abort
    // mutations. Otherwise a worker can read "running", an abort can publish
    // tombstones, and the late success metadata can still win the response race.
    return _site_export_push_with_session_lock($session_id, function (array $session) use (
        $responses_dir,
        $processing_file,
        $request_id,
        $response
    ): array {
        if (($session['status'] ?? '') === 'aborted') {
            _site_export_push_publish_response_metadata_once($responses_dir, $request_id, [
                'request_id' => $request_id,
                'error' => 'Push session aborted.',
                'created_at' => gmdate('c'),
            ]);
            if (is_file($processing_file)) {
                @unlink($processing_file);
            }
            return ['ok' => true, 'request_id' => $request_id, 'ignored' => true, 'status' => 'aborted'];
        }

        $body_file = $responses_dir . '/' . $request_id . '.body';
        if (isset($response['body_file'])) {
            if (!is_file($body_file)) {
                throw new RuntimeException('Response metadata arrived before response body.');
            }
            $response['body_file'] = $body_file;
        }

        $published = _site_export_push_publish_response_metadata_once($responses_dir, $request_id, $response);
        if (is_file($processing_file)) {
            @unlink($processing_file);
        }

        return ['ok' => true, 'request_id' => $request_id, 'published' => $published];
    });
}

function _site_export_push_abort_session(string $session_id): array {
    _site_export_push_mutate_session($session_id, function (array $session): array {
        $session['status'] = 'aborted';
        $session['aborted_at'] = gmdate('c');
        $session['updated_at'] = gmdate('c');
        return $session;
    });

    $session_dir = _site_export_push_session_dir($session_id);
    foreach (['requests', 'processing'] as $queue) {
        foreach (glob($session_dir . '/relay/' . $queue . '/*.json') ?: [] as $request_file) {
            $request_id = basename($request_file, '.json');
            _site_export_push_publish_response_metadata_once($session_dir . '/relay/responses', $request_id, [
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
    $terminal = in_array($session['status'] ?? '', ['complete', 'partial', 'error', 'aborted'], true);
    $import_status = null;
    if ($terminal && isset($session['import_status_snapshot']) && is_array($session['import_status_snapshot'])) {
        $import_status = $session['import_status_snapshot'];
    } else {
        $import_status = _site_export_push_read_import_status(
            _site_export_push_import_state_dir_for_session($session, $session_dir)
        );
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

function _site_export_push_import_state_id(array $session, array $import_options, string $target_fs_root): string {
    // The persistent import state is scoped to the source/target relationship,
    // not to a single relay session. Per-run controls such as relay_dir,
    // relay_timeout, and only are intentionally omitted: --only already uses
    // pull's selected-file diff/delete guards against the shared file index.
    $state_options = [];
    foreach ([
        'deactivate_host_plugins',
        'filter',
        'follow_symlinks',
        'fs_root_nonempty_behavior',
        'include_caches',
        'new_site_url',
        'old_site_url',
        'remap',
        'skip_create_table',
        'target_db',
        'target_engine',
        'target_host',
        'target_port',
        'target_sqlite_path',
        'target_user',
    ] as $key) {
        if (array_key_exists($key, $import_options)) {
            $state_options[$key] = $import_options[$key];
        }
    }

    $scope = [
        // Version 2 starts fresh from push states created before the importer
        // tracked remap fingerprints for reusable file indexes.
        'version' => 2,
        'source_url' => (string) ($session['source_url'] ?? ''),
        'target_fs_root' => $target_fs_root,
        'options' => $state_options,
    ];
    $json = json_encode($scope, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('Cannot encode push import state scope.');
    }

    return substr(hash('sha256', $json), 0, 32);
}

function _site_export_push_import_state_dir(string $import_state_id): string {
    return _site_export_push_base_dir() . '/states/' .
        _site_export_push_validate_id($import_state_id, 'import_state_id');
}

function _site_export_push_import_state_dir_for_session(array $session, string $session_dir): string {
    if (!empty($session['import_state_id']) && is_string($session['import_state_id'])) {
        return _site_export_push_import_state_dir($session['import_state_id']);
    }
    return $session_dir . '/import';
}

function _site_export_push_read_import_status(string $import_state_dir): ?array {
    $status_file = rtrim($import_state_dir, '/') . '/.import-status.json';
    if (!is_file($status_file)) {
        return null;
    }
    return _site_export_push_read_json_file($status_file);
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
    // Tests and source checkouts can opt into the checked-out importer without
    // changing the packaged plugin's default preference for its bundled vendor.
    if (defined('SITE_EXPORT_PUSH_DEV_IMPORTER_RUNTIME') && constant('SITE_EXPORT_PUSH_DEV_IMPORTER_RUNTIME') !== '') {
        $dev_runtime = (string) constant('SITE_EXPORT_PUSH_DEV_IMPORTER_RUNTIME');
        if (is_file($dev_runtime)) {
            require_once $dev_runtime;
            return;
        }
        throw new RuntimeException("Configured Reprint Importer runtime does not exist: {$dev_runtime}");
    }
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

function _site_export_push_list_request_uploads(array $request, string $uploads_dir): array {
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
        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException('Cannot stat relay upload sidecar.');
        }
        $uploads[$upload] = ['size' => $size];
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

function _site_export_push_publish_response_metadata_once(string $responses_dir, string $request_id, array $response): bool {
    _site_export_push_ensure_dir($responses_dir);
    $lock = _site_export_push_acquire_lock($responses_dir . '/' . $request_id . '.lock', true);
    if ($lock === null) {
        throw new RuntimeException("Cannot lock relay response metadata: {$request_id}");
    }
    try {
        $target = $responses_dir . '/' . $request_id . '.json';
        if (is_file($target)) {
            return false;
        }
        _site_export_push_write_json_file($target, $response);
        return true;
    } finally {
        _site_export_push_release_lock($lock);
    }
}

function _site_export_push_publish_response_body_once(string $responses_dir, string $request_id, string $body_file): bool {
    _site_export_push_ensure_dir($responses_dir);
    $target = $responses_dir . '/' . $request_id . '.body';
    $tmp_target = $target . '.tmp.' . bin2hex(random_bytes(4));
    _site_export_push_copy_file_streaming($body_file, $tmp_target);

    // Use the same per-request lock as metadata publishing. Otherwise a late
    // worker could overwrite the body file after the importer already observed
    // the first response metadata.
    $lock = _site_export_push_acquire_lock($responses_dir . '/' . $request_id . '.lock', true);
    if ($lock === null) {
        @unlink($tmp_target);
        throw new RuntimeException("Cannot lock relay response body: {$request_id}");
    }
    try {
        if (is_file($target) || is_file($responses_dir . '/' . $request_id . '.json')) {
            @unlink($tmp_target);
            return false;
        }
        if (!rename($tmp_target, $target)) {
            @unlink($tmp_target);
            throw new RuntimeException('Cannot publish relay response body.');
        }
        return true;
    } finally {
        _site_export_push_release_lock($lock);
    }
}

function _site_export_push_requeue_expired_requests(string $requests_dir, string $processing_dir): void {
    $now = time();
    foreach (glob($processing_dir . '/*.json') ?: [] as $file) {
        $mtime = filemtime($file);
        if ($mtime === false || $now - $mtime < SITE_EXPORT_PUSH_REQUEST_LEASE) {
            continue;
        }
        @rename($file, $requests_dir . '/' . basename($file));
    }
}

function _site_export_push_touch_existing_file(string $path): bool {
    // touch() creates missing files, which would resurrect an already requeued
    // or aborted relay lease. Compare the inode before and after so a heartbeat
    // can only refresh the processing file it observed.
    clearstatcache(true, $path);
    $inode = is_file($path) ? fileinode($path) : false;
    if ($inode === false) {
        return false;
    }
    if (!touch($path)) {
        return false;
    }
    clearstatcache(true, $path);
    if (fileinode($path) !== $inode) {
        if (is_file($path) && filesize($path) === 0) {
            @unlink($path);
        }
        return false;
    }
    return true;
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

function _site_export_push_mutate_session(string $session_id, callable $mutate): array {
    return _site_export_push_with_session_lock($session_id, function (array $session) use ($session_id, $mutate): array {
        $next = $mutate($session);
        if (!is_array($next)) {
            throw new RuntimeException('Push session mutation must return a session array.');
        }
        _site_export_push_write_session($session_id, $next);
        return $next;
    });
}

function _site_export_push_with_session_lock(string $session_id, callable $callback): array {
    $lock = _site_export_push_acquire_lock(_site_export_push_session_dir($session_id) . '/session.lock', true);
    if ($lock === null) {
        throw new RuntimeException("Cannot lock push session: {$session_id}");
    }
    try {
        $session = _site_export_push_read_session($session_id);
        return $callback($session);
    } finally {
        _site_export_push_release_lock($lock);
    }
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

function _site_export_push_copy_file_streaming(string $source, string $target): void {
    $input = fopen($source, 'rb');
    if (!is_resource($input)) {
        throw new RuntimeException("Cannot open source file: {$source}");
    }
    $output = fopen($target, 'wb');
    if (!is_resource($output)) {
        fclose($input);
        throw new RuntimeException("Cannot open target file: {$target}");
    }
    $ok = false;
    try {
        while (!feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException("Cannot read source file: {$source}");
            }
            if ($chunk === '') {
                continue;
            }
            if (fwrite($output, $chunk) !== strlen($chunk)) {
                throw new RuntimeException("Cannot write target file: {$target}");
            }
        }
        $ok = true;
    } finally {
        fclose($input);
        fclose($output);
        if (!$ok) {
            @unlink($target);
        }
    }
}

function _site_export_push_acquire_lock(string $path, bool $wait) {
    _site_export_push_ensure_dir(dirname($path));
    $handle = fopen($path, 'c');
    if (!is_resource($handle)) {
        throw new RuntimeException("Cannot open lock file: {$path}");
    }
    $operation = LOCK_EX | ($wait ? 0 : LOCK_NB);
    if (!flock($handle, $operation)) {
        fclose($handle);
        return null;
    }
    return $handle;
}

function _site_export_push_release_lock($handle): void {
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
