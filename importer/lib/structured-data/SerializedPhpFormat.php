<?php

require_once __DIR__ . '/StructuredDataFormat.php';

/**
 * Detects and rewrites URLs in PHP serialized data.
 *
 * Uses PhpSerializationProcessor's cursor API to iterate all string values
 * without calling unserialize(), so object instantiation and __wakeup side
 * effects are avoided. Each string value is passed through the recursive
 * callback for nested format handling (JSON inside serialized PHP, base64
 * inside serialized PHP, double-serialized PHP, etc.).
 *
 * Falls back to text rewriting (returns null) if the serialized data turns
 * out to be malformed during parsing.
 */
class SerializedPhpFormat implements StructuredDataFormat
{
    /**
     * Check if a string looks like PHP serialized data.
     *
     * This is a lightweight syntactic check (first/last character patterns)
     * ported from WordPress's is_serialized() function. It can produce false
     * positives for malformed data — rewrite() handles that by falling back
     * to text rewriting.
     */
    public function tryDecode(string $value)
    {
        return self::is_serialized($value) ? true : null;
    }

    public function rewrite(string $value, $decoded, callable $recurse): ?string
    {
        $p = new PhpSerializationProcessor($value);
        while ($p->next_value()) {
            $original = $p->get_value();
            $rewritten = $recurse($original);
            if ($rewritten !== $original) {
                $p->set_value($rewritten);
            }
        }

        // If the parser encountered malformed data, signal the orchestrator
        // to fall back to text rewriting rather than silently passing corrupted
        // data through.
        if ($p->is_malformed()) {
            return null;
        }

        return $p->get_updated_serialization();
    }

    /**
     * Check if a string is PHP serialized data.
     * Ported from WordPress's is_serialized() function.
     *
     * @param string $data The string to check.
     * @return bool
     */
    public static function is_serialized(string $data): bool
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
