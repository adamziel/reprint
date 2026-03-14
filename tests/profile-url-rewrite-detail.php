<?php
/**
 * Deep-dive profiler for the URL rewriting pipeline.
 *
 * Breaks down StructuredDataUrlRewriter::rewrite() into its sub-components:
 * - PhpSerializationProcessor (try-and-fail)
 * - JsonStringIterator (try-and-fail)
 * - base64_decode attempt
 * - Leaf rewriting: BlockMarkupUrlProcessor vs URLInTextProcessor
 *
 * Also tracks: how many values don't contain URLs at all, how many go through
 * each format detection path, and where early exits could save time.
 *
 * Usage: php tests/profile-url-rewrite-detail.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../importer/lib/url-rewrite/load.php';

use WordPress\DataLiberation\BlockMarkup\BlockMarkupUrlProcessor;
use WordPress\DataLiberation\URL\URLInTextProcessor;
use WordPress\DataLiberation\URL\WPURL;
use function WordPress\DataLiberation\URL\is_child_url_of;

// ─── Configuration ───────────────────────────────────────────────────────────

$URL_MAPPING = [
    'https://old-site.example.com' => 'https://new-site.example.com',
    'https://cdn.old-site.example.com' => 'https://cdn.new-site.example.com',
];
$TABLE_PREFIX = 'wp_';
$TARGET_SQL_SIZE = 1 * 1024 * 1024;

// ─── Profiling StructuredDataUrlRewriter ─────────────────────────────────────

/**
 * Instrumented version of StructuredDataUrlRewriter that tracks time spent
 * in every sub-component.
 */
class ProfilingStructuredDataUrlRewriter
{
    private array $url_mapping;

    // Timing accumulators
    public float $time_php_serialize_try = 0;   // PhpSerializationProcessor construction + is_malformed
    public float $time_php_serialize_iter = 0;  // iterating PHP serialized values
    public float $time_json_try = 0;            // JsonStringIterator construction + is_malformed
    public float $time_json_iter = 0;           // iterating JSON values
    public float $time_base64_try = 0;          // base64_decode attempts
    public float $time_block_markup = 0;        // BlockMarkupUrlProcessor
    public float $time_plain_text = 0;          // URLInTextProcessor (strtr path)
    public float $time_url_parse = 0;           // WPURL::parse in rewrite_urls

    // Counters
    public int $calls_total = 0;
    public int $calls_empty = 0;
    public int $calls_php_serialized = 0;
    public int $calls_json = 0;
    public int $calls_base64 = 0;
    public int $calls_leaf_block_markup = 0;
    public int $calls_leaf_plain_text = 0;
    public int $values_changed = 0;
    public int $values_unchanged = 0;

    // Track how many values contain no source domain at all
    public int $values_no_source_domain = 0;

    // Track the "wasted" format detection attempts
    public int $php_serialize_false_positives = 0; // was PHP serialized but contained no URLs
    public int $json_false_positives = 0;
    public int $base64_false_positives = 0;

    // Per-value type timing
    public float $time_on_values_without_source_domain = 0;

    public function __construct(array $url_mapping)
    {
        $this->url_mapping = $url_mapping;
    }

