<?php

/**
 * Classifies a decoded database string value into one of:
 * - serialized_php: PHP serialized data (s:N:"...";, a:N:{...}, O:N:"...", etc.)
 * - json: JSON objects or arrays
 * - text: Everything else (HTML, block markup, plain text, markdown, CSS)
 */
class ContentClassifier
{
    const TYPE_SERIALIZED_PHP = 'serialized_php';
    const TYPE_JSON = 'json';
    const TYPE_TEXT = 'text';

    /**
     * Classify a decoded database value.
     *
     * @param string $value The decoded string value.
     * @return string One of TYPE_SERIALIZED_PHP, TYPE_JSON, or TYPE_TEXT.
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
}
