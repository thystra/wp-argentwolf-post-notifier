<?php
/**
 * File: src/Campaign/CampaignRenderedBodyFinalizer.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Campaign;

use ArgentWolf\PostNotifier\Content\EmailContentRenderer;
use ArgentWolf\PostNotifier\Content\EmailContentSelector;
use ArgentWolf\PostNotifier\Content\EmailTemplateComposer;
use ArgentWolf\PostNotifier\Database\TableNames;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use wpdb;

/**
 * Freeze canonical recipient-neutral email bodies without making a campaign sendable.
 *
 * Real unsubscribe and management links MUST be appended downstream for each
 * recipient. Populated campaign bodies remain in building state and are not
 * complete deliverable messages.
 */
final class CampaignRenderedBodyFinalizer {
	/** Bound rendered body columns before writing to database longtext. */
	private const MAX_BODY_BYTES = 12582912;

	/** Stay within the campaign subject's SQL TEXT capacity. */
	private const MAX_SUBJECT_BYTES = 60000;

	/**
	 * WordPress database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Site-specific campaign table.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Compose exclusively from the existing write-once source snapshot.
	 *
	 * @param CampaignContentSnapshotStore|null $snapshots Snapshot reader.
	 * @param EmailTemplateComposer|null        $composer  Canonical content composer.
	 * @param wpdb|null                         $database  Optional database connection.
	 * @throws LogicException If the WordPress database is unavailable.
	 */
	public function __construct(
		private ?CampaignContentSnapshotStore $snapshots = null,
		private ?EmailTemplateComposer $composer = null,
		?wpdb $database = null
	) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb ?? null;
		}
		if ( ! $database instanceof wpdb ) {
			throw new LogicException( 'WordPress database connection is unavailable.' );
		}
		$this->database  = $database;
		$this->table     = TableNames::from_database( $database )->campaigns();
		$this->snapshots = $snapshots ?? new CampaignContentSnapshotStore( $database );
		$this->composer  = $composer ?? new EmailTemplateComposer(
			new EmailContentRenderer( new EmailContentSelector() )
		);
	}

	/**
	 * Atomically populate subject and recipient-neutral bodies only once.
	 *
	 * Requires a complete input snapshot and an otherwise-empty building shell.
	 * Does not resolve recipients, change status or send mail. A false result
	 * means the row was absent, already rendered, or otherwise ineligible.
	 *
	 * @param int $campaign_id Existing campaign identifier.
	 * @return bool True if this invocation performed the write.
	 * @throws InvalidArgumentException When the campaign ID or content is invalid.
	 * @throws RuntimeException When database persistence fails.
	 */
	public function finalize( int $campaign_id ): bool {
		if ( $campaign_id < 1 ) {
			throw new InvalidArgumentException( 'Invalid campaign identifier.' );
		}
		$input = $this->snapshots->read( $campaign_id );
		if ( null === $input ) {
			return false;
		}
		$message = $this->composer->compose_campaign_body( $input );
		foreach ( array( 'subject', 'html', 'text' ) as $field ) {
			if (
				! is_string( $message[ $field ] )
				|| '' === $message[ $field ]
				|| strlen( $message[ $field ] ) > (
					'subject' === $field ? self::MAX_SUBJECT_BYTES : self::MAX_BODY_BYTES
				)
			) {
				throw new InvalidArgumentException( 'Invalid campaign rendered message.' );
			}
		}

		$query = $this->database->prepare(
			'UPDATE %i SET subject = %s, html_body = %s, text_body = %s
			WHERE id = %d AND post_id = %d AND content_mode = %s
			AND status = %s AND template_snapshot_json IS NOT NULL
			AND subject = %s AND html_body = %s AND text_body = %s',
			$this->table,
			$message['subject'],
			$message['html'],
			$message['text'],
			$campaign_id,
			$input['post_id'],
			$input['content_mode'],
			CampaignRepository::STATUS_BUILDING,
			'',
			'',
			''
		);
		// Direct SQL provides a single compare-and-set over all three columns.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable
		if ( false === $result ) {
			throw new RuntimeException( 'Campaign rendered-body persistence failed.' );
		}
		return 1 === $result;
	}
}

// EOF: src/Campaign/CampaignRenderedBodyFinalizer.php.
