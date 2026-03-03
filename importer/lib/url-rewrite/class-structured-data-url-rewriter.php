<?php

use function WordPress\DataLiberation\URL\wp_rewrite_urls;

/**
 * Rewrites URLs in a single decoded database value by detecting the data
 * format and applying the appropriate rewriting strategy.
 *
 * Format detection is try-and-fail: construct the real parser, check if
 * it accepted the input. No heuristic pre-checks, no format detection
 * class — the parsers themselves are the authority on what's valid.
 *
 * 1. Serialized PHP → construct PhpSerializationProcessor, if not malformed,
 *    iterate string values and recurse on each
 * 2. JSON → construct JsonStringIterator, if not malformed, iterate string
 *    values and recurse on each
 * 3. Base64 → decode, recurse on decoded content, re-encode if changed
 * 4. Leaf text → wp_rewrite_urls() (block_markup hint) or strtr() (default)
 *
 * HTML is never auto-detected — the caller must explicitly pass
 * content_type='block_markup' for values known to contain HTML/block markup.
 * The hint propagates through recursive calls so that leaf strings inside
 * serialized PHP, JSON, or base64 eventually reach wp_rewrite_urls().
 */
class StructuredDataUrlRewriter
{
    const BLOCK_MARKUP = 'block_markup';

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

        // Try serialized PHP: the parser validates the entire structure
        // in the constructor. If it's not malformed, iterate and recurse.
        $p = new PhpSerializationProcessor($value);
        if (!$p->is_malformed()) {
            while ($p->next_value()) {
                $original = $p->get_value();
                $rewritten = $this->rewrite($original, $content_type);
                if ($rewritten !== $original) {
                    $p->set_value($rewritten);
                }
            }
            return $p->get_updated_serialization();
        }

        // Try JSON: the iterator calls json_decode in the constructor.
        // If it's not malformed, iterate and recurse.
        $iter = new JsonStringIterator($value);
        if (!$iter->is_malformed()) {
            while ($iter->next_value()) {
                $original = $iter->get_value();
                $rewritten = $this->rewrite($original, $content_type);
                if ($rewritten !== $original) {
                    $iter->set_value($rewritten);
                }
            }
            return $iter->get_result();
        }

        // Try base64 as a transport encoding. Decode, recurse on the
        // decoded content, and re-encode if the recursive call changed
        // anything. No format guessing on the decoded content — the
        // recursive call tries the real parsers.
        $decoded = base64_decode($value, true);
        if ($decoded !== false && $decoded !== '') {
            $rewritten = $this->rewrite($decoded, $content_type);
            if ($rewritten !== $decoded) {
                return base64_encode($rewritten);
            }
        }

        // Leaf: text handling. The caller decides whether this is block
        // markup (wp_rewrite_urls) or plain text (strtr). We never guess.
        if ($content_type === 'block_markup') {
            return wp_rewrite_urls([
                'block_markup' => $value,
                'url-mapping' => $this->url_mapping,
            ]);
        }

        return strtr($value, $this->url_mapping);
    }
}
