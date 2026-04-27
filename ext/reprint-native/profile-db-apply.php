#!/usr/bin/env php
<?php
/**
 * Profile the db-apply hot path in isolation.
 *
 * Generates a realistic SQL dump (INSERT statements with FROM_BASE64 values
 * containing URLs), then times parsing and URL rewriting with and without
 * the native extension. No database connection required.
 *
 * Usage:
 *   php profile-db-apply.php         # PHP userspace
 *   ./../../php-native profile-db-apply.php  # Rust extension
 */

$project_root = dirname(__DIR__, 2);
require_once $project_root . '/vendor/autoload.php';
require_once $project_root . '/packages/reprint-importer/src/lib/mysql-query-stream/load.php';
require_once $project_root . '/packages/reprint-importer/src/lib/url-rewrite/load.php';

$using_native = function_exists('reprint_sql_split');
echo ($using_native ? "Mode: NATIVE\n" : "Mode: PHP\n");

// ─── Generate synthetic dump ──────────────────────────────────────────────
$from_url = 'http://source.wordpress.test';
$to_url   = 'https://destination.example.com';

// Scale to approximate a realistic chunk of db-apply work.
// 50 INSERT statements × 250 rows each = 12,500 row insertions.
$num_inserts = 50;
$rows_per_insert = 250;

$inserts = [];
for ($i = 0; $i < $num_inserts; $i++) {
    $rows = [];
    for ($r = 0; $r < $rows_per_insert; $r++) {
        $id = $i * $rows_per_insert + $r + 1;
        // Realistic post_content with 2 embedded URLs + other content.
        $content = "Paragraph one. " .
                   "See {$from_url}/post/{$id} for details. " .
                   "Also {$from_url}/category/news/{$id}/ has more. " .
                   str_repeat("Lorem ipsum dolor sit amet. ", 5);
        $b64 = base64_encode($content);
        $rows[] = "({$id},CONVERT(FROM_BASE64('{$b64}') USING utf8mb4))";
    }
    $inserts[] = "INSERT INTO `wp_posts` (`ID`,`post_content`) VALUES\n" . implode(",\n", $rows) . ";";
}

$full_sql = implode("\n", $inserts);
$sql_size_kb = round(strlen($full_sql) / 1024);
$total_rows = $num_inserts * $rows_per_insert;
echo "SQL size: {$sql_size_kb} KB, {$num_inserts} INSERTs × {$rows_per_insert} rows = {$total_rows} rows\n";
echo str_repeat('─', 60) . "\n\n";

// ─── Phase 1: SQL query splitting ─────────────────────────────────────────
echo "Phase 1 — SQL query splitting ({$num_inserts} statements):\n";

$t0 = hrtime(true);
$stream = new WP_MySQL_Naive_Query_Stream();
$stream->append_sql($full_sql);
$stream->mark_input_complete();
$queries = [];
while ($stream->next_query()) {
    $queries[] = $stream->get_query();
}
$split_ms = round((hrtime(true) - $t0) / 1e6, 2);
echo "  Extracted " . count($queries) . " queries in {$split_ms} ms\n";
echo "  (" . round($sql_size_kb / ($split_ms / 1000) / 1024) . " MB/s throughput)\n\n";

// ─── Phase 2: URL rewriting ───────────────────────────────────────────────
echo "Phase 2 — URL rewriting ({$total_rows} rows with URLs):\n";

$url_mapping = [$from_url => $to_url];
$rewriter = new SqlStatementRewriter(
    new StructuredDataUrlRewriter($url_mapping),
    'wp_'
);

$t0 = hrtime(true);
$rewritten_count = 0;
foreach ($queries as $query) {
    $rewritten = $rewriter->rewrite($query);
    if ($rewritten !== $query) $rewritten_count++;
}
$rewrite_ms = round((hrtime(true) - $t0) / 1e6, 2);
echo "  Rewrote {$rewritten_count} of " . count($queries) . " statements in {$rewrite_ms} ms\n";
echo "  (" . round($rewrite_ms / count($queries) * 1000) . " µs/statement)\n\n";

// ─── Combined ─────────────────────────────────────────────────────────────
$total_ms = round($split_ms + $rewrite_ms, 2);
echo "Total (split + rewrite): {$total_ms} ms\n";
echo "  SQL split:   {$split_ms} ms (" . round($split_ms / $total_ms * 100) . "%)\n";
echo "  URL rewrite: {$rewrite_ms} ms (" . round($rewrite_ms / $total_ms * 100) . "%)\n";

// ─── Verify correctness ───────────────────────────────────────────────────
echo "\nCorrectness check:\n";
$sample = $rewriter->rewrite($queries[0]);
$contains_from = strpos($sample, $from_url) !== false;
$contains_to   = strpos($sample, $to_url) !== false;
echo "  Sample still contains source URL: " . ($contains_from ? "YES (bug!)" : "no") . "\n";
echo "  Sample contains dest URL:         " . ($contains_to ? "yes" : "NO (bug!)") . "\n";
