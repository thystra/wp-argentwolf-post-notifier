<?php
/**
 * File: src/Editor/EditorContactLookup.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Editor;

use ArgentWolf\PostNotifier\Admin\Capabilities;
use ArgentWolf\PostNotifier\Contracts\Registerable;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Subscriber\SubscriberStatus;
use LogicException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use wpdb;

/**
 * Privacy-bounded exact-email and saved-ID lookup for editor contact selectors.
 */
final class EditorContactLookup implements Registerable {
	/**
	 * REST namespace.
	 */
	public const REST_NAMESPACE = 'argentwolf-post-notifier/v1';

	/**
	 * Contact lookup route.
	 */
	public const ROUTE = '/editor/contacts';

	/**
	 * WordPress-user contact type.
	 */
	public const TYPE_USER = 'user';

	/**
	 * Standalone-subscriber contact type.
	 */
	public const TYPE_SUBSCRIBER = 'subscriber';

	/**
	 * Maximum IDs hydrated by one request.
	 */
	private const MAX_INCLUDE_IDS = 100;

	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Standalone-subscriber table.
	 *
	 * @var string
	 */
	private string $subscriber_table;

	/**
	 * Construct the lookup service.
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

		$this->database         = $database;
		$this->subscriber_table = TableNames::from_database( $database )->subscribers();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Register the capability-gated editor contact endpoint.
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'lookup' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'post_id' => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'type'    => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array(
							self::TYPE_USER,
							self::TYPE_SUBSCRIBER,
						),
					),
					'email'   => array(
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					),
					'include' => array(
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					),
				),
			)
		);
	}

	/**
	 * Authorize one editor lookup against the target post.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public function permission_check( WP_REST_Request $request ): true|WP_Error {
		if ( ! current_user_can( Capabilities::SEND_NOTIFICATIONS ) ) {
			return new WP_Error(
				'argentwolf_post_notifier_contact_lookup_forbidden',
				__(
					'You are not allowed to select notification contacts.',
					'argentwolf-post-notifier'
				),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$post_id = absint( $request->get_param( 'post_id' ) );
		$post    = get_post( $post_id );
		if ( null === $post || 'post' !== $post->post_type ) {
			return new WP_Error(
				'argentwolf_post_notifier_contact_lookup_post',
				__( 'The notification post could not be found.', 'argentwolf-post-notifier' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'argentwolf_post_notifier_contact_lookup_post_forbidden',
				__( 'You are not allowed to edit this post.', 'argentwolf-post-notifier' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Resolve an exact email or hydrate already-selected IDs.
	 *
	 * Exact-email lookup deliberately does not provide directory search. A
	 * sender must already know the destination address. Subscriber lookup
	 * returns only currently subscribed rows, while ID hydration may return an
	 * existing non-subscribed row so a stale selection can still be removed.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function lookup( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id     = absint( $request->get_param( 'post_id' ) );
		$type        = (string) $request->get_param( 'type' );
		$email       = trim( (string) $request->get_param( 'email' ) );
		$include_raw = trim( (string) $request->get_param( 'include' ) );

		if (
			! in_array( $type, array( self::TYPE_USER, self::TYPE_SUBSCRIBER ), true )
		) {
			return $this->bad_request(
				__( 'The contact type is invalid.', 'argentwolf-post-notifier' )
			);
		}

		if ( ( '' === $email ) === ( '' === $include_raw ) ) {
			return $this->bad_request(
				__(
					'Provide either one exact email or a saved-ID list.',
					'argentwolf-post-notifier'
				)
			);
		}

		if ( '' !== $email ) {
			$normalized_email = strtolower( trim( $email ) );
			$validated_email  = is_email( $normalized_email );
			if ( false === $validated_email ) {
				return $this->bad_request(
					__( 'The contact email is invalid.', 'argentwolf-post-notifier' )
				);
			}
			$normalized_email = strtolower( $validated_email );

			$rows = self::TYPE_USER === $type
				? $this->user_by_email( $normalized_email )
				: $this->subscriber_by_email( $normalized_email );
		} else {
			$ids = $this->parse_ids( $include_raw );
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}

			$ids = array_values(
				array_intersect(
					$ids,
					$this->stored_contact_ids( $post_id, $type )
				)
			);

			$rows = self::TYPE_USER === $type
				? $this->users_by_ids( $ids )
				: $this->subscribers_by_ids( $ids );
		}

		$contacts = array();
		foreach ( $rows as $row ) {
			$contact = $this->public_contact( $type, $row );
			if ( null !== $contact ) {
				$contacts[] = $contact;
			}
		}

		$response = new WP_REST_Response( array( 'contacts' => $contacts ), 200 );
		$response->header(
			'Cache-Control',
			'no-store, no-cache, must-revalidate, max-age=0'
		);

		return $response;
	}

	/**
	 * Look up one WordPress user by exact email.
	 *
	 * @param string $email Exact normalized email.
	 * @return array<int,array<string,mixed>>
	 */
	private function user_by_email( string $email ): array {
		$user = get_user_by( 'email', $email );
		if ( false === $user ) {
			return array();
		}

		return array(
			array(
				'id'           => (int) $user->ID,
				'email'        => (string) $user->user_email,
				'display_name' => (string) $user->display_name,
				'selectable'   => true,
			),
		);
	}

