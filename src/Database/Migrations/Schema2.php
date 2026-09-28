<?php
/**
 * File: src/Database/Migrations/Schema2.php
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
 * Add the privacy-conscious administrative audit-event table.
 *
 * Schema one remains immutable. This migration deliberately re-applies and
 * verifies schema one so same-version schema-two repair still covers the full
 * plugin-owned schema as well as the newly added audit table.
 */
final class Schema2 implements Migration {
	/**
	 * Construct the schema migration.
	 *
	 * @param wpdb $database WordPress database connection.
	 */
	public function __construct( private wpdb $database ) {
	}

	/**
	 * Ensure schema one and create the audit-event table.
	 *
	 * @return void
	 */
	public function up(): void {
		$schema_one = new Schema1( $this->database );
		$schema_one->up();
		$schema_one->verify();

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$tables          = TableNames::from_database( $this->database );
		$charset_collate = $this->database->get_charset_collate();
		dbDelta( $this->audit_events_sql( $tables->audit_events(), $charset_collate ) );
	}

	/**
	 * Verify both the inherited schema-one state and the audit-event table.
	 *
	 * @return void
	 * @throws RuntimeException When required schema state is missing.
	 */
	public function verify(): void {
		( new Schema1( $this->database ) )->verify();

		$table     = TableNames::from_database( $this->database )->audit_events();
		$inspector = new SchemaInspector( $this->database );
		if ( ! $inspector->table_exists( $table ) ) {
			throw new RuntimeException( 'Required audit-event database table is missing.' );
		}

		$required_indexes = array(
			'PRIMARY',
			'event_type',
			'actor_user_id',
			'subject',
			'email_hash',
			'created_at_gmt',
		);
		$actual_indexes   = $inspector->indexes( $table );
		foreach ( $required_indexes as $index ) {
			if ( ! in_array( $index, $actual_indexes, true ) ) {
				// Internal diagnostic; index names are fixed application constants.
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new RuntimeException(
					sprintf( 'Required audit-event index is missing: %s.', $index )
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}
	}

	/**
	 * Audit-event table SQL.
	 *
	 * The table intentionally has no raw email, token, IP-address, or user-agent
	 * field. Suppression events retain only the canonical keyed email hash.
	 *
	 * @param string $table           Table name.
	 * @param string $charset_collate Database charset/collation clause.
	 * @return string
	 */
	private function audit_events_sql( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(64) NOT NULL,
			actor_user_id bigint(20) unsigned DEFAULT NULL,
			subject_type varchar(32) NOT NULL,
			subject_id bigint(20) unsigned DEFAULT NULL,
			related_type varchar(32) DEFAULT NULL,
			related_id bigint(20) unsigned DEFAULT NULL,
			email_hash char(64) DEFAULT NULL,
			reason varchar(64) DEFAULT NULL,
			source varchar(64) DEFAULT NULL,
			created_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY actor_user_id (actor_user_id),
			KEY subject (subject_type,subject_id),
			KEY email_hash (email_hash),
			KEY created_at_gmt (created_at_gmt)
		) {$charset_collate};";
	}
}

// EOF: src/Database/Migrations/Schema2.php.
