<?php
/**
 * File: src/Database/SchemaInspector.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Database;

use wpdb;

/**
 * Read-only schema inspection used to verify migrations before version advance.
 */
final class SchemaInspector {
	/**
	 * Construct the inspector.
	 *
	 * @param wpdb $database WordPress database connection.
	 */
	public function __construct( private wpdb $database ) {
	}

	/**
	 * Determine whether a table exists exactly as named.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	public function table_exists( string $table ): bool {
		$query = $this->database->prepare(
			'SHOW TABLES LIKE %s',
			$this->database->esc_like( $table )
		);
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$value = $this->database->get_var( $query );
		// phpcs:enable

		return $table === $value;
	}

	/**
	 * Return column metadata keyed by field name.
	 *
	 * @param string $table Table name from TableNames.
	 * @return array<string,array<string,mixed>>
	 */
	public function columns( string $table ): array {
		// Table names come only from the internal TableNames resolver.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// phpcs:disable WordPress.DB.PreparedSQL
		$rows = $this->database->get_results(
			"SHOW FULL COLUMNS FROM `{$table}`",
			ARRAY_A
		);
		// phpcs:enable
		$columns = array();
		foreach ( (array) $rows as $row ) {
			if ( isset( $row['Field'] ) ) {
				$columns[ (string) $row['Field'] ] = $row;
			}
		}

		return $columns;
	}

	/**
	 * Return index names defined for a table.
	 *
	 * @param string $table Table name from TableNames.
	 * @return array<string>
	 */
	public function indexes( string $table ): array {
		// Table names come only from the internal TableNames resolver.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// phpcs:disable WordPress.DB.PreparedSQL
		$rows = $this->database->get_results(
			"SHOW INDEX FROM `{$table}`",
			ARRAY_A
		);
		// phpcs:enable
		$indexes = array();
		foreach ( (array) $rows as $row ) {
			if ( isset( $row['Key_name'] ) ) {
				$indexes[] = (string) $row['Key_name'];
			}
		}

		return array_values( array_unique( $indexes ) );
	}
}

// EOF: src/Database/SchemaInspector.php.
