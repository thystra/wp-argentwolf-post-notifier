<?php
/**
 * File: src/Database/Migrations/Schema4.php
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
 * Schema version four: per-user click-attribution consent and scanner flag.
 *
 * Adds nullable consent timestamp/wording-version columns to standalone
 * subscribers and a `likely_scanner` flag to click events. Frozen schemas one
 * to three are reused as-is.
 */
final class Schema4 implements Migration {

	/**
	 * Columns added by this schema, keyed by logical table.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const COLUMNS = array(
		'subscribers' => array(
			'click_consent_at_gmt'  => 'datetime DEFAULT NULL',
			'click_consent_version' => 'varchar(32) DEFAULT NULL',
		),
		'clicks'      => array(
			'likely_scanner' => 'tinyint(1) unsigned NOT NULL DEFAULT 0',
		),
	);

	/**
	 * Construct the migration.
	 *
	 * @param wpdb $database WordPress database connection.
	 */
	public function __construct( private wpdb $database ) {
	}

	/**
	 * Ensure schema three and add the new columns.
	 *
	 * @return void
	 * @throws RuntimeException When a column cannot be added.
	 */
	public function up(): void {
		$schema_three = new Schema3( $this->database );
		$schema_three->up();
		$schema_three->verify();

		$tables    = TableNames::from_database( $this->database )->all();
		$inspector = new SchemaInspector( $this->database );
		foreach ( self::COLUMNS as $logical => $columns ) {
			$table = $tables[ $logical ];
			foreach ( $columns as $column => $definition ) {
				if ( isset( $inspector->columns( $table )[ $column ] ) ) {
					continue;
				}
				// Table and column names and definitions are internal constants.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery
				// phpcs:disable WordPress.DB.PreparedSQL
				$result = $this->database->query(
					"ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}"
				);
				// phpcs:enable
				if ( false === $result ) {
					throw new RuntimeException( 'A schema four column could not be added.' );
				}
			}
		}
	}

	/**
	 * Verify inherited schema plus the new columns.
	 *
	 * @return void
	 * @throws RuntimeException When required schema state is missing.
	 */
	public function verify(): void {
		( new Schema3( $this->database ) )->verify();

		$tables    = TableNames::from_database( $this->database )->all();
		$inspector = new SchemaInspector( $this->database );
		foreach ( self::COLUMNS as $logical => $columns ) {
			foreach ( array_keys( $columns ) as $column ) {
				if ( ! isset( $inspector->columns( $tables[ $logical ] )[ $column ] ) ) {
					throw new RuntimeException( 'A required schema four column is missing.' );
				}
			}
		}
	}
}

// EOF: src/Database/Migrations/Schema4.php.
