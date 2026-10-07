<?php
/**
 * File: tests/Integration/EditorContactLookupTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\Capabilities;
use ArgentWolf\PostNotifier\Editor\EditorContactLookup;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use DateTimeImmutable;
use DateTimeZone;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Integration coverage for privacy-bounded editor contact lookup.
 */
final class EditorContactLookupTest extends WP_UnitTestCase {
	/**
	 * Authorized administrator fixture.
	 *
	 * @var int
	 */
	private int $administrator_id = 0;

	/**
	 * Editable post fixture.
	 *
	 * @var int
	 */
	private int $post_id = 0;

	/**
	 * Prepare an authorized editor context.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$GLOBALS['wp_rest_server'] = null;

		Capabilities::install( true );
		$this->administrator_id = self::factory()->user->create(
			array( 'role' => 'administrator' )
		);
		$this->post_id          = self::factory()->post->create(
			array(
				'post_author' => $this->administrator_id,
				'post_status' => 'draft',
			)
		);
		wp_set_current_user( $this->administrator_id );
	}

	/**
	 * Restore global user state.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * The plugin must register the lookup service on REST initialization.
	 *
	 * @return void
	 */
	public function test_plugin_registers_contact_lookup_service(): void {
		$service = Plugin::instance()->container()->get( EditorContactLookup::class );

		self::assertInstanceOf( EditorContactLookup::class, $service );
		self::assertNotFalse(
			has_action( 'rest_api_init', array( $service, 'register_route' ) )
		);
	}

	/**
	 * Exact user lookup returns only a masked editor identity.
	 *
	 * @return void
	 */
	public function test_exact_user_email_lookup_masks_email(): void {
		$user_id = self::factory()->user->create(
			array(
				'user_email'   => 'person@example.com',
				'display_name' => 'Person Example',
			)
		);

		$response = $this->lookup(
			EditorContactLookup::TYPE_USER,
			array( 'email' => 'person@example.com' )
		);

		self::assertSame( 200, $response->get_status() );
		$contacts = $response->get_data()['contacts'] ?? array();
		self::assertCount( 1, $contacts );
		self::assertSame( $user_id, $contacts[0]['id'] );
		self::assertSame( 'Person Example', $contacts[0]['label'] );
		self::assertSame( 'p***@example.com', $contacts[0]['detail'] );
		self::assertTrue( $contacts[0]['selectable'] );
		self::assertStringNotContainsString(
			'person@example.com',
			wp_json_encode( $response->get_data() )
		);
	}

	/**
	 * Subscriber lookup exposes only subscribed contacts and masked addresses.
	 *
	 * @return void
	 */
	public function test_subscriber_lookup_is_exact_masked_and_eligibility_bounded(): void {
		$subscribed_id = $this->subscriber(
			'subscribed@example.com',
			'Subscribed Person',
			true
		);
		$this->subscriber( 'pending@example.com', 'Pending Person', false );

		$response = $this->lookup(
			EditorContactLookup::TYPE_SUBSCRIBER,
			array( 'email' => 'subscribed@example.com' )
		);
		self::assertSame( 200, $response->get_status() );
		$contacts = $response->get_data()['contacts'] ?? array();
		self::assertCount( 1, $contacts );
		self::assertSame( $subscribed_id, $contacts[0]['id'] );
		self::assertSame( 'Subscribed Person', $contacts[0]['label'] );
		self::assertSame( 's***@example.com', $contacts[0]['detail'] );
		self::assertTrue( $contacts[0]['selectable'] );

		$pending = $this->lookup(
			EditorContactLookup::TYPE_SUBSCRIBER,
			array( 'email' => 'pending@example.com' )
		);
		self::assertSame( 200, $pending->get_status() );
		self::assertSame( array(), $pending->get_data()['contacts'] ?? null );
	}

	/**
	 * Saved-ID hydration may surface a stale subscriber only so it can be removed.
	 *
	 * @return void
	 */
	public function test_saved_pending_subscriber_is_hydrated_as_not_selectable(): void {
		$subscriber_id = $this->subscriber(
			'pending-selected@example.com',
			'Pending Selected',
			false
		);
		update_post_meta(
			$this->post_id,
			\ArgentWolf\PostNotifier\Editor\PostNotificationMeta::AUDIENCE_CONFIG_KEY,
			array(
				'included_subscriber_ids' => array( $subscriber_id ),
			)
		);

		$response = $this->lookup(
			EditorContactLookup::TYPE_SUBSCRIBER,
			array( 'include' => (string) $subscriber_id )
		);

		self::assertSame( 200, $response->get_status() );
		$contacts = $response->get_data()['contacts'] ?? array();
		self::assertCount( 1, $contacts );
		self::assertSame( $subscriber_id, $contacts[0]['id'] );
		self::assertFalse( $contacts[0]['selectable'] );
		self::assertSame( 'p***@example.com', $contacts[0]['detail'] );
	}

