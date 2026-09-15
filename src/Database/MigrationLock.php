<?php
/**
 * File: src/Database/MigrationLock.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Database;

use wpdb;

/**
 * Connection-scoped MySQL advisory lock for schema migrations.
 */
final class MigrationLock {
	/**
	 * Advisory-lock name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Construct the migration lock.
	 *
	 * @param wpdb $database WordPress database connection.
	 */
	public function __construct( private wpdb $database ) {
		$identity   = (string) $database->dbname . '|' . $database->prefix;
		$this->name = 'argentwolf_pn_schema_' . substr( hash( 'sha256', $identity ), 0, 32 );
	}

	/**
	 * Acquire the schema lock, waiting for a bounded period.
	 *
	 * @param int $timeout_seconds Maximum wait in seconds.
	 * @return bool
	 */
	public function acquire( int $timeout_seconds = 15 ): bool {
		$query = $this->database->prepare(
			'SELECT GET_LOCK( %s, %d )',
			$this->name,
			max( 0, $timeout_seconds )
		);

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$result = $this->database->get_var( $query );
		// phpcs:enable

		return '1' === (string) $result;
	}

	/**
	 * Release the schema lock held by this connection.
	 *
	 * @return void
	 */
	public function release(): void {
		$query = $this->database->prepare(
			'SELECT RELEASE_LOCK( %s )',
			$this->name
		);

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$this->database->get_var( $query );
		// phpcs:enable
	}
}

// EOF: src/Database/MigrationLock.php.
