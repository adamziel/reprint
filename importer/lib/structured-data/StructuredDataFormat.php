<?php

/**
 * Interface for structured data format handlers that can detect and rewrite
 * URLs in a specific data format (JSON, serialized PHP, base64, etc.).
 *
 * Format handlers are composed by an orchestrator (StructuredDataUrlRewriter)
 * that tries each handler in priority order. This allows arbitrary nesting
 * of formats — base64 in JSON in serialized PHP in base64 — without any
 * one handler needing to know about the others.
 *
 * The tryDecode/rewrite split avoids double-decoding: tryDecode returns the
 * decoded representation (parsed JSON, decoded base64 bytes, etc.) and
 * rewrite() receives it back to operate on without decoding again.
 */
interface StructuredDataFormat
{
    /**
     * Try to decode $value as this format.
     *
     * Returns the decoded representation for reuse during rewriting,
     * or null if the value is not in this format.
     *
     * The decoded value is passed back to rewrite() to avoid double-decoding.
     */
    /**
     * @return mixed The decoded representation, or null if not this format.
     */
    public function tryDecode(string $value);

    /**
     * Rewrite URLs in $value, which was confirmed to be in this format.
     *
     * @param string   $value   The original encoded value.
     * @param mixed    $decoded The decoded representation from tryDecode().
     * @param callable $recurse Callback to rewrite inner string values through
     *                          the full format chain: fn(string): string.
     * @return string|null The rewritten value, or null if the format turned out
     *                     to be malformed (orchestrator falls back to text).
     */
    public function rewrite(string $value, $decoded, callable $recurse): ?string;
}
