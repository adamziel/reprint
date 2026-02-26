<?php

/**
 * Walks PHP serialized data at the byte level and calls a callback for each
 * string value, reconstructing the output with corrected s:N: length prefixes.
 *
 * This avoids unserialize() entirely — no object instantiation, no __wakeup
 * side effects, no class dependency issues. The parser reads exactly N bytes
 * per s:N: prefix (never scans for a closing quote), so embedded quotes,
 * semicolons, and null bytes are handled correctly.
 *
 * The callback is called for string VALUES only — not for array keys or
 * object property names. This matches WordPress search-replace semantics.
 */
class PhpSerializedStringWalker
{
    private const DIGITS = '0123456789';

    /**
     * Walk all string values in a PHP serialized string and apply a callback.
     *
     * @param string   $serialized The PHP serialized data.
     * @param callable $callback   fn(string $value): string — called for each string value.
     * @return string|false The rebuilt serialized string, or false on malformed input.
     */
    public static function walk_strings(string $serialized, callable $callback)
    {
        $pos = 0;
        $result = self::parse_value($serialized, $pos, $callback, true);
        if ($result === false) {
            return false;
        }

        // If there's trailing data, the input is malformed
        if ($pos !== strlen($serialized)) {
            return false;
        }

        return $result;
    }

    /**
     * Dispatch on the type character at the current position.
     *
     * @param string   $data           The full serialized string.
     * @param int      &$pos           Current byte offset (updated in place).
     * @param callable $callback       The string-value callback.
     * @param bool     $apply_callback Whether to apply the callback (false for keys/property names).
     * @return string|false The reconstructed fragment, or false on error.
     */
    private static function parse_value(string $data, int &$pos, callable $callback, bool $apply_callback)
    {
        if (!isset($data[$pos])) {
            return false;
        }

        switch ($data[$pos]) {
            case 's':
                return self::parse_string($data, $pos, $callback, $apply_callback);
            case 'i':
                return self::parse_integer($data, $pos);
            case 'd':
                return self::parse_double($data, $pos);
            case 'b':
                return self::parse_boolean($data, $pos);
            case 'N':
                return self::parse_null($data, $pos);
            case 'a':
                return self::parse_array($data, $pos, $callback);
            case 'O':
                return self::parse_object($data, $pos, $callback);
            case 'C':
                return self::parse_custom($data, $pos);
            case 'r':
            case 'R':
                return self::parse_reference($data, $pos);
            default:
                return false;
        }
    }

