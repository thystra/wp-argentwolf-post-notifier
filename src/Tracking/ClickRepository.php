<?php
/**
 * File: src/Tracking/ClickRepository.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Tracking;

use ArgentWolf\PostNotifier\Campaign\CampaignRecipientRepository as Recipients;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Database\UtcDateTime;
use DateTimeInterface;
use LogicException;
use RuntimeException;
use wpdb;

/**
 * Click-token lookup and privacy-minimal click recording.
 *
 * A click event stores only the campaign recipient row ID, the UTC time and a
 * fixed destination kind. No IP address, user agent, referrer or cookie is read
 * or stored.
 */
final class ClickRepository {

	public const DESTINATION_POST = 'post';

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
	 * Resolve a click-token hash to its recipient row and campaign post.
	 *
	 * @param string $token_hash SHA-256 click-token hash.
	 * @return array{id:int,campaign_id:int,post_id:int,status:string,submitted_at_gmt:string}|null
	 */
	public function find_by_click_hash( string $token_hash ): ?array {
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $token_hash ) ) {
			return null;
		}
		$query = $this->database->prepare(
			'SELECT r.id, r.campaign_id, c.post_id, r.status, r.submitted_at_gmt
			FROM %i r INNER JOIN %i c ON c.id = r.campaign_id
			WHERE r.click_token_hash = %s
			AND r.personal_data_erased_at_gmt IS NULL
			LIMIT 1',
			$this->tables->campaign_recipients(),
			$this->tables->campaigns(),
			$token_hash
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->database->get_row( $query, ARRAY_A );
		// phpcs:enable
		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'id'               => (int) $row['id'],
			'campaign_id'      => (int) $row['campaign_id'],
			'post_id'          => (int) $row['post_id'],
			'status'           => (string) $row['status'],
			'submitted_at_gmt' => (string) ( $row['submitted_at_gmt'] ?? '' ),
		);
	}

	/**
	 * Record one click for a submitted recipient row.
	 *
	 * @param int               $recipient_id Campaign recipient row ID.
	 * @param int               $campaign_id  Campaign ID.
	 * @param DateTimeInterface $now          Click time.
	 * @return bool Whether this was the recipient's first tracked click.
	 * @throws RuntimeException When a write fails.
	 */
	public function record( int $recipient_id, int $campaign_id, DateTimeInterface $now ): bool {
		$stamp = UtcDateTime::format( $now );
		$this->write(
			$this->database->prepare(
				'INSERT INTO %i (campaign_recipient_id, clicked_at_gmt, destination_kind)
				VALUES (%d, %s, %s)',
				$this->tables->clicks(),
				$recipient_id,
				$stamp,
				self::DESTINATION_POST
			)
		);
		$first = 1 === $this->write(
			$this->database->prepare(
				'UPDATE %i SET first_clicked_at_gmt = %s
				WHERE id = %d AND first_clicked_at_gmt IS NULL',
				$this->tables->campaign_recipients(),
				$stamp,
				$recipient_id
			)
		);
		$this->write(
			$this->database->prepare(
				'UPDATE %i SET click_count = click_count + 1, last_clicked_at_gmt = %s
				WHERE id = %d',
				$this->tables->campaign_recipients(),
				$stamp,
				$recipient_id
			)
		);
		$this->write(
			$this->database->prepare(
				'UPDATE %i SET total_click_count = total_click_count + 1,
					unique_click_count = unique_click_count + %d
				WHERE id = %d',
				$this->tables->campaigns(),
				$first ? 1 : 0,
				$campaign_id
			)
		);

		return $first;
	}

	/**
	 * Whether a recipient status can receive clicks.
	 *
	 * @param string $status Recipient row status.
	 * @return bool
	 */
	public static function is_clickable_status( string $status ): bool {
		return Recipients::STATUS_SUBMITTED === $status;
	}

	/**
	 * Execute one prepared write.
	 *
	 * @param string $query Prepared SQL.
	 * @return int Affected rows.
	 * @throws RuntimeException When the write fails.
	 */
	private function write( string $query ): int {
		// Plugin-owned click persistence intentionally uses direct SQL.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable
		if ( false === $result ) {
			throw new RuntimeException( 'Click persistence failed.' );
		}

		return (int) $result;
	}
}

// EOF: src/Tracking/ClickRepository.php.
