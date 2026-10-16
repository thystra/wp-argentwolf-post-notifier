<?php
/**
 * File: src/Campaign/CampaignRepository.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Campaign;

use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Database\UtcDateTime;
use ArgentWolf\PostNotifier\Editor\ContentMode;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use wpdb;

/**
 * Persistence primitives for publication campaigns.
 */
final class CampaignRepository {

	/**
	 * Attempts for one campaign write that hits a deadlock or lock-wait timeout.
	 */
	private const LOCK_RETRY_ATTEMPTS = 5;

	/**
	 * Initial post-notification campaign kind.
	 */
	public const KIND_POST = 'post';

	/**
	 * Initial mutable campaign-build state.
	 */
	public const STATUS_BUILDING = 'building';

	/**
	 * Audience frozen; recipient rows await worker claims.
	 */
	public const STATUS_QUEUED = 'queued';

	/**
	 * At least one worker has claimed a recipient row.
	 */
	public const STATUS_SENDING = 'sending';

	/**
	 * No actionable rows remain and no row terminally failed.
	 */
	public const STATUS_COMPLETED = 'completed';

	/**
	 * No actionable rows remain and at least one row terminally failed.
	 */
	public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

	/**
	 * Campaign preparation failed closed before any recipient was queued.
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Campaign table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Construct the campaign repository.
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
		$this->table    = TableNames::from_database( $database )->campaigns();
	}

	/**
	 * Build the immutable initial-campaign idempotency key.
	 *
	 * @param int $site_id WordPress site/blog ID.
	 * @param int $post_id WordPress post ID.
	 * @return string
	 * @throws InvalidArgumentException When either identifier is invalid.
	 */
	public static function initial_key( int $site_id, int $post_id ): string {
		if ( $site_id < 1 || $post_id < 1 ) {
			throw new InvalidArgumentException( 'Campaign site and post IDs must be positive.' );
		}

		return sprintf( 'initial:%d:%d', $site_id, $post_id );
	}

