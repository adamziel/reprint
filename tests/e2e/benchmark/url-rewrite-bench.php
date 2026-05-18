<?php

$project_root = dirname( __DIR__, 3 );

require_once $project_root . '/vendor/autoload.php';
require_once $project_root . '/packages/reprint-importer/src/lib/wp-stubs.php';
require_once $project_root . '/packages/reprint-importer/src/lib/url-rewrite/class-php-serialization-processor.php';
require_once $project_root . '/packages/reprint-importer/src/lib/url-rewrite/class-json-string-iterator.php';
require_once $project_root . '/packages/reprint-importer/src/lib/url-rewrite/class-structured-data-url-rewriter.php';

$iterations = (int) ( getenv( 'BENCH_URL_REWRITE_ITERATIONS' ) ?: 20 );
$url_count  = (int) ( getenv( 'BENCH_URL_REWRITE_URLS' ) ?: 200 );

$from_url = 'http://localhost:9999';
$to_url   = 'https://native-rewrite.example';
$parts    = array();

for ( $i = 0; $i < $url_count; $i++ ) {
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

$start = hrtime( true );
for ( $i = 0; $i < $iterations; $i++ ) {
	$rewritten = $rewriter->rewrite( $content, StructuredDataUrlRewriter::PLAIN_TEXT );
}
$elapsed_ms = ( hrtime( true ) - $start ) / 1000000;

if ( false !== strpos( $rewritten, $from_url ) || false === strpos( $rewritten, $to_url ) ) {
	fwrite( STDERR, "URL rewrite benchmark did not rewrite the fixture as expected.\n" );
	exit( 1 );
}

$url_in_text_class = 'WordPress\\DataLiberation\\URL\\URLInTextProcessor';
$native_class      = 'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor';
$uses_native       = class_exists( $native_class, false ) && is_subclass_of( $url_in_text_class, $native_class );

echo json_encode(
	array(
		'iterations'          => $iterations,
		'urls_per_iteration'  => $url_count * 2,
		'total_urls_scanned'  => $iterations * $url_count * 2,
		'bytes_per_iteration' => strlen( $content ),
		'total_bytes_scanned' => strlen( $content ) * $iterations,
		'elapsed_ms'          => $elapsed_ms,
		'native_url_in_text'  => $uses_native ? 'yes' : 'no',
	)
);
