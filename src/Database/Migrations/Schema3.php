<?php
/**
 * File: src/Database/Migrations/Schema3.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Database\Migrations;

use ArgentWolf\PostNotifier\Database\Migration;
use ArgentWolf\PostNotifier\Database\SchemaInspector;
use ArgentWolf\PostNotifier\Database\TableNames;
use RuntimeException;
use wpdb;

/**
 * Schema version three: queue-claim ownership for campaign-recipient leases.
 *
 * Adds a nullable `lease_token` to campaign recipients so a worker can
 * atomically claim a bounded batch and later update only the rows whose
 * unexpired lease it still owns. Frozen schemas one and two are reused as-is.
 */
final class Schema3 implements Migration {
	/**
	 * Construct the migration.
	 *
	 * @param wpdb $database WordPress database connection.
	 */
	public function __construct( private wpdb $database ) {
	}

	/**
	 * Ensure schema two and add the lease-token column and index.
	 *
	 * @return void
	 * @throws RuntimeException When the column cannot be added.
	 */
	public function up(): void {
		$schema_two = new Schema2( $this->database );
		$schema_two->up();
		$schema_two->verify();

		$table     = TableNames::from_database( $this->database )->campaign_recipients();
		$inspector = new SchemaInspector( $this->database );

		if ( ! isset( $inspector->columns( $table )['lease_token'] ) ) {
			// Table names come only from the internal TableNames resolver.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			// phpcs:disable WordPress.DB.PreparedSQL
			$result = $this->database->query(
				"ALTER TABLE `{$table}` ADD COLUMN lease_token char(36) DEFAULT NULL "
				. 'AFTER lease_expires_at_gmt'
			);
			// phpcs:enable
			if ( false === $result ) {
				throw new RuntimeException( 'Campaign-recipient lease column could not be added.' );
			}
		}

		if ( ! in_array( 'lease_token', $inspector->indexes( $table ), true ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			// phpcs:disable WordPress.DB.PreparedSQL
			$result = $this->database->query(
				"ALTER TABLE `{$table}` ADD KEY lease_token (lease_token)"
			);
			// phpcs:enable
			if ( false === $result ) {
				throw new RuntimeException( 'Campaign-recipient lease index could not be added.' );
			}
		}
	}

	/**
	 * Verify inherited schema plus the lease-token column and index.
	 *
	 * @return void
	 * @throws RuntimeException When required schema state is missing.
	 */
	public function verify(): void {
		( new Schema2( $this->database ) )->verify();

		$table     = TableNames::from_database( $this->database )->campaign_recipients();
		$inspector = new SchemaInspector( $this->database );
		if ( ! isset( $inspector->columns( $table )['lease_token'] ) ) {
			throw new RuntimeException( 'Required campaign-recipient lease column is missing.' );
		}
		if ( ! in_array( 'lease_token', $inspector->indexes( $table ), true ) ) {
			throw new RuntimeException( 'Required campaign-recipient lease index is missing.' );
		}
	}
}

// EOF: src/Database/Migrations/Schema3.php.
