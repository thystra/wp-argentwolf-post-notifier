<?php
/**
 * Dependency-free scaffold tests.
 *
 * File: tests/run.php
 */

$root     = dirname( __DIR__ );
$failures = array();

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

require_once $root . '/autoload.php';

use ArgentWolf\PostNotifier\Lifecycle\Activator;
use ArgentWolf\PostNotifier\Lifecycle\UpgradeManager;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Support\Container;
use ArgentWolf\PostNotifier\Version;

$main   = file_get_contents( $root . '/argentwolf-post-notifier.php' );
$readme = file_get_contents( $root . '/readme.txt' );
$installer = file_get_contents( $root . '/bin/install-wp-tests.sh' );
$workflow  = file_get_contents( $root . '/.github/workflows/ci.yml' );

preg_match( '/^[\h]*\*[\h]+Version:[\h]*(\S+)[\h]*$/m', (string) $main, $header );
preg_match( '/^Stable tag:[\h]*(\S+)[\h]*$/m', (string) $readme, $stable );
preg_match( '/^[\h]*\*[\h]+Requires at least:[\h]*(\S+)[\h]*$/m', (string) $main, $wordpress );
preg_match( '/^[\h]*\*[\h]+Requires PHP:[\h]*(\S+)[\h]*$/m', (string) $main, $php );

$assert( Version::PLUGIN === ( $header[1] ?? null ), 'Plugin header and Version::PLUGIN must match.' );
$assert( Version::PLUGIN === ( $stable[1] ?? null ), 'Plugin version and readme Stable Tag must match.' );
$assert( '7.0' === ( $wordpress[1] ?? null ), 'Requires at least must be WordPress 7.0.' );
$assert( '8.2' === ( $php[1] ?? null ), 'Requires PHP must be 8.2.' );
$assert(
	! str_contains( (string) $main, 'Requires Plugins:' ),
	'The unresolved companion dependency must not be declared yet.'
);

$assert(
	! str_contains( (string) $installer, 'main "$@" || true' ),
	'WordPress test installation failures must not be suppressed.'
);
$assert(
	str_contains( (string) $installer, 'main "$@"' ),
	'WordPress test installer must return its real child-process status.'
);
$assert(
	str_contains(
		(string) $workflow,
		'test -r "${WP_TESTS_DIR}/includes/functions.php"'
	),
	'CI must verify the installed WordPress test-library path.'
);
$assert(
	str_contains( (string) $workflow, 'sudo apt-get install --yes subversion' ),
	'CI must install the Subversion dependency explicitly.'
);
$assert(
	! str_contains(
		(string) $installer,
		'mkdir -p "${tests_dir}" "${core_dir}"'
	),
	'WordPress core export destination must not be pre-created.'
);
$container = new Container();
$created   = 0;
$container->set(
	'test',
	static function () use ( &$created ): object {
		++$created;
		return new stdClass();
	}
);
$assert( $container->has( 'test' ), 'Container must report registered services.' );
$assert( $container->get( 'test' ) === $container->get( 'test' ), 'Services must be shared.' );
$assert( 1 === $created, 'Container factory must run once.' );

$GLOBALS['argentwolf_post_notifier_test_actions'] = array();
$GLOBALS['argentwolf_post_notifier_test_options'] = array();

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10 ): void {
		$GLOBALS['argentwolf_post_notifier_test_actions'][] = array( $hook, $callback, $priority );
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, mixed $value, bool $autoload = true ): bool {
		unset( $autoload );
		$GLOBALS['argentwolf_post_notifier_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['argentwolf_post_notifier_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$arguments ): void {
		unset( $hook, $arguments );
	}
}

$manager = new UpgradeManager();
$manager->register();

$assert(
	'plugins_loaded' === ( $GLOBALS['argentwolf_post_notifier_test_actions'][0][0] ?? null ),
	'UpgradeManager must register on plugins_loaded.'
);

Activator::activate();

$assert(
	Version::PLUGIN === (
		$GLOBALS['argentwolf_post_notifier_test_options']['argentwolf_post_notifier_version']
		?? null
	),
	'Activation must record the plugin version.'
);
$assert(
	Version::SCHEMA === (
		$GLOBALS['argentwolf_post_notifier_test_options']['argentwolf_post_notifier_schema_version']
		?? null
	),
	'Activation must record the schema version.'
);
$assert( Plugin::instance() === Plugin::instance(), 'Plugin::instance() must return one instance.' );

if ( array() !== $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, "All dependency-free scaffold tests passed.\n" );

// EOF: tests/run.php
