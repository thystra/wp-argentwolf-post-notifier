<?php
/**
 * File: src/Tracking/ClickAttributionReport.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Tracking;

use ArgentWolf\PostNotifier\Database\TableNames;
use LogicException;
use wpdb;

/**
 * Read model for consented, per-recipient click attribution.
 *
 * Only attributed click events exist in the clicks table (recipients without
 * consent are counted in campaign totals only), so every row here belongs to
 * someone who consented and whose attribution is still within retention.
 */
final class ClickAttributionReport {

	public const MAX_ROWS = 5000;

	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Table names.
	 *
	 * @var TableNames
	 */
	private TableNames $tables;

	/**
	 * Construct the report.
	 *
	 * @param wpdb|null $database Optional database connection.
	 * @throws LogicException When no database connection is available.
	 */
	public function __construct( ?wpdb $database = null ) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb instanceof wpdb ? $wpdb : null;
		}
		if ( null === $database ) {
			throw new LogicException( 'WordPress database connection is unavailable.' );
		}
		$this->database = $database;
		$this->tables   = TableNames::from_database( $database );
	}

	/**
	 * Attributed click rows, newest first.
	 *
	 * @param int $campaign_id Campaign filter, or 0 for all.
	 * @param int $limit       Maximum rows.
	 * @return array<int,array{campaign_id:int,post_id:int,email:string,clicked_at_gmt:string,likely_scanner:bool}>
	 */
	public function rows( int $campaign_id = 0, int $limit = self::MAX_ROWS ): array {
		$query = $this->database->prepare(
			'SELECT r.campaign_id, c.post_id, r.email_snapshot, k.clicked_at_gmt, k.likely_scanner
			FROM %i k
			INNER JOIN %i r ON r.id = k.campaign_recipient_id
			INNER JOIN %i c ON c.id = r.campaign_id
			WHERE r.personal_data_erased_at_gmt IS NULL
			AND ( %d = 0 OR r.campaign_id = %d )
			ORDER BY k.id DESC
			LIMIT %d',
			$this->tables->clicks(),
			$this->tables->campaign_recipients(),
			$this->tables->campaigns(),
			$campaign_id,
			$campaign_id,
			max( 1, min( self::MAX_ROWS, $limit ) )
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->database->get_results( $query, ARRAY_A );
		// phpcs:enable
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static fn ( array $row ): array => array(
				'campaign_id'    => (int) $row['campaign_id'],
				'post_id'        => (int) $row['post_id'],
				'email'          => (string) $row['email_snapshot'],
				'clicked_at_gmt' => (string) $row['clicked_at_gmt'],
				'likely_scanner' => 1 === (int) $row['likely_scanner'],
			),
			$rows
		);
	}
}

// EOF: src/Tracking/ClickAttributionReport.php.