    public function rewrite(string $value, ?string $content_type = null): string
    {
        $this->calls_total++;
        $call_start = microtime(true);

        if ($value === '') {
            $this->calls_empty++;
            return $value;
        }

        if ($content_type === 'skip') {
            return $value;
        }

        if ($content_type === null) {
            $content_type = 'plain_text';
        }

        // Check if value contains any source domain at all (substring check)
        $contains_source_domain = false;
        foreach ($this->url_mapping as $from => $to) {
            // Extract domain from URL for substring check
            $parsed = parse_url($from);
            $domain = $parsed['host'] ?? '';
            if ($domain !== '' && strpos($value, $domain) !== false) {
                $contains_source_domain = true;
                break;
            }
        }

        $original_value = $value;

        // Try PHP serialization
        $t0 = microtime(true);
        $p = new PhpSerializationProcessor($value);
        $is_php = !$p->is_malformed();
        $this->time_php_serialize_try += microtime(true) - $t0;

        if ($is_php) {
            $this->calls_php_serialized++;
            $t0 = microtime(true);
            $changed = false;
            while ($p->next_value()) {
                $orig = $p->get_value();
                $rewritten = $this->rewrite($orig, $content_type);
                if ($rewritten !== $orig) {
                    $p->set_value($rewritten);
                    $changed = true;
                }
            }
            $result = $p->get_updated_serialization();
            $this->time_php_serialize_iter += microtime(true) - $t0;
            if (!$changed) $this->php_serialize_false_positives++;

            if (!$contains_source_domain) {
                $this->time_on_values_without_source_domain += microtime(true) - $call_start;
            }
            return $result;
        }

        // Try JSON
        $t0 = microtime(true);
        $iter = new JsonStringIterator($value);
        $is_json = !$iter->is_malformed();
        $this->time_json_try += microtime(true) - $t0;

        if ($is_json) {
            $this->calls_json++;
            $t0 = microtime(true);
            $changed = false;
            while ($iter->next_value()) {
                $orig = $iter->get_value();
                $rewritten = $this->rewrite($orig, $content_type);
                if ($rewritten !== $orig) {
                    $iter->set_value($rewritten);
                    $changed = true;
                }
            }
            $result = $iter->get_result();
            $this->time_json_iter += microtime(true) - $t0;
            if (!$changed) $this->json_false_positives++;

            if (!$contains_source_domain) {
                $this->time_on_values_without_source_domain += microtime(true) - $call_start;
            }
            return $result;
        }

        // Try base64
        $t0 = microtime(true);
        $decoded = base64_decode($value, true);
        $this->time_base64_try += microtime(true) - $t0;

        if ($decoded !== false && $decoded !== '') {
            $this->calls_base64++;
            $rewritten = $this->rewrite($decoded, $content_type);
            if ($rewritten !== $decoded) {
                if (!$contains_source_domain) {
                    $this->time_on_values_without_source_domain += microtime(true) - $call_start;
                }
                return base64_encode($rewritten);
            }
            $this->base64_false_positives++;
        }

        // Leaf: block_markup or plain_text
        $result = $this->rewrite_urls_profiled($value, $content_type);

        if ($result === $original_value) {
            $this->values_unchanged++;
        } else {
            $this->values_changed++;
        }

        if (!$contains_source_domain) {
            $this->values_no_source_domain++;
            $this->time_on_values_without_source_domain += microtime(true) - $call_start;
        }

        return $result;
    }

    private function rewrite_urls_profiled(string $content, string $content_type): string
    {
        $t0 = microtime(true);
        $from_urls = array_keys($this->url_mapping);
        $base_url = $from_urls[0];

        $url_mapping_parsed = [];
        foreach ($this->url_mapping as $from => $to) {
            $url_mapping_parsed[] = [
                'from_url' => WPURL::parse($from),
                'to_url'   => WPURL::parse($to),
            ];
        }
        $this->time_url_parse += microtime(true) - $t0;

        if ($content_type === 'block_markup') {
            $this->calls_leaf_block_markup++;
            $t0 = microtime(true);
            $p = new BlockMarkupUrlProcessor($content, $base_url);
            while ($p->next_url()) {
                $parsed_url = $p->get_parsed_url();
                foreach ($url_mapping_parsed as $mapping) {
                    if (is_child_url_of($parsed_url, $mapping['from_url'])) {
                        $p->replace_base_url($mapping['to_url']);
                        break;
                    }
                }
            }
            $result = $p->get_updated_html();
            $this->time_block_markup += microtime(true) - $t0;
            return $result;
        } else {
            $this->calls_leaf_plain_text++;
            $t0 = microtime(true);
            $p = new URLInTextProcessor($content, $base_url);
            while ($p->next_url()) {
                $parsed_url = $p->get_parsed_url();
                foreach ($url_mapping_parsed as $mapping) {
                    if (is_child_url_of($parsed_url, $mapping['from_url'])) {
                        $new_raw_url = WPURL::replace_base_url(
                            $parsed_url,
                            [
                                'old_base_url' => $base_url,
                                'new_base_url' => $mapping['to_url'],
                                'raw_url'      => $p->get_raw_url(),
                                'is_relative'  => false,
                            ]
                        );
                        $p->set_raw_url($new_raw_url);
                        break;
                    }
                }
            }
            $result = $p->get_updated_text();
            $this->time_plain_text += microtime(true) - $t0;
            return $result;
        }
    }
}

