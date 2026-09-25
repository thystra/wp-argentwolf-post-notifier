<?php
/**
 * File: tests/Integration/DatabaseSchemaTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Database\DestructiveUninstaller;
use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\MigrationLock;
use ArgentWolf\PostNotifier\Database\SchemaInspector;
use ArgentWolf\PostNotifier\Database\SchemaMigrator;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Lifecycle\UpgradeManager;
use ArgentWolf\PostNotifier\Version;
use WP_UnitTestCase;

final class DatabaseSchemaTest extends WP_UnitTestCase {
	public function test_schema_one_tables_and_required_indexes_exist(): void {
		global $wpdb;

		$tables    = TableNames::from_database( $wpdb );
		$inspector = new SchemaInspector( $wpdb );
		foreach ( $tables->all() as $table ) {
			self::assertTrue( $inspector->table_exists( $table ) );
		}

		$this->assert_indexes(
			$tables->campaigns(),
			array(
				'PRIMARY',
				'uuid',
				'campaign_key',
				'post_id',
				'status',
				'created_at_gmt',
				'completed_at_gmt',
			)
		);
		$this->assert_indexes(
			$tables->campaign_recipients(),
			array(
				'PRIMARY',
				'recipient_uuid',
				'campaign_email',
				'unsubscribe_token_hash',
				'click_token_hash',
				'campaign_status',
				'queue_ready',
				'lease_expires_at_gmt',
				'personal_data_erased_at_gmt',
				'user_id',
				'subscriber_id',
			)
		);
		$this->assert_indexes(
			$tables->subscribers(),
			array(
				'PRIMARY',
				'uuid',
				'email_hash',
				'confirmation_token_hash',
				'manage_token_hash',
				'status',
				'confirmation_expires_at_gmt',
				'source_post_id',
			)
		);
		$this->assert_indexes(
			$tables->lists(),
			array( 'PRIMARY', 'uuid', 'name', 'created_by' )
		);
		$this->assert_indexes(
			$tables->list_members(),
			array( 'PRIMARY', 'typed_membership', 'user_id', 'subscriber_id' )
		);
		$this->assert_indexes(
			$tables->suppressions(),
			array( 'PRIMARY', 'email_hash', 'reason', 'updated_at_gmt' )
		);
		$this->assert_indexes(
			$tables->clicks(),
			array( 'PRIMARY', 'campaign_recipient_id', 'clicked_at_gmt' )
		);

		$recipient_columns = $inspector->columns( $tables->campaign_recipients() );
		self::assertSame( 'YES', $recipient_columns['email_snapshot']['Null'] ?? null );
		self::assertSame( 'YES', $recipient_columns['email_hash']['Null'] ?? null );
		self::assertArrayHasKey( 'personal_data_erased_at_gmt', $recipient_columns );
	}

	public function test_migration_from_schema_zero_is_idempotent(): void {
		global $wpdb;

		$tables    = TableNames::from_database( $wpdb );
		$inspector = new SchemaInspector( $wpdb );
		update_option( SchemaMigrator::SCHEMA_OPTION, '0', false );

		$migrator = new SchemaMigrator( $wpdb );
		$migrator->migrate();
		$migrator->migrate();

		self::assertSame( Version::SCHEMA, get_option( SchemaMigrator::SCHEMA_OPTION ) );
		foreach ( $tables->all() as $table ) {
			self::assertTrue( $inspector->table_exists( $table ) );
		}
	}

	public function test_failed_migration_does_not_advance_and_can_retry_after_repair(): void {
		global $wpdb;

		update_option( SchemaMigrator::SCHEMA_OPTION, '0', false );
		update_option( EmailIdentity::HASH_KEY_OPTION, 'invalid-key', false );

		$caught = null;
		try {
			( new SchemaMigrator( $wpdb ) )->migrate();
		} catch ( \RuntimeException $exception ) {
			$caught = $exception;
		}

		self::assertInstanceOf( \RuntimeException::class, $caught );
		self::assertSame( '0', get_option( SchemaMigrator::SCHEMA_OPTION ) );

		delete_option( EmailIdentity::HASH_KEY_OPTION );
		( new SchemaMigrator( $wpdb ) )->migrate();
		self::assertSame( Version::SCHEMA, get_option( SchemaMigrator::SCHEMA_OPTION ) );
		EmailIdentity::validate_hash_key();
	}

	public function test_current_schema_drift_is_repaired_without_version_advance(): void {
		global $wpdb;

		$tables = TableNames::from_database( $wpdb );
		$table  = $tables->campaigns();

		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `completed_at_gmt`" );
		self::assertNotContains(
			'completed_at_gmt',
			( new SchemaInspector( $wpdb ) )->indexes( $table )
		);
		update_option( SchemaMigrator::SCHEMA_OPTION, Version::SCHEMA, false );

		( new SchemaMigrator( $wpdb ) )->migrate();

		self::assertSame( Version::SCHEMA, get_option( SchemaMigrator::SCHEMA_OPTION ) );
		self::assertContains(
			'completed_at_gmt',
			( new SchemaInspector( $wpdb ) )->indexes( $table )
		);
	}

	/**
	 * Verify each tagged schema-zero checkpoint upgrades from an actually empty
	 * plugin-owned database state.
	 *
	 * @dataProvider released_schema_zero_checkpoints
	 *
	 * @param string $plugin_version Released plugin version.
	 * @return void
	 */
	public function test_released_schema_zero_checkpoint_upgrades_to_schema_one( string $plugin_version ): void {
		global $wpdb;

		update_option( DestructiveUninstaller::DELETE_DATA_OPTION, true, false );
		( new DestructiveUninstaller( $wpdb ) )->run();

		update_option( 'argentwolf_post_notifier_version', $plugin_version, false );
		update_option( SchemaMigrator::SCHEMA_OPTION, '0', false );

		( new UpgradeManager( new SchemaMigrator( $wpdb ) ) )->maybe_upgrade();

		self::assertSame( Version::PLUGIN, get_option( 'argentwolf_post_notifier_version' ) );
		self::assertSame( Version::SCHEMA, get_option( SchemaMigrator::SCHEMA_OPTION ) );
		foreach ( TableNames::from_database( $wpdb )->all() as $table ) {
			self::assertTrue( ( new SchemaInspector( $wpdb ) )->table_exists( $table ) );
		}
	}

	/**
	 * Tagged releases that shipped before plugin-owned tables existed.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function released_schema_zero_checkpoints(): array {
		return array(
			'alpha.1' => array( '0.1.0-alpha.1' ),
			'alpha.2' => array( '0.1.0-alpha.2' ),
		);
	}

	public function test_plugin_upgrade_revalidates_schema_even_when_schema_version_is_current(): void {
		global $wpdb;

		$tables = TableNames::from_database( $wpdb );
		$table  = $tables->campaign_recipients();
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `personal_data_erased_at_gmt`" );
		update_option( SchemaMigrator::SCHEMA_OPTION, Version::SCHEMA, false );
		update_option( 'argentwolf_post_notifier_version', '0.1.0-alpha.2', false );

		( new UpgradeManager( new SchemaMigrator( $wpdb ) ) )->maybe_upgrade();

		self::assertContains(
			'personal_data_erased_at_gmt',
			( new SchemaInspector( $wpdb ) )->indexes( $table )
		);
		self::assertSame(
			Version::PLUGIN,
			get_option( 'argentwolf_post_notifier_version' )
		);
	}

	public function test_newer_database_schema_is_refused(): void {
		global $wpdb;

		update_option( SchemaMigrator::SCHEMA_OPTION, '2', false );

		try {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessage( 'newer than this plugin code' );
			( new SchemaMigrator( $wpdb ) )->migrate();
		} finally {
			update_option( SchemaMigrator::SCHEMA_OPTION, Version::SCHEMA, false );
		}
	}

	public function test_email_identity_is_normalized_and_keyed(): void {
		$key      = str_repeat( "\x5a", 32 );
		$identity = new EmailIdentity( $key );

		self::assertSame( 'person@example.com', $identity->normalize( ' Person@Example.COM ' ) );
		self::assertNull( $identity->normalize( 'not-an-email' ) );
		self::assertSame(
			hash_hmac( 'sha256', 'person@example.com', $key ),
			$identity->hash( 'Person@Example.COM' )
		);
		self::assertSame(
			$identity->hash( 'Person@Example.COM' ),
			$identity->hash( 'person@example.com' )
		);
		self::assertNotSame(
			$identity->hash( 'person@example.com' ),
			$identity->hash( 'other@example.com' )
		);

		$stored_key = get_option( EmailIdentity::HASH_KEY_OPTION, '' );
		self::assertIsString( $stored_key );
		self::assertNotSame( '', $stored_key );
		self::assertSame(
			( new EmailIdentity() )->hash( 'Person@Example.COM' ),
			( new EmailIdentity() )->hash( 'person@example.com' )
		);
	}

	public function test_migration_lock_excludes_a_second_database_connection(): void {
		global $wpdb;

		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->set_prefix( $wpdb->prefix );

		$primary_lock = new MigrationLock( $wpdb );
		$other_lock   = new MigrationLock( $other );

		self::assertTrue( $primary_lock->acquire( 0 ) );
		try {
			self::assertFalse( $other_lock->acquire( 0 ) );
		} finally {
			$primary_lock->release();
		}

		self::assertTrue( $other_lock->acquire( 0 ) );
		$other_lock->release();
		$other->close();
	}

	/**
	 * Assert named indexes exist on a table.
	 *
	 * @param string        $table Table name.
	 * @param array<string> $expected Expected index names.
	 * @return void
	 */
	private function assert_indexes( string $table, array $expected ): void {
		global $wpdb;

		$keys = ( new SchemaInspector( $wpdb ) )->indexes( $table );
		foreach ( $expected as $index ) {
			self::assertContains( $index, $keys, "Missing index {$index} on {$table}." );
		}
	}
}

// EOF: tests/Integration/DatabaseSchemaTest.php.
