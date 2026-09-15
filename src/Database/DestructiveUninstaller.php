<?php
/**
 * File: src/Database/DestructiveUninstaller.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Database;

use LogicException;
use RuntimeException;
use wpdb;

/**
 * Explicit destructive removal of plugin-owned persistence.
 */
final class DestructiveUninstaller {
	/**
	 * Opt-in setting required before uninstall deletes persistent data.
	 */
	public const DELETE_DATA_OPTION = 'argentwolf_post_notifier_delete_data_on_uninstall';

	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Construct the destructive uninstaller.
	 *
	 * @param wpdb|null $database Optional explicit WordPress database connection.
	 * @throws LogicException When a WordPress database connection is unavailable.
	 */
	public function __construct( ?wpdb $database = null ) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb ?? null;
		}

		if ( ! $database instanceof wpdb ) {
			throw new LogicException( 'WordPress database connection is unavailable.' );
		}

		$this->database = $database;
	}

	/**
	 * Determine whether the site has explicitly enabled destructive uninstall.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) get_option( self::DELETE_DATA_OPTION, false );
	}

	/**
	 * Remove all persistence owned by the plugin.
	 *
	 * Callers must check is_enabled() before invoking this destructive operation.
	 *
	 * @return void
	 * @throws LogicException When destructive uninstall has not been explicitly enabled.
	 * @throws RuntimeException When a plugin-owned table cannot be removed.
	 */
	public function run(): void {
		if ( ! self::is_enabled() ) {
			throw new LogicException( 'Destructive uninstall is not enabled.' );
		}

		$tables = TableNames::from_database( $this->database )->all();
		foreach ( array_reverse( $tables ) as $table ) {
			$query = $this->database->prepare( 'DROP TABLE IF EXISTS %i', $table );

			// Plugin-owned table removal is necessarily a direct uncached database write.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
			$result = $this->database->query( $query );
			// phpcs:enable
			if ( false === $result ) {
				throw new RuntimeException( 'Plugin-owned database table could not be removed.' );
			}
		}

		delete_option( 'argentwolf_post_notifier_version' );
		delete_option( SchemaMigrator::SCHEMA_OPTION );
		delete_option( EmailIdentity::HASH_KEY_OPTION );
		delete_option( self::DELETE_DATA_OPTION );
	}
}

// EOF: src/Database/DestructiveUninstaller.php.
