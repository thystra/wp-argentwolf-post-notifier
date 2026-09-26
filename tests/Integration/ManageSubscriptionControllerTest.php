<?php
/**
 * File: tests/Integration/ManageSubscriptionControllerTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Subscriber\ManageSubscriptionController;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use ArgentWolf\PostNotifier\Subscriber\SubscriberStatus;
use ArgentWolf\PostNotifier\Subscriber\WordPressManageSubscriptionLinkFactory;
use ArgentWolf\PostNotifier\Suppression\SuppressionRepository;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;
use RuntimeException;
use WP_UnitTestCase;

final class ManageSubscriptionControllerTest extends WP_UnitTestCase {
	/**
	 * Saved request method.
	 *
	 * @var mixed
	 */
	private $request_method;

	public function set_up(): void {
		parent::set_up();
		$this->request_method = $_SERVER['REQUEST_METHOD'] ?? null;
		$_GET                 = array();
		$_POST                = array();
		$_REQUEST             = array();
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		if ( null === $this->request_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->request_method;
		}
		parent::tear_down();
	}

	public function test_plugin_registers_public_management_get_and_post_routes(): void {
		$controller = Plugin::instance()->container()->get( ManageSubscriptionController::class );
		self::assertInstanceOf( ManageSubscriptionController::class, $controller );

		self::assertNotFalse(
			has_action(
				'admin_post_nopriv_' . WordPressManageSubscriptionLinkFactory::GET_ACTION,
				array( $controller, 'handle_get' )
			)
		);
		self::assertNotFalse(
			has_action(
				'admin_post_nopriv_' . ManageSubscriptionController::POST_ACTION,
				array( $controller, 'handle_post' )
			)
		);
	}

	public function test_get_is_display_only_for_subscribed_address(): void {
		[ $controller, $service, $suppression ] = $this->controller_services();
		$manage_token = $this->confirmed_manage_token( $service );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['token']             = $manage_token;
		$html                      = $this->capture_wp_die(
			static fn () => $controller->handle_get()
		);

		self::assertStringContainsString( 'Stop post notifications', $html );
		self::assertSame( SubscriberStatus::Subscribed->value, $this->status_for( 'person@example.com' ) );
		self::assertFalse( $suppression->is_suppressed( 'person@example.com' ) );
	}

	public function test_valid_post_unsubscribes_globally(): void {
		[ $controller, $service, $suppression ] = $this->controller_services();
		$manage_token = $this->confirmed_manage_token( $service );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['token']             = $manage_token;
		$get_html                  = $this->capture_wp_die(
			static fn () => $controller->handle_get()
		);
		$nonce = $this->hidden_value( $get_html, 'argentwolf_post_notifier_manage_nonce' );

		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'argentwolf_post_notifier_manage_token'  => $manage_token,
			'argentwolf_post_notifier_manage_intent' => 'unsubscribe',
			'argentwolf_post_notifier_manage_nonce'  => $nonce,
		);
		$_REQUEST                  = $_POST;
		$post_html                 = $this->capture_wp_die(
			static fn () => $controller->handle_post()
		);

		self::assertStringContainsString( 'Subscription updated', $post_html );
		self::assertSame( SubscriberStatus::Unsubscribed->value, $this->status_for( 'person@example.com' ) );
		self::assertTrue( $suppression->is_suppressed( 'person@example.com' ) );
		self::assertSame(
			SuppressionService::SOURCE_SUBSCRIBER_MANAGE,
			$suppression->source_for( 'person@example.com' )
		);
	}

	public function test_verified_post_can_remove_same_source_suppression(): void {
		[ $controller, $service, $suppression ] = $this->controller_services();
		$manage_token = $this->confirmed_manage_token( $service );
		self::assertTrue( $service->unsubscribe_managed( $manage_token ) );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['token']             = $manage_token;
		$get_html                  = $this->capture_wp_die(
			static fn () => $controller->handle_get()
		);
		self::assertStringContainsString( 'Resume post notifications', $get_html );
		$nonce = $this->hidden_value( $get_html, 'argentwolf_post_notifier_manage_nonce' );

		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'argentwolf_post_notifier_manage_token'  => $manage_token,
			'argentwolf_post_notifier_manage_intent' => 'resubscribe',
			'argentwolf_post_notifier_manage_nonce'  => $nonce,
		);
		$_REQUEST                  = $_POST;
		$post_html                 = $this->capture_wp_die(
			static fn () => $controller->handle_post()
		);

		self::assertStringContainsString( 'Subscription updated', $post_html );
		self::assertSame( SubscriberStatus::Subscribed->value, $this->status_for( 'person@example.com' ) );
		self::assertFalse( $suppression->is_suppressed( 'person@example.com' ) );
	}

	public function test_invalid_nonce_does_not_unsubscribe(): void {
		[ $controller, $service, $suppression ] = $this->controller_services();
		$manage_token = $this->confirmed_manage_token( $service );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'argentwolf_post_notifier_manage_token'  => $manage_token,
			'argentwolf_post_notifier_manage_intent' => 'unsubscribe',
			'argentwolf_post_notifier_manage_nonce'  => 'invalid',
		);
		$_REQUEST                  = $_POST;
		$html                      = $this->capture_wp_die(
			static fn () => $controller->handle_post()
		);

		self::assertNotSame( '', $html );
		self::assertSame( SubscriberStatus::Subscribed->value, $this->status_for( 'person@example.com' ) );
		self::assertFalse( $suppression->is_suppressed( 'person@example.com' ) );
	}

	public function test_administrator_suppression_is_not_offered_for_resubscribe(): void {
		[ $controller, $service, $suppression ] = $this->controller_services();
		$manage_token = $this->confirmed_manage_token( $service );
		$suppression->suppress(
			'person@example.com',
			SuppressionService::REASON_MANUAL,
			SuppressionService::SOURCE_SUBSCRIBER_ADMIN
		);

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['token']             = $manage_token;
		$html                      = $this->capture_wp_die(
			static fn () => $controller->handle_get()
		);

		self::assertStringContainsString( 'cannot be changed from this link', $html );
		self::assertStringNotContainsString( 'Resume post notifications', $html );
		self::assertTrue( $suppression->is_suppressed( 'person@example.com' ) );
	}

	/**
	 * @return array{0:ManageSubscriptionController,1:SubscriberService,2:SuppressionService}
	 */
	private function controller_services(): array {
		global $wpdb;

		$identity    = new EmailIdentity();
		$suppression = new SuppressionService(
			new SuppressionRepository( $wpdb ),
			$identity
		);
		$service     = new SubscriberService(
			new SubscriberRepository( $wpdb ),
			$identity,
			$suppression
		);

		return array(
			new ManageSubscriptionController( $service, $suppression ),
			$service,
			$suppression,
		);
	}

	private function confirmed_manage_token( SubscriberService $service ): string {
		$signup = $service->request_confirmation(
			'person@example.com',
			null,
			'I agree to receive post notifications.',
			'block'
		);
		$token = $service->confirm_with_management(
			(string) $signup->confirmation_token()
		);
		self::assertNotNull( $token );

		return (string) $token;
	}

	private function status_for( string $email ): ?string {
		global $wpdb;

		$identity = new EmailIdentity();
		$hash     = $identity->hash( $email );
		$table    = TableNames::from_database( $wpdb )->subscribers();
		$status   = $wpdb->get_var(
			$wpdb->prepare( 'SELECT status FROM %i WHERE email_hash = %s', $table, $hash )
		);

		return is_string( $status ) ? $status : null;
	}

	private function capture_wp_die( callable $callback ): string {
		$filter = static fn () => static function ( $message ): void {
			throw new CapturedManageWpDie( is_string( $message ) ? $message : '' );
		};
		add_filter( 'wp_die_handler', $filter );

		try {
			$callback();
		} catch ( CapturedManageWpDie $exception ) {
			return $exception->getMessage();
		} finally {
			remove_filter( 'wp_die_handler', $filter );
		}

		throw new RuntimeException( 'Management controller did not terminate through wp_die().' );
	}

	private function hidden_value( string $html, string $name ): string {
		$pattern = '/name="' . preg_quote( $name, '/' ) . '" value="([^"]+)"/';
		if ( 1 !== preg_match( $pattern, $html, $matches ) ) {
			throw new RuntimeException( 'Expected hidden management field is missing.' );
		}

		return html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}

final class CapturedManageWpDie extends RuntimeException {
}

// EOF: tests/Integration/ManageSubscriptionControllerTest.php.
