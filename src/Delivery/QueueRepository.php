<?php
/**
 * File: src/Delivery/QueueRepository.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Delivery;

use ArgentWolf\PostNotifier\Campaign\CampaignRecipientRepository as Recipients;
use ArgentWolf\PostNotifier\Campaign\CampaignRepository as Campaigns;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Database\UtcDateTime;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use RuntimeException;
use wpdb;

/**
 * Atomic lease-based claims over campaign-recipient rows.
 *
 * Each claim is one single-statement UPDATE that stamps a fresh lease token,
 * so concurrent workers can never own the same unexpired row. Every later
 * state change is conditional on that lease token; a worker whose lease was
 * recovered by another worker cannot overwrite the newer owner's result. No
 * database transaction is held while mail is submitted.
 */
final class QueueRepository {
	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Attempts for one write that hits a deadlock or lock-wait timeout.
	 */
	private const LOCK_RETRY_ATTEMPTS = 5;

	/**
	 * Recipient table.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Campaign table.
	 *
	 * @var string
	 */
	private string $campaigns;

	/**
	 * Construct the queue repository.
	 *
	 * @param wpdb|null $database Optional database connection.
	 * @throws LogicException When the database is unavailable.
	 */
	public function __construct( ?wpdb $database = null ) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb ?? null;
		}
		if ( ! $database instanceof wpdb ) {
			throw new LogicException( 'WordPress database connection is unavailable.' );
		}
		$this->database  = $database;
		$tables          = TableNames::from_database( $database );
		$this->table     = $tables->campaign_recipients();
		$this->campaigns = $tables->campaigns();
	}

	/**
	 * Terminally fail expired leases that already used every attempt.
	 *
	 * @param int               $max_attempts Maximum attempts.
	 * @param DateTimeInterface $now          Current time.
	 * @return array<int,int> Campaign IDs touched.
	 * @throws RuntimeException When the write fails.
	 */
	public function fail_exhausted_expired_leases(
		int $max_attempts,
		DateTimeInterface $now
	): array {
		$stamp = UtcDateTime::format( $now );
		$ids   = $this->col(
			$this->database->prepare(
				'SELECT DISTINCT campaign_id FROM %i
				WHERE status = %s AND lease_expires_at_gmt <= %s AND attempt_count >= %d',
				$this->table,
				Recipients::STATUS_CLAIMED,
				$stamp,
				$max_attempts
			)
		);
		$this->write(
			$this->database->prepare(
				'UPDATE %i SET status = %s, failed_at_gmt = %s, lease_token = NULL,
					lease_expires_at_gmt = NULL, last_error_code = %s
				WHERE status = %s AND lease_expires_at_gmt <= %s AND attempt_count >= %d',
				$this->table,
				Recipients::STATUS_FAILED,
				$stamp,
				'lease_expired_max_attempts',
				Recipients::STATUS_CLAIMED,
				$stamp,
				$max_attempts
			)
		);

		return $ids;
	}

	/**
	 * Atomically claim up to $limit ready or lease-expired rows.
	 *
	 * @param string            $lease_token   Fresh UUID owned by this worker.
	 * @param int               $limit         Batch size.
	 * @param int               $lease_seconds Lease duration.
	 * @param DateTimeInterface $now           Current time.
	 * @return array<int,array<string,mixed>> Claimed rows.
	 * @throws RuntimeException When the write fails.
	 */
	public function claim(
		string $lease_token,
		int $limit,
		int $lease_seconds,
		DateTimeInterface $now
	): array {
		$current = DateTimeImmutable::createFromInterface( $now );
		$stamp   = UtcDateTime::format( $current );
		$expires = UtcDateTime::format(
			$current->add( new DateInterval( 'PT' . max( 30, $lease_seconds ) . 'S' ) )
		);

		$this->write(
			$this->database->prepare(
				'UPDATE %i SET status = %s, lease_token = %s, claimed_at_gmt = %s,
					lease_expires_at_gmt = %s, attempt_count = attempt_count + 1
				WHERE campaign_id IN (SELECT id FROM %i WHERE status IN (%s, %s))
				AND (
					(status = %s AND (next_attempt_at_gmt IS NULL OR next_attempt_at_gmt <= %s))
					OR (status = %s AND lease_expires_at_gmt <= %s)
				)
				ORDER BY id ASC LIMIT %d',
				$this->table,
				Recipients::STATUS_CLAIMED,
				$lease_token,
				$stamp,
				$expires,
				$this->campaigns,
				Campaigns::STATUS_QUEUED,
				Campaigns::STATUS_SENDING,
				Recipients::STATUS_QUEUED,
				$stamp,
				Recipients::STATUS_CLAIMED,
				$stamp,
				max( 1, $limit )
			)
		);

		$query = $this->database->prepare(
			'SELECT id, campaign_id, recipient_uuid, recipient_type, user_id, subscriber_id,
				email_snapshot, email_hash, attempt_count
			FROM %i WHERE lease_token = %s AND status = %s ORDER BY id ASC',
			$this->table,
			$lease_token,
			Recipients::STATUS_CLAIMED
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->database->get_results( $query, ARRAY_A );
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Store the per-attempt recipient-token hash while the lease is held.
	 *
	 * @param int    $row_id      Recipient row ID.
	 * @param string $lease_token Owning lease.
	 * @param string $token_hash  SHA-256 of the plaintext token.
	 * @return bool Whether the lease is still owned.
	 */
	public function set_token_hash( int $row_id, string $lease_token, string $token_hash ): bool {
		return 1 === $this->write(
			$this->database->prepare(
				'UPDATE %i SET unsubscribe_token_hash = %s
				WHERE id = %d AND lease_token = %s AND status = %s',
				$this->table,
				$token_hash,
				$row_id,
				$lease_token,
				Recipients::STATUS_CLAIMED
			)
		);
	}

	/**
	 * Record a transport-accepted submission.
	 *
	 * @param int               $row_id      Recipient row ID.
	 * @param string            $lease_token Owning lease.
	 * @param DateTimeInterface $now         Current time.
	 * @return bool
	 */
	public function mark_submitted(
		int $row_id,
		string $lease_token,
		DateTimeInterface $now
	): bool {
		return 1 === $this->write(
			$this->database->prepare(
				'UPDATE %i SET status = %s, submitted_at_gmt = %s, lease_token = NULL,
					lease_expires_at_gmt = NULL, last_error_code = NULL
				WHERE id = %d AND lease_token = %s AND status = %s',
				$this->table,
				Recipients::STATUS_SUBMITTED,
				UtcDateTime::format( $now ),
				$row_id,
				$lease_token,
				Recipients::STATUS_CLAIMED
			)
		);
	}

	/**
	 * Record a pre-send hard-ineligibility skip.
	 *
	 * @param int    $row_id      Recipient row ID.
	 * @param string $lease_token Owning lease.
	 * @param string $reason      Stable skip reason.
	 * @return bool
	 */
	public function mark_skipped( int $row_id, string $lease_token, string $reason ): bool {
		return 1 === $this->write(
			$this->database->prepare(
				'UPDATE %i SET status = %s, skip_reason = %s, lease_token = NULL,
					lease_expires_at_gmt = NULL
				WHERE id = %d AND lease_token = %s AND status = %s',
				$this->table,
				Recipients::STATUS_SKIPPED,
				$reason,
				$row_id,
				$lease_token,
				Recipients::STATUS_CLAIMED
			)
		);
	}

	/**
	 * Record a terminal failure.
	 *
	 * @param int               $row_id      Recipient row ID.
	 * @param string            $lease_token Owning lease.
	 * @param string            $code        Stable error code.
	 * @param DateTimeInterface $now         Current time.
	 * @return bool
	 */
	public function mark_failed(
		int $row_id,
		string $lease_token,
		string $code,
		DateTimeInterface $now
	): bool {
		return 1 === $this->write(
			$this->database->prepare(
				'UPDATE %i SET status = %s, failed_at_gmt = %s, last_error_code = %s,
					lease_token = NULL, lease_expires_at_gmt = NULL
				WHERE id = %d AND lease_token = %s AND status = %s',
				$this->table,
				Recipients::STATUS_FAILED,
				UtcDateTime::format( $now ),
				$code,
				$row_id,
				$lease_token,
				Recipients::STATUS_CLAIMED
			)
		);
	}

	/**
	 * Release a row for a later retry.
	 *
	 * @param int               $row_id      Recipient row ID.
	 * @param string            $lease_token Owning lease.
	 * @param string            $code        Stable error code.
	 * @param DateTimeInterface $next        Earliest next attempt.
	 * @return bool
	 */
	public function mark_retry(
		int $row_id,
		string $lease_token,
		string $code,
		DateTimeInterface $next
	): bool {
		return 1 === $this->write(
			$this->database->prepare(
				'UPDATE %i SET status = %s, next_attempt_at_gmt = %s, last_error_code = %s,
					lease_token = NULL, lease_expires_at_gmt = NULL
				WHERE id = %d AND lease_token = %s AND status = %s',
				$this->table,
				Recipients::STATUS_QUEUED,
				UtcDateTime::format( $next ),
				$code,
				$row_id,
				$lease_token,
				Recipients::STATUS_CLAIMED
			)
		);
	}

	/**
	 * Earliest future retry time across active campaigns, if any.
	 *
	 * @return string|null UTC MySQL datetime.
	 */
	public function next_attempt_at(): ?string {
		$query = $this->database->prepare(
			'SELECT MIN(r.next_attempt_at_gmt) FROM %i r
			INNER JOIN %i c ON c.id = r.campaign_id
			WHERE r.status = %s AND c.status IN (%s, %s)',
			$this->table,
			$this->campaigns,
			Recipients::STATUS_QUEUED,
			Campaigns::STATUS_QUEUED,
			Campaigns::STATUS_SENDING
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$value = $this->database->get_var( $query );
		// phpcs:enable

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Count recipient rows per skip reason, without recipient identities.
	 *
	 * @return array<string,int> Skip reason => rows, largest first.
	 */
	public function skip_reason_counts(): array {
		$query = $this->database->prepare(
			'SELECT skip_reason, COUNT(*) AS total FROM %i
			WHERE status = %s GROUP BY skip_reason ORDER BY total DESC, skip_reason',
			$this->table,
			Recipients::STATUS_SKIPPED
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->database->get_results( $query, ARRAY_A );
		// phpcs:enable

		$result = array();
		foreach ( (array) $rows as $row ) {
			$reason = (string) ( $row['skip_reason'] ?? '' );
			$result[ '' === $reason ? 'unknown' : $reason ] = (int) $row['total'];
		}

		return $result;
	}

	/**
	 * Count recipient rows per delivery status, without recipient identities.
	 *
	 * @return array<string,int> Recipient status => rows.
	 */
	public function recipient_status_counts(): array {
		$query = $this->database->prepare(
			'SELECT status, COUNT(*) AS total FROM %i GROUP BY status ORDER BY status',
			$this->table
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->database->get_results( $query, ARRAY_A );
		// phpcs:enable

		$result = array();
		foreach ( (array) $rows as $row ) {
			$result[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $result;
	}

	/**
	 * Aggregate queue status without recipient identities.
	 *
	 * @return array<string,array<string,int>> Campaign status => counts.
	 */
	public function aggregate_status(): array {
		$query = $this->database->prepare(
			'SELECT status, COUNT(*) AS campaigns, SUM(recipient_count) AS recipients,
				SUM(submitted_count) AS submitted, SUM(failed_count) AS failed,
				SUM(skipped_count) AS skipped
			FROM %i GROUP BY status ORDER BY status',
			$this->campaigns
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->database->get_results( $query, ARRAY_A );
		// phpcs:enable

		$result = array();
		foreach ( (array) $rows as $row ) {
			$result[ (string) $row['status'] ] = array(
				'campaigns'  => (int) $row['campaigns'],
				'recipients' => (int) $row['recipients'],
				'submitted'  => (int) $row['submitted'],
				'failed'     => (int) $row['failed'],
				'skipped'    => (int) $row['skipped'],
			);
		}

		return $result;
	}

	/**
	 * Recompute campaign counters from recipient rows and complete when idle.
	 *
	 * @param int               $campaign_id    Campaign ID.
	 * @param int               $audience_skips Audience-stage skip total.
	 * @param DateTimeInterface $now            Current time.
	 * @return string|null New terminal status when this call completed the campaign.
	 */
	public function refresh_campaign(
		int $campaign_id,
		int $audience_skips,
		DateTimeInterface $now
	): ?string {
		$this->write(
			$this->database->prepare(
				'UPDATE %i c SET
					c.submitted_count = (SELECT COUNT(*) FROM %i r
						WHERE r.campaign_id = c.id AND r.status = %s),
					c.failed_count = (SELECT COUNT(*) FROM %i r
						WHERE r.campaign_id = c.id AND r.status = %s),
					c.skipped_count = %d + (SELECT COUNT(*) FROM %i r
						WHERE r.campaign_id = c.id AND r.status = %s)
				WHERE c.id = %d',
				$this->campaigns,
				$this->table,
				Recipients::STATUS_SUBMITTED,
				$this->table,
				Recipients::STATUS_FAILED,
				$audience_skips,
				$this->table,
				Recipients::STATUS_SKIPPED,
				$campaign_id
			)
		);

		$stamp = UtcDateTime::format( $now );
		foreach ( array( true, false ) as $with_errors ) {
			$status = $with_errors
				? Campaigns::STATUS_COMPLETED_WITH_ERRORS
				: Campaigns::STATUS_COMPLETED;
			$cmp    = $with_errors ? '>' : '=';
			// $cmp is one of two fixed operators.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = "UPDATE %i c SET c.status = %s, c.completed_at_gmt = %s
				WHERE c.id = %d AND c.status IN (%s, %s) AND c.failed_count {$cmp} 0
				AND NOT EXISTS (
					SELECT 1 FROM %i r WHERE r.campaign_id = c.id AND r.status IN (%s, %s)
				)";
			// phpcs:enable
			$done = $this->write(
				$this->database->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$sql,
					$this->campaigns,
					$status,
					$stamp,
					$campaign_id,
					Campaigns::STATUS_QUEUED,
					Campaigns::STATUS_SENDING,
					$this->table,
					Recipients::STATUS_QUEUED,
					Recipients::STATUS_CLAIMED
				)
			);
			if ( 1 === $done ) {
				return $status;
			}
		}

		return null;
	}

	/**
	 * Execute one prepared write, retrying transient InnoDB lock conflicts.
	 *
	 * Every queue write is a single autocommit statement. Concurrent workers
	 * can deadlock between a claim's range update and another worker's
	 * row update. InnoDB then rolls back the victim statement completely, so
	 * re-running the same compare-and-set statement is safe. Without the retry,
	 * a worker that already handed a message to the transport would crash
	 * before recording it, and the row would be re-sent after lease expiry.
	 *
	 * @param string $query Prepared SQL.
	 * @return int Affected rows.
	 * @throws RuntimeException When the write fails.
	 */
	private function write( string $query ): int {
		for ( $attempt = 1; ; ++$attempt ) {
			// Plugin-owned queue persistence intentionally uses direct SQL.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
			$result = $this->database->query( $query );
			// phpcs:enable
			if ( false !== $result ) {
				return (int) $result;
			}
			if ( $attempt >= self::LOCK_RETRY_ATTEMPTS || ! $this->is_lock_conflict() ) {
				throw new RuntimeException( 'Queue persistence failed.' );
			}
			usleep( wp_rand( 2000, 20000 ) * $attempt );
		}
	}

	/**
	 * Whether the last statement failed with a retryable lock conflict.
	 *
	 * @return bool
	 */
	private function is_lock_conflict(): bool {
		$error = (string) $this->database->last_error;

		return str_contains( $error, 'Deadlock found' )
			|| str_contains( $error, 'Lock wait timeout exceeded' );
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
}

// EOF: src/Delivery/QueueRepository.php.
