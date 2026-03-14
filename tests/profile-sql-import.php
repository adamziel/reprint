<?php
/**
 * Profile SQL import to SQLite and MySQL with URL rewriting.
 *
 * Generates a ~1MB SQL dump with realistic WordPress-like content containing
 * URLs that need rewriting, then profiles the import pipeline on both engines.
 *
 * Usage: php tests/profile-sql-import.php
 */

// Load dependencies
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
$TARGET_SQL_SIZE = 1 * 1024 * 1024; // ~1 MB

// MySQL connection (skip-grant-tables mode)
$MYSQL_HOST = '127.0.0.1';
$MYSQL_USER = 'root';
$MYSQL_PASS = '';
$MYSQL_DB   = 'profile_import_test';

// SQLite
$SQLITE_PATH = '/tmp/profile_import_test.sqlite';
$SQLITE_DB   = 'profile_import_test';

// ─── Generate SQL dump ───────────────────────────────────────────────────────

function generate_sql_dump(int $target_size, string $table_prefix): string
{
    $sql = "";

    // DDL
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

    // Generate INSERT data
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

    // wp_options with serialized PHP and JSON containing URLs
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
        ['sidebars_widgets', serialize([
            'sidebar-1' => ['widget_text-2', 'widget_text-3'],
            'array_version' => 3,
        ])],
        ['theme_mods_flavor', json_encode([
            'header_image' => 'https://cdn.old-site.example.com/images/header.jpg',
            'background_image' => 'https://cdn.old-site.example.com/images/bg.jpg',
            'custom_logo' => 42,
        ])],
    ];

    $opt_rows = [];
    foreach ($options as $i => [$name, $value]) {
        $opt_rows[] = sprintf(
            "(%d, FROM_BASE64('%s'), FROM_BASE64('%s'), FROM_BASE64('%s'))",
            $i + 1,
            base64_encode($name),
            base64_encode($value),
            base64_encode('yes')
        );
    }
    $sql .= "INSERT INTO `{$table_prefix}options` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES\n";
    $sql .= implode(",\n", $opt_rows) . ";\n\n";

    // postmeta with JSON data
    $meta_rows = [];
    $meta_id = 0;
    for ($post_id = 1; $post_id <= min($id, 200); $post_id++) {
        $meta_id++;
        $meta_value = json_encode([
            'source' => 'https://old-site.example.com/api/posts/' . $post_id,
            'thumbnail' => 'https://cdn.old-site.example.com/thumbs/' . $post_id . '.jpg',
        ], JSON_UNESCAPED_SLASHES);
        $meta_rows[] = sprintf(
            "(%d, %d, FROM_BASE64('%s'), FROM_BASE64('%s'))",
            $meta_id,
            $post_id,
            base64_encode('_api_data'),
            base64_encode($meta_value)
        );

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

// ─── Profiling helpers ───────────────────────────────────────────────────────

/**
 * Profiling wrapper around SqlStatementRewriter that breaks down
 * rewrite time into: SQL parsing (lexer+parser+AST), Base64 scanning,
 * and per-value URL rewriting.
 */
class ProfilingSqlStatementRewriter
{
    /** @var StructuredDataUrlRewriter|OptimizedStructuredDataUrlRewriter */
    private $url_rewriter;
    private string $table_prefix;
    private array $db_columns_with_block_markup;

    public float $time_sql_parse = 0;
    public float $time_base64_scan_init = 0;
    public float $time_base64_scan_iterate = 0;
    public float $time_value_rewrite = 0;
    public float $time_result_assembly = 0;
    public int $values_scanned = 0;
    public int $values_rewritten = 0;

    /** @var WP_Parser_Grammar|null */
    private static ?WP_Parser_Grammar $grammar = null;

    public function __construct($url_rewriter, string $table_prefix = 'wp_')
    {
        $this->url_rewriter = $url_rewriter;
        $this->table_prefix = $table_prefix;

        // Build column map like the real SqlStatementRewriter
        $wp_columns = [
            'posts' => ['post_content' => 'block_markup', 'post_content_filtered' => 'block_markup', 'post_excerpt' => 'block_markup'],
            'comments' => ['comment_content' => 'block_markup'],
            'term_taxonomy' => ['description' => 'block_markup'],
        ];
        $this->db_columns_with_block_markup = [];
        foreach ($wp_columns as $suffix => $columns) {
            $this->db_columns_with_block_markup[$table_prefix . $suffix] = $columns;
            $this->db_columns_with_block_markup[$suffix] = $columns;
        }
    }

    public function rewrite(string $sql): string
    {
        if (strpos($sql, "FROM_BASE64(") === false) {
            return $sql;
        }

        // Phase 1: SQL parsing (lexer + parser + AST traversal)
        $t0 = microtime(true);
        $parsed = $this->parse_statement($sql);
        $this->time_sql_parse += microtime(true) - $t0;

        // Phase 2: Base64 scanner initialization (also uses lexer internally)
        $t0 = microtime(true);
        $scanner = new Base64ValueScanner($sql);
        $this->time_base64_scan_init += microtime(true) - $t0;

        // Phase 3: Iterate values and rewrite
        while (true) {
            $t0 = microtime(true);
            $has_value = $scanner->next_value();
            $this->time_base64_scan_iterate += microtime(true) - $t0;

            if (!$has_value) break;

            $t0 = microtime(true);
            $value = $scanner->get_value();
            $this->time_base64_scan_iterate += microtime(true) - $t0;

            $this->values_scanned++;

            // Determine content type
            $content_type = null;
            if ($parsed !== null) {
                $column_name = $this->find_column_at_offset($parsed['column_map'], $scanner->get_match_offset());
                if ($column_name !== null) {
                    $content_type = $this->db_columns_with_block_markup[$parsed['table']][$column_name] ?? null;
                }
            }

            // Phase 3b: Actual value rewriting
            $t0 = microtime(true);
            $rewritten = $this->url_rewriter->rewrite($value, $content_type);
            $this->time_value_rewrite += microtime(true) - $t0;

            if ($rewritten !== $value) {
                $t0 = microtime(true);
                $scanner->set_value($rewritten);
                $this->time_base64_scan_iterate += microtime(true) - $t0;
                $this->values_rewritten++;
            }
        }

        // Phase 4: Result assembly
        $t0 = microtime(true);
        $result = $scanner->get_result();
        $this->time_result_assembly += microtime(true) - $t0;

        return $result;
    }

    private function parse_statement(string $sql): ?array
    {
        $lexer  = new WP_MySQL_Lexer($sql);
        $tokens = $lexer->remaining_tokens();
        $parser = new WP_MySQL_Parser(self::get_grammar(), $tokens);

        if (!$parser->next_query()) return null;
        $ast = $parser->get_query_ast();
        if (!$ast) return null;

        $simple = $ast->get_first_child_node('simpleStatement');
        if (!$simple) return null;

        $insert = $simple->get_first_child_node('insertStatement');
        if ($insert) return $this->parse_insert($insert);

        $update = $simple->get_first_child_node('updateStatement');
        if ($update) return $this->parse_update($update);

        return null;
    }

    private function parse_insert(WP_Parser_Node $stmt): ?array
    {
        $table_ref = $stmt->get_first_child_node('tableRef');
        if (!$table_ref) return null;
        $table = $this->extract_identifier($table_ref);
        if ($table === null) return null;

        $constructor = $stmt->get_first_child_node('insertFromConstructor');
        if (!$constructor) return ['table' => $table, 'column_map' => []];

        $columns = [];
        $fields_node = $constructor->get_first_child_node('fields');
        if ($fields_node) {
            foreach ($fields_node->get_child_nodes('insertIdentifier') as $insert_id) {
                $col_name = $this->extract_identifier($insert_id);
                if ($col_name !== null) $columns[] = $col_name;
            }
        }

        $column_map = [];
        $insert_values = $constructor->get_first_child_node('insertValues');
        if ($insert_values) {
            $value_list = $insert_values->get_first_child_node('valueList');
            if ($value_list) {
                foreach ($value_list->get_child_nodes('values') as $values_node) {
                    $exprs = $values_node->get_child_nodes('expr');
                    foreach ($exprs as $i => $expr) {
                        if ($i < count($columns)) {
                            $column_map[] = [$expr->get_start(), $expr->get_start() + $expr->get_length(), $columns[$i]];
                        }
                    }
                }
            }
        }

        return ['table' => $table, 'column_map' => $column_map];
    }

    private function parse_update(WP_Parser_Node $stmt): ?array
    {
        $table_ref_list = $stmt->get_first_child_node('tableReferenceList');
        if (!$table_ref_list) return null;
        $table_ref = $table_ref_list->get_first_descendant_node('tableRef');
        if (!$table_ref) return null;
        $table = $this->extract_identifier($table_ref);
        if ($table === null) return null;

        $column_map = [];
        $update_list = $stmt->get_first_child_node('updateList');
        if ($update_list) {
            foreach ($update_list->get_child_nodes('updateElement') as $element) {
                $col_ref = $element->get_first_child_node('columnRef');
                if (!$col_ref) continue;
                $col_name = $this->extract_identifier($col_ref);
                $expr = $element->get_first_child_node('expr');
                if ($expr && $col_name !== null) {
                    $column_map[] = [$expr->get_start(), $expr->get_start() + $expr->get_length(), $col_name];
                }
            }
        }

        return ['table' => $table, 'column_map' => $column_map];
    }

    private function find_column_at_offset(array $column_map, int $offset): ?string
    {
        foreach ($column_map as [$start, $end, $column]) {
            if ($offset >= $start && $offset < $end) return $column;
        }
        return null;
    }

    private function extract_identifier(WP_Parser_Node $node): ?string
    {
        $tokens = $node->get_descendant_tokens(WP_MySQL_Lexer::BACK_TICK_QUOTED_ID);
        if (empty($tokens)) $tokens = $node->get_descendant_tokens(WP_MySQL_Lexer::IDENTIFIER);
        if (empty($tokens)) return null;
        return end($tokens)->get_value();
    }

    private static function get_grammar(): WP_Parser_Grammar
    {
        if (self::$grammar === null) {
            $path = dirname(__DIR__) . '/lib/sqlite-database-integration/wp-includes/mysql/mysql-grammar.php';
            $data = require $path;
            self::$grammar = new WP_Parser_Grammar($data);
        }
        return self::$grammar;
    }
}

/**
 * Profile the import of a SQL string into a target PDO connection.
 * Pass $use_optimized=true to use the OptimizedStructuredDataUrlRewriter.
 */
function profile_import(string $sql, PDO $pdo, array $url_mapping, string $table_prefix, bool $use_optimized = false): array
{
    $timings = [
        'total'              => 0,
        'query_stream_parse' => 0,
        'url_rewrite'        => 0,
        'db_execute'         => 0,
        'other'              => 0,
    ];
    $stmt_count = 0;
    $rewrite_count = 0;

    if ($use_optimized) {
        $value_rewriter = new OptimizedStructuredDataUrlRewriter($url_mapping);
    } else {
        $value_rewriter = new StructuredDataUrlRewriter($url_mapping);
    }
    $stmt_rewriter = new ProfilingSqlStatementRewriter(
        $value_rewriter,
        $table_prefix,
    );

    $query_stream = new WP_MySQL_Naive_Query_Stream();

    $total_start = microtime(true);

    $chunk_size = 64 * 1024;
    $offset = 0;
    $sql_len = strlen($sql);

    $process_query = function () use (&$query_stream, &$timings, &$stmt_count, &$rewrite_count, $stmt_rewriter, $pdo) {
        $t0 = microtime(true);
        $query = $query_stream->get_query();
        $timings['query_stream_parse'] += microtime(true) - $t0;

        $stmt_count++;

        $t0 = microtime(true);
        $rewritten = $stmt_rewriter->rewrite($query);
        $timings['url_rewrite'] += microtime(true) - $t0;
        if ($rewritten !== $query) {
            $rewrite_count++;
        }
        $query = $rewritten;

        $t0 = microtime(true);
        try {
            $pdo->exec($query);
        } catch (PDOException $e) {
            // Silently continue for profiling
        }
        $timings['db_execute'] += microtime(true) - $t0;
    };

    while ($offset < $sql_len) {
        $data = substr($sql, $offset, $chunk_size);
        $offset += strlen($data);

        $t0 = microtime(true);
        $query_stream->append_sql($data);
        $timings['query_stream_parse'] += microtime(true) - $t0;

        while (true) {
            $t0 = microtime(true);
            $has_query = $query_stream->next_query();
            $timings['query_stream_parse'] += microtime(true) - $t0;
            if (!$has_query) break;
            $process_query();
        }
    }

    $t0 = microtime(true);
    $query_stream->mark_input_complete();
    $timings['query_stream_parse'] += microtime(true) - $t0;

    while (true) {
        $t0 = microtime(true);
        $has_query = $query_stream->next_query();
        $timings['query_stream_parse'] += microtime(true) - $t0;
        if (!$has_query) break;
        $process_query();
    }

    $timings['total'] = microtime(true) - $total_start;
    $timings['other'] = $timings['total']
        - $timings['query_stream_parse']
        - $timings['url_rewrite']
        - $timings['db_execute'];

    return [
        'timings'       => $timings,
        'stmt_count'    => $stmt_count,
        'rewrite_count' => $rewrite_count,
        'rewrite_detail' => [
            'sql_parse'          => $stmt_rewriter->time_sql_parse,
            'base64_scan_init'   => $stmt_rewriter->time_base64_scan_init,
            'base64_scan_iter'   => $stmt_rewriter->time_base64_scan_iterate,
            'value_rewrite'      => $stmt_rewriter->time_value_rewrite,
            'result_assembly'    => $stmt_rewriter->time_result_assembly,
            'values_scanned'     => $stmt_rewriter->values_scanned,
            'values_rewritten'   => $stmt_rewriter->values_rewritten,
        ],
    ];
}

function fmt_ms(float $seconds): string
{
    if ($seconds < 0.001) return sprintf('%.3f ms', $seconds * 1000);
    return sprintf('%.1f ms', $seconds * 1000);
}

function fmt_pct(float $part, float $total): string
{
    if ($total == 0) return '0.0%';
    return sprintf('%.1f%%', 100 * $part / $total);
}

function print_results(string $label, array $result): void
{
    $t = $result['timings'];
    $d = $result['rewrite_detail'];

    echo "\n";
    echo "====================================================================\n";
    echo "  {$label}\n";
    echo "====================================================================\n";
    printf("  Total time:             %s\n", fmt_ms($t['total']));
    printf("  Statements executed:    %d\n", $result['stmt_count']);
    printf("  Statements rewritten:   %d\n", $result['rewrite_count']);
    printf("  Values scanned:         %d\n", $d['values_scanned']);
    printf("  Values rewritten:       %d\n", $d['values_rewritten']);
    echo "\n";

    // Top-level breakdown
    echo "  TOP-LEVEL BREAKDOWN (sorted by time):\n";
    echo "  " . str_repeat('─', 64) . "\n";

    $breakdown = [
        'URL rewrite (total)'   => $t['url_rewrite'],
        'DB execute'            => $t['db_execute'],
        'Query stream parse'    => $t['query_stream_parse'],
        'Other overhead'        => $t['other'],
    ];
    arsort($breakdown);

    $rank = 1;
    foreach ($breakdown as $name => $time) {
        $pct = fmt_pct($time, $t['total']);
        $bar_len = max(0, (int)(40 * $time / $t['total']));
        $bar = str_repeat('█', $bar_len) . str_repeat('░', 40 - $bar_len);
        printf("  #%d %-22s %10s  %6s  %s\n", $rank, $name, fmt_ms($time), $pct, $bar);
        $rank++;
    }

    // URL rewrite sub-breakdown
    echo "\n  URL REWRITE SUB-BREAKDOWN (sorted by time):\n";
    echo "  " . str_repeat('─', 64) . "\n";

    $rewrite_total = $t['url_rewrite'];
    $sub = [
        'SQL parse (lex+parse+AST)' => $d['sql_parse'],
        'Base64 scanner init'       => $d['base64_scan_init'],
        'Base64 scanner iterate'    => $d['base64_scan_iter'],
        'Value rewrite (URLs)'      => $d['value_rewrite'],
        'Result assembly'           => $d['result_assembly'],
    ];
    arsort($sub);

    $rank = 1;
    foreach ($sub as $name => $time) {
        $pct_of_rewrite = fmt_pct($time, $rewrite_total);
        $pct_of_total = fmt_pct($time, $t['total']);
        $bar_len = $rewrite_total > 0 ? max(0, (int)(40 * $time / $rewrite_total)) : 0;
        $bar = str_repeat('█', $bar_len) . str_repeat('░', 40 - $bar_len);
        printf("  #%d %-27s %10s  %6s of rewrite  %6s of total  %s\n",
            $rank, $name, fmt_ms($time), $pct_of_rewrite, $pct_of_total, $bar);
        $rank++;
    }

    echo "\n";
}

// ─── Optimized StructuredDataUrlRewriter ──────────────────────────────────────

/**
 * Optimized version with three short-circuits:
 * 1. Cache WPURL::parse() results in constructor.
 * 2. Skip values with no URL fingerprint (http:/https: or base64 fragments).
 * 3. Downgrade block_markup to plain_text if no "<!--" present.
 */
class OptimizedStructuredDataUrlRewriter
{
    private array $url_mapping;
    private array $parsed_url_mapping;
    private string $base_url;
    private array $url_fingerprints;

    public int $calls_total = 0;
    public int $short_circuited = 0;
    public int $block_markup_downgraded = 0;

    public function __construct(array $url_mapping)
    {
        $this->url_mapping = $url_mapping;
        $from_urls = array_keys($url_mapping);
        $this->base_url = $from_urls[0];
        $this->parsed_url_mapping = [];
        foreach ($url_mapping as $from => $to) {
            $this->parsed_url_mapping[] = [
                'from_url' => WPURL::parse($from),
                'to_url'   => WPURL::parse($to),
            ];
        }
        $this->url_fingerprints = self::compute_url_fingerprints();
    }

    private static function compute_url_fingerprints(): array
    {
        $patterns = ['http:', 'https:'];
        foreach (['http:', 'https:'] as $proto) {
            for ($a = 0; $a < 3; $a++) {
                $skip = $a > 0 ? (3 - $a) : 0;
                if ($skip >= strlen($proto)) continue;
                $remaining = strlen($proto) - $skip;
                $full_groups = (int)($remaining / 3);
                if ($full_groups === 0) continue;
                $stable_bytes = substr($proto, $skip, $full_groups * 3);
                $patterns[] = base64_encode($stable_bytes);
            }
        }
        return array_values(array_unique($patterns));
    }

    private function might_contain_url(string $value): bool
    {
        foreach ($this->url_fingerprints as $pattern) {
            if (strpos($value, $pattern) !== false) return true;
        }
        return false;
    }

    public function rewrite(string $value, ?string $content_type = null): string
    {
        $this->calls_total++;
        if ($value === '') return $value;
        if ($content_type === 'skip') return $value;
        if ($content_type === null) $content_type = 'plain_text';

        if (!$this->might_contain_url($value)) {
            $this->short_circuited++;
            return $value;
        }

        $p = new PhpSerializationProcessor($value);
        if (!$p->is_malformed()) {
            while ($p->next_value()) {
                $orig = $p->get_value();
                $rewritten = $this->rewrite($orig, $content_type);
                if ($rewritten !== $orig) $p->set_value($rewritten);
            }
            return $p->get_updated_serialization();
        }

        $iter = new JsonStringIterator($value);
        if (!$iter->is_malformed()) {
            while ($iter->next_value()) {
                $orig = $iter->get_value();
                $rewritten = $this->rewrite($orig, $content_type);
                if ($rewritten !== $orig) $iter->set_value($rewritten);
            }
            return $iter->get_result();
        }

        $decoded = base64_decode($value, true);
        if ($decoded !== false && $decoded !== '') {
            if (mb_check_encoding($decoded, 'UTF-8')) {
                $rewritten = $this->rewrite($decoded, $content_type);
                if ($rewritten !== $decoded) return base64_encode($rewritten);
            }
        }

        if ($content_type === 'block_markup' && strpos($value, '<!--') === false) {
            $content_type = 'plain_text';
            $this->block_markup_downgraded++;
        }

        if ($content_type === 'block_markup') {
            $p2 = new BlockMarkupUrlProcessor($value, $this->base_url);
            while ($p2->next_url()) {
                $parsed_url = $p2->get_parsed_url();
                foreach ($this->parsed_url_mapping as $mapping) {
                    if (is_child_url_of($parsed_url, $mapping['from_url'])) {
                        $p2->replace_base_url($mapping['to_url']);
                        break;
                    }
                }
            }
            return $p2->get_updated_html();
        } else {
            $p2 = new URLInTextProcessor($value, $this->base_url);
            while ($p2->next_url()) {
                $parsed_url = $p2->get_parsed_url();
                foreach ($this->parsed_url_mapping as $mapping) {
                    if (is_child_url_of($parsed_url, $mapping['from_url'])) {
                        $new_raw_url = WPURL::replace_base_url(
                            $parsed_url,
                            [
                                'old_base_url' => $this->base_url,
                                'new_base_url' => $mapping['to_url'],
                                'raw_url'      => $p2->get_raw_url(),
                                'is_relative'  => false,
                            ]
                        );
                        $p2->set_raw_url($new_raw_url);
                        break;
                    }
                }
            }
            return $p2->get_updated_text();
        }
    }
}

// ─── Main ────────────────────────────────────────────────────────────────────

echo "Generating ~1MB SQL dump with URL-rich WordPress content...\n";
$t0 = microtime(true);
$sql = generate_sql_dump($TARGET_SQL_SIZE, $TABLE_PREFIX);
$gen_time = microtime(true) - $t0;
$sql_size = strlen($sql);

$stream = new WP_MySQL_Naive_Query_Stream();
$stream->append_sql($sql);
$stream->mark_input_complete();
$stmt_total = 0;
while ($stream->next_query()) { $stmt_total++; }

printf("SQL dump generated: %.1f KB (%d statements) in %s\n",
    $sql_size / 1024, $stmt_total, fmt_ms($gen_time));
echo "URL mappings: " . count($URL_MAPPING) . "\n";
foreach ($URL_MAPPING as $from => $to) {
    echo "  {$from} => {$to}\n";
}

// ── SQLite import ──

echo "\n" . str_repeat('=', 68) . "\n";
echo "PROFILING: SQLite import with URL rewriting\n";
echo str_repeat('=', 68) . "\n";

if (file_exists($SQLITE_PATH)) unlink($SQLITE_PATH);

$sqlite_driver = __DIR__ . '/../lib/sqlite-database-integration/wp-pdo-mysql-on-sqlite.php';
$polyfills = __DIR__ . '/../lib/sqlite-database-integration/php-polyfills.php';
require_once $polyfills;
require_once $sqlite_driver;

$sqlite_dsn = sprintf("mysql-on-sqlite:path=%s;dbname=%s", $SQLITE_PATH, $SQLITE_DB);
$sqlite_pdo = new WP_PDO_MySQL_On_SQLite($sqlite_dsn, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$raw_sqlite = $sqlite_pdo->get_connection()->get_pdo();
$raw_sqlite->sqliteCreateFunction('FROM_BASE64', function ($data) {
    return $data === null ? null : base64_decode($data);
}, 1);
$raw_sqlite->sqliteCreateFunction('TO_BASE64', function ($data) {
    return $data === null ? null : base64_encode($data);
}, 1);

$sqlite_result = profile_import($sql, $sqlite_pdo, $URL_MAPPING, $TABLE_PREFIX);
print_results('SQLite with URL rewriting', $sqlite_result);

unset($sqlite_pdo, $raw_sqlite);

// ── SQLite import (OPTIMIZED) ──

echo "\n" . str_repeat('=', 68) . "\n";
echo "PROFILING: SQLite import with OPTIMIZED URL rewriting\n";
echo str_repeat('=', 68) . "\n";

if (file_exists($SQLITE_PATH)) unlink($SQLITE_PATH);

$sqlite_pdo_opt = new WP_PDO_MySQL_On_SQLite($sqlite_dsn, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$raw_sqlite_opt = $sqlite_pdo_opt->get_connection()->get_pdo();
$raw_sqlite_opt->sqliteCreateFunction('FROM_BASE64', function ($data) {
    return $data === null ? null : base64_decode($data);
}, 1);
$raw_sqlite_opt->sqliteCreateFunction('TO_BASE64', function ($data) {
    return $data === null ? null : base64_encode($data);
}, 1);

$sqlite_result_opt = profile_import($sql, $sqlite_pdo_opt, $URL_MAPPING, $TABLE_PREFIX, true);
print_results('SQLite with OPTIMIZED URL rewriting', $sqlite_result_opt);

unset($sqlite_pdo_opt, $raw_sqlite_opt);
if (file_exists($SQLITE_PATH)) unlink($SQLITE_PATH);

// ── MySQL import ──

echo str_repeat('=', 68) . "\n";
echo "PROFILING: MySQL import with URL rewriting\n";
echo str_repeat('=', 68) . "\n";

try {
    $mysql_pdo = new PDO(
        "mysql:host={$MYSQL_HOST};charset=utf8mb4",
        $MYSQL_USER, $MYSQL_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $mysql_pdo->exec("DROP DATABASE IF EXISTS `{$MYSQL_DB}`");
    $mysql_pdo->exec("CREATE DATABASE `{$MYSQL_DB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $mysql_pdo->exec("USE `{$MYSQL_DB}`");

    $mysql_result = profile_import($sql, $mysql_pdo, $URL_MAPPING, $TABLE_PREFIX);
    print_results('MySQL with URL rewriting', $mysql_result);

    $mysql_pdo->exec("DROP DATABASE IF EXISTS `{$MYSQL_DB}`");

    // ── MySQL import (OPTIMIZED) ──
    echo str_repeat('=', 68) . "\n";
    echo "PROFILING: MySQL import with OPTIMIZED URL rewriting\n";
    echo str_repeat('=', 68) . "\n";

    $mysql_pdo->exec("CREATE DATABASE `{$MYSQL_DB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $mysql_pdo->exec("USE `{$MYSQL_DB}`");

    $mysql_result_opt = profile_import($sql, $mysql_pdo, $URL_MAPPING, $TABLE_PREFIX, true);
    print_results('MySQL with OPTIMIZED URL rewriting', $mysql_result_opt);

    $mysql_pdo->exec("DROP DATABASE IF EXISTS `{$MYSQL_DB}`");
    unset($mysql_pdo);
} catch (PDOException $e) {
    fprintf(STDERR, "\nMySQL connection failed: %s\nSkipping MySQL profiling.\n", $e->getMessage());
}

// ── Comparison: Original vs Optimized ──

echo "\n" . str_repeat('=', 68) . "\n";
echo "  SPEEDUP: Original vs Optimized\n";
echo str_repeat('=', 68) . "\n\n";

$pairs = [
    ['SQLite', $sqlite_result, $sqlite_result_opt],
];
if (isset($mysql_result, $mysql_result_opt)) {
    $pairs[] = ['MySQL', $mysql_result, $mysql_result_opt];
}

foreach ($pairs as [$engine, $orig, $opt]) {
    printf("  %s:\n", $engine);
    printf("  %-30s %12s %12s %10s\n", '', 'Original', 'Optimized', 'Speedup');
    printf("  %-30s %12s %12s %10s\n", str_repeat('─', 30), str_repeat('─', 12), str_repeat('─', 12), str_repeat('─', 10));

    $keys = [
        'Total'              => 'total',
        'URL rewrite'        => 'url_rewrite',
        'DB execute'         => 'db_execute',
        'Query stream parse' => 'query_stream_parse',
    ];
    foreach ($keys as $label => $key) {
        $ov = $orig['timings'][$key];
        $nv = $opt['timings'][$key];
        $speedup = $nv > 0 ? $ov / $nv : INF;
        printf("  %-30s %12s %12s %9.2fx\n", $label, fmt_ms($ov), fmt_ms($nv), $speedup);
    }

    // URL rewrite sub-breakdown
    $sub_keys = [
        'Value rewrite'    => 'value_rewrite',
        'SQL parse'        => 'sql_parse',
        'Base64 scan iter' => 'base64_scan_iter',
    ];
    foreach ($sub_keys as $label => $key) {
        $ov = $orig['rewrite_detail'][$key];
        $nv = $opt['rewrite_detail'][$key];
        $speedup = $nv > 0 ? $ov / $nv : INF;
        printf("  %-30s %12s %12s %9.2fx\n", "  └ " . $label, fmt_ms($ov), fmt_ms($nv), $speedup);
    }
    echo "\n";
}

// ── Comparison: SQLite vs MySQL ──

if (isset($mysql_result)) {
    echo str_repeat('=', 68) . "\n";
    echo "  COMPARISON: SQLite vs MySQL\n";
    echo str_repeat('=', 68) . "\n\n";

    $categories = [
        'Total'                   => 'total',
        'DB execute'              => 'db_execute',
        'URL rewrite'             => 'url_rewrite',
        'Query stream parse'      => 'query_stream_parse',
    ];

    printf("  %-26s %12s %12s %10s\n", 'Category', 'SQLite', 'MySQL', 'Ratio');
    printf("  %-26s %12s %12s %10s\n", str_repeat('─', 26), str_repeat('─', 12), str_repeat('─', 12), str_repeat('─', 10));

    foreach ($categories as $label => $key) {
        $sv = $sqlite_result['timings'][$key];
        $mv = $mysql_result['timings'][$key];
        $ratio = $mv > 0 ? $sv / $mv : INF;
        printf("  %-26s %12s %12s %9.2fx\n", $label, fmt_ms($sv), fmt_ms($mv), $ratio);
    }

    echo "\n";
    printf("  %-26s %12s %12s %10s\n", 'URL Rewrite Sub-category', 'SQLite', 'MySQL', 'Ratio');
    printf("  %-26s %12s %12s %10s\n", str_repeat('─', 26), str_repeat('─', 12), str_repeat('─', 12), str_repeat('─', 10));

    $sub_keys = [
        'SQL parse'        => 'sql_parse',
        'Base64 scan init' => 'base64_scan_init',
        'Base64 scan iter' => 'base64_scan_iter',
        'Value rewrite'    => 'value_rewrite',
        'Result assembly'  => 'result_assembly',
    ];

    foreach ($sub_keys as $label => $key) {
        $sv = $sqlite_result['rewrite_detail'][$key];
        $mv = $mysql_result['rewrite_detail'][$key];
        $ratio = $mv > 0 ? $sv / $mv : INF;
        printf("  %-26s %12s %12s %9.2fx\n", $label, fmt_ms($sv), fmt_ms($mv), $ratio);
    }
    echo "\n";
}
