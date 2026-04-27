#!/usr/bin/env php
<?php
/**
 * Micro-benchmark for the native Rust extension vs PHP userspace.
 *
 * Tests the three hot paths identified by profiling (PR #166):
 *   1. SQL query splitter (22% of db-apply wall time)
 *   2. Fast INSERT scanner (core of the URL rewriting pipeline, 63%)
 *   3. Domain quick-check (part of URL rewriting)
 *   4. Plain-text URL rewriter (the leaf-value hotspot)
 *
 * Run without extension:  php bench.php
 * Run with extension:     php -d extension_dir=target/release -d extension=libreprint_native.so bench.php
 */

$using_native = function_exists('reprint_sql_split');
echo ($using_native ? "Mode: NATIVE (Rust extension loaded)\n" : "Mode: PHP userspace\n");
echo str_repeat('─', 60) . "\n\n";

// ─── Load PHP userspace classes ───────────────────────────────────────────
$project_root = dirname(__DIR__, 2);
require_once $project_root . '/vendor/autoload.php';

// Load MySQL query stream + URL rewriting classes.
foreach ([
    $project_root . '/packages/reprint-importer/src',
    $project_root . '/packages/reprint-importer/src/lib',
] as $d) {
    if (is_dir($d)) { break; }
}
require_once $project_root . '/packages/reprint-importer/src/lib/mysql-query-stream/load.php';
require_once $project_root . '/packages/reprint-importer/src/lib/url-rewrite/load.php';

// ─── Helpers ──────────────────────────────────────────────────────────────

function bench(string $label, int $iterations, callable $fn): void {
    // Warm up.
    for ($i = 0; $i < min(10, $iterations / 10); $i++) { $fn(); }

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) { $fn(); }
    $elapsed_ns = hrtime(true) - $start;

    $per_call_us = $elapsed_ns / $iterations / 1000;
    $calls_per_s = (int) ($iterations / ($elapsed_ns / 1e9));
    printf("  %-45s %8.1f µs/call  %8s calls/s\n",
        $label, $per_call_us, number_format($calls_per_s));
}

function bench_section(string $title): void {
    echo "\n$title\n" . str_repeat('-', 60) . "\n";
}

// ─── 1. SQL query splitter ────────────────────────────────────────────────
bench_section("1. SQL query splitter");

// Simulate a 64KB read chunk with one large INSERT (producer shape).
$row_count = 250;
$rows = [];
for ($i = 0; $i < $row_count; $i++) {
    $content = base64_encode("http://old.example.com/post/{$i} Lorem ipsum dolor sit amet, consectetur adipiscing elit.");
    $rows[] = "({$i},FROM_BASE64('{$content}'))";
}
$large_insert_sql = "INSERT INTO `wp_posts` (`ID`,`post_content`) VALUES\n" . implode(",\n", $rows) . ";";

// PHP userspace splitter.
bench("PHP WP_MySQL_Naive_Query_Stream", 500, function() use ($large_insert_sql) {
    $stream = new WP_MySQL_Naive_Query_Stream();
    $stream->append_sql($large_insert_sql);
    $stream->mark_input_complete();
    while ($stream->next_query()) {
        $stream->get_query();
    }
});

if (function_exists('reprint_sql_split')) {
    bench("Native reprint_sql_split", 500, function() use ($large_insert_sql) {
        reprint_sql_split($large_insert_sql, true);
    });
}

// ─── 2. Fast INSERT scanner ───────────────────────────────────────────────
bench_section("2. Fast INSERT scanner");

$scan_sql = $large_insert_sql;

bench("PHP FastInsertScanner::scan", 200, function() use ($scan_sql) {
    // Bypass native path to test PHP directly.
    // We call scan() but intercept the native check by using the PHP-only test.
    if (function_exists('reprint_fast_insert_scan')) {
        // For fair comparison: call PHP scanner without native bypass.
        // Use a trick: temporarily shadow the function check.
    }
    // Call the PHP preg_match-based scanner directly.
    $sql = $scan_sql;
    if (!preg_match('/\AINSERT\s+INTO\s+`((?:[^`]|``)+)`\s*\(([^)]+)\)\s*VALUES\b/i', $sql, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    // ... just test the regex cost.
});

bench("PHP FastInsertScanner::scan (full)", 200, function() use ($scan_sql) {
    FastInsertScanner::scan($scan_sql);
});

if (function_exists('reprint_fast_insert_scan')) {
    bench("Native reprint_fast_insert_scan", 200, function() use ($scan_sql) {
        reprint_fast_insert_scan($scan_sql);
    });
}

// ─── 3. Domain quick-check ────────────────────────────────────────────────
bench_section("3. Domain quick-check (maybe_contains_rewritable_urls)");

$domains = ['old.example.com', 'staging.example.com', 'dev.example.org', 'legacy.company.net'];
// A typical serialized PHP value that contains a matching domain.
$value_with_domain = 's:50:"' . str_repeat('x', 20) . 'old.example.com' . str_repeat('y', 15) . '";';
$value_no_domain   = 's:50:"' . str_repeat('z', 50) . '";';

bench("PHP strpos loop (hit)", 50000, function() use ($value_with_domain, $domains) {
    foreach ($domains as $d) {
        if (strpos($value_with_domain, $d) !== false) return true;
    }
    return false;
});

bench("PHP strpos loop (miss)", 50000, function() use ($value_no_domain, $domains) {
    foreach ($domains as $d) {
        if (strpos($value_no_domain, $d) !== false) return true;
    }
    return false;
});

if (function_exists('reprint_contains_any_domain')) {
    bench("Native reprint_contains_any_domain (hit)", 50000, function() use ($value_with_domain, $domains) {
        reprint_contains_any_domain($value_with_domain, $domains);
    });
    bench("Native reprint_contains_any_domain (miss)", 50000, function() use ($value_no_domain, $domains) {
        reprint_contains_any_domain($value_no_domain, $domains);
    });
}

// ─── 4. Plain-text URL rewriter ───────────────────────────────────────────
bench_section("4. Plain-text URL rewriter");

$url_mapping = ['http://old.example.com' => 'https://new.example.org'];
$sdur = new StructuredDataUrlRewriter($url_mapping);

// A typical post body with several embedded URLs.
$post_body = str_repeat(
    "Check out http://old.example.com/post/123 and http://old.example.com/uploads/image.jpg for more. " .
    "Also see http://external.net/page which should not be rewritten. ",
    10
);

bench("PHP URLInTextProcessor (full pipeline)", 1000, function() use ($sdur, $post_body) {
    $sdur->rewrite($post_body, 'plain_text');
});

if (function_exists('reprint_url_rewrite_plain_text')) {
    bench("Native reprint_url_rewrite_plain_text", 1000, function() use ($post_body) {
        reprint_url_rewrite_plain_text(
            $post_body,
            ['http://old.example.com'],
            ['https://new.example.org']
        );
    });
}

echo "\n";
echo str_repeat('─', 60) . "\n";
echo "Done.\n";
