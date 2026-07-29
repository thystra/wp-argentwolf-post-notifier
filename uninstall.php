<?php
/**
 * ArgentWolf Post Notifier uninstall handler.
 *
 * Persistent data is preserved unless the site owner has explicitly enabled
 * destructive uninstall. The first scaffold has no custom tables.
 *
 * File: uninstall.php
 *
 * @package ArgentWolf\PostNotifier
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	return;
}

$argentwolf_post_notifier_delete_data = (bool) get_option(
	'argentwolf_post_notifier_delete_data_on_uninstall',
	false
);

if ( ! $argentwolf_post_notifier_delete_data ) {
	return;
}

delete_option( 'argentwolf_post_notifier_version' );
delete_option( 'argentwolf_post_notifier_schema_version' );
delete_option( 'argentwolf_post_notifier_delete_data_on_uninstall' );

// EOF: uninstall.php.
