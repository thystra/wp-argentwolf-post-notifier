<?php
/**
 * File: tests/Integration/CapabilityTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\Capabilities;
use ArgentWolf\PostNotifier\Database\SchemaMigrator;
use ArgentWolf\PostNotifier\Lifecycle\Activator;
use ArgentWolf\PostNotifier\Lifecycle\Deactivator;
use ArgentWolf\PostNotifier\Lifecycle\UpgradeManager;
use ArgentWolf\PostNotifier\Subscriber\PendingSubscriberCleanup;
use ArgentWolf\PostNotifier\Version;
use WP_UnitTestCase;

final class CapabilityTest extends WP_UnitTestCase {
	public function tear_down(): void {
		wp_set_current_user( 0 );
		remove_role( 'arpn_list_manager' );
		remove_role( 'arpn_sender' );
		Capabilities::uninstall();
		Capabilities::install( true );
		PendingSubscriberCleanup::unschedule();
		parent::tear_down();
	}

	public function test_activation_installs_plugin_capabilities_for_administrators_only(): void {
		Capabilities::uninstall();
		Activator::activate();

		$administrator = get_role( 'administrator' );
		$editor        = get_role( 'editor' );
		self::assertInstanceOf( \WP_Role::class, $administrator );
		self::assertInstanceOf( \WP_Role::class, $editor );

		foreach ( Capabilities::all() as $capability ) {
			self::assertTrue( $administrator->has_cap( $capability ) );
			self::assertFalse( $editor->has_cap( $capability ) );
		}
		self::assertSame(
			Capabilities::VERSION,
			get_option( Capabilities::VERSION_OPTION )
		);
	}

	public function test_upgrade_check_installs_capabilities_without_plugin_version_change(): void {
		Capabilities::uninstall();
		update_option( 'argentwolf_post_notifier_version', Version::PLUGIN, false );
		update_option( SchemaMigrator::SCHEMA_OPTION, Version::SCHEMA, false );

		( new UpgradeManager() )->maybe_upgrade();

		$administrator = get_role( 'administrator' );
		self::assertInstanceOf( \WP_Role::class, $administrator );
		foreach ( Capabilities::all() as $capability ) {
			self::assertTrue( $administrator->has_cap( $capability ) );
		}
		self::assertSame(
			Capabilities::VERSION,
			get_option( Capabilities::VERSION_OPTION )
		);
	}

	public function test_deactivation_preserves_delegated_management_capabilities(): void {
		Capabilities::install( true );
		$role = add_role(
			'arpn_list_manager',
			'ARPN List Manager',
			array(
				'read'                            => true,
				Capabilities::MANAGE_LISTS          => true,
				Capabilities::MANAGE_SUBSCRIBERS    => true,
				Capabilities::SEND_NOTIFICATIONS    => true,
			)
		);
		self::assertInstanceOf( \WP_Role::class, $role );

		Deactivator::deactivate();

		$role = get_role( 'arpn_list_manager' );
		self::assertInstanceOf( \WP_Role::class, $role );
		self::assertTrue( $role->has_cap( Capabilities::MANAGE_LISTS ) );
		self::assertTrue( $role->has_cap( Capabilities::MANAGE_SUBSCRIBERS ) );
		self::assertTrue( $role->has_cap( Capabilities::SEND_NOTIFICATIONS ) );
	}

	public function test_uninstall_removes_plugin_management_capabilities_from_all_roles(): void {
		Capabilities::install( true );
		$role = add_role(
			'arpn_list_manager',
			'ARPN List Manager',
			array(
				'read'                            => true,
				Capabilities::MANAGE_LISTS          => true,
				Capabilities::MANAGE_SUBSCRIBERS    => true,
				Capabilities::SEND_NOTIFICATIONS    => true,
			)
		);
		self::assertInstanceOf( \WP_Role::class, $role );

		Capabilities::uninstall();

		$administrator = get_role( 'administrator' );
		$role          = get_role( 'arpn_list_manager' );
		self::assertInstanceOf( \WP_Role::class, $administrator );
		self::assertInstanceOf( \WP_Role::class, $role );
		foreach ( Capabilities::all() as $capability ) {
			self::assertFalse( $administrator->has_cap( $capability ) );
			self::assertFalse( $role->has_cap( $capability ) );
		}
		self::assertFalse( get_option( Capabilities::VERSION_OPTION, false ) );
	}

	public function test_send_capability_can_be_delegated_without_management_access(): void {
		Capabilities::install( true );
		$role = add_role(
			'arpn_sender',
			'ARPN Sender',
			array(
				'read'                           => true,
				Capabilities::SEND_NOTIFICATIONS => true,
			)
		);
		self::assertInstanceOf( \WP_Role::class, $role );

		$user_id = self::factory()->user->create(
			array( 'role' => 'arpn_sender' )
		);
		wp_set_current_user( $user_id );

		self::assertTrue( current_user_can( Capabilities::SEND_NOTIFICATIONS ) );
		self::assertFalse( current_user_can( Capabilities::MANAGE_LISTS ) );
		self::assertFalse( current_user_can( Capabilities::MANAGE_SUBSCRIBERS ) );
	}

	public function test_list_management_can_be_delegated_without_subscriber_access(): void {
		Capabilities::install( true );
		$role = add_role(
			'arpn_list_manager',
			'ARPN List Manager',
			array(
				'read'                    => true,
				Capabilities::MANAGE_LISTS => true,
			)
		);
		self::assertInstanceOf( \WP_Role::class, $role );

		$user_id = self::factory()->user->create(
			array( 'role' => 'arpn_list_manager' )
		);
		wp_set_current_user( $user_id );

		self::assertTrue( current_user_can( Capabilities::MANAGE_LISTS ) );
		self::assertFalse( current_user_can( Capabilities::MANAGE_SUBSCRIBERS ) );
		self::assertFalse( current_user_can( Capabilities::SEND_NOTIFICATIONS ) );
	}
}

// EOF: tests/Integration/CapabilityTest.php.