	/**
	 * Saved-ID hydration cannot enumerate contacts absent from post metadata.
	 *
	 * @return void
	 */
	public function test_saved_id_hydration_cannot_enumerate_unselected_contacts(): void {
		$subscriber_id = $this->subscriber(
			'not-selected@example.com',
			'Not Selected',
			true
		);

		$response = $this->lookup(
			EditorContactLookup::TYPE_SUBSCRIBER,
			array( 'include' => (string) $subscriber_id )
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array(), $response->get_data()['contacts'] ?? null );
	}

	/**
	 * A delegated sender can resolve an exact subscriber without admin capabilities.
	 *
	 * @return void
	 */
	public function test_delegated_sender_lookup_does_not_require_management_capabilities(): void {
		$editor_id = self::factory()->user->create(
			array( 'role' => 'editor' )
		);
		$editor    = get_userdata( $editor_id );
		self::assertInstanceOf( \WP_User::class, $editor );
		$editor->add_cap( Capabilities::SEND_NOTIFICATIONS );
		$post_id = self::factory()->post->create(
			array(
				'post_author' => $editor_id,
				'post_status' => 'draft',
			)
		);
		$this->subscriber( 'sender-target@example.com', 'Sender Target', true );
		wp_set_current_user( $editor_id );

		self::assertTrue( current_user_can( Capabilities::SEND_NOTIFICATIONS ) );
		self::assertFalse( current_user_can( Capabilities::MANAGE_LISTS ) );
		self::assertFalse( current_user_can( Capabilities::MANAGE_SUBSCRIBERS ) );

		$response = $this->lookup(
			EditorContactLookup::TYPE_SUBSCRIBER,
			array(
				'post_id' => $post_id,
				'email'   => 'sender-target@example.com',
			)
		);

		self::assertSame( 200, $response->get_status() );
		self::assertCount( 1, $response->get_data()['contacts'] ?? array() );
	}

	/**
	 * Editors lacking send authorization cannot use the contact endpoint.
	 *
	 * @return void
	 */
	public function test_editor_without_send_capability_cannot_lookup_contacts(): void {
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

		$response = $this->lookup(
			EditorContactLookup::TYPE_USER,
			array(
				'post_id' => $post_id,
				'email'   => 'nobody@example.com',
			)
		);

		self::assertSame( 403, $response->get_status() );
	}

	/**
	 * Dispatch one lookup request.
	 *
	 * @param string              $type   Contact type.
	 * @param array<string,mixed> $params Additional route parameters.
	 * @return \WP_REST_Response
	 */
	private function lookup( string $type, array $params ): \WP_REST_Response {
		$request = new WP_REST_Request(
			'POST',
			'/' . EditorContactLookup::REST_NAMESPACE . EditorContactLookup::ROUTE
		);
		$request->set_param( 'post_id', $params['post_id'] ?? $this->post_id );
		$request->set_param( 'type', $type );
		foreach ( array( 'email', 'include' ) as $name ) {
			if ( isset( $params[ $name ] ) ) {
				$request->set_param( $name, $params[ $name ] );
			}
		}

		$response = rest_do_request( $request );
		self::assertInstanceOf( \WP_REST_Response::class, $response );

		return $response;
	}

	/**
	 * Create one pending or subscribed standalone contact.
	 *
	 * @param string $email      Contact email.
	 * @param string $name       Display name.
	 * @param bool   $subscribed Whether to confirm the contact.
	 * @return int Subscriber row ID.
	 */
	private function subscriber( string $email, string $name, bool $subscribed ): int {
		$service = Plugin::instance()->container()->get( SubscriberService::class );
		self::assertInstanceOf( SubscriberService::class, $service );
		$now    = new DateTimeImmutable( '2026-10-07 10:00:00', new DateTimeZone( 'UTC' ) );
		$signup = $service->request_confirmation(
			$email,
			$name,
			'Consent',
			'editor-contact-test',
			null,
			$now
		);

		if ( $subscribed ) {
			self::assertNotNull( $signup->confirmation_token() );
			self::assertTrue(
				$service->confirm(
					(string) $signup->confirmation_token(),
					$now->modify( '+1 hour' )
				)
			);
		}

		return $signup->subscriber_id();
	}
}

// EOF: tests/Integration/EditorContactLookupTest.php.
