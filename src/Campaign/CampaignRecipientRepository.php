<?php
/**
 * File: src/Campaign/CampaignRecipientRepository.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Campaign;

use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Database\UtcDateTime;
use ArgentWolf\PostNotifier\Recipient\ResolvedAudienceRecipient;
use DateTimeInterface;
use LogicException;
use RuntimeException;
use wpdb;

/**
 * Immutable campaign-recipient snapshot rows and token lookups.
 */
final class CampaignRecipientRepository {
	public const STATUS_QUEUED    = 'queued';
	public const STATUS_CLAIMED   = 'claimed';
	public const STATUS_SUBMITTED = 'submitted';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_SKIPPED   = 'skipped';

	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

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
	 * Construct the repository.
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
	 * Insert one queued snapshot row; duplicates by campaign/email are ignored.
	 *
	 * @param int                       $campaign_id Campaign ID.
	 * @param ResolvedAudienceRecipient $recipient   Eligible resolved recipient.
	 * @param DateTimeInterface         $now         Snapshot time.
	 * @return bool True when a new row was inserted.
	 * @throws RuntimeException When the write fails.
	 */
	public function insert_snapshot(
		int $campaign_id,
		ResolvedAudienceRecipient $recipient,
		DateTimeInterface $now
	): bool {
		$name = function_exists( 'mb_substr' )
			? mb_substr( $recipient->display_name(), 0, 191 )
			: substr( $recipient->display_name(), 0, 191 );
		// Rows may only be added while the owning campaign is still building.
		$query = $this->database->prepare(
			'INSERT IGNORE INTO %i
			(campaign_id, recipient_uuid, recipient_type, user_id, subscriber_id,
			email_snapshot, email_hash, display_name_snapshot, status, attempt_count,
			next_attempt_at_gmt)
			SELECT c.id, %s, %s, NULLIF(%d, 0), NULLIF(%d, 0), %s, %s, %s, %s, 0, %s
			FROM %i c WHERE c.id = %d AND c.status = %s',
			$this->table,
			wp_generate_uuid4(),
			$recipient->primary_type()->value,
			(int) $recipient->user_id(),
			(int) $recipient->subscriber_id(),
			$recipient->email(),
			$recipient->email_hash(),
			$name,
			self::STATUS_QUEUED,
			UtcDateTime::format( $now ),
			$this->campaigns,
			$campaign_id,
			CampaignRepository::STATUS_BUILDING
		);

		// Direct SQL; uniqueness is enforced by UNIQUE(campaign_id,email_hash).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable
		if ( false === $result ) {
			throw new RuntimeException( 'Campaign recipient snapshot could not be stored.' );
		}

		return 1 === $result;
	}

	/**
	 * Count rows grouped by status for one campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<string,int>
	 */
	public function status_counts( int $campaign_id ): array {
		$query = $this->database->prepare(
			'SELECT status, COUNT(*) AS total FROM %i WHERE campaign_id = %d GROUP BY status',
			$this->table,
			$campaign_id
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->database->get_results( $query, ARRAY_A );
		// phpcs:enable

		$counts = array(
			self::STATUS_QUEUED    => 0,
			self::STATUS_CLAIMED   => 0,
			self::STATUS_SUBMITTED => 0,
			self::STATUS_FAILED    => 0,
			self::STATUS_SKIPPED   => 0,
		);
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Find the recipient row and campaign UUID for a recipient token hash.
	 *
	 * @param string $token_hash SHA-256 of a plaintext recipient token.
	 * @return array<string,mixed>|null
	 */
	public function find_by_token_hash( string $token_hash ): ?array {
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $token_hash ) ) {
			return null;
		}
		$query = $this->database->prepare(
			'SELECT r.id, r.campaign_id, r.recipient_type, r.user_id, r.subscriber_id,
				r.email_snapshot, r.email_hash, r.status
			FROM %i r INNER JOIN %i c ON c.id = r.campaign_id
			WHERE r.unsubscribe_token_hash = %s
			AND r.personal_data_erased_at_gmt IS NULL
			LIMIT 1',
			$this->table,
			$this->campaigns,
			$token_hash
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->database->get_row( $query, ARRAY_A );
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}
}

// EOF: src/Campaign/CampaignRecipientRepository.php.
