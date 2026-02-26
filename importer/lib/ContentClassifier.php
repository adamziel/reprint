<?php

/**
 * Classifies a decoded database string value into one of:
 * - serialized_php: PHP serialized data (s:N:"...";, a:N:{...}, O:N:"...", etc.)
 * - json: JSON objects or arrays
 * - base64: Base64-encoded data whose decoded content is itself structured
 *   (serialized PHP, JSON, or HTML/block markup)
 * - text: Everything else (HTML, block markup, plain text, markdown, CSS)
 */
class ContentClassifier
{
    const TYPE_SERIALIZED_PHP = 'serialized_php';
    const TYPE_JSON = 'json';
    const TYPE_BASE64 = 'base64';
    const TYPE_TEXT = 'text';

    private const BASE64_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';

    /**
     * Classify a decoded database value.
     *
     * @param string $value The decoded string value.
     * @return string One of TYPE_SERIALIZED_PHP, TYPE_JSON, TYPE_BASE64, or TYPE_TEXT.
     */
    public static function classify(string $value): string
    {
        if ($value === '') {
            return self::TYPE_TEXT;
        }

        // Check for serialized PHP first (port of WordPress is_serialized())
        if (self::is_serialized($value)) {
            return self::TYPE_SERIALIZED_PHP;
        }

        // Check for JSON (objects or arrays)
        $first = $value[0];
        if ($first === '{' || $first === '[') {
            json_decode($value);
            if (json_last_error() === JSON_ERROR_NONE) {
                return self::TYPE_JSON;
            }
        }

        // Check for base64-encoded structured data. Only triggers when the
        // decoded content is recognizably structured (serialized PHP, JSON,
        // or HTML/block markup), avoiding false positives on strings that
        // just happen to fall within the base64 alphabet.
        if (self::is_base64_structured($value)) {
            return self::TYPE_BASE64;
        }

        // Default: text (HTML, block markup, plain text, markdown, CSS)
        return self::TYPE_TEXT;
    }

    /**
     * Check if a string is PHP serialized data.
     * Ported from WordPress's is_serialized() function.
     *
     * @param string $data The string to check.
     * @return bool
     */
    private static function is_serialized(string $data): bool
    {
        // Serialized null
        if ($data === 'N;') {
            return true;
        }

        if (strlen($data) < 4) {
            return false;
        }

        // Check for serialized boolean (b:0; or b:1;)
        if ($data === 'b:0;' || $data === 'b:1;') {
            return true;
        }

        // Must have colon in second position
        if ($data[1] !== ':') {
            return false;
        }

        $first = $data[0];
        $last = $data[strlen($data) - 1];

        // String: s:N:"...";
        // Integer: i:N;
        // Double: d:N;
        // Boolean: b:N;
        if (in_array($first, ['s', 'i', 'd', 'b'], true)) {
            if ($last === ';') {
                return true;
            }
            return false;
        }

        // Array: a:N:{...}
        // Object: O:N:"..."{...}
        // Custom serializable: C:N:"..."{...}
        if (in_array($first, ['a', 'O', 'C'], true)) {
            if ($last === '}') {
                return true;
            }
            return false;
        }

        return false;
    }

    /**
     * Check if a string is base64-encoded structured data.
     *
     * Returns true only when the decoded content is recognizably structured:
     * serialized PHP, JSON, or HTML/block markup. This avoids false positives
     * on short strings or random text that happens to sit within the base64
     * alphabet (e.g. "TRUE", "testing123").
     */
    private static function is_base64_structured(string $data): bool
    {
        $len = strlen($data);

        // Too short to be useful base64 — decodes to fewer than 6 bytes,
        // which can't contain a meaningful structured payload.
        if ($len < 8) {
            return false;
        }

        // Valid base64 length is always a multiple of 4
        if ($len % 4 !== 0) {
            return false;
        }

        // Quick alphabet check via strspn (C-level, no regex)
        if (strspn($data, self::BASE64_ALPHABET) !== $len) {
            return false;
        }

        // Try decoding (strict mode rejects characters outside the alphabet)
        $decoded = base64_decode($data, true);
        if ($decoded === false || $decoded === '') {
            return false;
        }

        // Check if decoded content is serialized PHP
        if (self::is_serialized($decoded)) {
            return true;
        }

        // Check if decoded content is JSON
        $first = $decoded[0];
        if ($first === '{' || $first === '[') {
            json_decode($decoded);
            if (json_last_error() === JSON_ERROR_NONE) {
                return true;
            }
        }

        // Check if decoded content looks like HTML or block markup.
        // The < character as first non-whitespace is a strong signal
        // since base64 decoding to accidental HTML is extremely rare.
        $trimmed = ltrim($decoded);
        if (isset($trimmed[0]) && $trimmed[0] === '<') {
            return true;
        }

        return false;
    }
}
