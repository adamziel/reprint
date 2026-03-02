<?php

require_once __DIR__ . '/StructuredDataFormat.php';

/**
 * Detects and rewrites URLs in base64-encoded structured data.
 *
 * Detection verifies the base64 envelope (length, alphabet, successful decode)
 * then delegates to the orchestrator to check whether the decoded content is
 * recognizably structured (serialized PHP, JSON, or any other registered
 * format). This keeps base64 detection composable — adding a new format
 * handler automatically allows it to appear inside base64.
 *
 * As a special case, decoded content starting with < (HTML/block markup) is
 * also accepted, since base64-encoded HTML is common in WordPress and
 * accidental base64 decoding to HTML is extremely unlikely.
 *
 * The decoded bytes from tryDecode are returned for reuse in rewrite(),
 * avoiding a second base64_decode call.
 */
class Base64Format implements StructuredDataFormat
{
    private const BASE64_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';

    /**
     * Callback that checks whether a string is structured data by trying
     * all registered format handlers. Returns true if any handler matches.
     * Provided by the orchestrator so that base64 detection composes with
     * all registered formats without knowing about them.
     *
     * @var callable(string): bool
     */
    private $isStructuredContent;

    /**
     * @param callable(string): bool $isStructuredContent Returns true if the
     *        string matches any registered structured data format.
     */
    public function __construct(callable $isStructuredContent)
    {
        $this->isStructuredContent = $isStructuredContent;
    }

    /**
     * Try to decode $value as base64-encoded structured data.
     *
     * Returns the decoded bytes on success, or null if the value is not
     * valid base64 wrapping structured content.
     */
    public function tryDecode(string $value)
    {
        $len = strlen($value);

        // Too short to be useful base64 — decodes to fewer than 6 bytes,
        // which can't contain a meaningful structured payload.
        if ($len < 8) {
            return null;
        }

        // Valid base64 length is always a multiple of 4
        if ($len % 4 !== 0) {
            return null;
        }

        // Quick alphabet check via strspn (C-level, no regex)
        if (strspn($value, self::BASE64_ALPHABET) !== $len) {
            return null;
        }

        // Try decoding (strict mode rejects characters outside the alphabet)
        $decoded = base64_decode($value, true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        // Check if decoded content is structured by asking the orchestrator
        // to try all registered format handlers. This is what makes base64
        // composable — it doesn't need to know about JSON, serialized PHP,
        // or any other format. When a new format handler is registered,
        // base64-encoded instances of that format are automatically detected.
        if (($this->isStructuredContent)($decoded)) {
            return $decoded;
        }

        // Check if decoded content looks like HTML or block markup.
        // The < character as first non-whitespace is a strong signal
        // since base64 decoding to accidental HTML is extremely rare.
        $trimmed = ltrim($decoded);
        if (isset($trimmed[0]) && $trimmed[0] === '<') {
            return $decoded;
        }

        return null;
    }

    public function rewrite(string $value, $decoded, callable $recurse): ?string
    {
        $rewritten = $recurse($decoded);

        if ($rewritten === $decoded) {
            return $value;
        }

        return base64_encode($rewritten);
    }
}
