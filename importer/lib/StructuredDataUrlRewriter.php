<?php

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/structured-data/StructuredDataFormat.php';
require_once __DIR__ . '/structured-data/SerializedPhpFormat.php';
require_once __DIR__ . '/structured-data/JsonFormat.php';
require_once __DIR__ . '/structured-data/Base64Format.php';

use function WordPress\DataLiberation\URL\wp_rewrite_urls;

/**
 * Rewrites URLs in a single decoded database value by detecting the data
 * format and applying the appropriate rewriting strategy.
 *
 * Formats are handled by composable StructuredDataFormat handlers, each in
 * its own file under structured-data/. The orchestrator tries each handler
 * in priority order. When a handler detects its format (tryDecode returns
 * non-null), it rewrites the value using the already-decoded representation
 * — no double-decoding.
 *
 * Each handler's rewrite method receives a $recurse callback that routes
 * inner string values back through the full handler chain. This allows
 * arbitrary nesting: serialized PHP containing JSON containing base64
 * containing HTML, or any other combination, without any handler needing
 * to know about the others.
 *
 * Supported formats:
 * - Serialized PHP: uses PhpSerializationProcessor's cursor API to iterate
 *   string values, recursively classifying and rewriting each one
 * - JSON: recursively walks string values, classifying and rewriting each one
 * - Base64: decodes, recursively rewrites the inner content, re-encodes
 * - Block markup: uses wp_rewrite_urls() which handles HTML attributes,
 *   block comment JSON, text nodes, and CSS url() in style attributes
 * - Plain text: uses strtr() for simple string replacement (default for text)
 *
 * The caller can pass a $content_type hint to control how leaf text values
 * are rewritten:
 * - null (default): auto-detect format; use strtr() for TYPE_TEXT
 * - 'block_markup': use wp_rewrite_urls() for TYPE_TEXT (for post_content etc.)
 * - 'skip': return the value unchanged
 *
 * The hint propagates through recursive calls so that e.g. serialized PHP
 * inside a block_markup column eventually reaches wp_rewrite_urls() for its
 * leaf text strings.
 */
class StructuredDataUrlRewriter
{
    /** @var array<string, string> URL mapping: source_url => target_url */
    private array $url_mapping;

    /** @var StructuredDataFormat[] Format handlers in priority order. */
    private array $formats;

    /**
     * @param array<string, string> $url_mapping Source URL => target URL mapping.
     */
    public function __construct(array $url_mapping)
    {
        $this->url_mapping = $url_mapping;

        $serializedPhp = new SerializedPhpFormat();
        $json = new JsonFormat();

        // Start with the non-base64 formats so the isStructuredContent
        // callback can reference $this->formats. The base64 handler is
        // appended below, so nested base64 is also detected once the
        // callback runs (it captures $this->formats by reference via $this).
        $this->formats = [$serializedPhp, $json];

        $base64 = new Base64Format(function (string $value): bool {
            foreach ($this->formats as $format) {
                if ($format->tryDecode($value) !== null) {
                    return true;
                }
            }
            return false;
        });

        $this->formats[] = $base64;
    }

    /**
     * Rewrite URLs in a single decoded value.
     *
     * @param string      $value        The decoded database value.
     * @param string|null $content_type Content type hint: null (auto-detect, plain text default),
     *                                  'block_markup' (use wp_rewrite_urls), or 'skip' (no-op).
     * @return string The rewritten value, or the original if no changes were made.
     */
    public function rewrite(string $value, ?string $content_type = null): string
    {
        if ($value === '') {
            return $value;
        }

        if ($content_type === 'skip') {
            return $value;
        }

        $recurse = fn(string $v): string => $this->rewrite($v, $content_type);

        foreach ($this->formats as $format) {
            $decoded = $format->tryDecode($value);
            if ($decoded !== null) {
                $result = $format->rewrite($value, $decoded, $recurse);
                if ($result !== null) {
                    return $result;
                }
                // Format was detected but rewriting failed (e.g., malformed
                // serialized PHP). Fall through to text handling.
                break;
            }
        }

        // Leaf: text handling
        if ($content_type === 'block_markup') {
            return wp_rewrite_urls([
                'block_markup' => $value,
                'url-mapping' => $this->url_mapping,
            ]);
        }

        return strtr($value, $this->url_mapping);
    }
}
