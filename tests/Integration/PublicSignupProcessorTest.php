<?php
/**
 * File: tests/Integration/PublicSignupProcessorTest.php
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
use ArgentWolf\PostNotifier\Subscriber\PublicSignupProcessor;
use ArgentWolf\PostNotifier\Subscriber\PublicSignupRequest;
use ArgentWolf\PostNotifier\Subscriber\PublicSignupResponse;
use ArgentWolf\PostNotifier\Subscriber\RateLimitStore;
use ArgentWolf\PostNotifier\Subscriber\SignupRateLimiter;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use ArgentWolf\PostNotifier\Subscriber\SubscriberStatus;
use WP_UnitTestCase;

final class PublicSignupProcessorTest extends WP_UnitTestCase {
	public function test_plugin_container_exposes_signup_processor(): void {
		$processor = Plugin::instance()->container()->get( PublicSignupProcessor::class );

		self::assertInstanceOf( PublicSignupProcessor::class, $processor );
	}

	public function test_new_and_repeated_requests_return_same_public_code(): void {
		$transport = new RecordingMailTransport();
		$processor = $this->processor( $transport, true );
		$request   = $this->request( ' Person@Example.COM ' );

		$first  = $processor->process( $request );
		$second = $processor->process( $request );

		self::assertSame( PublicSignupResponse::CODE, $first->code() );
		self::assertSame( $first->code(), $second->code() );
		self::assertCount( 1, $transport->messages );
		self::assertSame( 'person@example.com', $transport->messages[0]->recipient() );
		self::assertStringContainsString(
			'https://example.test/confirm/',
			$transport->messages[0]->body()
		);
	}

	public function test_existing_subscribed_address_returns_same_public_code_without_mail(): void {
		global $wpdb;

		$transport = new RecordingMailTransport();
		$processor = $this->processor( $transport, true );
		$request   = $this->request( 'person@example.com' );

		$initial = $processor->process( $request );
		self::assertCount( 1, $transport->messages );

		$table = TableNames::from_database( $wpdb )->subscribers();
		$wpdb->update(
			$table,
			array( 'status' => SubscriberStatus::Subscribed->value ),
			array( 'email' => 'person@example.com' ),
			array( '%s' ),
			array( '%s' )
		);

		$existing = $processor->process( $request );

		self::assertSame( $initial->code(), $existing->code() );
		self::assertCount( 1, $transport->messages );
	}

	public function test_honeypot_and_rate_limit_fail_closed_to_same_public_response(): void {
		$transport = new RecordingMailTransport();
		$allowed   = $this->processor( $transport, true );
		$denied    = $this->processor( $transport, false );

		$honeypot = $allowed->process(
			new PublicSignupRequest(
				'bot@example.com',
				null,
				'I agree to receive post notifications.',
				'block',
				null,
				'https://spam.invalid/',
				'192.0.2.55'
			)
		);
		$rate_limited = $denied->process( $this->request( 'limited@example.com' ) );

		self::assertSame( PublicSignupResponse::CODE, $honeypot->code() );
		self::assertSame( $honeypot->code(), $rate_limited->code() );
		self::assertCount( 0, $transport->messages );
		self::assertSame( 0, $this->subscriber_count( 'bot@example.com' ) );
		self::assertSame( 0, $this->subscriber_count( 'limited@example.com' ) );
	}

	public function test_invalid_email_and_transport_failure_do_not_change_public_code(): void {
		$failed_transport            = new RecordingMailTransport( false );
		$processor                   = $this->processor( $failed_transport, true );
		$invalid                     = $processor->process( $this->request( 'not-an-email' ) );
		$transport_submission_failed = $processor->process(
			$this->request( 'failed@example.com' )
		);

		self::assertSame( PublicSignupResponse::CODE, $invalid->code() );
		self::assertSame( $invalid->code(), $transport_submission_failed->code() );
		self::assertCount( 1, $failed_transport->messages );
		self::assertSame( 1, $this->subscriber_count( 'failed@example.com' ) );
	}

	private function processor(
		RecordingMailTransport $transport,
		bool $rate_allowed
	): PublicSignupProcessor {
		global $wpdb;

		$identity = new EmailIdentity();
		$store    = new class( $rate_allowed ) implements RateLimitStore {
			public function __construct( private bool $allowed ) {
			}

			public function consume(
				string $bucket,
				int $limit,
				int $window_seconds,
				int $now
			): bool {
				unset( $bucket, $limit, $window_seconds, $now );
				return $this->allowed;
			}
		};

		return new PublicSignupProcessor(
			new SubscriberService( new SubscriberRepository( $wpdb ), $identity ),
			new SignupRateLimiter( $store, $identity, str_repeat( 's', 32 ) ),
			new StaticConfirmationLinkFactory(),
			new ConfirmationMailer( $transport, 'Example Site' )
		);
	}

	private function request( string $email ): PublicSignupRequest {
		return new PublicSignupRequest(
			$email,
			'Example Person',
			'I agree to receive post notifications.',
			'block',
			42,
			'',
			'192.0.2.44'
		);
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

final class RecordingMailTransport implements MailTransport {
	/** @var array<MailMessage> */
	public array $messages = array();

	public function __construct( private bool $submitted = true ) {
	}

	public function send( MailMessage $message ): DeliveryResult {
		$this->messages[] = $message;
		return $this->submitted
			? DeliveryResult::submitted()
			: DeliveryResult::failed();
	}
}

final class StaticConfirmationLinkFactory implements ConfirmationLinkFactory {
	public function create( string $plaintext_token ): string {
		return 'https://example.test/confirm/' . rawurlencode( $plaintext_token );
	}
}

// EOF: tests/Integration/PublicSignupProcessorTest.php.
