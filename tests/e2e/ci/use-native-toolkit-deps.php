<?php

/**
 * Rewrites Composer metadata inside the performance workflow workspace so the
 * benchmark PHAR can use native-aware php-toolkit packages without making the
 * normal Reprint test matrix depend on a checked-out php-toolkit-native path.
 */

$project_root = dirname( __DIR__, 3 );

function reprint_ci_read_json( $path ) {
	$contents = file_get_contents( $path );
	if ( false === $contents ) {
		fwrite( STDERR, "Could not read $path\n" );
		exit( 1 );
	}

	$data = json_decode( $contents, true );
	if ( ! is_array( $data ) ) {
		fwrite( STDERR, "Could not decode $path: " . json_last_error_msg() . "\n" );
		exit( 1 );
	}

	return $data;
}

function reprint_ci_write_json( $path, $data ) {
	$encoded = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( false === $encoded ) {
		fwrite( STDERR, "Could not encode $path: " . json_last_error_msg() . "\n" );
		exit( 1 );
	}

	file_put_contents( $path, $encoded . "\n" );
}

function reprint_ci_patch_package_require( $project_root, $relative_path ) {
	$path = $project_root . '/' . $relative_path;
	$data = reprint_ci_read_json( $path );

	$data['require']['wp-php-toolkit/data-liberation'] = '^0.8';
	$data['require']['wp-php-toolkit/html']            = '^0.8';

	reprint_ci_write_json( $path, $data );
}

$composer_path = $project_root . '/composer.json';
$composer      = reprint_ci_read_json( $composer_path );

$native_repositories = array(
	array(
		'type'    => 'path',
		'url'     => 'php-toolkit-native/components/DataLiberation',
		'options' => array(
			'symlink'  => false,
			'versions' => array(
				'wp-php-toolkit/data-liberation' => 'dev-trunk',
			),
		),
	),
	array(
		'type'    => 'path',
		'url'     => 'php-toolkit-native/components/HttpClient',
		'options' => array(
			'symlink'  => false,
			'versions' => array(
				'wp-php-toolkit/http-client' => 'dev-trunk',
			),
		),
	),
);

$repositories = isset( $composer['repositories'] ) && is_array( $composer['repositories'] )
	? $composer['repositories']
	: array();

$repositories = array_values(
	array_filter(
		$repositories,
		function ( $repository ) {
			return ! isset( $repository['url'] ) || 0 !== strpos( $repository['url'], 'php-toolkit-native/' );
		}
	)
);

$composer['repositories'] = array_merge( $repositories, $native_repositories );

$composer['require']['wp-php-toolkit/bytestream']      = 'v0.7.6 as 0.8.99';
$composer['require']['wp-php-toolkit/data-liberation'] = 'dev-trunk as 0.8.99';
$composer['require']['wp-php-toolkit/encoding']        = 'v0.7.6 as 0.8.99';
$composer['require']['wp-php-toolkit/filesystem']      = 'v0.7.6 as 0.8.99';
$composer['require']['wp-php-toolkit/html']            = 'v0.7.6 as 0.8.99';
$composer['require']['wp-php-toolkit/http-client']     = 'dev-trunk as 0.8.99';
$composer['require']['wp-php-toolkit/xml']             = 'v0.7.6 as 0.8.99';

reprint_ci_write_json( $composer_path, $composer );
reprint_ci_patch_package_require( $project_root, 'packages/reprint-exporter/composer.json' );
reprint_ci_patch_package_require( $project_root, 'packages/reprint-importer/composer.json' );

