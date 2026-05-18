<?php

$project_root = dirname( __DIR__, 3 );

require_once $project_root . '/vendor/autoload.php';

use WordPress\DataLiberation\URL\URLInTextProcessor;

$iterations = (int) ( getenv( 'BENCH_URL_REWRITE_ITERATIONS' ) ?: 20 );
$url_count  = (int) ( getenv( 'BENCH_URL_REWRITE_URLS' ) ?: 200 );

$from_url = 'http://localhost:9999';
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

$start = hrtime( true );
$matched_urls = 0;
for ( $i = 0; $i < $iterations; $i++ ) {
	$processor = new URLInTextProcessor( $content, $from_url );
	while ( $processor->next_url() ) {
		$processor->get_parsed_url();
		$matched_urls++;
	}
}
$elapsed_ms = ( hrtime( true ) - $start ) / 1000000;

if ( $matched_urls !== $iterations * $url_count * 2 ) {
	fwrite( STDERR, "URL detection benchmark matched $matched_urls URLs; expected " . ( $iterations * $url_count * 2 ) . ".\n" );
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
