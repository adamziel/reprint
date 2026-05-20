<?php

use WordPress\DataLiberation\BlockMarkup\BlockMarkupUrlProcessor;
use WordPress\DataLiberation\URL\URLInTextProcessor;
use WordPress\DataLiberation\URL\WPURL;

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
 * 4. Leaf text → wp_rewrite_urls() / URLInTextProcessor, depending on
 *    the content type hint
 *
 * WordPress block markup is auto-detected by its block comment marker. Other
 * HTML is only handled as block markup when the caller explicitly passes
 * content_type='block_markup'. The hint propagates through recursive calls
 * so that leaf strings inside serialized PHP, JSON, or base64 eventually
 * reach wp_rewrite_urls().
 */
class StructuredDataUrlRewriter
{
    const BLOCK_MARKUP = 'block_markup';
    const PLAIN_TEXT = 'plain_text';
    private const REWRITE_RESULT_CACHE_MAX = 4096;

    /** @var string[] Source domains extracted from url_mapping keys, for quick-reject checks. */
    private array $source_domains;

    /**
     * Literal origin rewrites that are safe to apply before the generic text
     * URL scanner. These entries only exist for source mappings whose old base
     * is an HTTP(S) origin with no path/query/fragment.
     *
     * @var list<array{from: string, to: string}>
     */
    private array $plain_text_literal_origin_mappings;

    /** @var string Compact source-origin to target-prefix mappings for the native fast path. */
    private string $native_plain_text_literal_origin_mapping;

    /** @var bool Whether the native plain text literal URL rewrite function is available. */
    private bool $native_plain_text_literal_url_rewriter_available;

    /**
     * Pre-parsed url_mapping grouped by the URL origin fields used by
     * is_child_url_of(): protocol and normalized hostname. Each entry is
     *   [ 'from_url' => <parsed URL>, 'to_url' => <parsed URL>, 'from_pathname' => <decoded pathname> ]
     * where <parsed URL> is whatever WPURL::parse() returns (declared as mixed
     * because WPURL::replace_base_url() accepts either string or parsed object
     * form — we pass the object form for performance).
     *
     * Parsing is pure, deterministic work that used to happen inside
     * rewrite_urls() on every leaf-value call. With N mappings and L leaves
     * that's 2·N·L WPURL::parse() invocations. On a wp.com-shaped dump
     * (N=120, L≈28k) that single loop dominated 94 % of db-apply wall time
     * under WASM PHP. Hoisting it into the constructor collapses it to 2·N,
     * which is effectively free.
     *
     * @var array<string, list<array{from_url: mixed, to_url: mixed, from_pathname: string}>>
     */
    private array $parsed_mapping_by_origin;

    /** @var string Default base_url used by the URL processors (first from-url). */
    private string $base_url;

    /** @var string Cache namespace for this rewriter's URL mapping. */
    private string $mapping_cache_key;

    /** @var array<string, false|array{raw_url: string, parsed_url: mixed}> */
    private array $rewrite_result_cache = [];

    /** @var string[] */
    private array $rewrite_result_cache_ring = [];

    private int $rewrite_result_cache_next = 0;

