<?php
/**
 * File: src/Editor/PostNotificationMeta.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Editor;

use ArgentWolf\PostNotifier\Admin\Capabilities;
use ArgentWolf\PostNotifier\Contracts\Registerable;

/**
 * Register the editor-owned post-notification configuration contract.
 */
final class PostNotificationMeta implements Registerable {
	/**
	 * Send-intent post meta key.
	 */
	public const SEND_INTENT_KEY = '_argentwolf_post_notifier_send_intent';

	/**
	 * Audience-configuration post meta key.
	 */
	public const AUDIENCE_CONFIG_KEY = '_argentwolf_post_notifier_audience_config';

	/**
	 * Content-mode post meta key.
	 */
	public const CONTENT_MODE_KEY = '_argentwolf_post_notifier_content_mode';

	/**
	 * Template-identifier post meta key.
	 */
	public const TEMPLATE_ID_KEY = '_argentwolf_post_notifier_template_id';

	/**
	 * Call-to-action post meta key.
	 */
	public const CTA_TEXT_KEY = '_argentwolf_post_notifier_cta_text';

	/**
	 * Supported post type.
	 */
	private const POST_TYPE = 'post';

	/**
	 * Maximum selected role slugs stored per post.
	 */
	private const MAX_ROLE_ITEMS = 100;

	/**
	 * Maximum IDs stored in each audience-selection bucket.
	 */
	private const MAX_AUDIENCE_ITEMS = 500;

