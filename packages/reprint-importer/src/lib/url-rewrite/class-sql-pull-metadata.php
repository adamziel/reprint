<?php

/**
 * Versioned side-channel metadata learned while streaming db-pull SQL.
 *
 * The metadata is advisory: db-apply may use it for domain display and
 * statement-count progress only after the envelope and db.sql fingerprint
 * validate. Invalid, stale, or absent metadata must be ignored.
 */
class SqlPullMetadata
{
    private const KIND = 'reprint.sql-pull-metadata';
    private const VERSION = 1;

    public const FILENAME = '.import-sql-metadata.json';
    public const LEGACY_DOMAINS_FILENAME = '.import-domains.json';
    public const LEGACY_STATS_FILENAME = '.import-sql-stats.json';

    public static function path(string $state_dir): string
    {
        return rtrim($state_dir, '/') . '/' . self::FILENAME;
    }

    public static function legacy_domains_path(string $state_dir): string
    {
        return rtrim($state_dir, '/') . '/' . self::LEGACY_DOMAINS_FILENAME;
    }

    public static function legacy_stats_path(string $state_dir): string
    {
        return rtrim($state_dir, '/') . '/' . self::LEGACY_STATS_FILENAME;
    }

    /**
     * @param string[] $domains
     */
    public static function write_complete(
        string $metadata_file,
        string $sql_file,
        array $domains,
        int $statements_total
    ): void {
        if (!is_file($sql_file)) {
            throw new RuntimeException("Cannot write SQL metadata: db.sql not found at {$sql_file}");
        }
        if ($statements_total < 0) {
            throw new InvalidArgumentException('Statement count cannot be negative.');
        }

        $hash = hash_file('sha256', $sql_file);
        if (!is_string($hash)) {
            throw new RuntimeException("Cannot hash SQL file: {$sql_file}");
        }

        self::write_payload($metadata_file, [
            'kind' => self::KIND,
            'version' => self::VERSION,
            'complete' => true,
            'generated_at' => gmdate('c'),
            'sql' => [
                'file' => basename($sql_file),
                'bytes' => filesize($sql_file),
                'sha256' => $hash,
            ],
            'statements' => [
                'total' => $statements_total,
                'counter' => 'WP_MySQL_Naive_Query_Stream',
            ],
            'domains' => [
                'origins' => self::normalize_domains($domains),
                'collector' => 'Base64ValueScanner+DomainCollector',
            ],
        ]);
    }

    /**
     * Persist crash-resumable partial discoveries. Partial metadata is valid
     * only for resuming db-pull discovery; db-apply requires complete metadata
     * with a matching db.sql hash.
     *
     * @param string[] $domains
     */
    public static function write_partial(
        string $metadata_file,
        array $domains,
        int $statements_counted,
        int $bytes_written
    ): void {
        if ($statements_counted < 0 || $bytes_written < 0) {
            throw new InvalidArgumentException('Partial SQL metadata counters cannot be negative.');
        }

        self::write_payload($metadata_file, [
            'kind' => self::KIND,
            'version' => self::VERSION,
            'complete' => false,
            'generated_at' => gmdate('c'),
            'sql' => [
                'file' => 'db.sql',
                'bytes' => $bytes_written,
                'sha256' => null,
            ],
            'statements' => [
                'total' => $statements_counted,
                'counter' => 'WP_MySQL_Naive_Query_Stream',
            ],
            'domains' => [
                'origins' => self::normalize_domains($domains),
                'collector' => 'Base64ValueScanner+DomainCollector',
            ],
        ]);
    }

    /**
     * @return array{domains: list<string>, statements_total: int}|null
     */
    public static function read_complete(string $metadata_file, string $sql_file, ?string &$reason = null): ?array
    {
        $payload = self::read_payload($metadata_file, $reason);
        if ($payload === null) {
            return null;
        }

        $validated = self::validate_payload($payload, true, $reason);
        if ($validated === null) {
            return null;
        }

        if (!is_file($sql_file)) {
            $reason = 'sql file missing';
            return null;
        }

        $sql = $payload['sql'] ?? null;
        if (!is_array($sql)) {
            $reason = 'sql fingerprint missing';
            return null;
        }

        $expected_bytes = $sql['bytes'] ?? null;
        $actual_bytes = filesize($sql_file);
        if (!is_int($expected_bytes) || $actual_bytes !== $expected_bytes) {
            $reason = 'sql file size mismatch';
            return null;
        }

        $expected_hash = $sql['sha256'] ?? null;
        if (!is_string($expected_hash) || !preg_match('/\A[a-f0-9]{64}\z/', $expected_hash)) {
            $reason = 'invalid sql hash';
            return null;
        }

        $actual_hash = hash_file('sha256', $sql_file);
        if (!is_string($actual_hash) || !hash_equals($expected_hash, $actual_hash)) {
            $reason = 'sql file hash mismatch';
            return null;
        }

        return $validated;
    }