// ─── Main profiling ──────────────────────────────────────────────────────────

echo "Extracting values from ~1MB SQL dump...\n";

// Generate the SQL and extract values inline (avoid the separate file dependency)
$sql_content = generate_sql_dump_inline($TARGET_SQL_SIZE, $TABLE_PREFIX);
$values = [];
$content_types = [];

$query_stream = new WP_MySQL_Naive_Query_Stream();
$query_stream->append_sql($sql_content);
$query_stream->mark_input_complete();

$block_markup_cols_by_table = [
    "wp_posts" => [
        // post_date=0, post_content=1, post_title=2, post_excerpt=3,
        // post_status=4, post_name=5, post_type=6, guid=7
        1 => 'block_markup',  // post_content
        3 => 'block_markup',  // post_excerpt
    ],
];

while ($query_stream->next_query()) {
    $query = $query_stream->get_query();
    if (strpos($query, 'FROM_BASE64(') === false) continue;

    $is_posts = strpos($query, '`wp_posts`') !== false;

    $scanner = new Base64ValueScanner($query);
    $col_idx = 0;
    while ($scanner->next_value()) {
        $value = $scanner->get_value();
        $values[] = $value;

        if ($is_posts && isset($block_markup_cols_by_table['wp_posts'][$col_idx % 8])) {
            $content_types[] = 'block_markup';
        } else {
            $content_types[] = 'plain_text';
        }
        $col_idx++;
    }
}

$sql_size = strlen($sql_content);
unset($sql_content, $query_stream);

$total_values = count($values);
$block_markup_count = count(array_filter($content_types, fn($t) => $t === 'block_markup'));
$plain_text_count = $total_values - $block_markup_count;

printf("Values extracted: %d (block_markup: %d, plain_text: %d) from %.1f KB SQL\n",
    $total_values, $block_markup_count, $plain_text_count, $sql_size / 1024);

// Count values that contain source domains
$has_source_domain = 0;
$no_source_domain = 0;
foreach ($values as $value) {
    $found = false;
    foreach ($URL_MAPPING as $from => $to) {
        $parsed = parse_url($from);
        $domain = $parsed['host'] ?? '';
        if ($domain !== '' && strpos($value, $domain) !== false) {
            $found = true;
            break;
        }
    }
    if ($found) $has_source_domain++;
    else $no_source_domain++;
}
printf("Values with source domain: %d (%.1f%%)\n", $has_source_domain, 100 * $has_source_domain / $total_values);
printf("Values WITHOUT source domain: %d (%.1f%%)\n\n", $no_source_domain, 100 * $no_source_domain / $total_values);

// ── Profile the rewriter ──
echo "Profiling StructuredDataUrlRewriter on all values...\n\n";

$rewriter = new ProfilingStructuredDataUrlRewriter($URL_MAPPING);
$total_start = microtime(true);
for ($i = 0; $i < $total_values; $i++) {
    $rewriter->rewrite($values[$i], $content_types[$i]);
}
$total_time = microtime(true) - $total_start;

// ── Report ──

echo "====================================================================\n";
echo "  URL REWRITE DETAILED PROFILE\n";
echo "====================================================================\n";
printf("  Total rewrite time:        %s\n", fmt_t($total_time));
printf("  Values processed:          %d\n", $rewriter->calls_total);
printf("  Values changed:            %d\n", $rewriter->values_changed);
printf("  Values unchanged (leaf):   %d\n\n", $rewriter->values_unchanged);

