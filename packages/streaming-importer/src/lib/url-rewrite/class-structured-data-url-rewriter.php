<?php

use WordPress\DataLiberation\BlockMarkup\BlockMarkupUrlProcessor;
use WordPress\DataLiberation\URL\URLInTextProcessor;
use WordPress\DataLiberation\URL\WPURL;

use function WordPress\DataLiberation\URL\is_child_url_of;
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
    const PLAIN_TEXT = 'plain_text';

    /** @var array<string, string> URL mapping: source_url => target_url */
    private array $url_mapping;

    /** @var string[] Source domains extracted from url_mapping keys, for quick-reject checks. */
    private array $source_domains;

    /**
     * @param array<string, string> $url_mapping Source URL => target URL mapping.
     */
    public function __construct(array $url_mapping)
    {
        $this->url_mapping = $url_mapping;

        // Extract unique source domains for the quick-reject check.
        $domains = [];
        foreach (array_keys($url_mapping) as $from_url) {
            $host = parse_url($from_url, PHP_URL_HOST);
            if ($host !== null && $host !== false) {
                $domains[$host] = true;
            }
        }
        $this->source_domains = array_keys($domains);
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

        if ($content_type === null) {
            $content_type = self::PLAIN_TEXT;
        }

        // Quick-reject: if the value doesn't contain href=", src=", or any
        // source domain, there's nothing to rewrite. This avoids expensive
        // parsing (serialized PHP, JSON, block markup) for the vast majority
        // of values that don't contain any rewritable URLs.
        if (!$this->maybe_contains_rewritable_urls($value)) {
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

        // Base64 decoding is temporarily disabled for performance.
        // The base64 transport layer in SQL is already handled by
        // Base64ValueScanner in SqlStatementRewriter — this block
        // was for base64-within-base64 nesting which is rare in practice.

        return self::rewrite_urls([
            'content' => $value,
            'content_type' => $content_type,
            'url-mapping' => $this->url_mapping,
        ]);
    }

    /**
     * Quick-reject check: returns false when the value certainly doesn't
     * contain any rewritable URLs, avoiding expensive parsing.
     *
     * A value is considered potentially rewritable if it contains:
     * - href=" or src=" (HTML attributes that carry URLs), OR
     * - any source domain from the url_mapping (bare URL occurrences)
     */
    private function maybe_contains_rewritable_urls(string $value): bool
    {
        if (strpos($value, 'href="') !== false || strpos($value, 'src="') !== false) {
            return true;
        }
        foreach ($this->source_domains as $domain) {
            if (strpos($value, $domain) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Migrate URLs in post content. See WPRewriteUrlsTests for
     * specific examples. TODO: A better description.
     *
     * Example:
     *
     * ```php
     * php > wp_rewrite_urls([
     *   'block_markup' => '<!-- wp:image {"src": "http://legacy-blog.com/image.jpg"} -->',
     *   'url-mapping' => [
     *     'http://legacy-blog.com' => 'https://modern-webstore.org'
     *   ]
     * ])
     * <!-- wp:image {"src":"https:\/\/modern-webstore.org\/image.jpg"} -->
     * ```
     *
     * @TODO Use a proper JSON parser and encoder to:
     * * Support UTF-16 characters
     * * Gracefully handle recoverable encoding issues
     * * Avoid changing the whitespace in the same manner as
     *   we do in WP_HTML_Tag_Processor. e.g. if we start with:
     *
     * ```html
     * <!-- wp:block {"url":"https://w.org"}` -->
     *                     ^ no space here
     * ```
     *
     * then it would be nice to re-encode that block markup also without the space character. This is similar
     * to how the tag processor avoids changing parts of the tag it doesn't need to change.
     * 
     * TODO: Migrate these changes back into the php-toolkit repo
     */
    static private function rewrite_urls( $options ) {
        if ( empty( $options['base_url'] ) ) {
            // Use first from-url as base_url if not specified.
            $from_urls           = array_keys( $options['url-mapping'] );
            $options['base_url'] = $from_urls[0];
        }

        $url_mapping = array();
        foreach ( $options['url-mapping'] as $from_url_string => $to_url_string ) {
            $url_mapping[] = array(
                'from_url' => WPURL::parse( $from_url_string ),
                'to_url'   => WPURL::parse( $to_url_string ),
            );
        }

        switch($options['content_type']) {
            case self::BLOCK_MARKUP:
                $p = new BlockMarkupUrlProcessor( $options['content'], $options['base_url'] );
                while ( $p->next_url() ) {
                    $parsed_url = $p->get_parsed_url();
                    foreach ( $url_mapping as $mapping ) {
                        if ( is_child_url_of( $parsed_url, $mapping['from_url'] ) ) {
                            $p->replace_base_url( $mapping['to_url'] );
                            break;
                        }
                    }
                }

                return $p->get_updated_html();
                
            case self::PLAIN_TEXT:
                $p = new URLInTextProcessor( $options['content'], $options['base_url'] );
                while ( $p->next_url() ) {
                    $parsed_url = $p->get_parsed_url();
                    foreach ( $url_mapping as $mapping ) {
                        if ( is_child_url_of( $parsed_url, $mapping['from_url'] ) ) {
                            $new_raw_url = WPURL::replace_base_url(
                                $parsed_url,
                                array(
                                    'old_base_url' => $options['base_url'],
                                    'new_base_url' => $mapping['to_url'],
                                    'raw_url'      => $p->get_raw_url(),
                                    'is_relative'  => false,
                                )
                            );

                            $p->set_raw_url( $new_raw_url );
                            break;
                        }
                    }
                }

                return $p->get_updated_text();

            default:
                _doing_it_wrong( __FUNCTION__, 'rewrite_urls() requires either block_markup or plain_text to be provided', '1.0.0' );
                return '';
        }
    }
}