	/**
	 * Maximum CTA text length.
	 */
	private const MAX_CTA_LENGTH = 200;

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'init',
			array( $this, 'register_meta_fields' ),
			5
		);
		add_filter(
			'rest_request_before_callbacks',
			array( $this, 'authorize_autosave_meta' ),
			10,
			3
		);
		add_filter(
			'wp_post_revision_meta_keys',
			array( $this, 'filter_revision_meta_keys' ),
			10,
			2
		);
	}

	/**
	 * Register revision-aware REST-visible notification metadata.
	 *
	 * REST schemas expose these protected values only in edit context. Writes
	 * require both permission to edit the target post and the dedicated send
	 * capability. Revision support lets core preserve authorized notification
	 * state when it creates autosave or ordinary revisions.
	 *
	 * @return void
	 */
	public function register_meta_fields(): void {
		$this->register_string_meta(
			self::SEND_INTENT_KEY,
			SendIntent::SiteDefault->value,
			SendIntent::values(),
			array( SendIntent::class, 'sanitize' ),
			__( 'Post notification send intent.', 'argentwolf-post-notifier' )
		);

		register_post_meta(
			self::POST_TYPE,
			self::AUDIENCE_CONFIG_KEY,
			array(
				'type'              => 'object',
				'description'       => __(
					'Post notification audience configuration.',
					'argentwolf-post-notifier'
				),
				'single'            => true,
				'default'           => self::default_audience_config(),
				'sanitize_callback' => array( self::class, 'sanitize_audience_config' ),
				'auth_callback'     => array( self::class, 'authorize_meta_write' ),
				'revisions_enabled' => true,
				'show_in_rest'      => array(
					'schema' => self::audience_rest_schema(),
				),
			)
		);

		$this->register_string_meta(
			self::CONTENT_MODE_KEY,
			ContentMode::SiteDefault->value,
			ContentMode::values(),
			array( ContentMode::class, 'sanitize' ),
			__( 'Post notification content mode.', 'argentwolf-post-notifier' )
		);

		register_post_meta(
			self::POST_TYPE,
			self::TEMPLATE_ID_KEY,
			array(
				'type'              => 'integer',
				'description'       => __(
					'Post notification template identifier; zero uses the site default.',
					'argentwolf-post-notifier'
				),
				'single'            => true,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( self::class, 'authorize_meta_write' ),
				'revisions_enabled' => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'context' => array( 'edit' ),
					),
				),
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::CTA_TEXT_KEY,
			array(
				'type'              => 'string',
				'description'       => __(
					'Optional post notification call-to-action text.',
					'argentwolf-post-notifier'
				),
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitize_cta_text' ),
				'auth_callback'     => array( self::class, 'authorize_meta_write' ),
				'revisions_enabled' => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'      => 'string',
						'maxLength' => self::MAX_CTA_LENGTH,
						'context'   => array( 'edit' ),
					),
				),
			)
		);
	}

	/**
	 * Return all editor configuration meta keys.
	 *
	 * @return array<int,string>
	 */
	public static function keys(): array {
		return array(
			self::SEND_INTENT_KEY,
			self::AUDIENCE_CONFIG_KEY,
			self::CONTENT_MODE_KEY,
			self::TEMPLATE_ID_KEY,
			self::CTA_TEXT_KEY,
		);
	}

	/**
	 * Authorize a write to notification metadata.
	 *
	 * @param bool         $allowed    Existing mapped result.
	 * @param string       $meta_key   Meta key being authorized.
	 * @param int          $object_id  Post ID.
	 * @param int          $user_id    Acting user ID.
	 * @param string       $capability Meta capability being mapped.
	 * @param array<mixed> $caps       Primitive capabilities considered.
	 * @return bool
	 */
	public static function authorize_meta_write(
		bool $allowed,
		string $meta_key,
		int $object_id,
		int $user_id,
		string $capability,
		array $caps
	): bool {
		unset( $allowed, $meta_key, $capability, $caps );

		if ( $object_id < 1 || $user_id < 1 ) {
			return false;
		}

		return user_can( $user_id, 'edit_post', $object_id )
			&& user_can( $user_id, Capabilities::SEND_NOTIFICATIONS );
	}

	/**
	 * Reject unauthorized notification metadata on the REST autosave route.
	 *
	 * Core writes revision-enabled autosave metadata directly, bypassing the
	 * registered meta auth callback. Keep the send capability authoritative.
	 *
	 * @param mixed            $response Current REST response or error.
	 * @param array<mixed>     $handler  Matched REST route handler.
	 * @param \WP_REST_Request $request  REST request.
	 * @return mixed
	 */
	public function authorize_autosave_meta(
		mixed $response,
		array $handler,
		\WP_REST_Request $request
	): mixed {
		unset( $handler );

		if ( is_wp_error( $response ) || 'POST' !== $request->get_method() ) {
			return $response;
		}

		$route = rtrim( $request->get_route(), '/' );
		if ( ! str_ends_with( $route, '/autosaves' ) ) {
			return $response;
		}

		$meta = $request->get_param( 'meta' );
		if ( ! is_array( $meta ) ) {
			return $response;
		}

		$submitted_keys = array_keys( $meta );
		if ( array() === array_intersect( self::keys(), $submitted_keys ) ) {
			return $response;
		}

		if ( current_user_can( Capabilities::SEND_NOTIFICATIONS ) ) {
			return $response;
		}

		return new \WP_Error(
			'argentwolf_post_notifier_cannot_edit_notification_meta',
			__(
				'Sorry, you are not allowed to edit post notification settings.',
				'argentwolf-post-notifier'
			),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Remove notification metadata from revision handling for unauthorized users.
	 *
	 * This protects non-REST autosave and revision paths that also write
	 * revision-enabled metadata without consulting registered meta auth callbacks.
	 *
	 * @param array<int,string> $keys      Revision-enabled meta keys.
	 * @param string            $post_type Post type being revisioned.
	 * @return array<int,string>
	 */
	public function filter_revision_meta_keys( array $keys, string $post_type ): array {
		if (
			self::POST_TYPE !== $post_type
			|| current_user_can( Capabilities::SEND_NOTIFICATIONS )
		) {
			return $keys;
		}

		return array_values( array_diff( $keys, self::keys() ) );
	}

	/**
	 * Return the canonical empty audience configuration.
	 *
	 * @return array<string,array<int,int|string>>
	 */
	public static function default_audience_config(): array {
		return array(
			'role_slugs'              => array(),
			'named_list_ids'          => array(),
			'included_user_ids'       => array(),
			'included_subscriber_ids' => array(),
			'excluded_user_ids'       => array(),
			'excluded_subscriber_ids' => array(),
		);
	}

	/**
	 * Normalize submitted audience configuration.
	 *
	 * Unknown properties are discarded for direct metadata writes; REST requests
	 * reject them through the schema before this sanitizer runs.
	 *
	 * @param mixed $value Candidate configuration.
	 * @return array<string,array<int,int|string>>
	 */
	public static function sanitize_audience_config( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return self::default_audience_config();
		}

		return array(
			'role_slugs'              => self::sanitize_role_slugs(
				$value['role_slugs'] ?? array()
			),
			'named_list_ids'          => self::sanitize_ids(
				$value['named_list_ids'] ?? array()
			),
			'included_user_ids'       => self::sanitize_ids(
				$value['included_user_ids'] ?? array()
			),
			'included_subscriber_ids' => self::sanitize_ids(
				$value['included_subscriber_ids'] ?? array()
			),
			'excluded_user_ids'       => self::sanitize_ids(
				$value['excluded_user_ids'] ?? array()
			),
			'excluded_subscriber_ids' => self::sanitize_ids(
				$value['excluded_subscriber_ids'] ?? array()
			),
		);
	}

	/**
	 * Sanitize optional call-to-action copy.
	 *
	 * @param mixed $value Candidate CTA text.
	 * @return string
	 */
	public static function sanitize_cta_text( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return wp_html_excerpt(
			sanitize_text_field( (string) $value ),
			self::MAX_CTA_LENGTH,
			''
		);
	}

	/**
	 * Register one enum-like string metadata field.
	 *
	 * @param string            $key           Meta key.
	 * @param string            $default_value Default value.
	 * @param array<int,string> $allowed       REST enum values.
	 * @param callable          $sanitizer     Sanitizer.
	 * @param string            $description   Human-readable description.
	 * @return void
	 */
	private function register_string_meta(
		string $key,
		string $default_value,
		array $allowed,
		callable $sanitizer,
		string $description
	): void {
		register_post_meta(
			self::POST_TYPE,
			$key,
			array(
				'type'              => 'string',
				'description'       => $description,
				'single'            => true,
				'default'           => $default_value,
				'sanitize_callback' => $sanitizer,
				'auth_callback'     => array( self::class, 'authorize_meta_write' ),
				'revisions_enabled' => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'    => 'string',
						'enum'    => $allowed,
						'context' => array( 'edit' ),
					),
				),
			)
		);
	}

	/**
	 * Return REST schema for the audience configuration object.
	 *
	 * @return array<string,mixed>
	 */
	private static function audience_rest_schema(): array {
		$id_list = array(
			'type'     => 'array',
			'maxItems' => self::MAX_AUDIENCE_ITEMS,
			'items'    => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);

		return array(
			'type'                 => 'object',
			'context'              => array( 'edit' ),
			'additionalProperties' => false,
			'properties'           => array(
				'role_slugs'              => array(
					'type'     => 'array',
					'maxItems' => self::MAX_ROLE_ITEMS,
					'items'    => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 64,
						'pattern'   => '^[a-z0-9_-]+$',
					),
				),
				'named_list_ids'          => $id_list,
				'included_user_ids'       => $id_list,
				'included_subscriber_ids' => $id_list,
				'excluded_user_ids'       => $id_list,
				'excluded_subscriber_ids' => $id_list,
			),
		);
	}

	/**
	 * Normalize role slugs deterministically.
	 *
	 * @param mixed $values Candidate values.
	 * @return array<int,string>
	 */
	private static function sanitize_role_slugs( mixed $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$normalized = array();
		foreach ( array_slice( $values, 0, self::MAX_ROLE_ITEMS ) as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$slug = sanitize_key( (string) $value );
			if ( '' !== $slug ) {
				$normalized[ $slug ] = $slug;
			}
		}

		ksort( $normalized, SORT_STRING );

		return array_values( $normalized );
	}

	/**
	 * Normalize positive integer identifiers deterministically.
	 *
	 * @param mixed $values Candidate values.
	 * @return array<int,int>
	 */
	private static function sanitize_ids( mixed $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$normalized = array();
		foreach ( array_slice( $values, 0, self::MAX_AUDIENCE_ITEMS ) as $value ) {
			if ( is_int( $value ) ) {
				$id = $value;
			} elseif ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
				$id = (int) $value;
			} else {
				continue;
			}

			if ( $id > 0 ) {
				$normalized[ $id ] = $id;
			}
		}

		ksort( $normalized, SORT_NUMERIC );

		return array_values( $normalized );
	}
}

// EOF: src/Editor/PostNotificationMeta.php.
