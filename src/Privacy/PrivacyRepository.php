<?php
/**
 * File: src/Privacy/PrivacyRepository.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Privacy;

use ArgentWolf\PostNotifier\Campaign\CampaignRecipientRepository as Recipients;
use ArgentWolf\PostNotifier\Campaign\CampaignRepository as Campaigns;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Database\UtcDateTime;
use LogicException;
use RuntimeException;
use wpdb;

/**
 * Bounded SQL for personal-data export, erasure and content-removal handling.
 *
 * Callers always pass a keyed email hash and/or a user ID, never a raw address,
 * except for the subscriber table lookup which uses the stored hash as well.
 */
final class PrivacyRepository {

	public const PAGE_SIZE = 100;

	public const SKIP_ERASED       = 'personal_data_erased';
	public const SKIP_USER_DELETED = 'user_deleted';
	public const SKIP_POST_REMOVED = 'post_removed';

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
	 * Construct the repository.
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
	 * Standalone subscriber row for an email hash.
	 *
	 * @param string $email_hash Keyed email hash.
	 * @return array<string,mixed>|null
	 */
	public function subscriber( string $email_hash ): ?array {
		$row = $this->row(
			$this->database->prepare(
				'SELECT id, email, display_name, status, created_at_gmt, confirmed_at_gmt,
					unsubscribed_at_gmt, consent_text_snapshot, signup_source,
					click_consent_at_gmt, click_consent_version
				FROM %i WHERE email_hash = %s LIMIT 1',
				$this->tables->subscribers(),
				$email_hash
			)
		);

		return $row;
	}

	/**
	 * Suppression row for an email hash.
	 *
	 * @param string $email_hash Keyed email hash.
	 * @return array<string,mixed>|null
	 */
	public function suppression( string $email_hash ): ?array {
		return $this->row(
			$this->database->prepare(
				'SELECT id, email_snapshot_or_redacted, reason, source, created_at_gmt
				FROM %i WHERE email_hash = %s LIMIT 1',
				$this->tables->suppressions(),
				$email_hash
			)
		);
	}

