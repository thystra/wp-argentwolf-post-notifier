<?php
/**
 * File: src/Audit/AuditRepository.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Audit;

use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Database\UtcDateTime;
use DateTimeInterface;
use LogicException;
use RuntimeException;
use wpdb;

/**
 * Persistence for privacy-conscious list and suppression audit events.
 */
final class AuditRepository {
	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Audit-event table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Construct the audit repository.
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
		$this->table    = TableNames::from_database( $database )->audit_events();
	}

	/**
	 * Persist one structured audit event.
	 *
	 * No raw email address, bearer token, IP address, or user-agent value is
	 * accepted by this storage contract.
	 *
	 * @param AuditEventType    $event_type    Stable event type.
	 * @param int|null          $actor_user_id WordPress actor ID, when authenticated.
	 * @param string            $subject_type  Stable subject type.
	 * @param int|null          $subject_id    Subject row/entity ID when applicable.
	 * @param string|null       $related_type  Optional related entity type.
	 * @param int|null          $related_id    Optional related entity ID.
	 * @param string|null       $email_hash    Canonical keyed email hash only.
	 * @param string|null       $reason        Stable reason code.
	 * @param string|null       $source        Stable source code.
	 * @param DateTimeInterface $now           Current UTC-compatible time.
	 * @return int Inserted audit-event row ID.
	 * @throws RuntimeException When the database operation fails.
	 */
	public function record(
		AuditEventType $event_type,
		?int $actor_user_id,
		string $subject_type,
		?int $subject_id,
		?string $related_type,
		?int $related_id,
		?string $email_hash,
		?string $reason,
		?string $source,
		DateTimeInterface $now
	): int {
		// Plugin-owned audit writes intentionally update the custom table.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->database->insert(
			$this->table,
			array(
				'event_type'     => $event_type->value,
				'actor_user_id'  => $actor_user_id,
				'subject_type'   => $subject_type,
				'subject_id'     => $subject_id,
				'related_type'   => $related_type,
				'related_id'     => $related_id,
				'email_hash'     => $email_hash,
				'reason'         => $reason,
				'source'         => $source,
				'created_at_gmt' => UtcDateTime::format( $now ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		// phpcs:enable

		if ( false === $result ) {
			throw new RuntimeException( 'Audit event could not be recorded.' );
		}

		return (int) $this->database->insert_id;
	}

	/**
	 * Return recent audit rows newest first.
	 *
	 * @param int $limit Maximum rows, bounded to 1-250.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit = 50 ): array {
		$limit = max( 1, min( 250, $limit ) );
		$query = $this->database->prepare(
			'SELECT id, event_type, actor_user_id, subject_type, subject_id,
				related_type, related_id, email_hash, reason, source, created_at_gmt
			FROM %i
			ORDER BY id DESC
			LIMIT %d',
			$this->table,
			$limit
		);

		// Plugin-owned audit reads intentionally query the custom table.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->database->get_results( $query, ARRAY_A );
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}
}

// EOF: src/Audit/AuditRepository.php.
