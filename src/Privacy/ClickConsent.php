<?php
/**
 * File: src/Privacy/ClickConsent.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Privacy;

use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Database\UtcDateTime;
use LogicException;
use RuntimeException;
use wpdb;

/**
 * Explicit, separately worded consent to per-user click attribution.
 *
 * Consent is never implied by subscribing. It is stored with its UTC time and
 * the wording version shown, for registered users in user meta and for
 * standalone subscribers in schema-4 columns. Withdrawal is immediate and
 * also deletes clicks already attributed to that person (totals are kept).
 */
final class ClickConsent {

	/**
	 * Version of the consent wording below. Bump when the text changes.
	 */
	public const VERSION = '1';

	public const USER_AT_KEY      = '_argentwolf_post_notifier_click_consent_at';
	public const USER_VERSION_KEY = '_argentwolf_post_notifier_click_consent_version';

	public const FIELD = 'argentwolf_post_notifier_click_consent';

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
	 * Construct the store.
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
	 * The consent wording shown next to the unticked checkbox.
	 *
	 * @return string
	 */
	public static function wording(): string {
		return __(
			// phpcs:ignore Generic.Files.LineLength -- Preserve gettext source text.
			'Optional: record which links I click in notification emails, linked to my address, so the site owner can see my individual clicks. I can withdraw this at any time.',
			'argentwolf-post-notifier'
		);
	}

	/**
	 * Confirmation-email paragraph, or '' while per-user tracking is off.
	 *
	 * Consent is never taken from the public form: it can only be given on the
	 * confirmation page reached from this email (proving inbox ownership), as a
	 * separate unticked choice.
	 *
	 * @return string
	 */
	public static function confirmation_email_notice(): string {
		if ( ! ( new PrivacySettings() )->per_user_tracking_enabled() ) {
			return '';
		}

		return sprintf(
			/* translators: %s: consent wording version. */
			__(
				// phpcs:ignore Generic.Files.LineLength -- Preserve gettext source text.
				'Optional: the confirmation page also offers a separate, unticked choice to allow per-recipient click tracking (consent wording version %s). Confirming the subscription alone does not grant it.',
				'argentwolf-post-notifier'
			),
			self::VERSION
		);
	}

	/**
	 * Whether a delivery recipient currently consents.
	 *
	 * @param int $user_id       Registered user ID or 0.
	 * @param int $subscriber_id Standalone subscriber ID or 0.
	 * @return bool
	 */
	public function has_consent( int $user_id, int $subscriber_id ): bool {
		if ( $user_id > 0 && '' !== (string) get_user_meta( $user_id, self::USER_AT_KEY, true ) ) {
			return true;
		}

		return $subscriber_id > 0 && null !== $this->subscriber_record( $subscriber_id );
	}

	/**
	 * Consent record of a registered user.
	 *
	 * @param int $user_id User ID.
	 * @return array{at:string,version:string}|null
	 */
	public function user_record( int $user_id ): ?array {
		$at = (string) get_user_meta( $user_id, self::USER_AT_KEY, true );

		return '' === $at
			? null
			: array(
				'at'      => $at,
				'version' => (string) get_user_meta( $user_id, self::USER_VERSION_KEY, true ),
			);
	}

	/**
	 * Consent record of a standalone subscriber.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return array{at:string,version:string}|null
	 */
	public function subscriber_record( int $subscriber_id ): ?array {
		$row = $this->database->get_row(
			$this->database->prepare(
				'SELECT click_consent_at_gmt, click_consent_version FROM %i WHERE id = %d',
				$this->tables->subscribers(),
				$subscriber_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || null === $row['click_consent_at_gmt'] ) {
			return null;
		}

		return array(
			'at'      => (string) $row['click_consent_at_gmt'],
			'version' => (string) $row['click_consent_version'],
		);
	}

	/**
	 * Grant consent for a registered user.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function grant_user( int $user_id ): void {
		update_user_meta( $user_id, self::USER_AT_KEY, UtcDateTime::now() );
		update_user_meta( $user_id, self::USER_VERSION_KEY, self::VERSION );
	}

	/**
	 * Withdraw a registered user's consent and delete their attributed clicks.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function withdraw_user( int $user_id ): void {
		delete_user_meta( $user_id, self::USER_AT_KEY );
		delete_user_meta( $user_id, self::USER_VERSION_KEY );
		$this->clear_attribution( 'user_id', $user_id );
	}

	/**
	 * Grant consent for a standalone subscriber.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return void
	 * @throws RuntimeException When the write fails.
	 */
	public function grant_subscriber( int $subscriber_id ): void {
		$this->write(
			$this->database->prepare(
				'UPDATE %i SET click_consent_at_gmt = %s, click_consent_version = %s
				WHERE id = %d',
				$this->tables->subscribers(),
				UtcDateTime::now(),
				self::VERSION,
				$subscriber_id
			)
		);
	}

	/**
	 * Withdraw a subscriber's consent and delete their attributed clicks.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return void
	 * @throws RuntimeException When a write fails.
	 */
	public function withdraw_subscriber( int $subscriber_id ): void {
		$this->write(
			$this->database->prepare(
				'UPDATE %i SET click_consent_at_gmt = NULL, click_consent_version = NULL
				WHERE id = %d',
				$this->tables->subscribers(),
				$subscriber_id
			)
		);
		$this->clear_attribution( 'subscriber_id', $subscriber_id );
	}

	/**
	 * Delete attributed click events and per-row counters for one identity.
	 *
	 * @param string $column  user_id or subscriber_id (internal constant).
	 * @param int    $id      Identity ID.
	 * @return void
	 * @throws RuntimeException When a write fails.
	 */
	private function clear_attribution( string $column, int $id ): void {
		$column = 'user_id' === $column ? 'user_id' : 'subscriber_id';
		$this->write(
			$this->database->prepare(
				'DELETE c FROM %i c INNER JOIN %i r ON r.id = c.campaign_recipient_id
				WHERE r.%i = %d',
				$this->tables->clicks(),
				$this->tables->campaign_recipients(),
				$column,
				$id
			)
		);
		$this->write(
			$this->database->prepare(
				'UPDATE %i SET click_count = 0, last_clicked_at_gmt = NULL WHERE %i = %d',
				$this->tables->campaign_recipients(),
				$column,
				$id
			)
		);
	}

	/**
	 * Execute one prepared write.
	 *
	 * @param string $query Prepared SQL.
	 * @return void
	 * @throws RuntimeException When the write fails.
	 */
	private function write( string $query ): void {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable
		if ( false === $result ) {
			throw new RuntimeException( 'Click consent persistence failed.' );
		}
	}
}

// EOF: src/Privacy/ClickConsent.php.
