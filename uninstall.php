<?php
/**
 * ArgentWolf Post Notifier uninstall handler.
 *
 * Persistent data is preserved by default. When the site owner has explicitly
 * enabled destructive uninstall, plugin-owned tables, version state, and the
 * keyed email-hash secret are removed.
 *
 * File: uninstall.php
 *
 * @package ArgentWolf\PostNotifier
 */

use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\SchemaMigrator;
use ArgentWolf\PostNotifier\Database\TableNames;

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

require_once __DIR__ . '/autoload.php';

global $wpdb;

$argentwolf_post_notifier_tables = TableNames::from_database( $wpdb )->all();
foreach ( array_reverse( $argentwolf_post_notifier_tables ) as $argentwolf_post_notifier_table ) {
	// Table names come only from the internal TableNames resolver.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	// phpcs:disable WordPress.DB.PreparedSQL
	$wpdb->query(
		"DROP TABLE IF EXISTS `{$argentwolf_post_notifier_table}`"
	);
	// phpcs:enable
}

delete_option( 'argentwolf_post_notifier_version' );
delete_option( SchemaMigrator::SCHEMA_OPTION );
delete_option( EmailIdentity::HASH_KEY_OPTION );
delete_option( 'argentwolf_post_notifier_delete_data_on_uninstall' );

// EOF: uninstall.php.
