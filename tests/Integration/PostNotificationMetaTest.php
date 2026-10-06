<?php
/**
 * File: tests/Integration/PostNotificationMetaTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\Capabilities;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Editor\SendIntent;
use ArgentWolf\PostNotifier\Plugin;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Integration coverage for REST-visible post notification metadata.
 */
final class PostNotificationMetaTest extends WP_UnitTestCase {
	/**
	 * Administrator user ID used by authorized request fixtures.
	 *
	 * @var int
	 */
	private int $administrator_id;

	/**
	 * Set up one integration test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$meta = Plugin::instance()->container()->get( PostNotificationMeta::class );
		self::assertInstanceOf( PostNotificationMeta::class, $meta );
		$meta->register();
		$meta->register_meta_fields();

		Capabilities::install( true );
		$this->administrator_id = self::factory()->user->create(
			array( 'role' => 'administrator' )
		);
		wp_set_current_user( $this->administrator_id );
	}

	/**
	 * Tear down one integration test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		Capabilities::install( true );
		parent::tear_down();
	}

	/**
	 * Confirm metadata registration precedes core REST initialization.
	 *
	 * @return void
	 */
	public function test_meta_registration_hook_precedes_core_rest_initialization(): void {
		$meta = Plugin::instance()->container()->get( PostNotificationMeta::class );
		self::assertInstanceOf( PostNotificationMeta::class, $meta );
		self::assertSame(
			5,
			has_action( 'init', array( $meta, 'register_meta_fields' ) )
		);
		self::assertSame(
			10,
			has_filter(
				'rest_request_before_callbacks',
				array( $meta, 'authorize_autosave_meta' )
			)
		);
		self::assertSame(
			10,
			has_filter(
				'wp_post_revision_meta_keys',
				array( $meta, 'filter_revision_meta_keys' )
			)
		);
	}

	/**
	 * Confirm the registered metadata contract and revision support.
	 *
	 * @return void
	 */
	public function test_meta_contract_is_registered_for_edit_context_and_revisions(): void {
		$registered    = get_registered_meta_keys( 'post', 'post' );
		$revision_keys = wp_post_revision_meta_keys( 'post' );

		foreach ( PostNotificationMeta::keys() as $meta_key ) {
			self::assertArrayHasKey( $meta_key, $registered );
			self::assertTrue( (bool) $registered[ $meta_key ]['single'] );
			self::assertTrue( (bool) $registered[ $meta_key ]['revisions_enabled'] );
			self::assertContains( $meta_key, $revision_keys );
			self::assertSame(
				array( 'edit' ),
				$registered[ $meta_key ]['show_in_rest']['schema']['context']
			);
		}
	}

	/**
	 * Confirm public REST responses do not expose notification metadata.
	 *
	 * @return void
	 */
	public function test_public_rest_response_does_not_expose_notification_meta(): void {
		$post_id = self::factory()->post->create(
			array( 'post_status' => 'publish' )
		);
		update_post_meta(
			$post_id,
			PostNotificationMeta::SEND_INTENT_KEY,
			SendIntent::Send->value
		);

		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', 'view' );
		$response = rest_do_request( $request );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		foreach ( PostNotificationMeta::keys() as $meta_key ) {
			self::assertFalse( isset( $data['meta'][ $meta_key ] ) );
		}
	}

	/**
	 * Confirm REST writes require post-edit and notification-send access.
	 *
	 * @return void
	 */
	public function test_rest_write_requires_post_edit_and_send_capability(): void {
		$editor_id = self::factory()->user->create(
			array( 'role' => 'editor' )
		);
		$post_id   = self::factory()->post->create(
			array(
				'post_author' => $editor_id,
				'post_status' => 'draft',
			)
		);
		wp_set_current_user( $editor_id );

		self::assertTrue( current_user_can( 'edit_post', $post_id ) );
		self::assertFalse( current_user_can( Capabilities::SEND_NOTIFICATIONS ) );

		$response = $this->update_post_meta_via_rest(
			$post_id,
			array(
				PostNotificationMeta::SEND_INTENT_KEY => SendIntent::Send->value,
			)
		);
		self::assertGreaterThanOrEqual( 400, $response->get_status() );
		self::assertSame(
			SendIntent::SiteDefault->value,
			get_post_meta( $post_id, PostNotificationMeta::SEND_INTENT_KEY, true )
		);

		$editor = get_user_by( 'id', $editor_id );
		self::assertInstanceOf( \WP_User::class, $editor );
		$editor->add_cap( Capabilities::SEND_NOTIFICATIONS );
		wp_set_current_user( $editor_id );

		$response = $this->update_post_meta_via_rest(
			$post_id,
			array(
				PostNotificationMeta::SEND_INTENT_KEY => SendIntent::Send->value,
			)
		);
		self::assertSame( 200, $response->get_status() );
		self::assertSame(
			SendIntent::Send->value,
			get_post_meta( $post_id, PostNotificationMeta::SEND_INTENT_KEY, true )
		);
	}

