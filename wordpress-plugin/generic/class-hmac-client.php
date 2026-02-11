<?php

/**
 * HMAC Client for the Site Export API.
 *
 * This class generates the required HMAC signatures for authenticating
 * requests to the Site Export API. The importing side uses this to sign
 * all outgoing requests.
 *
 * Usage:
 *   $client = new Site_Export_HMAC_Client($shared_secret);
 *   $headers = $client->get_auth_headers($request_body);
 *   // Add $headers to your HTTP request
 *
 * Usage with curl:
 *
 * ```php
 * // 1. First time: Generate and display a secret for the user
 * $secret = Site_Export_HMAC_Client::generate_secret();
 * echo "Please enter this secret in the Site Export plugin settings:\n";
 * echo $secret . "\n";
 *
 * // 2. For each request: Create client and sign requests
 * $client = new Site_Export_HMAC_Client($secret);
 *
 * // For GET requests:
 * $ch = curl_init('https://example.com/site-export-api/?endpoint=file_index&directory=/var/www/html');
 * $client->sign_curl_request($ch, '');
 * curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
 * $response = curl_exec($ch);
 *
 * // For POST requests with JSON body:
 * $body = json_encode(['paths' => ['/wp-content/uploads/image.jpg']]);
 * $ch = curl_init('https://example.com/site-export-api/?endpoint=file_fetch');
 * $client->sign_curl_request($ch, $body);
 * curl_setopt($ch, CURLOPT_POST, true);
 * curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
 * curl_setopt($ch, CURLOPT_HTTPHEADER, [
 *     'Content-Type: application/json',
 *     // Auth headers are added by sign_curl_request
 * ]);
 * $response = curl_exec($ch);
 * ```
 */
class Site_Export_HMAC_Client {

    /**
     * The shared secret used for HMAC computation.
     * @var string
     */
    private $secret;

    /**
     * Create a new HMAC client.
     *
     * @param string $secret The shared secret. Should be generated securely
     *                       and shared between import and export sides.
     */
    public function __construct(string $secret) {
        $this->secret = $secret;
    }

    /**
     * Generate a new shared secret.
     *
     * Call this once on the import side to generate a secret,
     * then have the user copy it to the export plugin settings.
     *
     * @param int $length Length of the secret in bytes (default 32 = 256 bits)
     * @return string Hex-encoded secret (64 characters for 32 bytes)
     */
    public static function generate_secret(int $length = 32): string {
        return bin2hex(random_bytes($length));
    }

    /**
     * Generate a cryptographically secure nonce.
     *
     * @return string Hex-encoded random nonce (32 characters)
     */
    public function generate_nonce(): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Get the current timestamp with microsecond precision.
     *
     * @return string Timestamp as a float string (e.g., "1699876543.123456")
     */
    public function get_timestamp(): string {
        return sprintf('%.6f', microtime(true));
    }

    /**
     * Compute the HMAC signature for a request.
     *
     * The signature covers a SHA-256 hash of the body rather than the
     * raw bytes.  This avoids having to predict the exact encoding that
     * libcurl will produce for multipart/form-data uploads while still
     * providing end-to-end integrity: the server independently hashes
     * the received body and verifies it matches X-Auth-Content-Hash
     * before checking the HMAC.
     *
     * Signature = HMAC-SHA256(nonce + timestamp + SHA256(body), secret)
     *
     * @param string $nonce        Random nonce for this request
     * @param string $timestamp    Request timestamp
     * @param string $content_hash Hex SHA-256 hash of the request body
     * @return string Hex-encoded HMAC signature
     */
    public function compute_signature(string $nonce, string $timestamp, string $content_hash = ''): string {
        if ($content_hash === '') {
            $content_hash = hash('sha256', '');
        }
        $message = $nonce . $timestamp . $content_hash;
        return hash_hmac('sha256', $message, $this->secret);
    }

    /**
     * Get all authentication headers for a request.
     *
     * This is the primary method to use when making requests.
     * It generates fresh nonce and timestamp values, hashes the body,
     * and computes the HMAC signature.
     *
     * @param string $body Request body (empty string for GET requests)
     * @return array Associative array of headers to add to the request
     */
    public function get_auth_headers(string $body = ''): array {
        $nonce = $this->generate_nonce();
        $timestamp = $this->get_timestamp();
        $content_hash = hash('sha256', $body);
        $signature = $this->compute_signature($nonce, $timestamp, $content_hash);

        return [
            'X-Auth-Signature' => $signature,
            'X-Auth-Nonce' => $nonce,
            'X-Auth-Timestamp' => $timestamp,
            'X-Auth-Content-Hash' => $content_hash,
        ];
    }

    /**
     * Return authentication headers formatted for cURL (["Name: value", ...]).
     *
     * @param string $body Request body (pass empty string for GET)
     * @return string[]
     */
    public function get_curl_headers(string $body = ''): array {
        $headers = $this->get_auth_headers($body);
        $curl_headers = [];
        foreach ($headers as $name => $value) {
            $curl_headers[] = "{$name}: {$value}";
        }
        return $curl_headers;
    }

    /**
     * Sign a cURL handle for an authenticated request.
     *
     * Convenience method for use with cURL.
     *
     * @param resource $ch   cURL handle
     * @param string   $body Request body (pass empty string for GET)
     * @return void
     */
    public function sign_curl_request($ch, string $body = ''): void {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->get_curl_headers($body));
    }

    /**
     * Create an authenticated HTTP context for file_get_contents.
     *
     * @param string $body    Request body
     * @param string $method  HTTP method (GET, POST)
     * @param array  $extra   Extra context options to merge
     * @return resource Stream context
     */
    public function create_stream_context(string $body = '', string $method = 'GET', array $extra = []) {
        $headers = $this->get_auth_headers($body);
        $header_string = '';
        foreach ($headers as $name => $value) {
            $header_string .= "{$name}: {$value}\r\n";
        }

        $options = [
            'http' => array_merge([
                'method' => $method,
                'header' => $header_string,
                'content' => $body,
            ], $extra['http'] ?? []),
        ];

        return stream_context_create($options);
    }
}
