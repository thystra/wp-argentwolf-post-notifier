<?php
/**
 * File: src/Database/SchemaMigrator.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Database;

use ArgentWolf\PostNotifier\Database\Migrations\Schema1;
use ArgentWolf\PostNotifier\Version;
use LogicException;
use RuntimeException;
use wpdb;

/**
 * Ordered, idempotent database-schema migration coordinator.
 */
final class SchemaMigrator {
	/**
	 * Schema option name.
	 */
	public const SCHEMA_OPTION = 'argentwolf_post_notifier_schema_version';

	/**
	 * Migration classes keyed by resulting schema version.
	 *
	 * @var array<int,class-string<Migration>>
	 */
	private const MIGRATIONS = array(
		1 => Schema1::class,
	);

	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Construct the schema migrator.
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
	 * Bring the active site schema to the code's target version.
	 *
	 * @return void
	 * @throws RuntimeException When migration cannot proceed safely.
	 */
	public function migrate(): void {
		$target  = $this->target_version();
		$current = $this->installed_version();
		if ( $current > $target ) {
			throw new RuntimeException(
				'Installed database schema is newer than this plugin code.'
			);
		}

		if ( 0 === $target ) {
			return;
		}

		$lock = new MigrationLock( $this->database );
		if ( ! $lock->acquire() ) {
			throw new RuntimeException( 'Database schema migration lock could not be acquired.' );
		}

		try {
			$current = $this->installed_version();
			if ( $current > $target ) {
				throw new RuntimeException(
					'Installed database schema is newer than this plugin code.'
				);
			}

			if ( $current === $target ) {
				// Re-applying the current idempotent migration repairs recoverable
				// table/index drift before the schema is considered healthy.
				$migration = $this->migration_for( $target );
				$migration->up();
				$migration->verify();
				return;
			}

			for ( $version = $current + 1; $version <= $target; ++$version ) {
				$migration = $this->migration_for( $version );
				$migration->up();
				$migration->verify();
				update_option( self::SCHEMA_OPTION, (string) $version, false );
			}
		} finally {
			$lock->release();
		}
	}

	/**
	 * Return the installed schema version.
	 *
	 * @return int
	 * @throws RuntimeException When the stored schema version is invalid.
	 */
	public function installed_version(): int {
		$installed = (string) get_option( self::SCHEMA_OPTION, '0' );
		if ( ! ctype_digit( $installed ) ) {
			throw new RuntimeException( 'Installed database schema version is invalid.' );
		}

		return (int) $installed;
	}

	/**
	 * Instantiate a registered migration.
	 *
	 * @param int $version Resulting schema version.
	 * @return Migration
	 * @throws RuntimeException When the migration is not registered.
	 */
	private function migration_for( int $version ): Migration {
		$migration_class = self::MIGRATIONS[ $version ] ?? null;
		if ( null === $migration_class ) {
			// Internal exception text is not rendered output; version is a typed integer.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException(
				sprintf( 'Database migration %d is not registered.', $version )
			);
			// phpcs:enable
		}

		return new $migration_class( $this->database );
	}

	/**
	 * Return the target schema version from code.
	 *
	 * @return int
	 * @throws LogicException When the code schema version or migration map is invalid.
	 */
	private function target_version(): int {
		if ( ! ctype_digit( Version::SCHEMA ) ) {
			throw new LogicException( 'Code database schema version is invalid.' );
		}

		$target = (int) Version::SCHEMA;
		if ( $target > 0 && ! isset( self::MIGRATIONS[ $target ] ) ) {
			throw new LogicException( 'Target database schema migration is not registered.' );
		}

		return $target;
	}
}

// EOF: src/Database/SchemaMigrator.php.
