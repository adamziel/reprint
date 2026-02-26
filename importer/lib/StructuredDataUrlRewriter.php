<?php

require_once __DIR__ . '/wp-stubs.php';

use WordPress\DataLiberation\URL\WPURL;
use function WordPress\DataLiberation\URL\wp_rewrite_urls;

/**
 * Rewrites URLs in a single decoded database value by detecting the data
 * format and applying the appropriate rewriting strategy.
 *
 * Formats are handled recursively — a serialized PHP array can contain a
 * base64-encoded JSON string that embeds block markup with URLs, and each
 * layer is decoded, rewritten, and re-encoded correctly.
 *
 * Supported formats:
 * - Serialized PHP: walks string values with PhpSerializedStringWalker,
 *   recursively classifying and rewriting each one
 * - JSON: recursively walks string values, classifying and rewriting each one
 * - Base64: decodes, recursively rewrites the inner content, re-encodes
 * - Text/HTML/Block markup: uses wp_rewrite_urls() which handles HTML attributes,
 *   block comment JSON, text nodes, and CSS url() in style attributes
 */
class StructuredDataUrlRewriter
{
    /** @var array<string, string> URL mapping: source_url => target_url */
    private array $url_mapping;

    /**
     * @param array<string, string> $url_mapping Source URL => target URL mapping.
     */
    public function __construct(array $url_mapping)
    {
        $this->url_mapping = $url_mapping;
    }

    /**
     * Rewrite URLs in a single decoded value.
     *
     * @param string $value The decoded database value.
     * @return string The rewritten value, or the original if no changes were made.
     */
    public function rewrite(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $type = ContentClassifier::classify($value);

        switch ($type) {
            case ContentClassifier::TYPE_SERIALIZED_PHP:
                return $this->rewrite_serialized_php($value);

            case ContentClassifier::TYPE_JSON:
                return $this->rewrite_json($value);

            case ContentClassifier::TYPE_BASE64:
                return $this->rewrite_base64($value);

            case ContentClassifier::TYPE_TEXT:
            default:
                return $this->rewrite_text($value);
        }
    }

    /**
     * Rewrite URLs in a PHP serialized value by walking all string values.
     *
     * Each string value is recursively classified and rewritten — this handles
     * nested serialization (double-serialized WordPress options), JSON inside
     * serialized PHP, and HTML inside serialized PHP.
     *
     * Falls back to text rewriting if the serialized data is malformed.
     */
    private function rewrite_serialized_php(string $value): string
    {
        $result = PhpSerializedStringWalker::walk_strings($value, function (string $s): string {
            return $this->rewrite($s);
        });

        // If the walker returns false, the input was malformed.
        // Fall back to treating it as text so we still attempt URL replacement
        // rather than silently passing corrupted data through.
        if ($result === false) {
            return $this->rewrite_text($value);
        }

        return $result;
    }

    /**
     * Rewrite URLs in a JSON value by recursively walking all string values.
     *
     * Each string value is passed back through rewrite() so nested formats
     * (serialized PHP inside JSON, base64 inside JSON, etc.) are handled.
     */
    private function rewrite_json(string $json): string
    {
        $data = json_decode($json, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return $json;
        }

        $changed = false;
        $data = $this->walk_json($data, $changed);

        if (!$changed) {
            return $json;
        }

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Recursively walk a JSON-decoded structure and rewrite URLs in string values.
     *
     * String values are routed through rewrite() for full format detection,
     * so a JSON string containing serialized PHP, base64, or nested JSON
     * will be handled correctly.
     *
     * @param mixed $data    The JSON-decoded data.
     * @param bool  $changed Set to true if any value was changed.
     * @return mixed The walked data with URLs rewritten.
     */
    private function walk_json($data, bool &$changed)
    {
        if (is_string($data)) {
            $rewritten = $this->rewrite($data);
            if ($rewritten !== $data) {
                $changed = true;
                return $rewritten;
            }
            return $data;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->walk_json($value, $changed);
            }
        }

        return $data;
    }

    /**
     * Rewrite URLs in base64-encoded data.
     *
     * Decodes the value, recursively rewrites the inner content (which may
     * be serialized PHP, JSON, HTML, or any other supported format), then
     * re-encodes. Returns the original if nothing changed or decode fails.
     */
    private function rewrite_base64(string $value): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return $this->rewrite_text($value);
        }

        $rewritten = $this->rewrite($decoded);

        if ($rewritten === $decoded) {
            return $value;
        }

        return base64_encode($rewritten);
    }

    /**
     * Rewrite URLs in text/HTML/block markup using wp_rewrite_urls().
     * This handles HTML attributes, block comment JSON, text nodes, and CSS url().
     */
    private function rewrite_text(string $value): string
    {
        return wp_rewrite_urls([
            'block_markup' => $value,
            'url-mapping' => $this->url_mapping,
        ]);
    }
}
