<?php
/**
 * ArgentWolf Post Notifier uninstall handler.
 *
 * Persistent data is preserved by default. Destructive uninstall runs only
 * after the site owner explicitly enables the delete-data option.
 *
 * File: uninstall.php
 *
 * @package ArgentWolf\PostNotifier
 */

use ArgentWolf\PostNotifier\Database\DestructiveUninstaller;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	return;
}

require_once __DIR__ . '/autoload.php';

if ( ! DestructiveUninstaller::is_enabled() ) {
	return;
}

( new DestructiveUninstaller() )->run();

// EOF: uninstall.php.
