<?php
/**
 * File: tests/Integration/ConfirmationControllerTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Subscriber\ConfirmationController;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use ArgentWolf\PostNotifier\Subscriber\SubscriberStatus;
use ArgentWolf\PostNotifier\Subscriber\WordPressConfirmationLinkFactory;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use WP_UnitTestCase;

final class ConfirmationControllerTest extends WP_UnitTestCase {
	/**
	 * Saved request method.
	 *
	 * @var mixed
	 */
	private $request_method;

	/**
	 * Preserve request globals.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->request_method = $_SERVER['REQUEST_METHOD'] ?? null;
		$_GET                 = array();
		$_POST                = array();
	}

	/**
	 * Restore request globals.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();
		if ( null === $this->request_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->request_method;
		}
		parent::tear_down();
	}

	/**
	 * The plugin bootstrap registers separate public GET and POST actions.
	 *
	 * @return void
	 */
	public function test_plugin_registers_separate_confirmation_routes(): void {
		$controller = Plugin::instance()->container()->get( ConfirmationController::class );
		self::assertInstanceOf( ConfirmationController::class, $controller );

		self::assertNotFalse(
			has_action(
				'admin_post_nopriv_' . WordPressConfirmationLinkFactory::GET_ACTION,
				array( $controller, 'handle_get' )
			)
		);
		self::assertNotFalse(
			has_action(
				'admin_post_nopriv_' . ConfirmationController::POST_ACTION,
				array( $controller, 'handle_post' )
			)
		);
	}

	/**
	 * A GET renders the confirmation form without changing subscriber state.
	 *
	 * @return void
	 */
	public function test_get_is_display_only_for_pending_subscriber(): void {
		$service = $this->subscriber_service();
		$signup  = $service->request_confirmation(
			'person@example.com',
			null,
			'I agree to receive post notifications.',
			'block',
			null
		);
		$token   = $signup->confirmation_token();
		self::assertNotNull( $token );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['token']             = $token;
		$html                      = $this->capture_wp_die(
			static fn () => ( new ConfirmationController( $service ) )->handle_get()
		);

		self::assertStringContainsString( 'Confirm subscription', $html );
		self::assertStringContainsString( ConfirmationController::POST_ACTION, $html );
		self::assertSame( SubscriberStatus::Pending->value, $this->status_for( 'person@example.com' ) );
	}

	/**
	 * A valid POST promotes the pending subscriber and consumes the token.
	 *
	 * @return void
	 */
	public function test_valid_post_confirms_pending_subscriber(): void {
		$service = $this->subscriber_service();
		$signup  = $service->request_confirmation(
			'person@example.com',
			null,
			'I agree to receive post notifications.',
			'block',
			null
		);
		$token   = $signup->confirmation_token();
		self::assertNotNull( $token );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['token']             = $token;
		$get_html                  = $this->capture_wp_die(
			static fn () => ( new ConfirmationController( $service ) )->handle_get()
		);
		$nonce                     = $this->hidden_value( $get_html, 'argentwolf_post_notifier_nonce' );

		$_GET                        = array();
		$_SERVER['REQUEST_METHOD']   = 'POST';
		$_POST                       = array(
			'argentwolf_post_notifier_token' => $token,
			'argentwolf_post_notifier_nonce' => $nonce,
		);
		$post_html                   = $this->capture_wp_die(
			static fn () => ( new ConfirmationController( $service ) )->handle_post()
		);

		self::assertStringContainsString( 'Subscription confirmed', $post_html );
		self::assertSame( SubscriberStatus::Subscribed->value, $this->status_for( 'person@example.com' ) );
	}

	/**
	 * Invalid POST proof does not change subscriber state.
	 *
	 * @return void
	 */
	public function test_invalid_nonce_does_not_confirm(): void {
		$service = $this->subscriber_service();
		$signup  = $service->request_confirmation(
			'person@example.com',
			null,
			'I agree to receive post notifications.',
			'block',
			null
		);
		$token   = $signup->confirmation_token();
		self::assertNotNull( $token );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'argentwolf_post_notifier_token' => $token,
			'argentwolf_post_notifier_nonce' => 'invalid',
		);
		$html                      = $this->capture_wp_die(
			static fn () => ( new ConfirmationController( $service ) )->handle_post()
		);

		self::assertNotSame( '', $html );
		self::assertSame( SubscriberStatus::Pending->value, $this->status_for( 'person@example.com' ) );
	}

	/**
	 * An expired confirmation token remains pending after POST.
	 *
	 * @return void
	 */
	public function test_expired_token_cannot_confirm(): void {
		$service = $this->subscriber_service();
		$signup  = $service->request_confirmation(
			'person@example.com',
			null,
			'I agree to receive post notifications.',
			'block',
			null,
			new DateTimeImmutable( '-2 days', new DateTimeZone( 'UTC' ) )
		);
		$token   = $signup->confirmation_token();
		self::assertNotNull( $token );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['token']             = $token;
		$get_html                  = $this->capture_wp_die(
			static fn () => ( new ConfirmationController( $service ) )->handle_get()
		);
		$nonce                     = $this->hidden_value( $get_html, 'argentwolf_post_notifier_nonce' );

		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'argentwolf_post_notifier_token' => $token,
			'argentwolf_post_notifier_nonce' => $nonce,
		);
		$post_html                 = $this->capture_wp_die(
			static fn () => ( new ConfirmationController( $service ) )->handle_post()
		);

		self::assertStringContainsString( 'Unable to confirm subscription', $post_html );
		self::assertSame( SubscriberStatus::Pending->value, $this->status_for( 'person@example.com' ) );
	}

	/**
	 * Return a subscriber service bound to the integration database.
	 *
	 * @return SubscriberService
	 */
	private function subscriber_service(): SubscriberService {
		global $wpdb;
		return new SubscriberService( new SubscriberRepository( $wpdb ), new EmailIdentity() );
	}

	/**
	 * Return the current subscriber status for an email.
	 *
	 * @param string $email Email address.
	 * @return string|null
	 */
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

	/**
	 * Capture a wp_die() response by replacing the die handler with an exception.
	 *
	 * @param callable $callback Handler invocation.
	 * @return string
	 * @throws RuntimeException When the callback does not terminate through wp_die().
	 */
	private function capture_wp_die( callable $callback ): string {
		$filter = static fn () => static function ( $message ): void {
			throw new CapturedWpDie( is_string( $message ) ? $message : '' );
		};
		add_filter( 'wp_die_handler', $filter );

		try {
			$callback();
		} catch ( CapturedWpDie $exception ) {
			return $exception->getMessage();
		} finally {
			remove_filter( 'wp_die_handler', $filter );
		}

		throw new RuntimeException( 'Confirmation controller did not terminate through wp_die().' );
	}

	/**
	 * Extract one hidden input value from captured markup.
	 *
	 * @param string $html Captured page HTML.
	 * @param string $name Hidden input name.
	 * @return string
	 * @throws RuntimeException When the hidden field is missing.
	 */
	private function hidden_value( string $html, string $name ): string {
		$pattern = '/name="' . preg_quote( $name, '/' ) . '" value="([^"]+)"/';
		if ( 1 !== preg_match( $pattern, $html, $matches ) ) {
			throw new RuntimeException( 'Expected hidden confirmation field is missing.' );
		}

		return html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}

final class CapturedWpDie extends RuntimeException {
}

// EOF: tests/Integration/ConfirmationControllerTest.php.
