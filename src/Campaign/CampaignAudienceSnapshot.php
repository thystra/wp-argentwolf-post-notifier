<?php
/**
 * File: src/Campaign/CampaignAudienceSnapshot.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Campaign;

use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Database\UtcDateTime;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Recipient\AudienceRequestBuilder;
use ArgentWolf\PostNotifier\Recipient\NamedListRepository;
use ArgentWolf\PostNotifier\Recipient\AudienceResolver;
use ArgentWolf\PostNotifier\Settings\NotificationDefaults;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonException;
use LogicException;
use RuntimeException;
use wpdb;

/**
 * Freeze campaign audience configuration and materialize recipient snapshots.
 *
 * The audience configuration is captured write-once at first publication so
 * later post-meta edits cannot widen or narrow an already-published
 * campaign. Materialization resolves that frozen configuration through the
 * canonical Alpha.5 resolver (roles, lists, explicit contacts, exclusions,
 * normalization/deduplication, verification, confirmation, preference and
 * global suppression) and atomically moves the campaign to `queued` with
 * recipient and aggregate skip counts that agree with the stored rows.
 */
final class CampaignAudienceSnapshot {
	public const VERSION = 1;

	/**
	 * Site-default registered users are not subscribed until an explicit site
	 * policy exists; this matches the editor estimate and fails closed.
	 */
	private const SITE_DEFAULT_USER_SUBSCRIBED = false;

	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Campaign table.
	 *
	 * @var string
	 */
	private string $campaigns;

	/**
	 * Recipient table.
	 *
	 * @var string
	 */
	private string $recipients_table;

