<?php
/**
 * File: tests/Integration/SubscribeBlockTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Mail\DeliveryResult;
use ArgentWolf\PostNotifier\Mail\MailMessage;
use ArgentWolf\PostNotifier\Mail\MailTransport;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Subscriber\ConfirmationLinkFactory;
use ArgentWolf\PostNotifier\Subscriber\ConfirmationMailer;
use ArgentWolf\PostNotifier\Subscriber\PublicSignupController;
use ArgentWolf\PostNotifier\Subscriber\PublicSignupProcessor;
use ArgentWolf\PostNotifier\Subscriber\RateLimitStore;
use ArgentWolf\PostNotifier\Subscriber\SignupFormContext;
use ArgentWolf\PostNotifier\Subscriber\SignupRateLimiter;
use ArgentWolf\PostNotifier\Subscriber\SubscribeBlock;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

final class SubscribeBlockTest extends WP_UnitTestCase {
	/**
	 * Original POST data.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_post = array();

	/**
	 * Original GET data.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_get = array();

	/**
	 * Original REQUEST data.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_request = array();

	/**
	 * Original SERVER data.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_server = array();

	protected function setUp(): void {
		parent::setUp();

		$this->original_post    = $_POST;
		$this->original_get     = $_GET;
		$this->original_request = $_REQUEST;
		$this->original_server  = $_SERVER;

		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
	}

	protected function tearDown(): void {
		$_POST    = $this->original_post;
		$_GET     = $this->original_get;
		$_REQUEST = $this->original_request;
		$_SERVER  = $this->original_server;
		wp_reset_postdata();

		parent::tearDown();
	}

	public function test_plugin_registers_dynamic_subscribe_block(): void {
		$container  = Plugin::instance()->container();
		$service    = $container->get( SubscribeBlock::class );
		$controller = $container->get( PublicSignupController::class );
		$block      = WP_Block_Type_Registry::get_instance()->get_registered(
			SubscribeBlock::BLOCK_NAME
		);

		self::assertInstanceOf( SubscribeBlock::class, $service );
		self::assertInstanceOf( PublicSignupController::class, $controller );
		self::assertNotNull( $block );
		self::assertTrue( $block->is_dynamic() );
		self::assertContains( SubscribeBlock::EDITOR_SCRIPT, $block->editor_script_handles );
		self::assertSame(
			'Get new post notifications by email',
			$block->attributes['heading']['default']
		);
		self::assertTrue( wp_script_is( SubscribeBlock::EDITOR_SCRIPT, 'registered' ) );
		self::assertNotFalse(
			has_action(
				'admin_post_nopriv_' . PublicSignupController::POST_ACTION,
				array( $controller, 'handle_submission' )
			)
		);
	}

	public function test_rendered_form_has_accessible_required_fields_and_generic_status(): void {
		$transport = new BlockRecordingMailTransport();
		$flow      = $this->flow( $transport );
		$block     = $flow['block'];

		$html = $block->render(
			array(
				'heading'     => 'Follow the journal',
				'description' => 'Get an email when a new entry is published.',
				'consentText' => 'I agree to receive new-entry notifications.',
				'buttonLabel' => 'Notify me',
			)
		);

		self::assertStringContainsString( 'Follow the journal', $html );
		self::assertStringContainsString( 'Get an email when a new entry is published.', $html );
		self::assertStringContainsString( 'type="email"', $html );
		self::assertStringContainsString( 'autocomplete="email" required', $html );
		self::assertStringContainsString( 'Name (optional)', $html );
		self::assertStringContainsString( 'type="checkbox" value="1" required', $html );
		self::assertStringContainsString( 'I agree to receive new-entry notifications.', $html );
		self::assertStringContainsString( '>Notify me</button>', $html );

		$_GET[ PublicSignupController::RESULT_QUERY ] = PublicSignupController::RESULT_RECEIVED;
		$status_html = $block->render( array() );

		self::assertStringContainsString( 'role="status"', $status_html );
		self::assertStringContainsString( 'aria-live="polite"', $status_html );
		self::assertStringContainsString( 'tabindex="-1"', $status_html );
		self::assertStringContainsString( 'check your email for a confirmation message', $status_html );

		$_GET[ PublicSignupController::RESULT_QUERY ] = PublicSignupController::RESULT_ERROR;
		$error_html = $block->render( array() );

		self::assertStringContainsString( 'role="status"', $error_html );
		self::assertStringContainsString( 'Reload the page and try again', $error_html );
	}

	public function test_existing_wordpress_user_gets_same_explicit_double_opt_in_flow(): void {
		global $post, $wpdb;

		$transport  = new BlockRecordingMailTransport();
		$flow       = $this->flow( $transport );
		$block      = $flow['block'];
		$controller = $flow['controller'];
		$post_id   = self::factory()->post->create();
		$user_id   = self::factory()->user->create(
			array( 'user_email' => 'person@example.com' )
		);
		$post      = get_post( $post_id );
		setup_postdata( $post );

		$html = $block->render(
			array( 'consentText' => 'I agree to receive journal notifications.' )
		);

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REMOTE_ADDR']    = '192.0.2.77';
		$_POST                     = array(
			'action' => PublicSignupController::POST_ACTION,
			'argentwolf_post_notifier_subscribe_nonce' => $this->hidden_value(
				$html,
				'argentwolf_post_notifier_subscribe_nonce'
			),
			'argentwolf_post_notifier_context' => $this->hidden_value(
				$html,
				'argentwolf_post_notifier_context'
			),
			'argentwolf_post_notifier_context_signature' => $this->hidden_value(
				$html,
				'argentwolf_post_notifier_context_signature'
			),
			'argentwolf_post_notifier_return' => 'https://outside.invalid/redirect',
			'argentwolf_post_notifier_email' => ' Person@Example.COM ',
			'argentwolf_post_notifier_name' => 'Example Person',
			'argentwolf_post_notifier_consent' => '1',
			'argentwolf_post_notifier_website' => '',
		);
		$_REQUEST = $_POST;

		$redirect = $this->capture_redirect( static function () use ( $controller ): void {
			$controller->handle_submission();
		} );

		self::assertSame(
			parse_url( home_url( '/' ), PHP_URL_HOST ),
			parse_url( $redirect, PHP_URL_HOST )
		);
		self::assertStringContainsString(
			PublicSignupController::RESULT_QUERY . '=' . PublicSignupController::RESULT_RECEIVED,
			$redirect
		);
		self::assertStringNotContainsString( 'user', $redirect );
		self::assertStringNotContainsString( 'existing', $redirect );
		self::assertCount( 1, $transport->messages );
		self::assertSame( 'person@example.com', $transport->messages[0]->recipient() );
		self::assertNotFalse( get_userdata( $user_id ) );

		$identity   = new EmailIdentity();
		$email_hash = $identity->hash( 'person@example.com' );
		$table      = TableNames::from_database( $wpdb )->subscribers();
		$row        = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, consent_text_snapshot, signup_source, source_post_id FROM %i WHERE email_hash = %s',
				$table,
				$email_hash
			),
			ARRAY_A
		);

		self::assertIsArray( $row );
		self::assertSame( 'pending', $row['status'] );
		self::assertSame( 'I agree to receive journal notifications.', $row['consent_text_snapshot'] );
		self::assertSame( 'block', $row['signup_source'] );
		self::assertSame( (string) $post_id, (string) $row['source_post_id'] );
	}

	public function test_tampered_render_context_fails_without_creating_subscriber(): void {
		$transport  = new BlockRecordingMailTransport();
		$flow       = $this->flow( $transport );
		$block      = $flow['block'];
		$controller = $flow['controller'];
		$html      = $block->render( array() );
		$context   = $this->hidden_value( $html, 'argentwolf_post_notifier_context' );
		$context   = substr( $context, 0, -1 ) . ( str_ends_with( $context, 'A' ) ? 'B' : 'A' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'argentwolf_post_notifier_subscribe_nonce' => $this->hidden_value(
				$html,
				'argentwolf_post_notifier_subscribe_nonce'
			),
			'argentwolf_post_notifier_context' => $context,
			'argentwolf_post_notifier_context_signature' => $this->hidden_value(
				$html,
				'argentwolf_post_notifier_context_signature'
			),
			'argentwolf_post_notifier_return' => home_url( '/' ),
			'argentwolf_post_notifier_email' => 'tampered@example.com',
			'argentwolf_post_notifier_consent' => '1',
		);
		$_REQUEST = $_POST;

		$redirect = $this->capture_redirect( static function () use ( $controller ): void {
			$controller->handle_submission();
		} );

		self::assertStringContainsString(
			PublicSignupController::RESULT_QUERY . '=' . PublicSignupController::RESULT_ERROR,
			$redirect
		);
		self::assertSame( 0, $this->subscriber_count( 'tampered@example.com' ) );
		self::assertCount( 0, $transport->messages );
	}

	public function test_missing_consent_fails_before_signup_processing(): void {
		$transport  = new BlockRecordingMailTransport();
		$flow       = $this->flow( $transport );
		$block      = $flow['block'];
		$controller = $flow['controller'];
		$html      = $block->render( array() );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'argentwolf_post_notifier_subscribe_nonce' => $this->hidden_value(
				$html,
				'argentwolf_post_notifier_subscribe_nonce'
			),
			'argentwolf_post_notifier_context' => $this->hidden_value(
				$html,
				'argentwolf_post_notifier_context'
			),
			'argentwolf_post_notifier_context_signature' => $this->hidden_value(
				$html,
				'argentwolf_post_notifier_context_signature'
			),
			'argentwolf_post_notifier_return' => home_url( '/' ),
			'argentwolf_post_notifier_email' => 'no-consent@example.com',
		);
		$_REQUEST = $_POST;

		$redirect = $this->capture_redirect( static function () use ( $controller ): void {
			$controller->handle_submission();
		} );

		self::assertStringContainsString(
			PublicSignupController::RESULT_QUERY . '=' . PublicSignupController::RESULT_ERROR,
			$redirect
		);
		self::assertSame( 0, $this->subscriber_count( 'no-consent@example.com' ) );
		self::assertCount( 0, $transport->messages );
	}

	/**
	 * Build one block/controller pair sharing the same signed form context.
	 *
	 * @param BlockRecordingMailTransport $transport Recording mail transport.
	 * @return array{block:SubscribeBlock,controller:PublicSignupController}
	 */
	private function flow( BlockRecordingMailTransport $transport ): array {
		global $wpdb;

		$identity = new EmailIdentity();
		$store    = new class() implements RateLimitStore {
			public function consume(
				string $bucket,
				int $limit,
				int $window_seconds,
				int $now
			): bool {
				unset( $bucket, $limit, $window_seconds, $now );
				return true;
			}
		};

		$processor = new PublicSignupProcessor(
			new SubscriberService( new SubscriberRepository( $wpdb ), $identity ),
			new SignupRateLimiter( $store, $identity, str_repeat( 'r', 32 ) ),
			new BlockStaticConfirmationLinkFactory(),
			new ConfirmationMailer( $transport, 'Example Site' )
		);
		$context   = new SignupFormContext( str_repeat( 'k', 32 ) );

		return array(
			'block'      => new SubscribeBlock( $context ),
			'controller' => new PublicSignupController( $processor, $context ),
		);
	}

	private function hidden_value( string $html, string $name ): string {
		$pattern = sprintf(
			'/<input[^>]+name="%s"[^>]+value="([^"]*)"/i',
			preg_quote( $name, '/' )
		);
		self::assertSame( 1, preg_match( $pattern, $html, $matches ) );

		return html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Capture one WordPress redirect without terminating the test request.
	 *
	 * @param callable():void $callback Redirect-producing callback.
	 * @return string
	 */
	private function capture_redirect( callable $callback ): string {
		$location = '';
		$filter   = static function ( string $url ) use ( &$location ): string {
			$location = $url;
			return '';
		};

		add_filter( 'wp_redirect', $filter );
		try {
			$callback();
		} finally {
			remove_filter( 'wp_redirect', $filter );
		}

		return $location;
	}

	private function subscriber_count( string $email ): int {
		global $wpdb;

		$identity   = new EmailIdentity();
		$email_hash = $identity->hash( $email );
		if ( null === $email_hash ) {
			return 0;
		}

		$table = TableNames::from_database( $wpdb )->subscribers();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE email_hash = %s',
				$table,
				$email_hash
			)
		);
	}
}

final class BlockRecordingMailTransport implements MailTransport {
	/** @var array<MailMessage> */
	public array $messages = array();

	public function send( MailMessage $message ): DeliveryResult {
		$this->messages[] = $message;
		return DeliveryResult::submitted();
	}
}

final class BlockStaticConfirmationLinkFactory implements ConfirmationLinkFactory {
	public function create( string $plaintext_token ): string {
		return 'https://example.test/confirm/' . rawurlencode( $plaintext_token );
	}
}

// EOF: tests/Integration/SubscribeBlockTest.php.
