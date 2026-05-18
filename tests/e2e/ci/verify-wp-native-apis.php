<?php

$project_root = $argv[1] ?? dirname( __DIR__, 3 );
$mode         = $argv[2] ?? 'public-api';
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

if ( 'extension-only' === $mode ) {
	echo json_encode(
		array(
			'wp_native_apis'    => 'enabled',
			'extension_classes' => 'loaded',
			'public_api_bridge' => 'not required',
		)
	);
	exit( 0 );
}

$public_classes = array(
	'WordPress\\DataLiberation\\URL\\URLInTextProcessor' => 'WordPress\\DataLiberation\\URL\\NativeURLInTextProcessor',
);

$not_native = array();
foreach ( $public_classes as $public_class => $native_class ) {
	if ( ! class_exists( $public_class ) ) {
		$not_native[] = $public_class . ' (not autoloadable)';
		continue;
	}

	if ( ! is_subclass_of( $public_class, $native_class ) ) {
		$not_native[] = $public_class . ' does not extend ' . $native_class;
	}
}

if ( $not_native ) {
	fwrite( STDERR, "Public API classes are not using native implementations:\n- " . implode( "\n- ", $not_native ) . "\n" );
	exit( 1 );
}

echo json_encode(
	array(
		'wp_native_apis' => 'enabled',
		'public_api_bridge' => 'url-in-text',
		'html_tag'      => 'php',
		'html'          => 'php',
		'xml'           => 'php',
		'url_in_text'   => 'native',
	)
);
