<?php
/**
 * File: src/Suppression/SuppressionRepository.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Suppression;

use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Database\UtcDateTime;
use DateTimeInterface;
use LogicException;
use RuntimeException;
use wpdb;

/**
 * Persistence for global email suppression.
 */
final class SuppressionRepository {
	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Suppression table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Construct the suppression repository.
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
		$this->table    = TableNames::from_database( $database )->suppressions();
	}

	/**
	 * Return one suppression row by canonical email hash.
	 *
	 * @param string $email_hash Canonical keyed email hash.
	 * @return array<string,mixed>|null
	 */
	public function find_by_hash( string $email_hash ): ?array {
		$query = $this->database->prepare(
			'SELECT email_hash, email_snapshot_or_redacted, reason, source,
				created_at_gmt, updated_at_gmt
			FROM %i
			WHERE email_hash = %s
			LIMIT 1',
			$this->table,
			$email_hash
		);

		// Plugin-owned suppression reads intentionally query the custom table.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->database->get_row( $query, ARRAY_A );
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Create or replace the current suppression state for one email hash.
	 *
	 * @param string            $email_hash     Canonical keyed email hash.
	 * @param string            $email_snapshot Normalized email snapshot.
	 * @param string            $reason         Suppression reason code.
	 * @param string            $source         Suppression source code.
	 * @param DateTimeInterface $now            Current UTC-compatible time.
	 * @return void
	 * @throws RuntimeException When the database operation fails.
	 */
	public function upsert(
		string $email_hash,
		string $email_snapshot,
		string $reason,
		string $source,
		DateTimeInterface $now
	): void {
		$now_gmt = UtcDateTime::format( $now );
		$query   = $this->database->prepare(
			'INSERT INTO %i
				(email_hash, email_snapshot_or_redacted, reason, source,
				created_at_gmt, updated_at_gmt)
			VALUES (%s, %s, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				email_snapshot_or_redacted = VALUES(email_snapshot_or_redacted),
				reason = VALUES(reason),
				source = VALUES(source),
				updated_at_gmt = VALUES(updated_at_gmt)',
			$this->table,
			$email_hash,
			$email_snapshot,
			$reason,
			$source,
			$now_gmt,
			$now_gmt
		);

		// Plugin-owned suppression writes intentionally update the custom table.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable

		if ( false === $result ) {
			throw new RuntimeException( 'Email suppression could not be saved.' );
		}
	}

	/**
	 * Create or refresh suppression without replacing a different source.
	 *
	 * This conditional upsert keeps a concurrent administrator suppression from
	 * being overwritten by a self-service request that observed an older state.
	 *
	 * @param string            $email_hash     Canonical keyed email hash.
	 * @param string            $email_snapshot Normalized email snapshot.
	 * @param string            $reason         Suppression reason code.
	 * @param string            $source         Suppression source code.
	 * @param DateTimeInterface $now            Current UTC-compatible time.
	 * @return void
	 * @throws RuntimeException When the database operation fails.
	 */
	public function upsert_if_absent_or_source(
		string $email_hash,
		string $email_snapshot,
		string $reason,
		string $source,
		DateTimeInterface $now
	): void {
		$now_gmt = UtcDateTime::format( $now );
		$query   = $this->database->prepare(
			'INSERT INTO %i
				(email_hash, email_snapshot_or_redacted, reason, source,
				created_at_gmt, updated_at_gmt)
			VALUES (%s, %s, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				email_snapshot_or_redacted = IF(
					source = VALUES(source),
					VALUES(email_snapshot_or_redacted),
					email_snapshot_or_redacted
				),
				reason = IF(source = VALUES(source), VALUES(reason), reason),
				updated_at_gmt = IF(
					source = VALUES(source),
					VALUES(updated_at_gmt),
					updated_at_gmt
				)',
			$this->table,
			$email_hash,
			$email_snapshot,
			$reason,
			$source,
			$now_gmt,
			$now_gmt
		);

		// Plugin-owned suppression writes intentionally update the custom table.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable

		if ( false === $result ) {
			throw new RuntimeException( 'Email suppression could not be saved safely.' );
		}
	}

	/**
	 * Remove a suppression only when it still belongs to the expected source.
	 *
	 * @param string $email_hash      Canonical keyed email hash.
	 * @param string $expected_source Source allowed to remove its own suppression.
	 * @return bool True when one suppression row was removed.
	 * @throws RuntimeException When the database operation fails.
	 */
	public function delete_if_source(
		string $email_hash,
		string $expected_source
	): bool {
		$query = $this->database->prepare(
			'DELETE FROM %i WHERE email_hash = %s AND source = %s',
			$this->table,
			$email_hash,
			$expected_source
		);

		// Plugin-owned suppression writes intentionally update the custom table.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable

		if ( false === $result ) {
			throw new RuntimeException( 'Email suppression could not be removed.' );
		}

		return 1 === $result;
	}
}

// EOF: src/Suppression/SuppressionRepository.php.
