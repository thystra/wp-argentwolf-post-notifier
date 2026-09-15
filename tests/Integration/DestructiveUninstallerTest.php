<?php
/**
 * File: tests/Integration/DestructiveUninstallerTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Database\DestructiveUninstaller;
use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\SchemaInspector;
use ArgentWolf\PostNotifier\Database\SchemaMigrator;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Version;
use WP_UnitTestCase;

final class DestructiveUninstallerTest extends WP_UnitTestCase {
	public function test_delete_data_option_is_opt_in(): void {
		delete_option( DestructiveUninstaller::DELETE_DATA_OPTION );
		self::assertFalse( DestructiveUninstaller::is_enabled() );

		update_option( DestructiveUninstaller::DELETE_DATA_OPTION, '1', false );
		self::assertTrue( DestructiveUninstaller::is_enabled() );
	}

	public function test_destructive_uninstall_refuses_without_opt_in(): void {
		global $wpdb;

		delete_option( DestructiveUninstaller::DELETE_DATA_OPTION );
		$this->expectException( \LogicException::class );
		( new DestructiveUninstaller( $wpdb ) )->run();
	}

	public function test_destructive_uninstall_removes_owned_state_and_can_be_recovered(): void {
		global $wpdb;

		$tables      = TableNames::from_database( $wpdb );
		$inspector   = new SchemaInspector( $wpdb );
		$uninstaller = new DestructiveUninstaller( $wpdb );

		/*
		 * WP_UnitTestCase rewrites CREATE TABLE and DROP TABLE queries to their
		 * TEMPORARY equivalents inside each test transaction. This test must
		 * exercise the production DDL against the plugin's permanent tables.
		 */
		self::commit_transaction();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		update_option( DestructiveUninstaller::DELETE_DATA_OPTION, '1', false );
		update_option( 'argentwolf_post_notifier_version', Version::PLUGIN, false );
		EmailIdentity::ensure_hash_key();

		try {
			$uninstaller->run();

			foreach ( $tables->all() as $table ) {
				self::assertFalse(
					$inspector->table_exists( $table ),
					'Table should have been removed: ' . $table
				);
			}
			self::assertFalse( get_option( 'argentwolf_post_notifier_version', false ) );
			self::assertFalse( get_option( SchemaMigrator::SCHEMA_OPTION, false ) );
			self::assertFalse( get_option( EmailIdentity::HASH_KEY_OPTION, false ) );
			self::assertFalse(
				get_option( DestructiveUninstaller::DELETE_DATA_OPTION, false )
			);
		} finally {
			try {
				( new SchemaMigrator( $wpdb ) )->migrate();
				update_option( 'argentwolf_post_notifier_version', Version::PLUGIN, false );
				self::commit_transaction();
			} finally {
				$this->start_transaction();
			}
		}

		foreach ( $tables->all() as $table ) {
			self::assertTrue( $inspector->table_exists( $table ) );
		}
		self::assertSame( Version::SCHEMA, get_option( SchemaMigrator::SCHEMA_OPTION ) );
	}
}

// EOF: tests/Integration/DestructiveUninstallerTest.php.
