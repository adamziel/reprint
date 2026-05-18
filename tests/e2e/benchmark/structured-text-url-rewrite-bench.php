<?php

$project_root = dirname( __DIR__, 3 );

require_once $project_root . '/vendor/autoload.php';
require_once $project_root . '/packages/reprint-importer/src/lib/url-rewrite/load.php';

$iterations = (int) ( getenv( 'BENCH_STRUCTURED_REWRITE_ITERATIONS' ) ?: 10 );
$rows       = (int) ( getenv( 'BENCH_STRUCTURED_REWRITE_ROWS' ) ?: 500 );

$from_url = 'http://old.example';
$to_url   = 'https://new.example/base';
$parts    = array();

for ( $i = 0; $i < $rows; $i++ ) {
	$parts[] = sprintf(
		'Entry %d links to %s/posts/%d?source=bench and %s/wp-content/uploads/%d.jpg.',
		$i,
		$from_url,
		$i,
		$from_url,
		$i
	);
}

$content  = implode( "\n", $parts );
$rewriter = new StructuredDataUrlRewriter(
	array(
		$from_url => $to_url,
	)
);

$start        = hrtime( true );
$changed_urls = 0;
for ( $i = 0; $i < $iterations; $i++ ) {
	$rewritten = $rewriter->rewrite( $content, StructuredDataUrlRewriter::PLAIN_TEXT );
	$changed_urls += substr_count( $rewritten, 'https://new.example/base/' );
}
$elapsed_ms = ( hrtime( true ) - $start ) / 1000000;

$expected_urls = $iterations * $rows * 2;
if ( $changed_urls !== $expected_urls ) {
	fwrite( STDERR, "Structured text rewrite changed $changed_urls URLs; expected $expected_urls.\n" );
	exit( 1 );
}

echo json_encode(
	array(
		'iterations'                 => $iterations,
		'rows_per_iteration'         => $rows,
		'urls_per_iteration'         => $rows * 2,
		'total_urls_rewritten'       => $expected_urls,
		'bytes_per_iteration'        => strlen( $content ),
		'total_bytes_scanned'        => strlen( $content ) * $iterations,
		'elapsed_ms'                 => $elapsed_ms,
		'native_batch_text_rewrite'  => function_exists( 'wp_native_apis_rewrite_text_url_bases' ) ? 'yes' : 'no',
		'condition'                  => 'StructuredDataUrlRewriter plain text batch URL base rewrite',
	)
);
