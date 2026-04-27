#!/usr/bin/env php
<?php
/**
 * Detailed timing breakdown of the URL rewriting pipeline.
 */
$project_root = dirname(__DIR__, 2);
require_once $project_root . '/vendor/autoload.php';
require_once $project_root . '/packages/reprint-importer/src/lib/mysql-query-stream/load.php';
require_once $project_root . '/packages/reprint-importer/src/lib/url-rewrite/load.php';

$using_native = function_exists('reprint_sql_split');
echo ($using_native ? "Mode: NATIVE\n" : "Mode: PHP\n");

$from_url = 'http://source.wordpress.test';
$to_url   = 'https://destination.example.com';
$rows_per_insert = 250;

// Build one statement.
$rows = [];
for ($r = 0; $r < $rows_per_insert; $r++) {
    $content = "See {$from_url}/post/{$r} for details. Also {$from_url}/news/{$r}/ here. " .
               str_repeat("Lorem ipsum dolor sit amet. ", 5);
    $b64 = base64_encode($content);
    $rows[] = "({$r},CONVERT(FROM_BASE64('{$b64}') USING utf8mb4))";
}
$sql = "INSERT INTO `wp_posts` (`ID`,`post_content`) VALUES\n" . implode(",\n", $rows) . ";";
$sql_kb = round(strlen($sql) / 1024);

$url_rewriter = new StructuredDataUrlRewriter([$from_url => $to_url]);
$iters = 30;

echo "SQL: {$sql_kb} KB, {$rows_per_insert} rows, {$iters} iterations\n";
echo str_repeat('─', 60) . "\n\n";

// ─── Timed phases ─────────────────────────────────────────────────────────
$t_scan = $t_from_entries = $t_iter = $t_url = $t_result = 0;

for ($i = 0; $i < $iters; $i++) {
    // Phase A: FastInsertScanner::scan
    $t = hrtime(true);
    $fast = FastInsertScanner::scan($sql);
    $t_scan += hrtime(true) - $t;

    if ($fast === null) { echo "ERROR: scan returned null\n"; exit(1); }

    // Phase B: Base64ValueScanner::from_entries
    $t = hrtime(true);
    $scanner = Base64ValueScanner::from_entries($sql, $fast['base64_entries']);
    $t_from_entries += hrtime(true) - $t;

    // Phase C: iterate values + URL rewrite
    $t_iter_start = hrtime(true);
    while ($scanner->next_value()) {
        $value = $scanner->get_value();
        if (strpos($value, 'http') === false) continue;

        $t = hrtime(true);
        $rewritten = $url_rewriter->rewrite($value, null);
        $t_url += hrtime(true) - $t;

        if ($rewritten !== $value) $scanner->set_value($rewritten);
    }
    $t_iter += hrtime(true) - $t_iter_start - $t_url;

    // Phase D: get_result
    $t = hrtime(true);
    $scanner->get_result();
    $t_result += hrtime(true) - $t;
}

$ns = fn($t) => round($t / $iters / 1000) . " µs";
$ms = fn($t) => round($t / $iters / 1e6, 2) . " ms";

echo "Per statement ({$rows_per_insert} rows):\n";
printf("  FastInsertScanner::scan         %s\n", $ms($t_scan));
printf("  Base64ValueScanner::from_entries%s\n", $ms($t_from_entries));
printf("  Loop overhead (no url rewrite)  %s\n", $ms($t_iter));
printf("  URL rewrite x{$rows_per_insert}            %s\n", $ms($t_url));
printf("  get_result                      %s\n", $ms($t_result));

$total = ($t_scan + $t_from_entries + $t_iter + $t_url + $t_result) / $iters / 1e6;
printf("  Total:                          %.2f ms\n", $total);
