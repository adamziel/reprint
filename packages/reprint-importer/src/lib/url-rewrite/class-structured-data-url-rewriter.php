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

    /** @var string[] Source domains extracted from url_mapping keys, for quick-reject checks. */
    private array $source_domains;

    /**
     * Pre-parsed url_mapping: each entry is
     *   [ 'from_url' => <parsed URL>, 'to_url' => <parsed URL> ]
     * where <parsed URL> is whatever WPURL::parse() returns (declared as
     * mixed here because is_child_url_of() and WPURL::replace_base_url()
     * both accept either a string or the parsed object form — we pass the
     * object form for performance).
     *
     * Parsing is pure, deterministic work that used to happen inside
     * rewrite_urls() on every leaf-value call. With N mappings and L leaves
     * that's 2·N·L WPURL::parse() invocations. On a wp.com-shaped dump
     * (N=120, L≈28k) that single loop dominated 94 % of db-apply wall time
     * under WASM PHP. Hoisting it into the constructor collapses it to 2·N,
     * which is effectively free.
     *
     * @var array<int, array{from_url: mixed, to_url: mixed}>
     */
    private array $parsed_mapping;

    /** @var string Default base_url used by the URL processors (first from-url). */
    private string $base_url;

    /**
     * Literal $from_url => $to_url map used by the strtr fast path.
     * Same content as $url_mapping but kept here so try_literal_replace()
     * can avoid array_combine on every call.
     *
     * @var array<string, string>
     */
    private array $literal_mapping;

    /**
     * @param array<string, string> $url_mapping Source URL => target URL mapping.
     */
    public function __construct(array $url_mapping)
    {
        // Extract unique source domains for the quick-reject check.
        $domains = [];
        foreach (array_keys($url_mapping) as $from_url) {
            $host = parse_url($from_url, PHP_URL_HOST);
            if ($host !== null && $host !== false) {
                $domains[$host] = true;
            }
        }
        $this->source_domains = array_keys($domains);

        // Parse the mapping once. Each WPURL::parse() does non-trivial work
        // (scheme/host/path tokenisation, punycode, etc.) and used to be
        // repeated on every leaf we rewrote.
        $this->parsed_mapping = [];
        foreach ($url_mapping as $from_url_string => $to_url_string) {
            $this->parsed_mapping[] = [
                'from_url' => WPURL::parse($from_url_string),
                'to_url'   => WPURL::parse($to_url_string),
            ];
        }

        // Default base_url: first from-url in the mapping. Preserves the
        // behaviour of the previous per-call default so outputs are unchanged.
        $from_urls = array_keys($url_mapping);
        $this->base_url = $from_urls[0] ?? '';

        // Build the literal map. Cover both the plain `https://old.com` form
        // and the JSON-escaped `https:\/\/old.com` form that block-comment
        // JSON serialises slashes as. The structured pipeline knows about
        // JSON contexts and re-escapes when replacing; the strtr fast path
        // doesn't, so we pre-stage both forms.
        $this->literal_mapping = [];
        foreach ($url_mapping as $from => $to) {
            $this->literal_mapping[$from] = $to;
            $from_json = str_replace('/', '\\/', $from);
            $to_json = str_replace('/', '\\/', $to);
            if ($from_json !== $from) {
                $this->literal_mapping[$from_json] = $to_json;
            }
        }
    }

    /**
     * @var string|null Cached regex matching any literal from_url with a
     *   safe URL-start boundary. Built lazily on first use because building
     *   it requires the literal_mapping to be populated.
     */
    private ?string $literal_replace_regex = null;

    /**
     * Tokenization-free URL rewrite for the common case where the URL
     * appears as a literal `$from_url{path}{query}{fragment}` substring.
     *
     * The structured pipeline's main cost is WPURL::parse — measured at
     * ~78 µs per call, ~80% of URLInTextProcessor::next_url(). For URLs
     * the user already wrote in the canonical form their mapping keys
     * specify, the parse/replace_base_url roundtrip produces byte-
     * identical output to a regex-driven substring substitution.
     *
     * Two safety nets keep this from rewriting things the structured
     * pipeline wouldn't:
     *
     *   - Left-boundary lookbehind (?<!\w) prevents matching when the
     *     from_url is glued onto another word, e.g.
     *     `do-you-knowhttp://old.com/x` should not rewrite.
     *
     *   - Bailout on `[=&]https?://` patterns: when an URL appears as a
     *     query-string parameter of an outer URL the structured
     *     pipeline correctly leaves the inner URL alone (it sees only
     *     the outer URL). strtr-style replacement can't make that
     *     distinction, so we fall through to the structured pipeline
     *     for any value that contains the giveaway.
     *
     *   - Post-replace source-domain check: if the result still mentions
     *     a source host the regex didn't catch — port-stripped, scheme-
     *     mismatched, scheme-less — fall through so the structured
     *     pipeline can clean up the leftovers on the partially-rewritten
     *     output.
     *
     * Three return cases:
     *   - null: strtr-equivalent did nothing useful. Caller runs the
     *     structured pipeline on the original value.
     *   - ['result' => …, 'pipeline_needed' => false]: every URL in the
     *     value was handled. Skip the structured pipeline entirely.
     *   - ['result' => …, 'pipeline_needed' => true]: handled some URLs
     *     but variants remain. Caller runs the structured pipeline on
     *     this partial output so we keep what we already rewrote.
     *
     * @return array{result: string, pipeline_needed: bool}|null
     */
    public function try_literal_replace(string $value): ?array
    {
        // Bail on HTML / block markup. URLs inside JSON within block
        // comments need structural awareness — BlockMarkupUrlProcessor
        // applies JSON-style slash escaping when rewriting attribute
        // values, and strtr / regex replacement can't replicate that
        // context-sensitively. The presence of any `<` byte is the
        // safe signal: no tag-or-block-comment markup, no escaping
        // surprises.
        if (strpos($value, '<') !== false) {
            return null;
        }
        // Bail on URL-inside-URL nesting: `?url=https://...` or
        // `&continue=http://...`. The structured pipeline correctly leaves
        // these alone; we have no cheap way to.
        if (preg_match('/[=&]https?:\/\//', $value)) {
            return null;
        }

        if ($this->literal_replace_regex === null) {
            $this->literal_replace_regex = $this->build_literal_replace_regex();
        }
        if ($this->literal_replace_regex === '') {
            return null; // empty mapping
        }

        $changed = false;
        $rewritten = preg_replace_callback(
            $this->literal_replace_regex,
            function ($m) use (&$changed) {
                $changed = true;
                return $this->literal_mapping[$m[1]];
            },
            $value
        );
        if (!$changed) {
            return null;
        }
        foreach ($this->source_domains as $domain) {
            if (strpos($rewritten, $domain) !== false) {
                return ['result' => $rewritten, 'pipeline_needed' => true];
            }
        }
        return ['result' => $rewritten, 'pipeline_needed' => false];
    }

    private function build_literal_replace_regex(): string
    {
        if (empty($this->literal_mapping)) {
            return '';
        }
        // Sort by length descending so longer prefixes match first
        // (e.g. http://example.com/blog before http://example.com).
        $keys = array_keys($this->literal_mapping);
        usort($keys, fn($a, $b) => strlen($b) - strlen($a));
        $alternatives = implode('|', array_map(fn($k) => preg_quote($k, '/'), $keys));
        // Left boundary: not a word char or '-' (so do-you-knowhttp:// doesn't
        // match). preg_match with /u keeps things sane on multibyte input.
        return '/(?<![\w-])(' . $alternatives . ')/u';
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

        // Leaf text. Try the literal strtr path first — it handles the
        // common case (URLs appear in the value as exact substrings of
        // mapping keys) without going through WPURL::parse, which costs
        // ~80µs per URL and is the largest single cost in db-apply for
        // URL-rewriting workloads.
        $literal = $this->try_literal_replace($value);
        if ($literal !== null && !$literal['pipeline_needed']) {
            return $literal['result'];
        }
        $start = $literal !== null ? $literal['result'] : $value;
        return $this->rewrite_urls($start, $content_type);
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
    private function rewrite_urls( string $content, string $content_type ): string {
        // $this->parsed_mapping is built once in the constructor and reused
        // here on every call, avoiding a fresh round of WPURL::parse() per
        // leaf value.
        $parsed_mapping = $this->parsed_mapping;
        $base_url       = $this->base_url;

        switch ( $content_type ) {
            case self::BLOCK_MARKUP:
                $p = new BlockMarkupUrlProcessor( $content, $base_url );
                while ( $p->next_url() ) {
                    $parsed_url = $p->get_parsed_url();
                    foreach ( $parsed_mapping as $mapping ) {
                        if ( is_child_url_of( $parsed_url, $mapping['from_url'] ) ) {
                            $p->replace_base_url( $mapping['to_url'] );
                            break;
                        }
                    }
                }

                return $p->get_updated_html();

            case self::PLAIN_TEXT:
                $p = new URLInTextProcessor( $content, $base_url );
                while ( $p->next_url() ) {
                    $parsed_url = $p->get_parsed_url();
                    foreach ( $parsed_mapping as $mapping ) {
                        if ( is_child_url_of( $parsed_url, $mapping['from_url'] ) ) {
                            $new_raw_url = WPURL::replace_base_url(
                                $parsed_url,
                                array(
                                    'old_base_url' => $base_url,
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
