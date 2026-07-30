<?php
/**
 * File: tests/Integration/VerificationProviderTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Verification\VerificationProvider;
use ArgentWolf\PostNotifier\Verification\VerificationStatus;
use WP_UnitTestCase;

final class VerificationProviderTest extends WP_UnitTestCase {
	public function test_released_companion_contract_is_healthy(): void {
		$provider = Plugin::instance()->container()->get(
			VerificationProvider::class
		);

		self::assertInstanceOf( VerificationProvider::class, $provider );
		self::assertTrue( $provider->health()->is_healthy() );
		self::assertSame( '0.3.4', $provider->health()->version() );
	}

	public function test_companion_statuses_are_mapped_without_private_adapter(): void {
		$provider = Plugin::instance()->container()->get(
			VerificationProvider::class
		);
		self::assertInstanceOf( VerificationProvider::class, $provider );

		$verified_user = self::factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);
		$pending_user  = self::factory()->user->create();
		update_user_meta( $verified_user, '_wrav_ev_verified', '1' );
		update_user_meta( $pending_user, '_wrav_ev_verified', '0' );

		self::assertSame(
			VerificationStatus::Verified,
			$provider->status_for_user( $verified_user )
		);
		self::assertSame(
			VerificationStatus::Pending,
			$provider->status_for_user( $pending_user )
		);

		wp_delete_user( $pending_user );
		self::assertSame(
			VerificationStatus::Unknown,
			$provider->status_for_user( $pending_user )
		);
	}
}

// EOF: tests/Integration/VerificationProviderTest.php.
