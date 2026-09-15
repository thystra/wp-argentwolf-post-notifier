<?php
/**
 * File: src/Database/DataCleanup.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Database;

use DateTimeInterface;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use wpdb;

/**
 * Bounded privacy and retention primitives for plugin-owned data.
 *
 * This class deliberately does not choose retention policy. Callers provide the
 * cutoff and invoke one bounded batch at a time.
 */
final class DataCleanup {
	/**
	 * Conservative default batch size.
	 */
	public const DEFAULT_BATCH_SIZE = 100;

	/**
	 * Hard upper bound for one cleanup operation.
	 */
	public const MAX_BATCH_SIZE = 500;

	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Plugin table names.
	 *
	 * @var TableNames
	 */
	private TableNames $tables;

	/**
	 * Construct cleanup primitives.
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
		$this->tables   = TableNames::from_database( $database );
	}

	/**
	 * Delete a bounded batch of expired pending subscribers.
	 *
	 * The caller chooses the retention cutoff. Subscribed or unsubscribed rows are
	 * never removed by this operation.
	 *
	 * @param DateTimeInterface $expired_before Delete pending rows expired before this time.
	 * @param int               $limit Maximum rows to delete in this batch.
	 * @return int Number of rows deleted.
	 * @throws InvalidArgumentException When the requested batch size is unsafe.
	 * @throws RuntimeException When the database operation fails.
	 */
	public function delete_expired_pending_subscribers(
		DateTimeInterface $expired_before,
		int $limit = self::DEFAULT_BATCH_SIZE
	): int {
		$query = $this->database->prepare(
			'DELETE FROM %i
			WHERE status = %s
			AND confirmation_expires_at_gmt IS NOT NULL
			AND confirmation_expires_at_gmt < %s
			ORDER BY id ASC
			LIMIT %d',
			$this->tables->subscribers(),
			'pending',
			UtcDateTime::format( $expired_before ),
			$this->validate_limit( $limit )
		);

		return $this->execute_write( $query );
	}

	/**
	 * Delete a bounded batch of click events older than a caller-selected cutoff.
	 *
	 * @param DateTimeInterface $clicked_before Delete clicks before this time.
	 * @param int               $limit Maximum rows to delete in this batch.
	 * @return int Number of rows deleted.
	 * @throws InvalidArgumentException When the requested batch size is unsafe.
	 * @throws RuntimeException When the database operation fails.
	 */
	public function delete_click_events_before(
		DateTimeInterface $clicked_before,
		int $limit = self::DEFAULT_BATCH_SIZE
	): int {
		$query = $this->database->prepare(
			'DELETE FROM %i
			WHERE clicked_at_gmt < %s
			ORDER BY id ASC
			LIMIT %d',
			$this->tables->clicks(),
			UtcDateTime::format( $clicked_before ),
			$this->validate_limit( $limit )
		);

		return $this->execute_write( $query );
	}

	/**
	 * Redact a bounded batch of recipient-level personal data for old completed campaigns.
	 *
	 * Aggregate status/counter rows remain available after identity fields are
	 * removed. The email hash is also removed because a retained site-local HMAC
	 * key would otherwise allow a known address to be correlated with the row.
	 *
	 * @param DateTimeInterface $completed_before Redact campaigns completed before this time.
	 * @param int               $limit Maximum recipient rows to redact in this batch.
	 * @return int Number of recipient rows redacted.
	 * @throws InvalidArgumentException When the requested batch size is unsafe.
	 * @throws RuntimeException When the database operation fails.
	 */
	public function redact_completed_campaign_recipients(
		DateTimeInterface $completed_before,
		int $limit = self::DEFAULT_BATCH_SIZE
	): int {
		$limit = $this->validate_limit( $limit );
		$query = $this->database->prepare(
			'SELECT recipients.id
			FROM %i AS recipients
			INNER JOIN %i AS campaigns ON campaigns.id = recipients.campaign_id
			WHERE campaigns.completed_at_gmt IS NOT NULL
			AND campaigns.completed_at_gmt < %s
			AND recipients.personal_data_erased_at_gmt IS NULL
			ORDER BY recipients.id ASC
			LIMIT %d',
			$this->tables->campaign_recipients(),
			$this->tables->campaigns(),
			UtcDateTime::format( $completed_before ),
			$limit
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$ids = $this->database->get_col( $query );
		// phpcs:enable
		if ( empty( $ids ) ) {
			return 0;
		}

		$ids          = array_map( 'intval', $ids );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "UPDATE %i
			SET user_id = NULL,
				subscriber_id = NULL,
				email_snapshot = NULL,
				email_hash = NULL,
				unsubscribe_token_hash = NULL,
				click_token_hash = NULL,
				display_name_snapshot = NULL,
				personal_data_erased_at_gmt = %s
			WHERE id IN ({$placeholders})";
		$args         = array_merge(
			array(
				$this->tables->campaign_recipients(),
				UtcDateTime::now(),
			),
			$ids
		);
		// The SQL template contains only internally generated %d placeholders.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$update = $this->database->prepare( $sql, ...$args );

		return $this->execute_write( $update );
	}

	/**
	 * Validate and return a cleanup batch size.
	 *
	 * @param int $limit Requested batch size.
	 * @return int
	 * @throws InvalidArgumentException When the batch size is outside safe bounds.
	 */
	private function validate_limit( int $limit ): int {
		if ( $limit < 1 || $limit > self::MAX_BATCH_SIZE ) {
			throw new InvalidArgumentException( 'Cleanup batch size is outside the safe range.' );
		}

		return $limit;
	}

	/**
	 * Execute a write query and normalize its result.
	 *
	 * @param string $query Prepared query.
	 * @return int Number of affected rows.
	 * @throws RuntimeException When the database write fails.
	 */
	private function execute_write( string $query ): int {
		// These writes operate only on plugin-owned tables and are intentionally
		// uncached bounded maintenance operations.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable
		if ( false === $result ) {
			throw new RuntimeException( 'Plugin data cleanup database operation failed.' );
		}

		return (int) $result;
	}
}

// EOF: src/Database/DataCleanup.php.
