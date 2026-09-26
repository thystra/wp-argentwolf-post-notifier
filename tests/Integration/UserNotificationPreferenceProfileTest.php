<?php
/**
 * File: tests/Integration/UserNotificationPreferenceProfileTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\UserNotificationPreferenceProfile;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreference;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreferenceRepository;
use WP_UnitTestCase;

final class UserNotificationPreferenceProfileTest extends WP_UnitTestCase {
	protected function tearDown(): void {
		unset( $_POST[ UserNotificationPreferenceProfile::FIELD_NAME ] );
		unset( $_POST[ UserNotificationPreferenceProfile::NONCE_FIELD ] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_plugin_registers_self_service_profile_hooks(): void {
		$service = Plugin::instance()->container()->get(
			UserNotificationPreferenceProfile::class
		);

		self::assertInstanceOf( UserNotificationPreferenceProfile::class, $service );
		self::assertNotFalse(
			has_action( 'show_user_profile', array( $service, 'render' ) )
		);
		self::assertNotFalse(
			has_action( 'personal_options_update', array( $service, 'save' ) )
		);
		self::assertFalse(
			has_action( 'edit_user_profile_update', array( $service, 'save' ) )
		);
	}

	public function test_repository_defaults_missing_or_unknown_metadata_to_site_default(): void {
		$user_id     = self::factory()->user->create();
		$preferences = new RegisteredUserPreferenceRepository();

		self::assertSame(
			RegisteredUserPreference::SiteDefault,
			$preferences->get( $user_id )
		);

		update_user_meta(
			$user_id,
			RegisteredUserPreferenceRepository::META_KEY,
			'unexpected'
		);

		self::assertSame(
			RegisteredUserPreference::SiteDefault,
			$preferences->get( $user_id )
		);
	}

	public function test_current_user_can_store_each_defined_preference(): void {
		$user_id     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$preferences = new RegisteredUserPreferenceRepository();
		$profile     = new UserNotificationPreferenceProfile( $preferences );
		wp_set_current_user( $user_id );

		foreach ( RegisteredUserPreference::cases() as $preference ) {
			$_POST[ UserNotificationPreferenceProfile::NONCE_FIELD ] = wp_create_nonce(
				UserNotificationPreferenceProfile::NONCE_ACTION . ':' . $user_id
			);
			$_POST[ UserNotificationPreferenceProfile::FIELD_NAME ] = $preference->value;
			$profile->save( $user_id );

			self::assertSame( $preference, $preferences->get( $user_id ) );
			self::assertSame(
				$preference->value,
				get_user_meta(
					$user_id,
					RegisteredUserPreferenceRepository::META_KEY,
					true
				)
			);
		}
	}

	public function test_invalid_request_does_not_replace_existing_preference(): void {
		$user_id     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$preferences = new RegisteredUserPreferenceRepository();
		$profile     = new UserNotificationPreferenceProfile( $preferences );
		$preferences->set( $user_id, RegisteredUserPreference::Unsubscribed );
		wp_set_current_user( $user_id );
		$_POST[ UserNotificationPreferenceProfile::NONCE_FIELD ] = wp_create_nonce(
			UserNotificationPreferenceProfile::NONCE_ACTION . ':' . $user_id
		);

		$_POST[ UserNotificationPreferenceProfile::FIELD_NAME ] = 'invalid-state';
		$profile->save( $user_id );

		self::assertSame(
			RegisteredUserPreference::Unsubscribed,
			$preferences->get( $user_id )
		);
	}

	public function test_administrator_cannot_use_profile_hook_to_change_another_user(): void {
		$admin_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user_id     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$preferences = new RegisteredUserPreferenceRepository();
		$profile     = new UserNotificationPreferenceProfile( $preferences );
		wp_set_current_user( $admin_id );
		$_POST[ UserNotificationPreferenceProfile::NONCE_FIELD ] = wp_create_nonce(
			UserNotificationPreferenceProfile::NONCE_ACTION . ':' . $user_id
		);

		$_POST[ UserNotificationPreferenceProfile::FIELD_NAME ] =
			RegisteredUserPreference::Subscribed->value;
		$profile->save( $user_id );

		self::assertSame(
			RegisteredUserPreference::SiteDefault,
			$preferences->get( $user_id )
		);
	}

	public function test_profile_control_renders_only_for_the_current_user(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_userdata( $user_id );
		$profile = new UserNotificationPreferenceProfile(
			new RegisteredUserPreferenceRepository()
		);
		self::assertNotFalse( $user );

		wp_set_current_user( $user_id );
		ob_start();
		$profile->render( $user );
		$output = (string) ob_get_clean();
		self::assertStringContainsString( 'Email notification preference', $output );
		self::assertStringContainsString( 'site_default', $output );
		self::assertStringContainsString( 'unsubscribed', $output );

		wp_set_current_user( $other );
		ob_start();
		$profile->render( $user );
		self::assertSame( '', (string) ob_get_clean() );
	}
}

// EOF: tests/Integration/UserNotificationPreferenceProfileTest.php.