    /**
     * Parse a string value: s:N:"...";
     *
     * Reads exactly N bytes after the opening quote — never scans for a
     * closing quote. This correctly handles strings containing quotes,
     * semicolons, null bytes, and other special characters.
     */
    private static function parse_string(string $data, int &$pos, callable $callback, bool $apply_callback)
    {
        // s:N:"...";
        $len = strlen($data);
        if (!isset($data[$pos]) || $data[$pos] !== 's' || !isset($data[$pos + 1]) || $data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2; // skip 's:'

        // Read the byte-length number using strspn for fast digit scanning
        $digit_len = strspn($data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }
        $byte_length = (int) substr($data, $pos, $digit_len);
        $pos += $digit_len;

        // Expect :" then exactly $byte_length bytes then ";
        // Minimum remaining: 2 (:" ) + $byte_length + 2 (";) = $byte_length + 4
        if ($pos + $byte_length + 4 > $len || $data[$pos] !== ':' || $data[$pos + 1] !== '"') {
            return false;
        }
        $pos += 2; // skip ':"'

        $value = substr($data, $pos, $byte_length);
        $pos += $byte_length;

        if ($data[$pos] !== '"' || $data[$pos + 1] !== ';') {
            return false;
        }
        $pos += 2; // skip '";'

        // Apply callback for values, not for keys/property names
        if ($apply_callback) {
            $value = $callback($value);
        }

        return 's:' . strlen($value) . ':"' . $value . '";';
    }

    /**
     * Parse an integer: i:N;
     */
    private static function parse_integer(string $data, int &$pos)
    {
        if (!isset($data[$pos]) || $data[$pos] !== 'i' || !isset($data[$pos + 1]) || $data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2; // skip 'i:'

        $start = $pos;
        // Optional leading minus
        if (isset($data[$pos]) && $data[$pos] === '-') {
            $pos++;
        }
        $digit_len = strspn($data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }
        $pos += $digit_len;

        if (!isset($data[$pos]) || $data[$pos] !== ';') {
            return false;
        }
        $result = 'i:' . substr($data, $start, $pos - $start) . ';';
        $pos++; // skip ';'

        return $result;
    }

    /**
     * Parse a double/float: d:N;
     * Handles integers, decimals, scientific notation, INF, -INF, NAN.
     */
    private static function parse_double(string $data, int &$pos)
    {
        if (!isset($data[$pos]) || $data[$pos] !== 'd' || !isset($data[$pos + 1]) || $data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2; // skip 'd:'

        // Read until semicolon — PHP serialize can produce various float representations
        $span = strcspn($data, ';', $pos);
        if ($span === 0 || $pos + $span >= strlen($data)) {
            return false;
        }

        $result = 'd:' . substr($data, $pos, $span) . ';';
        $pos += $span + 1; // skip value + ';'

        return $result;
    }

    /**
     * Parse a boolean: b:0; or b:1;
     */
    private static function parse_boolean(string $data, int &$pos)
    {
        // b:V; — exactly 4 bytes
        if (!isset($data[$pos + 3])
            || $data[$pos] !== 'b'
            || $data[$pos + 1] !== ':'
            || ($data[$pos + 2] !== '0' && $data[$pos + 2] !== '1')
            || $data[$pos + 3] !== ';'
        ) {
            return false;
        }
        $result = 'b:' . $data[$pos + 2] . ';';
        $pos += 4;
        return $result;
    }

    /**
     * Parse null: N;
     */
    private static function parse_null(string $data, int &$pos)
    {
        if (!isset($data[$pos + 1]) || $data[$pos] !== 'N' || $data[$pos + 1] !== ';') {
            return false;
        }
        $pos += 2;
        return 'N;';
    }

    /**
     * Parse an array: a:N:{key;value;...}
     *
     * Keys are parsed without the callback (they're structural, not content).
     * Values get the callback applied.
     */
    private static function parse_array(string $data, int &$pos, callable $callback)
    {
        if (!isset($data[$pos]) || $data[$pos] !== 'a' || !isset($data[$pos + 1]) || $data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2; // skip 'a:'

        // Read element count
        $digit_len = strspn($data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }
        $count = (int) substr($data, $pos, $digit_len);
        $pos += $digit_len;

        // Expect :{
        if (!isset($data[$pos + 1]) || $data[$pos] !== ':' || $data[$pos + 1] !== '{') {
            return false;
        }
        $pos += 2; // skip ':{'

        $elements = '';
        for ($i = 0; $i < $count; $i++) {
            // Parse key (string or integer, no callback)
            $key = self::parse_value($data, $pos, $callback, false);
            if ($key === false) {
                return false;
            }

            // Parse value (with callback)
            $value = self::parse_value($data, $pos, $callback, true);
            if ($value === false) {
                return false;
            }

            $elements .= $key . $value;
        }

        // Expect closing }
        if (!isset($data[$pos]) || $data[$pos] !== '}') {
            return false;
        }
        $pos++; // skip '}'

        return 'a:' . $count . ':{' . $elements . '}';
    }

    /**
     * Parse an object: O:N:"classname":N:{propname;value;...}
     *
     * Property names are parsed without the callback (they're structural).
     * Property values get the callback applied.
     *
     * Private/protected properties use null-byte visibility markers in
     * property names (e.g., \0ClassName\0prop for private). These are
     * preserved correctly because we read by byte count, not by scanning
     * for delimiters.
     */
    private static function parse_object(string $data, int &$pos, callable $callback)
    {
        $len = strlen($data);
        if (!isset($data[$pos]) || $data[$pos] !== 'O' || !isset($data[$pos + 1]) || $data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2; // skip 'O:'

        // Read class name length
        $digit_len = strspn($data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }
        $name_len = (int) substr($data, $pos, $digit_len);
        $pos += $digit_len;

        // Expect :" then exactly name_len bytes then ":
        // Minimum remaining: 2 (:" ) + name_len + 2 (":) = name_len + 4
        if ($pos + $name_len + 4 > $len || $data[$pos] !== ':' || $data[$pos + 1] !== '"') {
            return false;
        }
        $pos += 2;

        $class_name = substr($data, $pos, $name_len);
        $pos += $name_len;

        if ($data[$pos] !== '"' || $data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2;

        // Read property count
        $digit_len = strspn($data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }
        $prop_count = (int) substr($data, $pos, $digit_len);
        $pos += $digit_len;

        // Expect :{
        if (!isset($data[$pos + 1]) || $data[$pos] !== ':' || $data[$pos + 1] !== '{') {
            return false;
        }
        $pos += 2;

        $elements = '';
        for ($i = 0; $i < $prop_count; $i++) {
            // Parse property name (no callback — structural, not content)
            $prop_name = self::parse_value($data, $pos, $callback, false);
            if ($prop_name === false) {
                return false;
            }

            // Parse property value (with callback)
            $prop_value = self::parse_value($data, $pos, $callback, true);
            if ($prop_value === false) {
                return false;
            }

            $elements .= $prop_name . $prop_value;
        }

        // Expect closing }
        if (!isset($data[$pos]) || $data[$pos] !== '}') {
            return false;
        }
        $pos++;

        return 'O:' . $name_len . ':"' . $class_name . '":' . $prop_count . ':{' . $elements . '}';
    }

    /**
     * Parse a reference: r:N; or R:N;
     *
     * r:N; is a value reference, R:N; is a pointer reference.
     * Both are passed through unchanged.
     */
    private static function parse_reference(string $data, int &$pos)
    {
        $type = $data[$pos];
        if (!isset($data[$pos + 1]) || $data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2; // skip 'r:' or 'R:'

        $digit_len = strspn($data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }

        $result = $type . ':' . substr($data, $pos, $digit_len) . ';';
        $pos += $digit_len;

        if (!isset($data[$pos]) || $data[$pos] !== ';') {
            return false;
        }
        $pos++; // skip ';'

        return $result;
    }

    /**
     * Parse a custom serializable object: C:N:"classname":N:{payload}
     *
     * The payload is opaque — we don't know its internal format, so we
     * pass it through unchanged. This handles classes that implement
     * the Serializable interface.
     */
    private static function parse_custom(string $data, int &$pos)
    {
        $len = strlen($data);
        if (!isset($data[$pos]) || $data[$pos] !== 'C' || !isset($data[$pos + 1]) || $data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2; // skip 'C:'

        // Read class name length
        $digit_len = strspn($data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }
        $name_len = (int) substr($data, $pos, $digit_len);
        $pos += $digit_len;

        // Expect :" then exactly name_len bytes then ":
        if ($pos + $name_len + 4 > $len || $data[$pos] !== ':' || $data[$pos + 1] !== '"') {
            return false;
        }
        $pos += 2;

        $class_name = substr($data, $pos, $name_len);
        $pos += $name_len;

        if ($data[$pos] !== '"' || $data[$pos + 1] !== ':') {
            return false;
        }
        $pos += 2;

        // Read payload length
        $digit_len = strspn($data, self::DIGITS, $pos);
        if ($digit_len === 0) {
            return false;
        }
        $payload_len = (int) substr($data, $pos, $digit_len);
        $pos += $digit_len;

        // Expect :{ then exactly payload_len bytes then }
        if ($pos + $payload_len + 3 > $len || $data[$pos] !== ':' || $data[$pos + 1] !== '{') {
            return false;
        }
        $pos += 2;

        $payload = substr($data, $pos, $payload_len);
        $pos += $payload_len;

        if ($data[$pos] !== '}') {
            return false;
        }
        $pos++;

        return 'C:' . $name_len . ':"' . $class_name . '":' . $payload_len . ':{' . $payload . '}';
    }
}
