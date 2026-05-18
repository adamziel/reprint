<?php

$project_root = dirname( __DIR__, 3 );

require_once $project_root . '/vendor/autoload.php';

use WordPress\DataLiberation\URL\URLInTextProcessor;

$iterations = (int) ( getenv( 'BENCH_HTML_URL_ITERATIONS' ) ?: 8 );
$card_count = (int) ( getenv( 'BENCH_HTML_URL_CARDS' ) ?: 1200 );
$base_url   = 'http://localhost:9999';
$use_native = class_exists( 'WP_HTML_Native_Tag_Processor', false )
	&& class_exists( 'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor', false );

function reprint_build_html_url_fixture( $card_count, $base_url ) {
	$html_parts = array();
	$text_parts = array();

	for ( $i = 0; $i < $card_count; $i++ ) {
		$html_parts[] = sprintf(
			'<article class="card card-%1$d" data-id="%1$d"><a class="title" href="%2$s/posts/%1$d?utm=bench&amp;slot=%1$d" data-track="hero-%1$d">Post %1$d</a><img src="%2$s/wp-content/uploads/%1$d.jpg" alt="Image %1$d" width="640" height="480"><p>Related: %2$s/text/%1$d and example.com/docs/%1$d.</p></article>',
			$i,
			$base_url
		);
		$text_parts[] = sprintf(
			'Related: %s/text/%d and example.com/docs/%d.',
			$base_url,
			$i,
			$i
		);
	}

	return array(
		implode( "\n", $html_parts ),
		implode( "\n", $text_parts ),
	);
}

function reprint_collect_html_resource_urls_userland( $html ) {
	$processor = new WP_HTML_Tag_Processor( $html );
	$urls      = array();
	$tag_count = 0;

	while ( $processor->next_tag() ) {
		++$tag_count;
		$tag = $processor->get_tag();
		if ( 'A' === $tag ) {
			$href = $processor->get_attribute( 'href' );
			if ( is_string( $href ) ) {
				$urls[] = $href;
			}
		} elseif ( 'IMG' === $tag ) {
			$src = $processor->get_attribute( 'src' );
			if ( is_string( $src ) ) {
				$urls[] = $src;
			}
		}
	}

	return array( $urls, $tag_count );
}

function reprint_collect_native_matching_attribute( $html, $tag_name, $attribute_name ) {
	$processor = new WP_HTML_Native_Tag_Processor( $html );
	$urls      = array();

	while ( true ) {
		$batch = $processor->next_matching_tag_attribute_compact_summary_batch( $tag_name, $attribute_name, 256, false );
		if ( ! is_string( $batch ) || '' === $batch ) {
			break;
		}

		$rows = explode( "\x1e", $batch );
		foreach ( $rows as $row ) {
			$parts = explode( "\x1f", $row, 3 );
			if ( 3 !== count( $parts ) || '' === $parts[2] || '1' !== $parts[2][0] ) {
				continue;
			}

			$urls[] = substr( $parts[2], 1 );
		}
	}

	return $urls;
}

function reprint_collect_html_resource_urls_native( $html ) {
	$urls = array_merge(
		reprint_collect_native_matching_attribute( $html, 'a', 'href' ),
		reprint_collect_native_matching_attribute( $html, 'img', 'src' )
	);

	$inventory = ( new WP_HTML_Native_Tag_Processor( $html ) )->summarize_tag_inventory( false );
	$parts     = is_string( $inventory ) ? explode( "\x1f", $inventory ) : array();
	$tag_count = isset( $parts[0] ) ? (int) $parts[0] : 0;

	return array( $urls, $tag_count );
}

function reprint_count_urls_in_text( $text, $base_url ) {
	$processor = new URLInTextProcessor( $text, $base_url );
	$count     = 0;

	while ( $processor->next_url() ) {
		if ( false !== $processor->get_parsed_url() ) {
			++$count;
		}
	}

	return $count;
}

list( $html, $text ) = reprint_build_html_url_fixture( $card_count, $base_url );

$start          = hrtime( true );
$html_urls      = 0;
$html_tags      = 0;
$text_urls      = 0;
$expected_urls  = $card_count * 4 * $iterations;
$expected_attrs = $card_count * 2 * $iterations;

for ( $i = 0; $i < $iterations; $i++ ) {
	if ( $use_native ) {
		list( $resource_urls, $tag_count ) = reprint_collect_html_resource_urls_native( $html );
	} else {
		list( $resource_urls, $tag_count ) = reprint_collect_html_resource_urls_userland( $html );
	}

	$html_urls += count( $resource_urls );
	$html_tags += $tag_count;
	$text_urls += reprint_count_urls_in_text( implode( ' ', $resource_urls ) . "\n" . $text, $base_url );
}

$elapsed_ms = ( hrtime( true ) - $start ) / 1000000;

if ( $html_urls !== $expected_attrs ) {
	fwrite( STDERR, "HTML URL benchmark found $html_urls resource URLs; expected $expected_attrs.\n" );
	exit( 1 );
}

if ( $text_urls !== $expected_urls ) {
	fwrite( STDERR, "HTML URL benchmark parsed $text_urls URLs; expected $expected_urls.\n" );
	exit( 1 );
}

echo json_encode(
	array(
		'cards'                => $card_count,
		'iterations'           => $iterations,
		'html_bytes'           => strlen( $html ),
		'text_bytes'           => strlen( $text ),
		'html_tags_scanned'    => $html_tags,
		'html_resource_urls'   => $html_urls,
		'urls_parsed'          => $text_urls,
		'elapsed_ms'           => $elapsed_ms,
		'native_html'          => $use_native ? 'yes' : 'no',
		'native_url_in_text'   => $use_native ? 'yes' : 'no',
		'html_strategy'        => $use_native ? 'native compact attribute batches' : 'php per-tag scan',
	)
);