	/**
	 * Look up one currently subscribed standalone contact by exact email.
	 *
	 * @param string $email Exact normalized email.
	 * @return array<int,array<string,mixed>>
	 */
	private function subscriber_by_email( string $email ): array {
		$query = $this->database->prepare(
			'SELECT id, email, display_name, status
			FROM %i
			WHERE email = %s AND status = %s
			LIMIT 1',
			$this->subscriber_table,
			$email,
			SubscriberStatus::Subscribed->value
		);

		// Plugin-owned editor reads intentionally query the custom subscriber table.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->database->get_row( $query, ARRAY_A );
		// phpcs:enable

		return is_array( $row ) ? array( $row ) : array();
	}

	/**
	 * Hydrate selected WordPress user IDs.
	 *
	 * @param array<int,int> $ids User IDs.
	 * @return array<int,array<string,mixed>>
	 */
	private function users_by_ids( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$users = get_users(
			array(
				'include' => $ids,
				'fields'  => array( 'ID', 'display_name', 'user_email' ),
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
		$rows  = array();

		foreach ( $users as $user ) {
			$rows[] = array(
				'id'           => (int) $user->ID,
				'email'        => (string) $user->user_email,
				'display_name' => (string) $user->display_name,
				'selectable'   => true,
			);
		}

		return $rows;
	}

	/**
	 * Hydrate selected standalone-subscriber IDs.
	 *
	 * @param array<int,int> $ids Subscriber IDs.
	 * @return array<int,array<string,mixed>>
	 */
	private function subscribers_by_ids( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder list is generated internally.
		$query = $this->database->prepare(
			'SELECT id, email, display_name, status
			FROM %i
			WHERE id IN (' . $placeholders . ')
			ORDER BY id ASC',
			$this->subscriber_table,
			...$ids
		);

		// Plugin-owned editor reads intentionally query the custom subscriber table.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->database->get_results( $query, ARRAY_A );
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Convert one database row into the minimal editor-visible identity.
	 *
	 * @param string              $type Contact type.
	 * @param array<string,mixed> $row  Internal contact row.
	 * @return array<string,mixed>|null
	 */
	private function public_contact( string $type, array $row ): ?array {
		$id = absint( $row['id'] ?? 0 );
		if ( $id < 1 ) {
			return null;
		}

		$display_name = sanitize_text_field( (string) ( $row['display_name'] ?? '' ) );
		$email        = sanitize_email( (string) ( $row['email'] ?? '' ) );
		$selectable   = self::TYPE_USER === $type
			? true
			: SubscriberStatus::Subscribed->value === (string) ( $row['status'] ?? '' );

		if (
			'' === $display_name
			|| 1 === preg_match( '/\b[^\s@]+@[^\s@]+\.[^\s@]+\b/', $display_name )
		) {
			$display_name = self::TYPE_USER === $type
				? sprintf(
					/* translators: %d: WordPress user ID. */
					__( 'WordPress user #%d', 'argentwolf-post-notifier' ),
					$id
				)
				: sprintf(
					/* translators: %d: standalone subscriber ID. */
					__( 'Subscriber #%d', 'argentwolf-post-notifier' ),
					$id
				);
		}

		return array(
			'id'         => $id,
			'label'      => $display_name,
			'detail'     => $this->masked_email( $email ),
			'selectable' => $selectable,
		);
	}

	/**
	 * Parse a bounded comma-separated saved-ID list.
	 *
	 * @param string $value Serialized IDs.
	 * @return array<int,int>|WP_Error
	 */
	private function parse_ids( string $value ): array|WP_Error {
		$parts = array_filter(
			array_map( 'trim', explode( ',', $value ) ),
			static fn ( string $part ): bool => '' !== $part
		);
		if ( count( $parts ) > self::MAX_INCLUDE_IDS ) {
			return $this->bad_request(
				__( 'Too many contact IDs were requested.', 'argentwolf-post-notifier' )
			);
		}

		$ids = array();
		foreach ( $parts as $part ) {
			if ( 1 !== preg_match( '/^[1-9][0-9]*$/D', $part ) ) {
				return $this->bad_request(
					__( 'The contact ID list is invalid.', 'argentwolf-post-notifier' )
				);
			}

			$id         = (int) $part;
			$ids[ $id ] = $id;
		}

		ksort( $ids, SORT_NUMERIC );

		return array_values( $ids );
	}

	/**
	 * Return saved contact IDs that may be hydrated for one post and type.
	 *
	 * Hydration is intentionally restricted to IDs already present in the target
	 * post metadata. This prevents the endpoint from becoming an ID-enumeration
	 * directory for send-authorized users.
	 *
	 * @param int    $post_id Target post ID.
	 * @param string $type    Contact type.
	 * @return array<int,int>
	 */
	private function stored_contact_ids( int $post_id, string $type ): array {
		$config = get_post_meta(
			$post_id,
			PostNotificationMeta::AUDIENCE_CONFIG_KEY,
			true
		);
		if ( ! is_array( $config ) ) {
			return array();
		}

		$fields = self::TYPE_USER === $type
			? array( 'included_user_ids', 'excluded_user_ids' )
			: array( 'included_subscriber_ids', 'excluded_subscriber_ids' );
		$ids    = array();

		foreach ( $fields as $field ) {
			$values = $config[ $field ] ?? array();
			if ( ! is_array( $values ) ) {
				continue;
			}

			foreach ( $values as $value ) {
				$id = absint( $value );
				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}
		}

		ksort( $ids, SORT_NUMERIC );

		return array_values( $ids );
	}

	/**
	 * Mask an email address for editor display.
	 *
	 * @param string $email Normalized email.
	 * @return string
	 */
	private function masked_email( string $email ): string {
		if ( '' === $email || ! str_contains( $email, '@' ) ) {
			return '';
		}

		list( $local, $domain ) = explode( '@', $email, 2 );
		if ( '' === $local || '' === $domain ) {
			return '';
		}

		return substr( $local, 0, 1 ) . '***@' . $domain;
	}

	/**
	 * Build a generic invalid-request error.
	 *
	 * @param string $message Internal validation message.
	 * @return WP_Error
	 */
	private function bad_request( string $message ): WP_Error {
		return new WP_Error(
			'argentwolf_post_notifier_contact_lookup_invalid',
			$message,
			array( 'status' => 400 )
		);
	}
}

// EOF: src/Editor/EditorContactLookup.php.
