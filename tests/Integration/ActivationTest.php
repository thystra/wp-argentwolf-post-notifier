<?php
/**
 * File: tests/Integration/ActivationTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Lifecycle\Activator;
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
}

// EOF: tests/Integration/ActivationTest.php