    /**
     * @return list<string>
     */
    public static function read_resume_domains(string $metadata_file): array
    {
        $reason = null;
        $payload = self::read_payload($metadata_file, $reason);
        if ($payload === null) {
            return [];
        }

        $validated = self::validate_payload($payload, false, $reason);
        return $validated['domains'] ?? [];
    }

    /**
     * @param string[] $domains
     */
    public static function write_legacy_domains(string $domains_file, array $domains): void
    {
        self::write_payload(
            $domains_file,
            self::normalize_domains($domains),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

    public static function write_legacy_stats(string $stats_file, int $statements_total): void
    {
        if ($statements_total < 0) {
            throw new InvalidArgumentException('Statement count cannot be negative.');
        }
        self::write_payload($stats_file, ['statements_total' => $statements_total]);
    }

    /**
     * Validate a legacy .import-domains.json array for resume migration only.
     *
     * @return list<string>
     */
    public static function read_legacy_domains(string $domains_file): array
    {
        $reason = null;
        $payload = self::read_payload($domains_file, $reason);
        if (!is_array($payload)) {
            return [];
        }

        try {
            return self::normalize_domains($payload);
        } catch (InvalidArgumentException $e) {
            return [];
        }
    }

    /**
     * @return array<mixed>|null
     */
    private static function read_payload(string $path, ?string &$reason = null): ?array
    {
        if (!is_file($path)) {
            $reason = 'metadata file missing';
            return null;
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            $reason = 'metadata file unreadable';
            return null;
        }

        $payload = json_decode($contents, true);
        if (!is_array($payload)) {
            $reason = 'metadata json invalid';
            return null;
        }

        return $payload;
    }

    /**
     * @param array<mixed> $payload
     * @return array{domains: list<string>, statements_total: int}|null
     */
    private static function validate_payload(array $payload, bool $require_complete, ?string &$reason = null): ?array
    {
        if (($payload['kind'] ?? null) !== self::KIND) {
            $reason = 'metadata kind mismatch';
            return null;
        }
        if (($payload['version'] ?? null) !== self::VERSION) {
            $reason = 'metadata version mismatch';
            return null;
        }
        if ($require_complete && (($payload['complete'] ?? null) !== true)) {
            $reason = 'metadata incomplete';
            return null;
        }

        $statements = $payload['statements'] ?? null;
        if (!is_array($statements) || !isset($statements['total']) || !is_int($statements['total'])) {
            $reason = 'statement count missing';
            return null;
        }
        if ($statements['total'] < 0) {
            $reason = 'statement count negative';
            return null;
        }

        $domains = $payload['domains'] ?? null;
        $origins = is_array($domains) ? ($domains['origins'] ?? null) : null;
        if (!is_array($origins)) {
            $reason = 'domains missing';
            return null;
        }

        try {
            $normalized_domains = self::normalize_domains($origins);
        } catch (InvalidArgumentException $e) {
            $reason = 'domain list invalid';
            return null;
        }

        return [
            'domains' => $normalized_domains,
            'statements_total' => $statements['total'],
        ];
    }

    /**
     * @param array<mixed> $domains
     * @return list<string>
     */
    private static function normalize_domains(array $domains): array
    {
        $normalized = [];
        foreach ($domains as $domain) {
            if (!is_string($domain)) {
                throw new InvalidArgumentException('Domain origin must be a string.');
            }
            $origin = self::normalize_origin($domain);
            if ($origin === null) {
                throw new InvalidArgumentException("Invalid domain origin: {$domain}");
            }
            $normalized[$origin] = true;
        }

        $origins = array_keys($normalized);
        sort($origins);
        return array_values($origins);
    }

    private static function normalize_origin(string $origin): ?string
    {
        $origin = trim($origin);
        if ($origin === '' || strlen($origin) > 2048) {
            return null;
        }

        $parts = parse_url($origin);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        if (!is_string($scheme) || !is_string($host) || $host === '') {
            return null;
        }

        $scheme = strtolower($scheme);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        foreach (['user', 'pass', 'path', 'query', 'fragment'] as $forbidden) {
            if (array_key_exists($forbidden, $parts)) {
                return null;
            }
        }

        $normalized = $scheme . '://' . strtolower($host);
        if (isset($parts['port'])) {
            if (!is_int($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535) {
                return null;
            }
            $normalized .= ':' . $parts['port'];
        }

        return $normalized;
    }

    /**
     * @param mixed $payload
     */
    private static function write_payload(string $path, $payload, int $flags = 0): void
    {
        $json = json_encode($payload, $flags);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode SQL metadata: ' . json_last_error_msg());
        }

        $tmp = $path . '.tmp';
        $bytes = file_put_contents($tmp, $json . "\n");
        if ($bytes === false) {
            throw new RuntimeException("Failed to write SQL metadata: {$tmp}");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to rename SQL metadata: {$tmp} -> {$path}");
        }
    }
}
