<?php

require_once __DIR__ . '/StructuredDataFormat.php';

/**
 * Detects and rewrites URLs in JSON objects and arrays.
 *
 * Detection checks for a leading { or [ followed by a successful json_decode.
 * Scalar JSON values ("hello", 42) are NOT detected — only objects and arrays.
 *
 * The decoded array/object from tryDecode is reused in rewrite() to avoid
 * calling json_decode a second time. String values are recursively walked
 * and passed through the format chain for nested format handling (serialized
 * PHP inside JSON, base64 inside JSON, nested JSON strings, etc.).
 */
class JsonFormat implements StructuredDataFormat
{
    /**
     * Try to decode $value as a JSON object or array.
     *
     * Returns the decoded PHP array on success (json_decode with assoc=true),
     * or null if not JSON.
     */
    public function tryDecode(string $value)
    {
        $first = $value[0];
        if ($first !== '{' && $first !== '[') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $decoded;
    }

    public function rewrite(string $value, $decoded, callable $recurse): ?string
    {
        $changed = false;
        $data = $this->walk($decoded, $changed, $recurse);

        if (!$changed) {
            return $value;
        }

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Recursively walk a JSON-decoded structure and rewrite URLs in string values.
     *
     * String values are routed through $recurse for full format detection,
     * so a JSON string containing serialized PHP, base64, or nested JSON
     * will be handled correctly.
     */
    /**
     * @param mixed $data
     * @return mixed
     */
    private function walk($data, bool &$changed, callable $recurse)
    {
        if (is_string($data)) {
            $rewritten = $recurse($data);
            if ($rewritten !== $data) {
                $changed = true;
                return $rewritten;
            }
            return $data;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->walk($value, $changed, $recurse);
            }
        }

        return $data;
    }
}
