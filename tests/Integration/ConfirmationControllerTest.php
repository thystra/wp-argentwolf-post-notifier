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
use ArgentWolf\PostNotifier\Privacy\ClickConsent;
use ArgentWolf\PostNotifier\Privacy\PrivacySettings;
use ArgentWolf\PostNotifier\Subscriber\ConfirmationController;
use ArgentWolf\PostNotifier\Subscriber\PublicSignupProcessor;
use ArgentWolf\PostNotifier\Subscriber\PublicSignupRequest;
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
		$_REQUEST             = array();
	}

	/**
	 * Restore request globals.
	 *
	 * @return void
	 */
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

		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'argentwolf_post_notifier_token' => $token,
			'argentwolf_post_notifier_nonce' => $nonce,
		);
		$_REQUEST                  = $_POST;
		$post_html                 = $this->capture_wp_die(
			static fn () => ( new ConfirmationController( $service ) )->handle_post()
		);

		self::assertStringContainsString( 'Subscription confirmed', $post_html );
		self::assertStringContainsString( 'Manage this subscription', $post_html );
		self::assertSame(
			SubscriberStatus::Subscribed->value,
			$this->status_for( 'person@example.com' )
		);
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
		$_REQUEST                  = $_POST;
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
		$_REQUEST                  = $_POST;
		$post_html                 = $this->capture_wp_die(
			static fn () => ( new ConfirmationController( $service ) )->handle_post()
		);

		self::assertStringContainsString( 'Unable to confirm subscription', $post_html );
		self::assertSame( SubscriberStatus::Pending->value, $this->status_for( 'person@example.com' ) );
	}

	public function test_third_party_tick_on_public_form_never_grants_click_consent(): void {
		$this->enable_per_user_tracking();
		$html = do_blocks( '<!-- wp:argentwolf-post-notifier/subscribe /-->' );
		self::assertStringContainsString( 'argentwolf_post_notifier_email', $html );
		self::assertStringNotContainsString( ClickConsent::FIELD, $html, 'The public form has no tracking box.' );

		// A third party posts the field anyway, twice; nothing is recorded.
		$_POST[ ClickConsent::FIELD ] = '1';
		$processor                    = Plugin::instance()->container()->get( PublicSignupProcessor::class );
		self::assertInstanceOf( PublicSignupProcessor::class, $processor );
		foreach ( array( 1, 2 ) as $attempt ) {
			$processor->process(
				new PublicSignupRequest(
					'victim@example.com',
					null,
					'I agree to receive post notifications.',
					'block',
					null,
					'',
					'192.0.2.' . $attempt
				)
			);
		}
		$_POST = array();
		self::assertNull( ( new ClickConsent() )->subscriber_record( $this->subscriber_id( 'victim@example.com' ) ) );
	}

	public function test_owner_confirmation_with_tracking_box_grants_consent(): void {
		$this->enable_per_user_tracking();
		[ $html, $id ] = $this->confirm( 'owner-yes@example.com', true );
		self::assertStringContainsString( 'Subscription confirmed', $html );
		$record = ( new ClickConsent() )->subscriber_record( $id );
		self::assertNotNull( $record );
		self::assertSame( ClickConsent::VERSION, $record['version'] );
	}

	public function test_owner_confirmation_without_tracking_box_does_not_grant_and_clears_pre_tick(): void {
		$this->enable_per_user_tracking();
		$consent = new ClickConsent();
		// Simulate a 0.1.0-rc.2 form pre-tick stored on the pending row.
		$pre = $this->subscriber_service()->request_confirmation(
			'owner-no@example.com',
			null,
			'I agree to receive post notifications.',
			'block'
		);
		$consent->grant_subscriber( $pre->subscriber_id() );
		$get_html      = null;
		[ $html, $id ] = $this->confirm( 'owner-no@example.com', false, $get_html, $pre->confirmation_token() );
		self::assertStringContainsString( 'Subscription confirmed', $html );
		self::assertSame( $pre->subscriber_id(), $id );
		self::assertNull( $consent->subscriber_record( $id ) );
	}

	public function test_tracking_box_is_ignored_while_per_user_tracking_is_off(): void {
		[ $html, $id ] = $this->confirm( 'owner-off@example.com', true, $get_html );
		self::assertStringContainsString( 'Subscription confirmed', $html );
		self::assertStringNotContainsString( ClickConsent::FIELD, $get_html );
		self::assertNull( ( new ClickConsent() )->subscriber_record( $id ) );
		self::assertSame( '', ClickConsent::confirmation_email_notice() );
	}

	/**
	 * Turn on aggregate and per-user tracking.
	 *
	 * @return void
	 */
	private function enable_per_user_tracking(): void {
		( new PrivacySettings() )->save(
			array(
				'click_tracking'    => '1',
				'per_user_tracking' => '1',
			)
		);
	}

	/**
	 * Request, open and confirm one address, optionally ticking the tracking box.
	 *
	 * @param string      $email    Address.
	 * @param bool        $tick     Whether to tick the tracking box.
	 * @param string|null $get_html Receives the landing page HTML.
	 * @param string|null $token    Existing confirmation token, or null to request one.
	 * @return array{0:string,1:int}
	 */
	private function confirm( string $email, bool $tick, ?string &$get_html = null, ?string $token = null ): array {
		$service    = $this->subscriber_service();
		$controller = new ConfirmationController( $service, null, new ClickConsent(), new PrivacySettings() );
		$token    ??= (string) $service->request_confirmation(
			$email,
			null,
			'I agree to receive post notifications.',
			'block'
		)->confirmation_token();

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['token']             = $token;
		$get_html                  = $this->capture_wp_die( static fn () => $controller->handle_get() );
		if ( ( new PrivacySettings() )->per_user_tracking_enabled() ) {
			self::assertStringContainsString( ClickConsent::FIELD, $get_html );
			self::assertStringContainsString( 'consent wording version ' . ClickConsent::VERSION, $get_html );
			self::assertDoesNotMatchRegularExpression( '/<input[^>]+checked/i', $get_html, 'The box is unticked.' );
			self::assertStringContainsString(
				'version ' . ClickConsent::VERSION,
				ClickConsent::confirmation_email_notice()
			);
		}
		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'argentwolf_post_notifier_token' => $token,
			'argentwolf_post_notifier_nonce' => $this->hidden_value( $get_html, 'argentwolf_post_notifier_nonce' ),
		);
		if ( $tick ) {
			$_POST[ ClickConsent::FIELD ] = '1';
		}
		$_REQUEST = $_POST;
		$html     = $this->capture_wp_die( static fn () => $controller->handle_post() );
		$_POST    = array();
		$_REQUEST = array();

		return array( $html, $this->subscriber_id( $email ) );
	}

	/**
	 * Subscriber row ID for an address.
	 *
	 * @param string $email Address.
	 * @return int
	 */
	private function subscriber_id( string $email ): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE email_hash = %s',
				TableNames::from_database( $wpdb )->subscribers(),
				( new EmailIdentity() )->hash( $email )
			)
		);
		// phpcs:enable
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
