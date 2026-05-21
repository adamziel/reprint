<?php

use WordPress\DataLiberation\BlockMarkup\BlockMarkupUrlProcessor;
use WordPress\DataLiberation\URL\URLInTextProcessor;
use WordPress\DataLiberation\URL\WPURL;

use function WordPress\DataLiberation\URL\is_child_url_of;
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
 * 4. Leaf text → BlockMarkupUrlProcessor (block_markup hint) or
 *    URLInTextProcessor (default)
 *
 * HTML is never auto-detected — the caller must explicitly pass
 * content_type='block_markup' for values known to contain HTML/block markup.
 * The hint propagates through recursive calls so that leaf strings inside
 * serialized PHP, JSON, or base64 eventually reach the same block-markup
 * parser.
 */
class StructuredDataUrlRewriter
{
    const BLOCK_MARKUP = 'block_markup';
    const PLAIN_TEXT = 'plain_text';
    private const REWRITE_RESULT_CACHE_MAX = 4096;
    private const VALUE_REWRITE_CACHE_MAX = 1024;

    /** @var string[] Source domains extracted from url_mapping keys, for quick-reject checks. */
    private array $source_domains;

    /** @var array<int, array{from_url: mixed, to_url: mixed}> Pre-parsed URL mappings in caller-provided order. */
    private array $parsed_mapping;

    /** @var string Default base_url used by the URL processors (first from-url). */
    private string $base_url;

    /** @var string Cache namespace for this rewriter's URL mapping. */
    private string $mapping_cache_key;

    /** @var array<string, false|array{raw_url: string, parsed_url: mixed}> */
    private array $rewrite_result_cache = [];

    /** @var string[] */
    private array $rewrite_result_cache_ring = [];

    private int $rewrite_result_cache_next = 0;

    /** @var array<string, array{content_type: string, input: string, output: string}> */
    private array $value_rewrite_cache = [];

    /** @var string[] */
    private array $value_rewrite_cache_ring = [];

