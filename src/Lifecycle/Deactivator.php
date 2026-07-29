<?php
/**
 * File: src/Lifecycle/Deactivator.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Lifecycle;

use ArgentWolf\PostNotifier\Version;

/**
 * Plugin deactivation.
 */
final class Deactivator {
	/**
	 * Deactivate the plugin.
	 *
	 * No persistent data is deleted during deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		/**
		 * Fires after the development scaffold deactivates.
		 *
		 * @param string $plugin_version Plugin version.
		 */
		do_action(
			'argentwolf_post_notifier_deactivated',
			Version::PLUGIN
		);
	}

	/**
	 * Prevent construction.
	 */
	private function __construct() {
	}
}

// EOF: src/Lifecycle/Deactivator.php.