    /**
     * @param array<string, string> $url_mapping Source URL => target URL mapping.
     */
    public function __construct(array $url_mapping)
    {
        // Extract unique source domains for the quick-reject check. Include
        // both parse_url()'s literal host and WPURL's normalized host so IDN
        // mappings still match punycode URLs stored in the database.
        $domains = [];
        foreach (array_keys($url_mapping) as $from_url) {
            $host = parse_url($from_url, PHP_URL_HOST);
            if ($host !== null && $host !== false) {
                $domains[strtolower($host)] = true;
            }
            $parsed = WPURL::parse($from_url);
            if ($parsed !== false) {
                $normalized_host = $parsed->hostname;
                if ($normalized_host !== '') {
                    $domains[strtolower($normalized_host)] = true;
                }
            }
        }
        $this->source_domains = array_keys($domains);

        // Parse the mapping once. Each WPURL::parse() does non-trivial work
        // (scheme/host/path tokenisation, punycode, etc.) and used to be
        // repeated on every leaf we rewrote.
        $this->parsed_mapping_by_origin = [];
        $this->plain_text_literal_origin_mappings = [];
        $native_plain_text_literal_origin_mappings = [];
        foreach ($url_mapping as $from_url_string => $to_url_string) {
            $from_url = WPURL::parse($from_url_string);
            $mapping = [
                'from_url'      => $from_url,
                'to_url'        => WPURL::parse($to_url_string),
                'from_pathname' => false !== $from_url ? urldecode($from_url->pathname) : '',
            ];
            if (false !== $from_url) {
                $origin_key = $from_url->protocol . "\0" . $from_url->hostname;
                $this->parsed_mapping_by_origin[$origin_key][] = $mapping;
            }

            $literal_mapping = $this->build_plain_text_literal_origin_mapping($from_url_string, $to_url_string);
            if ($literal_mapping !== null) {
                $this->plain_text_literal_origin_mappings[] = $literal_mapping;
                $native_plain_text_literal_origin_mappings[] = $literal_mapping['from'] . "\x1f" . $literal_mapping['to'];
            }
        }
        $this->native_plain_text_literal_origin_mapping = implode("\x1e", $native_plain_text_literal_origin_mappings);
        $this->native_plain_text_literal_url_rewriter_available =
            function_exists('wp_native_apis_rewrite_plain_text_literal_urls') &&
            (!defined('WP_NATIVE_APIS_DISABLE_DEFAULTS') || !WP_NATIVE_APIS_DISABLE_DEFAULTS);
        $this->mapping_cache_key = sha1(json_encode($url_mapping, JSON_UNESCAPED_SLASHES));

        // Default base_url: first from-url in the mapping. Preserves the
        // behaviour of the previous per-call default so outputs are unchanged.
        $from_urls = array_keys($url_mapping);
        $this->base_url = $from_urls[0] ?? '';
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

        // Unknown SQL columns can still contain WordPress block markup. If we
        // see the block comment marker, use the block-markup processor rather
        // than treating comment JSON and HTML attributes as undifferentiated
        // text.
        if ($content_type === self::PLAIN_TEXT && strpos($value, '<!-- wp:') !== false) {
            $content_type = self::BLOCK_MARKUP;
        }

        // Quick-reject: if the value doesn't contain href=", src=", or any
        // source domain, there's nothing to rewrite. This avoids expensive
        // parsing (serialized PHP, JSON, block markup) for the vast majority
        // of values that don't contain any rewritable URLs.
        if (!$this->maybe_contains_rewritable_urls($value)) {
            return $value;
        }

        if ($content_type === self::PLAIN_TEXT) {
            $literal_rewrite = $this->rewrite_plain_text_literal_leaf($value);
            if ($literal_rewrite !== false) {
                return $literal_rewrite;
            }
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

        return $this->rewrite_urls($value, $content_type);
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
            if (stripos($value, $domain) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Rewrite a decoded value already known by the SQL layer to be block markup.
     *
     * Even if the value looks like it contains literal source URLs, this must
     * still go through the block-markup URL processor. URL spellings can vary
     * through escaping, encoding, host normalization, punycode, block comment
     * JSON, and CSS syntax; byte-level replacements are not equivalent to URL
     * rewriting.
     */
    public function rewrite_known_block_markup_value(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (!$this->maybe_contains_rewritable_urls($value)) {
            return $value;
        }

        return $this->rewrite($value, self::BLOCK_MARKUP);
    }

    /**
     * Return whether a decoded value may contain one of the configured source
     * domains. This intentionally checks hosts instead of full source URLs so
     * escaped spellings of `://` in block markup or JSON do not matter.
     */
    public function value_might_contain_source_domain(string $value): bool
    {
        if ($this->source_domains === []) {
            return true;
        }

        foreach ($this->source_domains as $domain) {
            if (stripos($value, $domain) !== false) {
                return true;
            }
        }

        return false;
    }

    private function get_cached_rewrite_result(string $cache_key)
    {
        return array_key_exists($cache_key, $this->rewrite_result_cache)
            ? $this->rewrite_result_cache[$cache_key]
            : null;
    }

    /**
     * @param false|array{raw_url: string, parsed_url: mixed} $value
     */
    private function set_cached_rewrite_result(string $cache_key, $value): void
    {
        if (!array_key_exists($cache_key, $this->rewrite_result_cache)) {
            if (count($this->rewrite_result_cache_ring) < self::REWRITE_RESULT_CACHE_MAX) {
                $this->rewrite_result_cache_ring[] = $cache_key;
            } else {
                $evicted_key = $this->rewrite_result_cache_ring[$this->rewrite_result_cache_next];
                unset($this->rewrite_result_cache[$evicted_key]);
                $this->rewrite_result_cache_ring[$this->rewrite_result_cache_next] = $cache_key;
            }

            $this->rewrite_result_cache_next = ($this->rewrite_result_cache_next + 1) % self::REWRITE_RESULT_CACHE_MAX;
        }

        $this->rewrite_result_cache[$cache_key] = $value;
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
        // $this->parsed_mapping_by_origin is built once in the constructor and reused
        // here on every call, avoiding a fresh round of WPURL::parse() per
        // leaf value.
        $base_url       = $this->base_url;

        switch ( $content_type ) {
            case self::BLOCK_MARKUP:
                // Without a `<` byte there can be no HTML tag or WordPress
                // block-comment opener for BlockMarkupUrlProcessor to own.
                // Treat the value as the leaf text it is instead of running
                // the block-markup parser stack.
                if ( strpos( $content, '<' ) === false ) {
                    return $this->rewrite_urls( $content, self::PLAIN_TEXT );
                }

                $p = new BlockMarkupUrlProcessor( $content, $base_url );
                while ( $p->next_url() ) {
                    $raw_url = $p->get_raw_url();
                    $token_type = $p->get_token_type() ?? '';
                    $cache_key = $this->mapping_cache_key . "\0" . self::BLOCK_MARKUP . "\0" . $token_type . "\0" . $raw_url;
                    $cached = $this->get_cached_rewrite_result($cache_key);
                    if ($cached !== null) {
                        if ($cached !== false) {
                            $p->set_url($cached['raw_url'], $cached['parsed_url']);
                        }
                        continue;
                    }

                    $parsed_url = $p->get_parsed_url();
                    $converted = false;
                    foreach ( $this->get_origin_mappings( $parsed_url ) as $mapping ) {
                        if ( $this->parsed_url_is_child_of_mapping( $parsed_url, $mapping ) ) {
                            $converted = WPURL::replace_base_url(
                                $parsed_url,
                                array(
                                    'old_base_url' => $base_url,
                                    'new_base_url' => $mapping['to_url'],
                                    'raw_url'      => $raw_url,
                                    'is_relative'  => (
                                        '#text' !== $token_type &&
                                        ! WPURL::can_parse($raw_url)
                                    ),
                                )
                            );
                            break;
                        }
                    }

                    $cache_value = false;
                    if ($converted !== false) {
                        $cache_value = [
                            'raw_url'    => (string) $converted,
                            'parsed_url' => $converted->new_url,
                        ];
                        $p->set_url($cache_value['raw_url'], $cache_value['parsed_url']);
                    }
                    $this->set_cached_rewrite_result($cache_key, $cache_value);
                }

                return $p->get_updated_html();

            case self::PLAIN_TEXT:
                $literal_rewrite = $this->rewrite_plain_text_literal_leaf($content);
                if ($literal_rewrite !== false) {
                    return $literal_rewrite;
                }

                $p = new URLInTextProcessor( $content, $base_url );
                while ( $p->next_url() ) {
                    $raw_url = $p->get_raw_url();
                    $cache_key = $this->mapping_cache_key . "\0" . self::PLAIN_TEXT . "\0" . $raw_url;
                    $cached = $this->get_cached_rewrite_result($cache_key);
                    if ($cached !== null) {
                        if ($cached !== false) {
                            $p->set_raw_url($cached['raw_url']);
                        }
                        continue;
                    }

                    $parsed_url = $p->get_parsed_url();
                    $converted = false;
                    foreach ( $this->get_origin_mappings( $parsed_url ) as $mapping ) {
                        if ( $this->parsed_url_is_child_of_mapping( $parsed_url, $mapping ) ) {
                            $converted = WPURL::replace_base_url(
                                $parsed_url,
                                array(
                                    'old_base_url' => $mapping['from_url'],
                                    'new_base_url' => $mapping['to_url'],
                                    'raw_url'      => $raw_url,
                                    'is_relative'  => false,
                                )
                            );
                            break;
                        }
                    }

                    $cache_value = false;
                    if ($converted !== false) {
                        $cache_value = [
                            'raw_url'    => (string) $converted,
                            'parsed_url' => $converted->new_url,
                        ];
                        $p->set_raw_url($cache_value['raw_url']);
                    }
                    $this->set_cached_rewrite_result($cache_key, $cache_value);
                }

                return $p->get_updated_text();

            default:
                _doing_it_wrong( __FUNCTION__, 'rewrite_urls() requires either block_markup or plain_text to be provided', '1.0.0' );
                return '';
        }
    }

    /**
     * @return list<array{from_url: mixed, to_url: mixed, from_pathname: string}>
     */
    private function get_origin_mappings( $parsed_url ): array
    {
        if ( false === $parsed_url ) {
            return [];
        }

        $origin_key = $parsed_url->protocol . "\0" . $parsed_url->hostname;
        return $this->parsed_mapping_by_origin[$origin_key] ?? [];
    }

    /**
     * Equivalent to is_child_url_of() for pre-parsed, same-origin URLs.
     *
     * @param array{from_url: mixed, to_url: mixed, from_pathname: string} $mapping
     */
    private function parsed_url_is_child_of_mapping( $child, array $mapping ): bool
    {
        if ( false === $child || false === $mapping['from_url'] ) {
            return false;
        }

        $child_pathname_no_trailing_slash = rtrim( urldecode( $child->pathname ), '/' );
        $parent_pathname = $mapping['from_pathname'];

        return (
            $parent_pathname === $child_pathname_no_trailing_slash ||
            $parent_pathname === $child_pathname_no_trailing_slash . '/' ||
            0 === strncmp( $child_pathname_no_trailing_slash . '/', $parent_pathname, strlen( $parent_pathname ) )
        );
    }

    /**
     * Try the conservative plain leaf literal-origin fast path.
     *
     * This is safe to call before column-hint resolution. It only rewrites
     * values that look like unstructured plain text; values containing syntax
     * delimiters used by JSON, serialized PHP strings, HTML, block markup,
     * shortcodes, CSS, Markdown links, or escaped URLs return false and must
     * continue through the normal parser-owned path.
     *
     * @return false|string false when the generic parser path must handle it.
     */
    public function rewrite_plain_text_literal_leaf(string $content)
    {
        $native_rewrite = $this->try_native_rewrite_plain_text_literal_urls($content);
        if ($native_rewrite !== false) {
            return $native_rewrite;
        }

        return $this->try_rewrite_plain_text_literal_origins($content);
    }

    /**
     * Try native plain text literal source-origin rewriting.
     *
     * The native primitive has the same rigor as the PHP path below: it only
     * rewrites known plain text leaves using pre-normalized HTTP(S)
     * source-origin mappings, and returns false when the generic parser-owned
     * path must handle the value.
     *
     * @return false|string false when the generic URL processor must handle it.
     */
    private function try_native_rewrite_plain_text_literal_urls(string $content)
    {
        if (
            !$this->native_plain_text_literal_url_rewriter_available ||
            $this->native_plain_text_literal_origin_mapping === ''
        ) {
            return false;
        }

        $rewritten = \wp_native_apis_rewrite_plain_text_literal_urls(
            $content,
            $this->native_plain_text_literal_origin_mapping
        );

        return is_string($rewritten) ? $rewritten : false;
    }

    /**
     * Build one literal-origin rewrite entry for the conservative plain-text
     * fast path.
     *
     * The fast path is intentionally narrower than full URL rewriting:
     * source URLs must be plain HTTP(S) origins, and target URLs may only add
     * a path prefix. Path-bearing source mappings still go through WPURL so
     * path-prefix, escaping, and normalization semantics stay parser-owned.
     *
     * @return array{from: string, to: string}|null
     */
    private function build_plain_text_literal_origin_mapping(string $from_url, string $to_url): ?array
    {
        if (
            preg_match('/[^\x00-\x7F]/', $from_url) ||
            preg_match('/[^\x00-\x7F]/', $to_url)
        ) {
            return null;
        }

        $from = parse_url($from_url);
        $to = parse_url($to_url);
        if (!is_array($from) || !is_array($to)) {
            return null;
        }

        if (
            !isset($from['scheme'], $from['host'], $to['scheme'], $to['host']) ||
            !in_array(strtolower($from['scheme']), ['http', 'https'], true) ||
            !in_array(strtolower($to['scheme']), ['http', 'https'], true) ||
            isset($from['user'], $from['pass'], $from['query'], $from['fragment']) ||
            isset($to['user'], $to['pass'], $to['query'], $to['fragment'])
        ) {
            return null;
        }

        $from_path = $from['path'] ?? '';
        if ($from_path !== '' && $from_path !== '/') {
            return null;
        }

        $from_origin = strtolower($from['scheme']) . '://' . strtolower($from['host']);
        if (isset($from['port'])) {
            $from_origin .= ':' . $from['port'];
        }

        $to_prefix = strtolower($to['scheme']) . '://' . strtolower($to['host']);
        if (isset($to['port'])) {
            $to_prefix .= ':' . $to['port'];
        }
        $to_path = $to['path'] ?? '';
        if ($to_path !== '' && $to_path !== '/') {
            $to_prefix .= '/' . trim($to_path, '/');
        }

        return [
            'from' => $from_origin,
            'to'   => $to_prefix,
        ];
    }

    /**
     * Rewrite simple literal source-origin URLs in plain leaf text.
     *
     * This is a happy-path shortcut, not a replacement URL parser. It only
     * fires when the value has no obvious structured container delimiters, the
     * source URL starts at the beginning of the value or after whitespace, and
     * the byte after the source origin can only continue the same URL as a path,
     * query, or fragment. Anything ambiguous returns false and falls back to the
     * normal parser-owned path.
     *
     * @return false|string false when the generic URL processor must handle it.
     */
    private function try_rewrite_plain_text_literal_origins(string $content)
    {
        if (
            $this->plain_text_literal_origin_mappings === [] ||
            strpbrk($content, "<>\"'\\{}[]()") !== false
        ) {
            return false;
        }

        $replacements = [];
        foreach ($this->plain_text_literal_origin_mappings as $mapping) {
            $from = $mapping['from'];
            $from_length = strlen($from);
            $offset = 0;

            while (true) {
                $position = strpos($content, $from, $offset);
                if ($position === false) {
                    break;
                }

                if (
                    !$this->plain_text_literal_origin_has_valid_left_boundary($content, $position) ||
                    !$this->plain_text_literal_origin_has_valid_right_boundary($content, $position + $from_length)
                ) {
                    return false;
                }

                $replacements[] = [$position, $from_length, $mapping['to']];
                $offset = $position + $from_length;
            }
        }

        if ($replacements === []) {
            return false;
        }

        usort(
            $replacements,
            static fn(array $a, array $b): int => $a[0] <=> $b[0]
        );

        $rewritten = '';
        $cursor = 0;
        foreach ($replacements as $replacement) {
            [$position, $length, $to] = $replacement;
            if ($position < $cursor) {
                return false;
            }

            $rewritten .= substr($content, $cursor, $position - $cursor);
            $rewritten .= $to;
            $cursor = $position + $length;
        }
        $rewritten .= substr($content, $cursor);

        return $rewritten;
    }

    private function plain_text_literal_origin_has_valid_left_boundary(string $content, int $position): bool
    {
        if ($position === 0) {
            return true;
        }

        return ctype_space($content[$position - 1]);
    }

    private function plain_text_literal_origin_has_valid_right_boundary(string $content, int $position): bool
    {
        if ($position >= strlen($content)) {
            return true;
        }

        return $content[$position] === '/' || $content[$position] === '?' || $content[$position] === '#';
    }
}