// Format detection breakdown
echo "  FORMAT DETECTION PATHS (what path each value took):\n";
echo "  " . str_repeat('─', 64) . "\n";
printf("  PHP serialized:     %6d values\n", $rewriter->calls_php_serialized);
printf("  JSON:               %6d values\n", $rewriter->calls_json);
printf("  Base64:             %6d values\n", $rewriter->calls_base64);
printf("  Leaf block_markup:  %6d values\n", $rewriter->calls_leaf_block_markup);
printf("  Leaf plain_text:    %6d values\n", $rewriter->calls_leaf_plain_text);
printf("  Empty:              %6d values\n\n", $rewriter->calls_empty);

// False positives (format detected but nothing changed)
echo "  FORMAT DETECTION FALSE POSITIVES (parsed but no URLs found):\n";
echo "  " . str_repeat('─', 64) . "\n";
printf("  PHP serialized (parsed, no URL change):  %d\n", $rewriter->php_serialize_false_positives);
printf("  JSON (parsed, no URL change):            %d\n", $rewriter->json_false_positives);
printf("  Base64 (decoded, no URL change):         %d\n\n", $rewriter->base64_false_positives);

// Time breakdown
echo "  TIME BREAKDOWN (sorted by time):\n";
echo "  " . str_repeat('─', 64) . "\n";

$breakdown = [
    'BlockMarkupUrlProcessor'   => $rewriter->time_block_markup,
    'URLInTextProcessor'        => $rewriter->time_plain_text,
    'PhpSerialize try (constr)' => $rewriter->time_php_serialize_try,
    'PhpSerialize iterate'      => $rewriter->time_php_serialize_iter,
    'JSON try (construct)'      => $rewriter->time_json_try,
    'JSON iterate'              => $rewriter->time_json_iter,
    'base64_decode attempt'     => $rewriter->time_base64_try,
    'WPURL::parse (per-leaf)'   => $rewriter->time_url_parse,
];
arsort($breakdown);

$rank = 1;
foreach ($breakdown as $name => $time) {
    $pct = $total_time > 0 ? 100 * $time / $total_time : 0;
    $bar_len = max(0, (int)(40 * $time / $total_time));
    $bar = str_repeat('█', $bar_len) . str_repeat('░', 40 - $bar_len);
    printf("  #%d %-27s %10s  %5.1f%%  %s\n", $rank, $name, fmt_t($time), $pct, $bar);
    $rank++;
}

// Per-value-type timing
echo "\n  TIME ON VALUES WITHOUT SOURCE DOMAIN:\n";
echo "  " . str_repeat('─', 64) . "\n";
printf("  Time spent on domain-free values:  %s (%.1f%% of total)\n",
    fmt_t($rewriter->time_on_values_without_source_domain),
    $total_time > 0 ? 100 * $rewriter->time_on_values_without_source_domain / $total_time : 0);
printf("  These values CANNOT produce rewrites — this time is pure overhead\n");

// Short-circuit analysis
echo "\n  POTENTIAL SHORT-CIRCUIT SAVINGS:\n";
echo "  " . str_repeat('─', 64) . "\n";
printf("  1. Skip values without source domain substring:    ~%s saved (%.1f%%)\n",
    fmt_t($rewriter->time_on_values_without_source_domain),
    $total_time > 0 ? 100 * $rewriter->time_on_values_without_source_domain / $total_time : 0);

// Estimate: if we skip format detection for non-domain values
$format_detection_overhead = $rewriter->time_php_serialize_try + $rewriter->time_json_try + $rewriter->time_base64_try;
printf("  2. Total format detection overhead (try-and-fail):  %s (%.1f%%)\n",
    fmt_t($format_detection_overhead),
    $total_time > 0 ? 100 * $format_detection_overhead / $total_time : 0);

$repeated_wpurl_parse = $rewriter->time_url_parse;
printf("  3. WPURL::parse called per-leaf (could cache):     %s (%.1f%%)\n",
    fmt_t($repeated_wpurl_parse),
    $total_time > 0 ? 100 * $repeated_wpurl_parse / $total_time : 0);

