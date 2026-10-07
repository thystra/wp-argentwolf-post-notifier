<?php
/**
 * File: tests/Integration/EditorAudienceEstimateTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\Capabilities;
use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Editor\EditorAudienceEstimate;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use ArgentWolf\PostNotifier\Suppression\SuppressionRepository;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;
use DateTimeImmutable;
use DateTimeZone;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Integration coverage for aggregate-only editor audience estimates.
 */
final class EditorAudienceEstimateTest extends WP_UnitTestCase {
	/**
	 * Administrator fixture.
	 *
	 * @var int
	 */
	private int $administrator_id = 0;

	/**
	 * Prepare an authorized REST editor context.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->reset_rest_state();

		Capabilities::install( true );
		$this->administrator_id = self::factory()->user->create(
			array( 'role' => 'administrator' )
		);
		wp_set_current_user( $this->administrator_id );
		rest_get_server();
	}

	/**
	 * Restore REST and user state.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
		$this->reset_rest_state();
	}

	/**
	 * The plugin service must register the authenticated estimate route.
	 *
	 * @return void
	 */
	public function test_service_registers_audience_estimate_route(): void {
		$service = Plugin::instance()->container()->get(
			EditorAudienceEstimate::class
		);
		self::assertInstanceOf( EditorAudienceEstimate::class, $service );

		$routes = rest_get_server()->get_routes();
		$route  = '/'
			. EditorAudienceEstimate::REST_NAMESPACE
			. EditorAudienceEstimate::ROUTE;
		self::assertArrayHasKey( $route, $routes );
	}

	/**
	 * The estimate returns counts only and honors unsaved explicit inclusion.
	 *
	 * @return void
	 */
	public function test_estimate_returns_aggregate_counts_without_recipient_identity(): void {
		$post_id       = $this->draft_post();
		$subscriber_id = $this->subscribed_subscriber(
			'estimate-person@example.com'
		);
		$audience      = PostNotificationMeta::default_audience_config();
		$audience['included_subscriber_ids'] = array( $subscriber_id );

		$response = $this->estimate( $post_id, $audience );
		self::assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		self::assertSame( array( 'eligible', 'skipped' ), array_keys( $data ) );
		self::assertSame( 1, $data['eligible'] );
		self::assertSame( 0, array_sum( $data['skipped'] ) );
		self::assertArrayNotHasKey( 'recipients', $data );
		self::assertStringNotContainsString(
			'estimate-person@example.com',
			(string) wp_json_encode( $data )
		);
	}

	/**
	 * Role expansion uses current editor configuration and site-default is fail-closed.
	 *
	 * @return void
	 */
	public function test_role_expansion_treats_site_default_user_as_not_opted_in(): void {
		self::factory()->user->create(
			array(
				'role'         => 'author',
				'user_email'   => 'role-estimate@example.com',
				'display_name' => 'Role Estimate',
			)
		);
		$audience               = PostNotificationMeta::default_audience_config();
		$audience['role_slugs'] = array( 'author' );

		$response = $this->estimate( $this->draft_post(), $audience );
		self::assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		self::assertSame( 0, $data['eligible'] );
		self::assertSame( 1, $data['skipped']['unsubscribed'] );
	}

	/**
	 * Estimate access requires both send authorization and post edit access.
	 *
	 * @return void
	 */
	public function test_estimate_requires_send_and_post_edit_capabilities(): void {
		$post_id = $this->draft_post();
		$user_id = self::factory()->user->create(
			array( 'role' => 'contributor' )
		);
		wp_set_current_user( $user_id );

		$response = $this->estimate(
			$post_id,
			PostNotificationMeta::default_audience_config()
		);
		self::assertSame( 403, $response->get_status() );

		$user = get_userdata( $user_id );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( Capabilities::SEND_NOTIFICATIONS );
		wp_set_current_user( $user_id );

		$response = $this->estimate(
			$post_id,
			PostNotificationMeta::default_audience_config()
		);
		self::assertSame( 403, $response->get_status() );
	}

	/**
	 * Unknown audience properties must fail REST schema validation.
	 *
	 * @return void
	 */
	public function test_estimate_rejects_unknown_audience_properties(): void {
		$audience               = PostNotificationMeta::default_audience_config();
		$audience['unexpected'] = array( 1 );

		$response = $this->estimate( $this->draft_post(), $audience );
		self::assertSame( 400, $response->get_status() );
	}

	/**
	 * Create one administrator-owned draft.
	 *
	 * @return int
	 */
	private function draft_post(): int {
		return self::factory()->post->create(
			array(
				'post_author' => $this->administrator_id,
				'post_status' => 'draft',
			)
		);
	}

	/**
	 * Submit one audience-estimate request.
	 *
	 * @param int                 $post_id  Target post.
	 * @param array<string,mixed> $audience Current editor audience configuration.
	 * @return \WP_REST_Response
	 */
	private function estimate( int $post_id, array $audience ): \WP_REST_Response {
		$request = new WP_REST_Request(
			'POST',
			'/'
				. EditorAudienceEstimate::REST_NAMESPACE
				. EditorAudienceEstimate::ROUTE
		);
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'audience', $audience );
		$response = rest_do_request( $request );
		self::assertInstanceOf( \WP_REST_Response::class, $response );

		return $response;
	}

	/**
	 * Create and confirm one standalone subscriber.
	 *
	 * @param string $email Destination email.
	 * @return int
	 */
	private function subscribed_subscriber( string $email ): int {
		$now      = new DateTimeImmutable(
			'2026-10-07 12:00:00',
			new DateTimeZone( 'UTC' )
		);
		$identity = new EmailIdentity();
		$service  = new SubscriberService(
			new SubscriberRepository(),
			$identity,
			new SuppressionService(
				new SuppressionRepository(),
				$identity
			)
		);
		$signup = $service->request_confirmation(
			$email,
			'Estimate Person',
			'Consent',
			'audience-estimate-test',
			null,
			$now
		);
		self::assertNotNull( $signup->confirmation_token() );
		self::assertTrue(
			$service->confirm(
				(string) $signup->confirmation_token(),
				$now->modify( '+1 hour' )
			)
		);

		return $signup->subscriber_id();
	}

	/**
	 * Reset REST server and cached post-type controller instances.
	 *
	 * @return void
	 */
	private function reset_rest_state(): void {
		$GLOBALS['wp_rest_server'] = null;

		$post_type = get_post_type_object( 'post' );
		if ( ! $post_type instanceof \WP_Post_Type ) {
			return;
		}

		$post_type->rest_controller           = null;
		$post_type->revisions_rest_controller = null;
		$post_type->autosave_rest_controller  = null;
	}
}

// EOF: tests/Integration/EditorAudienceEstimateTest.php.
