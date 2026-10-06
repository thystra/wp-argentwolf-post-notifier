<?php
/**
 * File: src/Admin/Capabilities.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

/**
 * Dedicated administration capability installation and removal.
 */
final class Capabilities {
	/**
	 * Capability for standalone subscriber administration and CSV intake.
	 */
	public const MANAGE_SUBSCRIBERS = 'manage_post_notification_subscribers';

	/**
	 * Capability for named-list administration.
	 */
	public const MANAGE_LISTS = 'manage_post_notification_lists';

	/**
	 * Capability for editing per-post notification intent and configuration.
	 */
	public const SEND_NOTIFICATIONS = 'send_post_notifications';

	/**
	 * Stored capability-layout version.
	 */
	public const VERSION_OPTION = 'argentwolf_post_notifier_capability_version';

	/**
	 * Current capability-layout version.
	 */
	public const VERSION = '2';

	/**
	 * Install the current capability layout for the administrator role.
	 *
	 * Normal upgrade checks are version-gated. Activation may force a refresh so
	 * reactivating the plugin restores the administrator management baseline.
	 *
	 * @param bool $force Whether to refresh the administrator grants unconditionally.
	 * @return void
	 */
	public static function install( bool $force = false ): void {
		if (
			! $force
			&& self::VERSION === (string) get_option( self::VERSION_OPTION, '' )
		) {
			return;
		}

		$administrator = get_role( 'administrator' );
		if ( null === $administrator ) {
			return;
		}

		foreach ( self::all() as $capability ) {
			$administrator->add_cap( $capability );
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Remove plugin-owned capabilities from every role during uninstall.
	 *
	 * Deactivation deliberately does not call this method so delegated access is
	 * retained across ordinary disable/enable maintenance cycles.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		foreach ( array_keys( wp_roles()->get_names() ) as $role_name ) {
			$role = get_role( $role_name );
			if ( null === $role ) {
				continue;
			}

			foreach ( self::all() as $capability ) {
				$role->remove_cap( $capability );
			}
		}

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Return all plugin-owned management capabilities.
	 *
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array(
			self::MANAGE_SUBSCRIBERS,
			self::MANAGE_LISTS,
			self::SEND_NOTIFICATIONS,
		);
	}

	/**
	 * Prevent construction.
	 */
	private function __construct() {
	}
}

// EOF: src/Admin/Capabilities.php.
