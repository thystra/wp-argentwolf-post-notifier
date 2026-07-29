<?php
/**
 * File: src/Lifecycle/UpgradeManager.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Lifecycle;

use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Version;

/**
 * Idempotent plugin-version and schema-version coordinator.
 */
final class UpgradeManager implements Registerable {
	/**
	 * Register the upgrade check.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 5 );
	}

	/**
	 * Record the current code versions.
	 *
	 * Schema migrations begin in the database milestone. Schema zero performs
	 * no database operation.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		$installed_plugin = (string) get_option(
			'argentwolf_post_notifier_version',
			''
		);
		$installed_schema = (string) get_option(
			'argentwolf_post_notifier_schema_version',
			''
		);

		if ( Version::PLUGIN !== $installed_plugin ) {
			update_option(
				'argentwolf_post_notifier_version',
				Version::PLUGIN,
				false
			);
		}

		if ( Version::SCHEMA !== $installed_schema ) {
			update_option(
				'argentwolf_post_notifier_schema_version',
				Version::SCHEMA,
				false
			);
		}
	}
}

// EOF: src/Lifecycle/UpgradeManager.php.