    private int $value_rewrite_cache_next = 0;

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
                $this->add_source_domain_variants($domains, $host);
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
     *                                  'block_markup' (use BlockMarkupUrlProcessor), or 'skip' (no-op).
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
        if (stripos($value, 'href=') !== false || stripos($value, 'src=') !== false) {
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
     * @param array<string, true> $domains
     */
    private function add_source_domain_variants(array &$domains, string $host): void
    {
        if ($host === '') {
            return;
        }

        $domains[$host] = true;

        if (function_exists('idn_to_ascii')) {
            $ascii = defined('INTL_IDNA_VARIANT_UTS46')
                ? @idn_to_ascii($host, 0, INTL_IDNA_VARIANT_UTS46)
                : @idn_to_ascii($host);
            if (is_string($ascii) && $ascii !== '') {
                $domains[$ascii] = true;
            }
        }

        if (function_exists('idn_to_utf8')) {
            $unicode = defined('INTL_IDNA_VARIANT_UTS46')
                ? @idn_to_utf8($host, 0, INTL_IDNA_VARIANT_UTS46)
                : @idn_to_utf8($host);
            if (is_string($unicode) && $unicode !== '') {
                $domains[$unicode] = true;
            }
        }
    }

    /**
     * Rewrite a decoded value already known by the SQL layer to be block markup.
     *
     * Block markup owns HTML attributes, block-comment JSON, CSS url() values,
     * text URLs, entity decoding, URL casing, and IDN canonicalization. This
     * intentionally routes through the structured parser instead of doing a
     * literal source-base replacement, because one database value may contain
     * multiple spellings of the same URL that only the parser can recognize as
     * equivalent.
     */
    public function rewrite_known_block_markup_value(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (!$this->maybe_contains_rewritable_urls($value)) {
            return $value;
        }

        $looks_like_structured_envelope = $this->looks_like_structured_envelope($value);

        if (
            !$looks_like_structured_envelope &&
            !$this->contains_raw_text_element($value) &&
            $this->source_domain_occurrences_are_outside_markup($value)
        ) {
            if (strpos($value, '<') === false) {
                return $this->rewrite_urls($value, self::PLAIN_TEXT);
            }

            $cache_key = $this->get_value_rewrite_cache_key(self::BLOCK_MARKUP, $value);
            $cached = $this->get_cached_value_rewrite($cache_key, self::BLOCK_MARKUP, $value);
            if ($cached !== null) {
                return $cached;
            }

            $rewritten = $this->rewrite_urls($value, self::PLAIN_TEXT);
            $this->set_cached_value_rewrite($cache_key, self::BLOCK_MARKUP, $value, $rewritten);
            return $rewritten;
        }

        return $looks_like_structured_envelope
            ? $this->rewrite($value, self::BLOCK_MARKUP)
            : $this->rewrite_urls($value, self::BLOCK_MARKUP);
    }

    private function contains_raw_text_element(string $value): bool
    {
        return stripos($value, '<script') !== false
            || stripos($value, '<style') !== false
            || stripos($value, '<textarea') !== false
            || stripos($value, '<title') !== false;
    }

    private function looks_like_structured_envelope(string $value): bool
    {
        return preg_match('/^\s*(?:[aOsbidNCR]:|N;|[\\[{"])/', $value) === 1;
    }

    /**
     * Return true when every configured source-domain occurrence is outside
     * HTML tag/comment markup, so block-markup rewriting can safely reduce to
     * text-node URL rewriting.
     */
    private function source_domain_occurrences_are_outside_markup(string $value): bool
    {
        if (strpos($value, '<') === false) {
            return true;
        }

        if (
            stripos($value, 'href=') !== false ||
            stripos($value, 'src=') !== false ||
            stripos($value, 'srcset=') !== false ||
            stripos($value, 'poster=') !== false ||
            stripos($value, 'style=') !== false ||
            stripos($value, 'url(') !== false
        ) {
            return false;
        }

        $source_domain_offsets = [];
        foreach ($this->source_domains as $domain) {
            $offset = 0;
            while (false !== ($found = stripos($value, $domain, $offset))) {
                $source_domain_offsets[] = $found;
                $offset = $found + strlen($domain);
            }
        }

        if ($source_domain_offsets === []) {
            return false;
        }

        sort($source_domain_offsets);
        $next_source_domain = 0;
        $source_domain_count = count($source_domain_offsets);
        $length = strlen($value);
        $in_markup = false;
        $quote = null;

        for ($i = 0; $i < $length && $next_source_domain < $source_domain_count; $i++) {
            while (
                $next_source_domain < $source_domain_count &&
                $source_domain_offsets[$next_source_domain] === $i
            ) {
                if ($in_markup) {
                    return false;
                }
                $next_source_domain++;
            }

            $char = $value[$i];
            if ($in_markup) {
                if ($quote !== null) {
                    if ($char === $quote) {
                        $quote = null;
                    }
                    continue;
                }

                if ($char === '"' || $char === "'") {
                    $quote = $char;
                    continue;
                }

                if ($char === '>') {
                    $in_markup = false;
                }
                continue;
            }

            if ($char === '<') {
                $in_markup = true;
            }
        }

        return true;
    }

    /**
     * Return true when every configured source-domain occurrence is inside
     * HTML tag/comment markup, so block-markup rewriting can skip text-node
     * URL scanning while still using structured setters for tags and blocks.
     */
    private function source_domain_occurrences_are_inside_markup(string $value): bool
    {
        if (strpos($value, '<') === false) {
            return false;
        }

        $source_domain_offsets = [];
        foreach ($this->source_domains as $domain) {
            $offset = 0;
            while (false !== ($found = stripos($value, $domain, $offset))) {
                $source_domain_offsets[] = $found;
                $offset = $found + strlen($domain);
            }
        }

        if ($source_domain_offsets === []) {
            return false;
        }

        sort($source_domain_offsets);
        $next_source_domain = 0;
        $source_domain_count = count($source_domain_offsets);
        $length = strlen($value);
        $in_markup = false;
        $quote = null;

        for ($i = 0; $i < $length && $next_source_domain < $source_domain_count; $i++) {
            while (
                $next_source_domain < $source_domain_count &&
                $source_domain_offsets[$next_source_domain] === $i
            ) {
                if (!$in_markup) {
                    return false;
                }
                $next_source_domain++;
            }

            $char = $value[$i];
            if ($in_markup) {
                if ($quote !== null) {
                    if ($char === $quote) {
                        $quote = null;
                    }
                    continue;
                }

                if ($char === '"' || $char === "'") {
                    $quote = $char;
                    continue;
                }

                if ($char === '>') {
                    $in_markup = false;
                }
                continue;
            }

            if ($char === '<') {
                $in_markup = true;
            }
        }

        return true;
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

    private function get_value_rewrite_cache_key(string $content_type, string $value): string
    {
        return $content_type . "\0" . strlen($value) . "\0" . sha1($value);
    }

    private function get_cached_value_rewrite(string $cache_key, string $content_type, string $value): ?string
    {
        if (!array_key_exists($cache_key, $this->value_rewrite_cache)) {
            return null;
        }

        $entry = $this->value_rewrite_cache[$cache_key];
        if ($entry['content_type'] !== $content_type || $entry['input'] !== $value) {
            return null;
        }

        return $entry['output'];
    }

    private function set_cached_value_rewrite(string $cache_key, string $content_type, string $input, string $output): void
    {
        if (!array_key_exists($cache_key, $this->value_rewrite_cache)) {
            if (count($this->value_rewrite_cache_ring) < self::VALUE_REWRITE_CACHE_MAX) {
                $this->value_rewrite_cache_ring[] = $cache_key;
            } else {
                $evicted_key = $this->value_rewrite_cache_ring[$this->value_rewrite_cache_next];
                unset($this->value_rewrite_cache[$evicted_key]);
                $this->value_rewrite_cache_ring[$this->value_rewrite_cache_next] = $cache_key;
            }

            $this->value_rewrite_cache_next = ($this->value_rewrite_cache_next + 1) % self::VALUE_REWRITE_CACHE_MAX;
        }

        $this->value_rewrite_cache[$cache_key] = [
            'content_type' => $content_type,
            'input'       => $input,
            'output'      => $output,
        ];
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
        $parsed_mapping = $this->parsed_mapping;
        $base_url       = $this->base_url;

        switch ( $content_type ) {
            case self::BLOCK_MARKUP:
                $p = new BlockMarkupUrlProcessor( $content, $base_url );
                $scan_text_nodes = !$this->source_domain_occurrences_are_inside_markup($content);
                if ($scan_text_nodes) {
                    while ( $p->next_url() ) {
                        $this->rewrite_current_block_markup_url($p, $parsed_mapping, $base_url);
                    }
                } else {
                    while ($p->next_token()) {
                        if ($p->get_token_type() === '#text') {
                            continue;
                        }

                        while ($p->next_url_in_current_token()) {
                            $this->rewrite_current_block_markup_url($p, $parsed_mapping, $base_url);
                        }
                    }
                }

                return $p->get_updated_html();

            case self::PLAIN_TEXT:
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
                    foreach ( $parsed_mapping as $mapping ) {
                        if ( is_child_url_of( $parsed_url, $mapping['from_url'] ) ) {
                            $converted = WPURL::replace_base_url(
                                $parsed_url,
                                array(
                                    'old_base_url' => $base_url,
                                    'new_base_url' => $mapping['to_url'],
                                    'raw_url'      => $p->get_raw_url(),
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
     * @param array<int, array{from_url: mixed, to_url: mixed}> $parsed_mapping
     */
    private function rewrite_current_block_markup_url(BlockMarkupUrlProcessor $p, array $parsed_mapping, string $base_url): void
    {
        $raw_url = $p->get_raw_url();
        $token_type = $p->get_token_type() ?? '';
        $cache_key = $this->mapping_cache_key . "\0" . self::BLOCK_MARKUP . "\0" . $token_type . "\0" . $raw_url;
        $cached = $this->get_cached_rewrite_result($cache_key);
        if ($cached !== null) {
            if ($cached !== false) {
                $p->set_url($cached['raw_url'], $cached['parsed_url']);
            }
            return;
        }

        $parsed_url = $p->get_parsed_url();
        $converted = false;
        foreach ( $parsed_mapping as $mapping ) {
            if ( is_child_url_of( $parsed_url, $mapping['from_url'] ) ) {
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
}
