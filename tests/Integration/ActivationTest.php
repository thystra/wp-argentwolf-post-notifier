<?php
/**
 * File: tests/Integration/ActivationTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Lifecycle\Activator;
use ArgentWolf\PostNotifier\Lifecycle\Deactivator;
use ArgentWolf\PostNotifier\Version;
use WP_UnitTestCase;

final class ActivationTest extends WP_UnitTestCase {
	public function test_activation_is_idempotent(): void {
		Activator::activate();
		Activator::activate();

		self::assertSame(
			Version::PLUGIN,
			get_option( 'argentwolf_post_notifier_version' )
		);
		self::assertSame(
			Version::SCHEMA,
			get_option( 'argentwolf_post_notifier_schema_version' )
		);
	}

	public function test_deactivation_fires_scaffold_hook_without_removing_version_state(): void {
		Activator::activate();

		$observed_version = null;
		$observer         = static function ( string $plugin_version ) use ( &$observed_version ): void {
			$observed_version = $plugin_version;
		};

		add_action( 'argentwolf_post_notifier_deactivated', $observer );
		Deactivator::deactivate();
		remove_action( 'argentwolf_post_notifier_deactivated', $observer );

		self::assertSame( Version::PLUGIN, $observed_version );
		self::assertSame(
			Version::PLUGIN,
			get_option( 'argentwolf_post_notifier_version' )
		);
	}
}

// EOF: tests/Integration/ActivationTest.php
