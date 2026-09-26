<?php
/**
 * File: src/Admin/SubscriberAdminRepository.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Admin;

use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Database\UtcDateTime;
use ArgentWolf\PostNotifier\Subscriber\SubscriberStatus;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use wpdb;

/**
 * Read and operator-update persistence for standalone subscriber administration.
 */
final class SubscriberAdminRepository {
	/**
	 * Maximum rows returned by one administrative query.
	 */
	public const MAX_PAGE_SIZE = 500;

	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Subscriber table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Construct the administration repository.
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
		$this->table    = TableNames::from_database( $database )->subscribers();
	}

	/**
	 * Return one page of subscribers and the matching total.
	 *
	 * @param SubscriberStatus|null $status   Optional status filter.
	 * @param string                $search   Optional email/name search text.
	 * @param int                   $page     One-based page number.
	 * @param int                   $per_page Rows per page.
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 * @throws InvalidArgumentException When the requested page size is unsafe.
	 */
	public function page(
		?SubscriberStatus $status,
		string $search,
		int $page,
		int $per_page
	): array {
		$per_page    = $this->validate_page_size( $per_page );
		$page        = max( 1, $page );
		$offset      = ( $page - 1 ) * $per_page;
		$status_text = null === $status ? '' : $status->value;
		$search      = trim( $search );
		$like        = '%' . $this->database->esc_like( $search ) . '%';

		$count_query = $this->database->prepare(
			'SELECT COUNT(*) FROM %i
			WHERE (%s = \'\' OR status = %s)
			AND (%s = \'\' OR email LIKE %s OR display_name LIKE %s)',
			$this->table,
			$status_text,
			$status_text,
			$search,
			$like,
			$like
		);
		$rows_query  = $this->database->prepare(
			'SELECT id, email, display_name, status, created_at_gmt, confirmed_at_gmt,
				unsubscribed_at_gmt, consent_text_snapshot, signup_source, source_post_id,
				updated_at_gmt
			FROM %i
			WHERE (%s = \'\' OR status = %s)
			AND (%s = \'\' OR email LIKE %s OR display_name LIKE %s)
			ORDER BY id DESC
			LIMIT %d OFFSET %d',
			$this->table,
			$status_text,
			$status_text,
			$search,
			$like,
			$like,
			$per_page,
			$offset
		);

		// Plugin-owned administration reads intentionally query the custom table.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$total = $this->database->get_var( $count_query );
		$rows  = $this->database->get_results( $rows_query, ARRAY_A );
		// phpcs:enable

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => max( 0, (int) $total ),
		);
	}

	/**
	 * Return one ascending-ID batch for CSV export.
	 *
	 * @param SubscriberStatus|null $status   Optional status filter.
	 * @param string                $search   Optional email/name search text.
	 * @param int                   $after_id Return rows after this subscriber ID.
	 * @param int                   $limit    Maximum rows returned.
	 * @return array<int,array<string,mixed>>
	 * @throws InvalidArgumentException When the requested batch size is unsafe.
	 */
	public function export_batch(
		?SubscriberStatus $status,
		string $search,
		int $after_id,
		int $limit
	): array {
		$limit       = $this->validate_page_size( $limit );
		$status_text = null === $status ? '' : $status->value;
		$search      = trim( $search );
		$like        = '%' . $this->database->esc_like( $search ) . '%';
		$query       = $this->database->prepare(
			'SELECT id, email, display_name, status, created_at_gmt, confirmed_at_gmt,
				unsubscribed_at_gmt, consent_text_snapshot, signup_source, source_post_id,
				updated_at_gmt
			FROM %i
			WHERE id > %d
			AND (%s = \'\' OR status = %s)
			AND (%s = \'\' OR email LIKE %s OR display_name LIKE %s)
			ORDER BY id ASC
			LIMIT %d',
			$this->table,
			max( 0, $after_id ),
			$status_text,
			$status_text,
			$search,
			$like,
			$like,
			$limit
		);

		// Plugin-owned administration reads intentionally query the custom table.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->database->get_results( $query, ARRAY_A );
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Move one standalone subscriber into the suppressed state.
	 *
	 * Suppression clears reusable management/confirmation bearer hashes. This is
	 * deliberately one-way in the alpha.4 administration screen; verified
	 * resubscription is a later workflow.
	 *
	 * @param int                    $subscriber_id Subscriber row ID.
	 * @param DateTimeInterface|null $now           Optional current-time override.
	 * @return bool True when a non-suppressed row was changed.
	 * @throws RuntimeException When the database operation fails.
	 */
	public function suppress(
		int $subscriber_id,
		?DateTimeInterface $now = null
	): bool {
		if ( $subscriber_id < 1 ) {
			return false;
		}

		$current = null === $now
			? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
			: DateTimeImmutable::createFromInterface( $now )->setTimezone(
				new DateTimeZone( 'UTC' )
			);
		$query   = $this->database->prepare(
			'UPDATE %i
			SET status = %s,
				confirmation_token_hash = NULL,
				confirmation_expires_at_gmt = NULL,
				manage_token_hash = NULL,
				updated_at_gmt = %s
			WHERE id = %d
			AND status <> %s',
			$this->table,
			SubscriberStatus::Suppressed->value,
			UtcDateTime::format( $current ),
			$subscriber_id,
			SubscriberStatus::Suppressed->value
		);

		// Plugin-owned administration writes intentionally update the custom table.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable

		if ( false === $result ) {
			throw new RuntimeException( 'Subscriber suppression could not be saved.' );
		}

		return 1 === $result;
	}

	/**
	 * Validate an administrative page/batch size.
	 *
	 * @param int $size Requested size.
	 * @return int
	 * @throws InvalidArgumentException When the size is outside the safe range.
	 */
	private function validate_page_size( int $size ): int {
		if ( $size < 1 || $size > self::MAX_PAGE_SIZE ) {
			throw new InvalidArgumentException( 'Administrative page size is invalid.' );
		}

		return $size;
	}
}

// EOF: src/Admin/SubscriberAdminRepository.php.
