<?php

$project_root = $argv[1] ?? dirname( __DIR__, 3 );
$autoloaders  = array(
	$project_root . '/vendor/autoload.php',
	dirname( __DIR__, 3 ) . '/vendor/autoload.php',
);

foreach ( $autoloaders as $autoloader ) {
	if ( file_exists( $autoloader ) ) {
		require_once $autoloader;
		break;
	}
}

$extension_classes = array(
	'WP_HTML_Native_Tag_Processor',
	'WP_HTML_Native_Processor',
	'WordPress\\XML\\NativeXMLProcessor',
	'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor',
);

$missing = array();
foreach ( $extension_classes as $class_name ) {
	if ( ! class_exists( $class_name, false ) ) {
		$missing[] = $class_name;
	}
}

if ( $missing ) {
	fwrite( STDERR, 'Missing native API extension classes: ' . implode( ', ', $missing ) . "\n" );
	exit( 1 );
}

if ( ! function_exists( 'wp_native_apis_rewrite_text_url_bases' ) ) {
	fwrite( STDERR, "Missing native batch text URL rewrite function.\n" );
	exit( 1 );
}

$rewritten = wp_native_apis_rewrite_text_url_bases(
	'Visit http://old.example/posts/7.',
	'http://old.example',
	"http://old.example\x1fhttps://new.example"
);
if ( 'Visit https://new.example/posts/7.' !== $rewritten ) {
	fwrite( STDERR, "Native batch text URL rewrite returned unexpected output: {$rewritten}\n" );
	exit( 1 );
}

echo json_encode(
	array(
		'wp_native_apis'             => 'enabled',
		'extension_classes'          => 'loaded',
		'batch_text_url_rewrite'     => 'verified',
	)
);