	/**
	 * Construct the snapshot service.
	 *
	 * @param AudienceResolver|null            $resolver   Resolver; required to materialize.
	 * @param AudienceRequestBuilder|null      $requests   Request builder.
	 * @param CampaignRecipientRepository|null $recipients Recipient persistence.
	 * @param wpdb|null                        $database   Optional database connection.
	 * @throws LogicException When the database is unavailable.
	 */
	public function __construct(
		private ?AudienceResolver $resolver = null,
		private ?AudienceRequestBuilder $requests = null,
		private ?CampaignRecipientRepository $recipients = null,
		?wpdb $database = null
	) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb ?? null;
		}
		if ( ! $database instanceof wpdb ) {
			throw new LogicException( 'WordPress database connection is unavailable.' );
		}
		$this->database         = $database;
		$tables                 = TableNames::from_database( $database );
		$this->campaigns        = $tables->campaigns();
		$this->recipients_table = $tables->campaign_recipients();
		$this->requests         = $requests ?? new AudienceRequestBuilder();
		$this->recipients       = $recipients ?? new CampaignRecipientRepository( $database );
	}

	/**
	 * Freeze the post's current sanitized audience configuration once.
	 *
	 * @param int $campaign_id Building campaign ID.
	 * @param int $post_id     Originating post ID.
	 * @return bool Whether a valid frozen configuration now exists.
	 * @throws RuntimeException When persistence fails.
	 */
	public function freeze_config( int $campaign_id, int $post_id ): bool {
		if ( null !== $this->read_config( $campaign_id ) ) {
			return true;
		}
		$config = PostNotificationMeta::sanitize_audience_config(
			get_post_meta( $post_id, PostNotificationMeta::AUDIENCE_CONFIG_KEY, true )
		);
		$config = ( new NotificationDefaults() )->resolve_audience(
			$config,
			new NamedListRepository( $this->database )
		);
		$all    = true === $config['include_all_subscribers'];
		unset( $config['include_all_subscribers'] );
		$json = $this->encode(
			array(
				'version'                 => self::VERSION,
				'config'                  => $config,
				'include_all_subscribers' => $all,
			)
		);

		$query = $this->database->prepare(
			'UPDATE %i SET audience_snapshot_json = %s
			WHERE id = %d AND post_id = %d AND status = %s AND audience_snapshot_json IS NULL',
			$this->campaigns,
			$json,
			$campaign_id,
			$post_id,
			CampaignRepository::STATUS_BUILDING
		);
		// Write-once compare-and-set.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable
		if ( false === $result ) {
			throw new RuntimeException( 'Campaign audience snapshot persistence failed.' );
		}

		return null !== $this->read_config( $campaign_id );
	}

	/**
	 * Read the frozen audience configuration, or null when absent/corrupt.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<string,mixed>|null
	 */
	public function read_config( int $campaign_id ): ?array {
		$document = $this->read_document( $campaign_id );
		if ( null === $document || ! is_array( $document['config'] ?? null ) ) {
			return null;
		}

		$config = PostNotificationMeta::sanitize_audience_config( $document['config'] );

		$config['include_all_subscribers'] = true === (
			$document['include_all_subscribers'] ?? false
		);
		return $config;
	}

	/**
	 * Read the frozen audience-stage aggregate skip counts.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<string,int>
	 */
	public function skip_counts( int $campaign_id ): array {
		$document = $this->read_document( $campaign_id );
		$counts   = array();
		foreach ( (array) ( $document['skip_counts'] ?? array() ) as $reason => $count ) {
			if ( is_string( $reason ) && is_int( $count ) && $count >= 0 ) {
				$counts[ $reason ] = $count;
			}
		}

		return $counts;
	}

	/**
	 * Resolve the frozen audience, insert recipient rows, and queue the campaign.
	 *
	 * Safe to repeat: rows are unique per campaign/email, and the queue
	 * transition is a single compare-and-set from `building`. Zero-recipient
	 * campaigns complete immediately with diagnostic counts.
	 *
	 * @param int                    $campaign_id Building campaign ID.
	 * @param DateTimeInterface|null $now         Optional operation time.
	 * Null means the audience snapshot is absent or unreadable.
	 *
	 * @return array{queued:bool,recipients:int,skipped:int,status:string}|null
	 * @throws LogicException When no resolver was supplied.
	 * @throws RuntimeException When persistence fails.
	 */
	public function materialize( int $campaign_id, ?DateTimeInterface $now = null ): ?array {
		if ( null === $this->resolver ) {
			throw new LogicException( 'Audience resolver is required to materialize recipients.' );
		}
		$config = $this->read_config( $campaign_id );
		if ( null === $config ) {
			return null;
		}
		$current = $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		$resolution = $this->resolver->resolve(
			$this->requests->from_config( $config, self::SITE_DEFAULT_USER_SUBSCRIBED )
		);
		foreach ( $resolution->recipients() as $recipient ) {
			$this->recipients->insert_snapshot( $campaign_id, $recipient, $current );
		}

		$skips   = $resolution->skip_counts();
		$skipped = array_sum( $skips );
		$counts  = $this->recipients->status_counts( $campaign_id );
		$total   = array_sum( $counts );
		$status  = 0 === $total
			? CampaignRepository::STATUS_COMPLETED
			: CampaignRepository::STATUS_QUEUED;

		$json = $this->encode(
			array(
				'version'                 => self::VERSION,
				'config'                  => $config,
				'include_all_subscribers' => true === (
					$config['include_all_subscribers'] ?? false
				),
				'skip_counts'             => $skips,
				'resolved_gmt'            => UtcDateTime::format( $current ),
			)
		);

		$stamp      = UtcDateTime::format( $current );
		$completion = CampaignRepository::STATUS_COMPLETED === $status ? $stamp : null;
		$query      = $this->database->prepare(
			'UPDATE %i SET status = %s, audience_snapshot_json = %s,
				recipient_count = (SELECT COUNT(*) FROM %i WHERE campaign_id = %d),
				skipped_count = %d, queued_at_gmt = %s,
				completed_at_gmt = NULLIF(%s, \'\'), last_error_code = NULL
			WHERE id = %d AND status = %s',
			$this->campaigns,
			$status,
			$json,
			$this->recipients_table,
			$campaign_id,
			$skipped,
			$stamp,
			(string) $completion,
			$campaign_id,
			CampaignRepository::STATUS_BUILDING
		);
		// Single compare-and-set transition out of building.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable
		if ( false === $result ) {
			throw new RuntimeException( 'Campaign queue transition failed.' );
		}

		return array(
			'queued'     => 1 === $result,
			'recipients' => $total,
			'skipped'    => $skipped,
			'status'     => $status,
		);
	}

	/**
	 * Decode the stored audience document.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array<string,mixed>|null
	 */
	private function read_document( int $campaign_id ): ?array {
		if ( $campaign_id < 1 ) {
			return null;
		}
		$query = $this->database->prepare(
			'SELECT audience_snapshot_json FROM %i WHERE id = %d LIMIT 1',
			$this->campaigns,
			$campaign_id
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$raw = $this->database->get_var( $query );
		// phpcs:enable
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		try {
			$document = json_decode( $raw, true, 16, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			return null;
		}
		if ( ! is_array( $document ) || self::VERSION !== ( $document['version'] ?? null ) ) {
			return null;
		}

		return $document;
	}

	/**
	 * Encode one audience document.
	 *
	 * @param array<string,mixed> $document Document.
	 * @return string
	 * @throws RuntimeException When encoding fails.
	 */
	private function encode( array $document ): string {
		$json = wp_json_encode( $document, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			throw new RuntimeException( 'Campaign audience snapshot could not be encoded.' );
		}

		return $json;
	}
}

// EOF: src/Campaign/CampaignAudienceSnapshot.php.