echo "\n";

function fmt_t(float $seconds): string
{
    if ($seconds < 0.001) return sprintf('%.3f ms', $seconds * 1000);
    return sprintf('%.1f ms', $seconds * 1000);
}

// ─── Inline SQL generator (same as profile-sql-import.php) ───────────────────

function generate_sql_dump_inline(int $target_size, string $table_prefix): string
{
    $sql = "";

    $sql .= "CREATE TABLE `{$table_prefix}posts` (\n";
    $sql .= "  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n";
    $sql .= "  `post_author` bigint(20) unsigned NOT NULL DEFAULT 0,\n";
    $sql .= "  `post_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',\n";
    $sql .= "  `post_content` longtext NOT NULL,\n";
    $sql .= "  `post_title` text NOT NULL,\n";
    $sql .= "  `post_excerpt` text NOT NULL,\n";
    $sql .= "  `post_status` varchar(20) NOT NULL DEFAULT 'publish',\n";
    $sql .= "  `post_name` varchar(200) NOT NULL DEFAULT '',\n";
    $sql .= "  `post_type` varchar(20) NOT NULL DEFAULT 'post',\n";
    $sql .= "  `guid` varchar(255) NOT NULL DEFAULT '',\n";
    $sql .= "  PRIMARY KEY (`ID`)\n";
    $sql .= ");\n\n";

    $sql .= "CREATE TABLE `{$table_prefix}options` (\n";
    $sql .= "  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n";
    $sql .= "  `option_name` varchar(191) NOT NULL DEFAULT '',\n";
    $sql .= "  `option_value` longtext NOT NULL,\n";
    $sql .= "  `autoload` varchar(20) NOT NULL DEFAULT 'yes',\n";
    $sql .= "  PRIMARY KEY (`option_id`)\n";
    $sql .= ");\n\n";

    $sql .= "CREATE TABLE `{$table_prefix}postmeta` (\n";
    $sql .= "  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n";
    $sql .= "  `post_id` bigint(20) unsigned NOT NULL DEFAULT 0,\n";
    $sql .= "  `meta_key` varchar(255) DEFAULT NULL,\n";
    $sql .= "  `meta_value` longtext,\n";
    $sql .= "  PRIMARY KEY (`meta_id`)\n";
    $sql .= ");\n\n";

    $id = 0;
    $batch_size = 50;
    $rows_buffer = [];

    $flush_posts = function () use (&$sql, &$rows_buffer, $table_prefix) {
        if (empty($rows_buffer)) return;
        $sql .= "INSERT INTO `{$table_prefix}posts` (`ID`, `post_author`, `post_date`, `post_content`, `post_title`, `post_excerpt`, `post_status`, `post_name`, `post_type`, `guid`) VALUES\n";
        $sql .= implode(",\n", $rows_buffer) . ";\n\n";
        $rows_buffer = [];
    };

    $content_templates = [
        '<!-- wp:paragraph --><p>Welcome to our site! Visit <a href="https://old-site.example.com/about">our about page</a> for more info. Check out <a href="https://old-site.example.com/products">products</a> and <a href="https://cdn.old-site.example.com/images/hero.jpg">hero image</a>.</p><!-- /wp:paragraph --><!-- wp:image {"url":"https://cdn.old-site.example.com/images/featured.jpg","id":42} --><figure class="wp-block-image"><img src="https://cdn.old-site.example.com/images/featured.jpg" alt="Featured"/></figure><!-- /wp:image -->',
        '<!-- wp:heading --><h2>Blog Post Title</h2><!-- /wp:heading --><!-- wp:paragraph --><p>This is a longer post with <a href="https://old-site.example.com/category/news">news links</a> and <a href="https://old-site.example.com/category/tech">tech links</a>. Our CDN hosts assets at <a href="https://cdn.old-site.example.com/assets/style.css">style.css</a>.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Another paragraph with more <a href="https://old-site.example.com/contact">contact</a> info.</p><!-- /wp:paragraph -->',
        'Plain text content referencing https://old-site.example.com/page and https://cdn.old-site.example.com/files/doc.pdf with various other text padding to make it more realistic and representative of actual WordPress post content that might appear in a production database.',
    ];

    $title_templates = [
        'Welcome to https://old-site.example.com',
        'About Our Company',
        'Contact Us at https://old-site.example.com/contact',
        'Product Catalog',
        'Blog Post Number',
    ];

    while (strlen($sql) < $target_size * 0.85) {
        $id++;
        $content = $content_templates[$id % count($content_templates)];
        $title = $title_templates[$id % count($title_templates)] . " #{$id}";
        $excerpt = "Excerpt for post {$id} - visit https://old-site.example.com/post-{$id}";
        $guid = "https://old-site.example.com/?p={$id}";
        $post_name = "post-{$id}";
        $date = '2024-01-' . str_pad(($id % 28) + 1, 2, '0', STR_PAD_LEFT) . ' 12:00:00';

        $row = sprintf(
            "(%d, 1, FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'))",
            $id,
            base64_encode($date),
            base64_encode($content),
            base64_encode($title),
            base64_encode($excerpt),
            base64_encode('publish'),
            base64_encode($post_name),
            base64_encode('post'),
            base64_encode($guid)
        );
        $rows_buffer[] = $row;

        if (count($rows_buffer) >= $batch_size) {
            $flush_posts();
        }
    }
    $flush_posts();

    // wp_options
    $options = [
        ['siteurl', 'https://old-site.example.com'],
        ['home', 'https://old-site.example.com'],
        ['blogname', 'My Test Site'],
        ['blogdescription', 'A site at https://old-site.example.com'],
        ['widget_text', serialize([
            2 => ['title' => 'Links', 'text' => '<a href="https://old-site.example.com/about">About</a>'],
            3 => ['title' => 'CDN', 'text' => '<img src="https://cdn.old-site.example.com/img/logo.png"/>'],
            '_multiwidget' => 1,
        ])],
        ['sidebars_widgets', serialize(['sidebar-1' => ['widget_text-2', 'widget_text-3'], 'array_version' => 3])],
        ['theme_mods_flavor', json_encode(['header_image' => 'https://cdn.old-site.example.com/images/header.jpg', 'background_image' => 'https://cdn.old-site.example.com/images/bg.jpg', 'custom_logo' => 42])],
    ];

    $opt_rows = [];
    foreach ($options as $i => [$name, $value]) {
        $opt_rows[] = sprintf("(%d, FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'))", $i + 1, base64_encode($name), base64_encode($value), base64_encode('yes'));
    }
    $sql .= "INSERT INTO `{$table_prefix}options` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES\n";
    $sql .= implode(",\n", $opt_rows) . ";\n\n";

    // postmeta
    $meta_rows = [];
    $meta_id = 0;
    for ($post_id = 1; $post_id <= min($id, 200); $post_id++) {
        $meta_id++;
        $meta_value = json_encode(['source' => 'https://old-site.example.com/api/posts/' . $post_id, 'thumbnail' => 'https://cdn.old-site.example.com/thumbs/' . $post_id . '.jpg'], JSON_UNESCAPED_SLASHES);
        $meta_rows[] = sprintf("(%d, %d, FROM_BASE64('%s'), FROM_BASE64('%s'))", $meta_id, $post_id, base64_encode('_api_data'), base64_encode($meta_value));

        if (count($meta_rows) >= $batch_size) {
            $sql .= "INSERT INTO `{$table_prefix}postmeta` (`meta_id`, `post_id`, `meta_key`, `meta_value`) VALUES\n";
            $sql .= implode(",\n", $meta_rows) . ";\n\n";
            $meta_rows = [];
        }
    }
    if (!empty($meta_rows)) {
        $sql .= "INSERT INTO `{$table_prefix}postmeta` (`meta_id`, `post_id`, `meta_key`, `meta_value`) VALUES\n";
        $sql .= implode(",\n", $meta_rows) . ";\n\n";
    }

    return $sql;
}