	/**
	 * Confirm audience configuration is canonicalized and strictly shaped.
	 *
	 * @return void
	 */
	public function test_audience_config_is_canonicalized_and_rejects_unknown_properties(): void {
		$post_id = self::factory()->post->create(
			array( 'post_status' => 'draft' )
		);

		$response = $this->update_post_meta_via_rest(
			$post_id,
			array(
				PostNotificationMeta::AUDIENCE_CONFIG_KEY => array(
					'role_slugs'              => array( 'editor', 'author', 'editor' ),
					'named_list_ids'          => array( 9, 3, 9 ),
					'included_user_ids'       => array( 8, 2, 8 ),
					'included_subscriber_ids' => array( 7 ),
					'excluded_user_ids'       => array( 6 ),
					'excluded_subscriber_ids' => array( 5, 4, 5 ),
				),
			)
		);
		self::assertSame( 200, $response->get_status() );
		self::assertSame(
			array(
				'role_slugs'              => array( 'author', 'editor' ),
				'named_list_ids'          => array( 3, 9 ),
				'included_user_ids'       => array( 2, 8 ),
				'included_subscriber_ids' => array( 7 ),
				'excluded_user_ids'       => array( 6 ),
				'excluded_subscriber_ids' => array( 4, 5 ),
			),
			get_post_meta( $post_id, PostNotificationMeta::AUDIENCE_CONFIG_KEY, true )
		);

		$response = $this->update_post_meta_via_rest(
			$post_id,
			array(
				PostNotificationMeta::AUDIENCE_CONFIG_KEY => array(
					'role_slugs' => array( 'editor' ),
					'unexpected' => array( 1 ),
				),
			)
		);
		self::assertGreaterThanOrEqual( 400, $response->get_status() );
	}

	/**
	 * Confirm autosave metadata requires notification-send capability.
	 *
	 * @return void
	 */
	public function test_autosave_meta_requires_send_capability(): void {
		$editor_id = self::factory()->user->create(
			array( 'role' => 'editor' )
		);
		$post_id   = self::factory()->post->create(
			array(
				'post_author' => $this->administrator_id,
				'post_status' => 'draft',
			)
		);
		update_post_meta(
			$post_id,
			PostNotificationMeta::SEND_INTENT_KEY,
			SendIntent::Send->value
		);
		wp_set_current_user( $editor_id );

		$response = $this->autosave_meta_via_rest(
			$post_id,
			array(
				PostNotificationMeta::SEND_INTENT_KEY => SendIntent::DoNotSend->value,
			)
		);

		self::assertSame( 403, $response->get_status() );
		self::assertSame(
			SendIntent::Send->value,
			get_post_meta( $post_id, PostNotificationMeta::SEND_INTENT_KEY, true )
		);
		self::assertFalse( wp_get_post_autosave( $post_id, $editor_id ) );

		foreach ( PostNotificationMeta::keys() as $meta_key ) {
			self::assertNotContains( $meta_key, wp_post_revision_meta_keys( 'post' ) );
		}
	}

	/**
	 * Confirm autosave metadata is revisioned without mutating the parent.
	 *
	 * @return void
	 */
	public function test_autosave_meta_is_revisioned_without_mutating_parent(): void {
		$author_id = self::factory()->user->create(
			array( 'role' => 'author' )
		);
		$post_id   = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'draft',
			)
		);
		update_post_meta(
			$post_id,
			PostNotificationMeta::SEND_INTENT_KEY,
			SendIntent::Send->value
		);

		$response = $this->autosave_meta_via_rest(
			$post_id,
			array(
				PostNotificationMeta::SEND_INTENT_KEY => SendIntent::DoNotSend->value,
			)
		);

		self::assertContains( $response->get_status(), array( 200, 201 ) );
		self::assertSame(
			SendIntent::Send->value,
			get_post_meta( $post_id, PostNotificationMeta::SEND_INTENT_KEY, true )
		);
		$autosave_id = (int) ( $response->get_data()['id'] ?? 0 );
		self::assertGreaterThan( 0, $autosave_id );
		self::assertNotSame( $post_id, $autosave_id );
		self::assertSame( $post_id, wp_is_post_autosave( $autosave_id ) );
		self::assertSame(
			SendIntent::DoNotSend->value,
			get_post_meta( $autosave_id, PostNotificationMeta::SEND_INTENT_KEY, true )
		);
	}

	/**
	 * Submit notification metadata through the core autosave REST route.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $meta    Meta values.
	 * @return \WP_REST_Response
	 */
	private function autosave_meta_via_rest( int $post_id, array $meta ): \WP_REST_Response {
		$request = new WP_REST_Request(
			'POST',
			'/wp/v2/posts/' . $post_id . '/autosaves'
		);
		$request->set_param( 'meta', $meta );
		$response = rest_do_request( $request );
		self::assertInstanceOf( \WP_REST_Response::class, $response );

		return $response;
	}

	/**
	 * Update registered meta through the posts REST controller.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $meta    Meta values.
	 * @return \WP_REST_Response
	 */
	private function update_post_meta_via_rest( int $post_id, array $meta ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'meta', $meta );
		$response = rest_do_request( $request );
		self::assertInstanceOf( \WP_REST_Response::class, $response );

		return $response;
	}
}

// EOF: tests/Integration/PostNotificationMetaTest.php.
