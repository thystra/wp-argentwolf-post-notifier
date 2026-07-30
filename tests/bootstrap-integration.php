<?php
/**
 * File: tests/bootstrap-integration.php
 *
 * @package ArgentWolf\PostNotifier\Tests
 */

$composer = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_readable( $composer ) ) {
	fwrite( STDERR, "Composer dependencies are required for integration tests.\n" );
	exit( 1 );
}

require_once $composer;

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define(
		'WP_TESTS_PHPUNIT_POLYFILLS_PATH',
		dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills'
	);
}

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! is_readable( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library is missing. Run bin/install-wp-tests.sh first.\n" );
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		$companion = getenv( 'ARGENTWOLF_EMAIL_VERIFICATION_FILE' );
		if ( $companion && is_readable( $companion ) ) {
			require_once $companion;
		}

		require dirname( __DIR__ ) . '/argentwolf-post-notifier.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';

// EOF: tests/bootstrap-integration.php.