	/**
	 * One page of campaign-recipient rows for an identity.
	 *
	 * @param string $email_hash Keyed email hash.
	 * @param int    $user_id    Registered user ID, or 0.
	 * @param int    $page       1-based page.
	 * @return array<int,array<string,mixed>>
	 */
	public function recipient_page( string $email_hash, int $user_id, int $page ): array {
		$query = $this->database->prepare(
			'SELECT r.id, r.campaign_id, c.post_id, r.email_snapshot, r.display_name_snapshot,
				r.status, r.skip_reason, r.submitted_at_gmt, r.click_count,
				r.first_clicked_at_gmt, r.last_clicked_at_gmt,
				( SELECT GROUP_CONCAT( k.clicked_at_gmt ORDER BY k.id )
					FROM %i k WHERE k.campaign_recipient_id = r.id ) AS attributed_clicks
			FROM %i r INNER JOIN %i c ON c.id = r.campaign_id
			WHERE r.personal_data_erased_at_gmt IS NULL
			AND (r.email_hash = %s OR (%d > 0 AND r.user_id = %d))
			ORDER BY r.id ASC LIMIT %d OFFSET %d',
			$this->tables->clicks(),
			$this->tables->campaign_recipients(),
			$this->tables->campaigns(),
			$email_hash,
			$user_id,
			$user_id,
			self::PAGE_SIZE,
			self::PAGE_SIZE * max( 0, $page - 1 )
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->database->get_results( $query, ARRAY_A );
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Redact one bounded batch of campaign-recipient rows for an identity.
	 *
	 * Rows still waiting in the queue are skipped so they are never sent. Their
	 * click events are deleted; aggregate campaign counters are kept.
	 *
	 * @param string $email_hash Keyed email hash, or '' to match by user only.
	 * @param int    $user_id    Registered user ID, or 0.
	 * @param string $reason     Skip reason for still-queued rows.
	 * @return int Rows redacted in this batch.
	 * @throws RuntimeException When a write fails.
	 */
	public function redact_recipients( string $email_hash, int $user_id, string $reason ): int {
		$ids = $this->col(
			$this->database->prepare(
				'SELECT id FROM %i
				WHERE personal_data_erased_at_gmt IS NULL
				AND ((%s <> \'\' AND email_hash = %s) OR (%d > 0 AND user_id = %d))
				ORDER BY id ASC LIMIT %d',
				$this->tables->campaign_recipients(),
				$email_hash,
				$email_hash,
				$user_id,
				$user_id,
				self::PAGE_SIZE
			)
		);
		if ( array() === $ids ) {
			return 0;
		}
		$in = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// The IN list contains only internally generated %d placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->write(
			$this->database->prepare(
				"DELETE FROM %i WHERE campaign_recipient_id IN ({$in})",
				$this->tables->clicks(),
				...$ids
			)
		);
		$this->write(
			$this->database->prepare(
				"UPDATE %i SET status = %s, skip_reason = %s, lease_token = NULL
				WHERE status = %s AND id IN ({$in})",
				$this->tables->campaign_recipients(),
				Recipients::STATUS_SKIPPED,
				$reason,
				Recipients::STATUS_QUEUED,
				...$ids
			)
		);

		return $this->write(
			$this->database->prepare(
				"UPDATE %i SET user_id = NULL, subscriber_id = NULL, email_snapshot = NULL,
					email_hash = NULL, unsubscribe_token_hash = NULL, click_token_hash = NULL,
					display_name_snapshot = NULL, personal_data_erased_at_gmt = %s
				WHERE id IN ({$in})",
				$this->tables->campaign_recipients(),
				UtcDateTime::now(),
				...$ids
			)
		);
		// phpcs:enable
	}

	/**
	 * Delete the standalone subscriber row and its list memberships.
	 *
	 * @param string $email_hash Keyed email hash.
	 * @return int Subscriber rows deleted.
	 * @throws RuntimeException When a write fails.
	 */
	public function delete_subscriber( string $email_hash ): int {
		$subscriber = $this->subscriber( $email_hash );
		if ( null === $subscriber ) {
			return 0;
		}
		$this->write(
			$this->database->prepare(
				'DELETE FROM %i WHERE subscriber_id = %d',
				$this->tables->list_members(),
				(int) $subscriber['id']
			)
		);

		return $this->write(
			$this->database->prepare(
				'DELETE FROM %i WHERE id = %d',
				$this->tables->subscribers(),
				(int) $subscriber['id']
			)
		);
	}

	/**
	 * Remove list memberships of a registered user.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 * @throws RuntimeException When a write fails.
	 */
	public function delete_user_memberships( int $user_id ): int {
		return $this->write(
			$this->database->prepare(
				'DELETE FROM %i WHERE user_id = %d',
				$this->tables->list_members(),
				$user_id
			)
		);
	}

	/**
	 * Drop the readable address from a suppression while keeping its hash.
	 *
	 * @param string $email_hash Keyed email hash.
	 * @return int
	 * @throws RuntimeException When a write fails.
	 */
	public function redact_suppression( string $email_hash ): int {
		return $this->write(
			$this->database->prepare(
				'UPDATE %i SET email_snapshot_or_redacted = NULL, updated_at_gmt = %s
				WHERE email_hash = %s AND email_snapshot_or_redacted IS NOT NULL',
				$this->tables->suppressions(),
				UtcDateTime::now(),
				$email_hash
			)
		);
	}

	/**
	 * Remove email hashes from audit events for an erased identity.
	 *
	 * @param string $email_hash Keyed email hash.
	 * @return int
	 * @throws RuntimeException When a write fails.
	 */
	public function redact_audit( string $email_hash ): int {
		return $this->write(
			$this->database->prepare(
				'UPDATE %i SET email_hash = NULL WHERE email_hash = %s',
				$this->tables->audit_events(),
				$email_hash
			)
		);
	}

	/**
	 * Stop undelivered campaigns for a post that is no longer public.
	 *
	 * Queued or sending campaigns become failed with code post_removed and their
	 * queued rows are skipped. Rows already claimed by a worker finish their
	 * current attempt. Campaigns still building are left to the queue gate, which
	 * fails them closed (post_not_published) with its own audit record.
	 *
	 * @param int $post_id Post ID.
	 * @return int Campaigns stopped.
	 * @throws RuntimeException When a write fails.
	 */
	public function stop_post_campaigns( int $post_id ): int {
		$ids = $this->col(
			$this->database->prepare(
				'SELECT id FROM %i WHERE post_id = %d AND status IN (%s, %s)',
				$this->tables->campaigns(),
				$post_id,
				Campaigns::STATUS_QUEUED,
				Campaigns::STATUS_SENDING
			)
		);
		if ( array() === $ids ) {
			return 0;
		}
		$in = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$at = UtcDateTime::now();

		// The IN list contains only internally generated %d placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stopped = $this->write(
			$this->database->prepare(
				"UPDATE %i SET status = %s, last_error_code = %s, completed_at_gmt = %s
				WHERE status IN (%s, %s) AND id IN ({$in})",
				$this->tables->campaigns(),
				Campaigns::STATUS_FAILED,
				self::SKIP_POST_REMOVED,
				$at,
				Campaigns::STATUS_QUEUED,
				Campaigns::STATUS_SENDING,
				...$ids
			)
		);
		$this->write(
			$this->database->prepare(
				"UPDATE %i SET status = %s, skip_reason = %s
				WHERE status = %s AND campaign_id IN ({$in})",
				$this->tables->campaign_recipients(),
				Recipients::STATUS_SKIPPED,
				self::SKIP_POST_REMOVED,
				Recipients::STATUS_QUEUED,
				...$ids
			)
		);
		// phpcs:enable

		return $stopped;
	}

	/**
	 * Read one row.
	 *
	 * @param string $query Prepared SQL.
	 * @return array<string,mixed>|null
	 */
	private function row( string $query ): ?array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->database->get_row( $query, ARRAY_A );
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Read one integer column.
	 *
	 * @param string $query Prepared SQL.
	 * @return array<int,int>
	 */
	private function col( string $query ): array {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$values = $this->database->get_col( $query );
		// phpcs:enable

		return array_map( 'intval', (array) $values );
	}

	/**
	 * Execute one prepared write.
	 *
	 * @param string $query Prepared SQL.
	 * @return int
	 * @throws RuntimeException When the write fails.
	 */
	private function write( string $query ): int {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable
		if ( false === $result ) {
			throw new RuntimeException( 'Privacy persistence failed.' );
		}

		return (int) $result;
	}
}

// EOF: src/Privacy/PrivacyRepository.php.