	/**
	 * Atomically create or resolve the one initial building campaign for a post.
	 *
	 * Milestone 7 reserves the immutable campaign identity at actual publication.
	 * Later content/audience milestones fill the building row before it can move
	 * to queued state. Empty message bodies here are therefore build-state
	 * placeholders, not deliverable content.
	 *
	 * @param int                    $site_id           WordPress site/blog ID.
	 * @param int                    $post_id           Originating post ID.
	 * @param string                 $post_modified_gmt Post modified GMT snapshot.
	 * @param ContentMode            $content_mode      Stored editorial content mode.
	 * @param DateTimeInterface|null $now               Optional operation time.
	 * @return int Campaign row ID.
	 * @throws RuntimeException When persistence fails.
	 */
	public function create_initial_building(
		int $site_id,
		int $post_id,
		string $post_modified_gmt,
		ContentMode $content_mode,
		?DateTimeInterface $now = null
	): int {
		$campaign_key = self::initial_key( $site_id, $post_id );
		$uuid         = wp_generate_uuid4();
		$current      = $now ?? new DateTimeImmutable(
			'now',
			new DateTimeZone( 'UTC' )
		);
		$modified_gmt = '0000-00-00 00:00:00' === $post_modified_gmt
			? ''
			: $post_modified_gmt;

		$query = $this->database->prepare(
			'INSERT INTO %i
			(uuid, campaign_key, campaign_kind, post_id, post_modified_gmt_snapshot,
			status, subject, html_body, text_body, content_mode, created_at_gmt)
			VALUES (%s, %s, %s, %d, NULLIF(%s, \'\'), %s, %s, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
			$this->table,
			$uuid,
			$campaign_key,
			self::KIND_POST,
			$post_id,
			$modified_gmt,
			self::STATUS_BUILDING,
			'',
			'',
			'',
			$content_mode->value,
			UtcDateTime::format( $current )
		);

		// Direct access is required for plugin-owned persistence primitives.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable

		if ( false === $result ) {
			throw new RuntimeException( 'Initial campaign could not be created.' );
		}

		$row = $this->find_by_key( $campaign_key );
		if ( null === $row ) {
			throw new RuntimeException( 'Initial campaign could not be resolved after creation.' );
		}

		return (int) $row['id'];
	}

	/**
	 * Find one campaign row by ID, including frozen content and snapshots.
	 *
	 * @param int $campaign_id Campaign row ID.
	 * @return array<string,mixed>|null
	 */
	public function find( int $campaign_id ): ?array {
		if ( $campaign_id < 1 ) {
			return null;
		}
		$query = $this->database->prepare(
			'SELECT id, uuid, campaign_key, campaign_kind, post_id, status, subject,
				html_body, text_body, template_snapshot_json, audience_snapshot_json,
				content_mode, created_at_gmt, queued_at_gmt, started_at_gmt,
				completed_at_gmt, recipient_count, submitted_count, failed_count,
				skipped_count, unique_click_count, total_click_count, last_error_code
			FROM %i WHERE id = %d LIMIT 1',
			$this->table,
			$campaign_id
		);

		// Direct access is required for plugin-owned persistence primitives.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->database->get_row( $query, ARRAY_A );
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Return a bounded list of building campaign IDs created before a cutoff.
	 *
	 * Used by the watchdog to recover preparation that a lost cron event or
	 * crashed request never completed.
	 *
	 * @param DateTimeInterface $before Creation cutoff.
	 * @param int               $limit  Maximum rows (1-50).
	 * @return array<int,int>
	 */
	public function stale_building_ids( DateTimeInterface $before, int $limit = 10 ): array {
		$limit = max( 1, min( 50, $limit ) );
		$query = $this->database->prepare(
			'SELECT id FROM %i
			WHERE status = %s AND template_snapshot_json IS NOT NULL
			AND audience_snapshot_json IS NOT NULL AND created_at_gmt <= %s
			ORDER BY id ASC LIMIT %d',
			$this->table,
			self::STATUS_BUILDING,
			UtcDateTime::format( $before ),
			$limit
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$ids = $this->database->get_col( $query );
		// phpcs:enable

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Record a fixed diagnostic code on a campaign still in building state.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param string $code        Stable diagnostic code.
	 * @return void
	 */
	public function note_building_error( int $campaign_id, string $code ): void {
		$query = $this->database->prepare(
			'UPDATE %i SET last_error_code = %s WHERE id = %d AND status = %s',
			$this->table,
			$code,
			$campaign_id,
			self::STATUS_BUILDING
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$this->database->query( $query );
		// phpcs:enable
	}

	/**
	 * Compare-and-set one status transition, optionally setting a timestamp column.
	 *
	 * @param int                    $campaign_id Campaign ID.
	 * @param array<int,string>      $from        Allowed current statuses.
	 * @param string                 $to          New status.
	 * @param string|null            $time_column One of queued/started/completed_at_gmt.
	 * @param string|null            $error_code  Optional fixed diagnostic code.
	 * @param DateTimeInterface|null $now     Optional operation time.
	 * @return bool True when this call performed the transition; false when the
	 *              campaign was not in an allowed status (for example another
	 *              worker already moved it). A RuntimeException propagates from
	 *              the write when it fails for a non-transient reason.
	 * @throws InvalidArgumentException On an unsupported time column.
	 */
	public function transition(
		int $campaign_id,
		array $from,
		string $to,
		?string $time_column = null,
		?string $error_code = null,
		?DateTimeInterface $now = null
	): bool {
		$columns = array( 'queued_at_gmt', 'started_at_gmt', 'completed_at_gmt' );
		if ( null !== $time_column && ! in_array( $time_column, $columns, true ) ) {
			throw new InvalidArgumentException( 'Unsupported campaign time column.' );
		}
		if ( array() === $from ) {
			return false;
		}
		$current = UtcDateTime::format(
			$now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
		);

		$set  = 'status = %s';
		$args = array( $this->table, $to );
		if ( null !== $time_column ) {
			$set   .= ', ' . $time_column . ' = %s';
			$args[] = $current;
		}
		if ( null !== $error_code ) {
			$set   .= ', last_error_code = %s';
			$args[] = $error_code;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $from ), '%s' ) );
		$args[]       = $campaign_id;
		array_push( $args, ...array_values( $from ) );

		// $set and $placeholders contain only fixed fragments and placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$query = $this->database->prepare(
			"UPDATE %i SET {$set} WHERE id = %d AND status IN ({$placeholders})",
			...$args
		);
		// phpcs:enable

		return 1 === $this->write_with_lock_retry( $query );
	}

	/**
	 * Execute one compare-and-set write, retrying transient InnoDB lock conflicts.
	 *
	 * Concurrent queue workers update the same campaign row (queued to sending,
	 * counter refresh) while other workers' claims and refreshes hold recipient
	 * locks, so MySQL 8 can pick this statement as a deadlock victim. InnoDB
	 * rolls the victim statement back completely, and every caller's WHERE
	 * clause is a compare-and-set on the current status, so re-running it is
	 * safe: a worker that lost the race simply affects zero rows.
	 *
	 * @param string $query Prepared SQL.
	 * @return int Affected rows.
	 * @throws RuntimeException When the write fails for a non-transient reason or keeps
	 *                          conflicting.
	 */
	private function write_with_lock_retry( string $query ): int {
		for ( $attempt = 1; ; ++$attempt ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
			$result = $this->database->query( $query );
			// phpcs:enable
			if ( false !== $result ) {
				return (int) $result;
			}
			$error    = (string) $this->database->last_error;
			$conflict = str_contains( $error, 'Deadlock found' )
				|| str_contains( $error, 'Lock wait timeout exceeded' );
			if ( ! $conflict || $attempt >= self::LOCK_RETRY_ATTEMPTS ) {
				throw new RuntimeException( 'Campaign status transition failed.' );
			}
			usleep( wp_rand( 2000, 20000 ) * $attempt );
		}
	}

	/**
	 * Find one campaign by its idempotency key.
	 *
	 * @param string $campaign_key Campaign idempotency key.
	 * @return array<string,mixed>|null
	 */
	public function find_by_key( string $campaign_key ): ?array {
		$query = $this->database->prepare(
			'SELECT id, uuid, campaign_key, campaign_kind, post_id,
				post_modified_gmt_snapshot, status, subject, html_body, text_body,
				content_mode, created_at_gmt
			FROM %i
			WHERE campaign_key = %s
			LIMIT 1',
			$this->table,
			$campaign_key
		);

		// Direct access is required for plugin-owned persistence primitives.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->database->get_row( $query, ARRAY_A );
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}
}

// EOF: src/Campaign/CampaignRepository.php.
