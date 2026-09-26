<?php
/**
 * File: src/Recipient/NamedListRepository.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Recipient;

use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Database\UtcDateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use wpdb;

/**
 * Persistence for named recipient lists and typed list memberships.
 */
final class NamedListRepository {
	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Named-list table.
	 *
	 * @var string
	 */
	private string $lists_table;

	/**
	 * Typed list-membership table.
	 *
	 * @var string
	 */
	private string $members_table;

	/**
	 * Standalone-subscriber table.
	 *
	 * @var string
	 */
	private string $subscribers_table;

	/**
	 * Construct the named-list repository.
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

		$tables                  = TableNames::from_database( $database );
		$this->database          = $database;
		$this->lists_table       = $tables->lists();
		$this->members_table     = $tables->list_members();
		$this->subscribers_table = $tables->subscribers();
	}

	/**
	 * Return all named lists ordered by name.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		$query = $this->database->prepare(
			'SELECT named_list.id, named_list.uuid, named_list.name, named_list.description,
				named_list.created_by, named_list.created_at_gmt, named_list.updated_at_gmt,
				COUNT(member.id) AS member_count
			FROM %i AS named_list
			LEFT JOIN %i AS member ON member.list_id = named_list.id
			GROUP BY named_list.id, named_list.uuid, named_list.name,
				named_list.description, named_list.created_by, named_list.created_at_gmt,
				named_list.updated_at_gmt
			ORDER BY named_list.name ASC, named_list.id ASC',
			$this->lists_table,
			$this->members_table
		);

		// Plugin-owned administration reads intentionally query custom tables.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->database->get_results( $query, ARRAY_A );
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Find one named list.
	 *
	 * @param int $list_id List row ID.
	 * @return array<string,mixed>|null
	 */
	public function find( int $list_id ): ?array {
		if ( $list_id < 1 ) {
			return null;
		}

		$query = $this->database->prepare(
			'SELECT id, uuid, name, description, created_by, created_at_gmt,
				updated_at_gmt FROM %i WHERE id = %d LIMIT 1',
			$this->lists_table,
			$list_id
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->database->get_row( $query, ARRAY_A );
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Create one named list.
	 *
	 * @param string                 $name        List name.
	 * @param string                 $description Optional description.
	 * @param int|null               $created_by  Creating WordPress user ID.
	 * @param DateTimeInterface|null $now         Optional current-time override.
	 * @return int Created list row ID.
	 * @throws RuntimeException When persistence fails.
	 */
	public function create(
		string $name,
		string $description,
		?int $created_by = null,
		?DateTimeInterface $now = null
	): int {
		$name        = $this->required_name( $name );
		$description = $this->description( $description );
		$current     = $this->normalize_now( $now );
		$uuid        = wp_generate_uuid4();

		$query = $this->database->prepare(
			'INSERT INTO %i
			(uuid, name, description, created_by, created_at_gmt, updated_at_gmt)
			VALUES (%s, %s, NULLIF(%s, \'\'), NULLIF(%d, 0), %s, %s)',
			$this->lists_table,
			$uuid,
			$name,
			$description,
			$created_by,
			UtcDateTime::format( $current ),
			UtcDateTime::format( $current )
		);

		// Plugin-owned administration writes intentionally update custom tables.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable

		if ( 1 !== $result ) {
			throw new RuntimeException( 'Named list could not be created.' );
		}

		return (int) $this->database->insert_id;
	}

	/**
	 * Update one named list.
	 *
	 * @param int                    $list_id     List row ID.
	 * @param string                 $name        List name.
	 * @param string                 $description Optional description.
	 * @param DateTimeInterface|null $now         Optional current-time override.
	 * @return bool True when a row was changed.
	 * @throws RuntimeException When persistence fails.
	 */
	public function update(
		int $list_id,
		string $name,
		string $description,
		?DateTimeInterface $now = null
	): bool {
		if ( $list_id < 1 ) {
			return false;
		}

		$name        = $this->required_name( $name );
		$description = $this->description( $description );
		$current     = $this->normalize_now( $now );
		$query       = $this->database->prepare(
			'UPDATE %i SET name = %s, description = NULLIF(%s, \'\'), updated_at_gmt = %s
			WHERE id = %d',
			$this->lists_table,
			$name,
			$description,
			UtcDateTime::format( $current ),
			$list_id
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable

		if ( false === $result ) {
			throw new RuntimeException( 'Named list could not be updated.' );
		}

		return 1 === $result;
	}

	/**
	 * Delete one named list and its membership rows atomically.
	 *
	 * Contacts, subscriber state, preferences, and suppressions are untouched.
	 *
	 * @param int $list_id List row ID.
	 * @return bool True when the list existed and was removed.
	 * @throws RuntimeException When persistence fails.
	 */
	public function delete( int $list_id ): bool {
		if ( $list_id < 1 ) {
			return false;
		}

		$query = $this->database->prepare(
			'DELETE named_list, member
			FROM %i AS named_list
			LEFT JOIN %i AS member ON member.list_id = named_list.id
			WHERE named_list.id = %d',
			$this->lists_table,
			$this->members_table,
			$list_id
		);

		// Plugin-owned administration writes intentionally update custom tables.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable

		if ( false === $result ) {
			throw new RuntimeException( 'Named list could not be deleted.' );
		}

		return $result > 0;
	}

	/**
	 * Return typed members for one list.
	 *
	 * @param int $list_id List row ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function members( int $list_id ): array {
		if ( $list_id < 1 ) {
			return array();
		}

		$query = $this->database->prepare(
			'SELECT member.id AS membership_id, member.member_type, member.member_key,
				member.user_id, member.subscriber_id, member.created_at_gmt,
				CASE WHEN member.member_type = %s THEN wp_user.user_email
					ELSE subscriber.email END AS email,
				CASE WHEN member.member_type = %s THEN wp_user.display_name
					ELSE subscriber.display_name END AS display_name,
				subscriber.status AS subscriber_status
			FROM %i AS member
			LEFT JOIN %i AS wp_user ON wp_user.ID = member.user_id
			LEFT JOIN %i AS subscriber ON subscriber.id = member.subscriber_id
			WHERE member.list_id = %d
			ORDER BY email ASC, member.id ASC',
			NamedListMemberType::User->value,
			NamedListMemberType::User->value,
			$this->members_table,
			$this->database->users,
			$this->subscribers_table,
			$list_id
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->database->get_results( $query, ARRAY_A );
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Resolve the typed entity ID for an administrative email lookup.
	 *
	 * @param NamedListMemberType $type  Member type.
	 * @param string              $email Normalized email address.
	 * @return int|null
	 */
	public function resolve_member_id( NamedListMemberType $type, string $email ): ?int {
		if ( NamedListMemberType::User === $type ) {
			$user = get_user_by( 'email', $email );

			return false === $user ? null : (int) $user->ID;
		}

		$query = $this->database->prepare(
			'SELECT id FROM %i WHERE email = %s LIMIT 1',
			$this->subscribers_table,
			$email
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$subscriber_id = $this->database->get_var( $query );
		// phpcs:enable

		return null === $subscriber_id ? null : (int) $subscriber_id;
	}

	/**
	 * Add one typed member to a named list.
	 *
	 * Membership is organizational only. This operation never changes user
	 * preferences, subscriber status, confirmation state, or global suppression.
	 *
	 * @param int                    $list_id   List row ID.
	 * @param NamedListMemberType    $type      Member type.
	 * @param int                    $entity_id WordPress user or subscriber row ID.
	 * @param DateTimeInterface|null $now       Optional current-time override.
	 * @return bool True when a new membership was inserted.
	 * @throws InvalidArgumentException When the list or member does not exist.
	 * @throws RuntimeException When persistence fails.
	 */
	public function add_member(
		int $list_id,
		NamedListMemberType $type,
		int $entity_id,
		?DateTimeInterface $now = null
	): bool {
		if ( null === $this->find( $list_id ) ) {
			throw new InvalidArgumentException( 'Named list does not exist.' );
		}
		if ( ! $this->entity_exists( $type, $entity_id ) ) {
			throw new InvalidArgumentException( 'Named-list member does not exist.' );
		}

		$user_id       = NamedListMemberType::User === $type ? $entity_id : 0;
		$subscriber_id = NamedListMemberType::Subscriber === $type ? $entity_id : 0;
		$query         = $this->database->prepare(
			'INSERT IGNORE INTO %i
			(list_id, member_type, member_key, user_id, subscriber_id, created_at_gmt)
			VALUES (%d, %s, %s, NULLIF(%d, 0), NULLIF(%d, 0), %s)',
			$this->members_table,
			$list_id,
			$type->value,
			$type->member_key( $entity_id ),
			$user_id,
			$subscriber_id,
			UtcDateTime::format( $this->normalize_now( $now ) )
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable

		if ( false === $result ) {
			throw new RuntimeException( 'Named-list membership could not be saved.' );
		}

		return 1 === $result;
	}

	/**
	 * Remove one membership row from one named list.
	 *
	 * @param int $list_id       List row ID.
	 * @param int $membership_id Membership row ID.
	 * @return bool True when one membership was removed.
	 * @throws RuntimeException When persistence fails.
	 */
	public function remove_member( int $list_id, int $membership_id ): bool {
		if ( $list_id < 1 || $membership_id < 1 ) {
			return false;
		}

		$query = $this->database->prepare(
			'DELETE FROM %i WHERE id = %d AND list_id = %d',
			$this->members_table,
			$membership_id,
			$list_id
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->database->query( $query );
		// phpcs:enable

		if ( false === $result ) {
			throw new RuntimeException( 'Named-list membership could not be removed.' );
		}

		return 1 === $result;
	}

	/**
	 * Determine whether the typed backing entity exists.
	 *
	 * @param NamedListMemberType $type      Member type.
	 * @param int                 $entity_id WordPress user or subscriber row ID.
	 * @return bool
	 */
	private function entity_exists( NamedListMemberType $type, int $entity_id ): bool {
		if ( $entity_id < 1 ) {
			return false;
		}
		if ( NamedListMemberType::User === $type ) {
			return false !== get_user_by( 'id', $entity_id );
		}

		$query = $this->database->prepare(
			'SELECT id FROM %i WHERE id = %d LIMIT 1',
			$this->subscribers_table,
			$entity_id
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$id = $this->database->get_var( $query );
		// phpcs:enable

		return null !== $id;
	}

	/**
	 * Validate a list name for persistence.
	 *
	 * @param string $name Candidate list name.
	 * @return string
	 * @throws InvalidArgumentException When the name is empty or too long.
	 */
	private function required_name( string $name ): string {
		$name = trim( $name );
		if ( '' === $name || strlen( $name ) > 191 ) {
			throw new InvalidArgumentException( 'Named-list name is invalid.' );
		}

		return $name;
	}

	/**
	 * Validate a list description for persistence.
	 *
	 * @param string $description Candidate description.
	 * @return string
	 */
	private function description( string $description ): string {
		return trim( $description );
	}

	/**
	 * Return an immutable UTC time value.
	 *
	 * @param DateTimeInterface|null $now Optional current-time override.
	 * @return DateTimeImmutable
	 */
	private function normalize_now( ?DateTimeInterface $now ): DateTimeImmutable {
		$current = null === $now
			? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
			: DateTimeImmutable::createFromInterface( $now );

		return $current->setTimezone( new DateTimeZone( 'UTC' ) );
	}
}

// EOF: src/Recipient/NamedListRepository.php.
