<?php
/**
 * File: src/Lifecycle/Activator.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Lifecycle;

use ArgentWolf\PostNotifier\Database\SchemaMigrator;
use ArgentWolf\PostNotifier\Version;

/**
 * Plugin activation.
 */
final class Activator {
	/**
	 * Activate the plugin.
	 *
	 * @return void
	 */
	public static function activate(): void {
		( new SchemaMigrator() )->migrate();

		update_option(
			'argentwolf_post_notifier_version',
			Version::PLUGIN,
			false
		);

		/**
		 * Fires after activation and required schema migrations complete.
		 *
		 * @param string $plugin_version Plugin version.
		 * @param string $schema_version Schema version.
		 */
		do_action(
			'argentwolf_post_notifier_activated',
			Version::PLUGIN,
			Version::SCHEMA
		);
	}

	/**
	 * Prevent construction.
	 */
	private function __construct() {
	}
}

// EOF: src/Lifecycle/Activator.php.
