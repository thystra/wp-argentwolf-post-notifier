<?php
/**
 * File: src/Subscriber/SubscriberRepository.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Database\UtcDateTime;
use DateTimeInterface;
use LogicException;
use RuntimeException;
use wpdb;

/**
 * Persistence operations for standalone subscribers.
 */
final class SubscriberRepository {
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
	 * Construct the repository.
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
	 * Create a new pending subscriber or refresh an existing pending record.
	 *
	 * Existing subscribed, unsubscribed, and suppressed records are never moved
	 * back to pending by a public signup. A pending record receives a rotated
	 * confirmation token only after the resend cooldown has elapsed.
	 *
	 * @param string            $uuid                UUID for a possible new row.
	 * @param string            $email               Normalized email address.
	 * @param string            $email_hash          Keyed normalized-email hash.
	 * @param string|null       $display_name        Optional normalized display name.
	 * @param string            $consent_text        Consent text snapshot.
	 * @param string            $signup_source       Bounded source identifier.
	 * @param int|null          $source_post_id      Optional source post ID.
	 * @param string            $token_hash          New confirmation-token hash.
	 * @param DateTimeInterface $expires_at          New token expiry.
	 * @param DateTimeInterface $cooldown_cutoff     Latest previous-send time allowed.
	 * @param DateTimeInterface $now                 Current operation time.
	 * @return array{subscriber_id:int,token_rotated:bool}
	 * @throws RuntimeException When a database operation fails.
	 */
	public function create_or_refresh_pending(
		string $uuid,
		string $email,
		string $email_hash,
		?string $display_name,
		string $consent_text,
		string $signup_source,
		?int $source_post_id,
		string $token_hash,
		DateTimeInterface $expires_at,
		DateTimeInterface $cooldown_cutoff,
		DateTimeInterface $now
	): array {
		$now_gmt     = UtcDateTime::format( $now );
		$expires_gmt = UtcDateTime::format( $expires_at );
		$cutoff_gmt  = UtcDateTime::format( $cooldown_cutoff );

		$insert = $this->database->prepare(
			'INSERT INTO %i
			(uuid, email, email_hash, display_name, status, confirmation_token_hash,
			confirmation_expires_at_gmt, created_at_gmt, last_confirmation_sent_at_gmt,
			consent_text_snapshot, signup_source, source_post_id, updated_at_gmt)
			VALUES (%s, %s, %s, NULLIF(%s, \'\'), %s, %s, %s, %s, %s, %s, %s, NULLIF(%d, 0), %s)
			ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
			$this->table,
			$uuid,
			$email,
			$email_hash,
			$display_name,
			SubscriberStatus::Pending->value,
			$token_hash,
			$expires_gmt,
			$now_gmt,
			$now_gmt,
			$consent_text,
			$signup_source,
			$source_post_id,
			$now_gmt
		);

		// Direct access is required for plugin-owned persistence primitives.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$insert_result = $this->database->query( $insert );
		// phpcs:enable

		if ( false === $insert_result ) {
			throw new RuntimeException( 'Subscriber record could not be created.' );
		}

		$row = $this->find_by_email_hash( $email_hash );
		if ( null === $row ) {
			throw new RuntimeException( 'Subscriber record could not be resolved after signup.' );
		}

		$subscriber_id = (int) $row['id'];
		if ( 1 === $insert_result && $uuid === (string) $row['uuid'] ) {
			return array(
				'subscriber_id' => $subscriber_id,
				'token_rotated' => true,
			);
		}

		if ( SubscriberStatus::Pending->value !== (string) $row['status'] ) {
			return array(
				'subscriber_id' => $subscriber_id,
				'token_rotated' => false,
			);
		}

		$token_update = $this->database->prepare(
			'UPDATE %i
			SET confirmation_token_hash = %s,
				confirmation_expires_at_gmt = %s,
				last_confirmation_sent_at_gmt = %s,
				updated_at_gmt = %s
			WHERE id = %d
			AND status = %s
			AND (
				last_confirmation_sent_at_gmt IS NULL
				OR last_confirmation_sent_at_gmt <= %s
			)',
			$this->table,
			$token_hash,
			$expires_gmt,
			$now_gmt,
			$now_gmt,
			$subscriber_id,
			SubscriberStatus::Pending->value,
			$cutoff_gmt
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$token_result = $this->database->query( $token_update );
		// phpcs:enable
		if ( false === $token_result ) {
			throw new RuntimeException( 'Subscriber confirmation token could not be refreshed.' );
		}

		$metadata_update = $this->database->prepare(
			'UPDATE %i
			SET email = %s,
				display_name = NULLIF(%s, \'\'),
				consent_text_snapshot = %s,
				signup_source = %s,
				source_post_id = NULLIF(%d, 0),
				updated_at_gmt = %s
			WHERE id = %d AND status = %s',
			$this->table,
			$email,
			$display_name,
			$consent_text,
			$signup_source,
			$source_post_id,
			$now_gmt,
			$subscriber_id,
			SubscriberStatus::Pending->value
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$metadata_result = $this->database->query( $metadata_update );
		// phpcs:enable
		if ( false === $metadata_result ) {
			throw new RuntimeException( 'Pending subscriber metadata could not be refreshed.' );
		}

		return array(
			'subscriber_id' => $subscriber_id,
			'token_rotated' => 1 === $token_result,
		);
	}

	/**
	 * Confirm one unexpired pending subscriber by token hash.
	 *
	 * @param string            $token_hash Confirmation-token hash.
	 * @param DateTimeInterface $now        Current operation time.
	 * @return bool True only when a pending record was promoted.
	 * @throws RuntimeException When the database operation fails.
	 */
	public function confirm_pending( string $token_hash, DateTimeInterface $now ): bool {
		$now_gmt = UtcDateTime::format( $now );
		$query   = $this->database->prepare(
			'UPDATE %i
			SET status = %s,
				confirmed_at_gmt = %s,
				confirmation_token_hash = NULL,
				confirmation_expires_at_gmt = NULL,
				updated_at_gmt = %s
			WHERE status = %s
			AND confirmation_token_hash = %s
			AND confirmation_expires_at_gmt IS NOT NULL
			AND confirmation_expires_at_gmt >= %s',
			$this->table,
			SubscriberStatus::Subscribed->value,
			$now_gmt,
			$now_gmt,
			SubscriberStatus::Pending->value,
			$token_hash,
			$now_gmt
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable
		if ( false === $result ) {
			throw new RuntimeException( 'Subscriber confirmation database operation failed.' );
		}

		return 1 === $result;
	}

	/**
	 * Find one subscriber by keyed normalized-email hash.
	 *
	 * @param string $email_hash Email identity hash.
	 * @return array<string,mixed>|null
	 */
	private function find_by_email_hash( string $email_hash ): ?array {
		$query = $this->database->prepare(
			'SELECT * FROM %i WHERE email_hash = %s LIMIT 1',
			$this->table,
			$email_hash
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->database->get_row( $query, ARRAY_A );
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}
}

// EOF: src/Subscriber/SubscriberRepository.php.
