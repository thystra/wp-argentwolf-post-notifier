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
	 * Initial post-notification campaign kind.
	 */
	public const KIND_POST = 'post';

	/**
	 * Initial mutable campaign-build state.
	 */
	public const STATUS_BUILDING = 'building';

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
