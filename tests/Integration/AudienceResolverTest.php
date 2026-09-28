<?php
/**
 * File: tests/Integration/AudienceResolverTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Recipient\AudienceResolutionRequest;
use ArgentWolf\PostNotifier\Recipient\AudienceResolver;
use ArgentWolf\PostNotifier\Recipient\AudienceSource;
use ArgentWolf\PostNotifier\Recipient\NamedListMemberType;
use ArgentWolf\PostNotifier\Recipient\NamedListRepository;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreference;
use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreferenceRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use ArgentWolf\PostNotifier\Suppression\SuppressionRepository;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;
use ArgentWolf\PostNotifier\Verification\RegisteredUserEligibility;
use ArgentWolf\PostNotifier\Verification\VerificationProvider;
use ArgentWolf\PostNotifier\Verification\VerificationProviderHealth;
use ArgentWolf\PostNotifier\Verification\VerificationStatus;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

final class AudienceResolverTest extends WP_UnitTestCase {
	public function test_plugin_container_registers_reusable_audience_policy(): void {
		self::assertTrue( Plugin::instance()->container()->has( AudienceResolver::class ) );
	}

	public function test_explicit_exclusion_overrides_explicit_inclusion(): void {
		$user_id = self::factory()->user->create(
			array( 'user_email' => 'excluded@example.com' )
		);
		$this->preferences()->set( $user_id, RegisteredUserPreference::Subscribed );

		$resolution = $this->resolver( array( $user_id => VerificationStatus::Verified ) )
			->resolve(
				new AudienceResolutionRequest(
					included_user_ids: array( $user_id ),
					excluded_user_ids: array( $user_id )
				)
			);

		self::assertCount( 0, $resolution->recipients() );
		self::assertSame( 1, $resolution->skipped( 'excluded' ) );
	}

	public function test_suppression_overrides_role_list_and_explicit_sources(): void {
		$now     = $this->utc( '2026-09-26 18:00:00' );
		$user_id = self::factory()->user->create(
			array( 'user_email' => 'blocked@example.com' )
		);
		$this->preferences()->set( $user_id, RegisteredUserPreference::Subscribed );

		$subscriber_id = $this->subscribed_subscriber( 'blocked@example.com', $now );
		$list_id       = $this->lists()->create( 'Blocked', '', $user_id, $now );
		$this->lists()->add_member(
			$list_id,
			NamedListMemberType::Subscriber,
			$subscriber_id,
			$now
		);
		$this->suppression()->suppress(
			'blocked@example.com',
			SuppressionService::REASON_MANUAL,
			SuppressionService::SOURCE_SUBSCRIBER_ADMIN,
			$now
		);

		$resolution = $this->resolver( array( $user_id => VerificationStatus::Verified ) )
			->resolve(
				new AudienceResolutionRequest(
					role_user_ids: array( $user_id ),
					named_list_ids: array( $list_id ),
					included_subscriber_ids: array( $subscriber_id )
				)
			);

		self::assertCount( 0, $resolution->recipients() );
		self::assertSame( 1, $resolution->skipped( 'suppressed' ) );
	}

	public function test_shared_email_merges_to_one_registered_primary_recipient(): void {
		$now     = $this->utc( '2026-09-26 19:00:00' );
		$user_id = self::factory()->user->create(
			array(
				'user_email'   => 'shared@example.com',
				'display_name' => 'Registered Person',
			)
		);
		$this->preferences()->set( $user_id, RegisteredUserPreference::Subscribed );
		$subscriber_id = $this->subscribed_subscriber( 'shared@example.com', $now );
		$list_id       = $this->lists()->create( 'Shared', '', $user_id, $now );
		$this->lists()->add_member(
			$list_id,
			NamedListMemberType::Subscriber,
			$subscriber_id,
			$now
		);

		$resolution = $this->resolver( array( $user_id => VerificationStatus::Verified ) )
			->resolve(
				new AudienceResolutionRequest(
					role_user_ids: array( $user_id ),
					named_list_ids: array( $list_id ),
					included_user_ids: array( $user_id ),
					included_subscriber_ids: array( $subscriber_id )
				)
			);

		self::assertCount( 1, $resolution->recipients() );
		$recipient = $resolution->recipients()[0];
		self::assertSame( 'shared@example.com', $recipient->email() );
		self::assertSame( NamedListMemberType::User, $recipient->primary_type() );
		self::assertSame( $user_id, $recipient->user_id() );
		self::assertSame( $subscriber_id, $recipient->subscriber_id() );
		self::assertSame( 1, $resolution->skipped( 'duplicate' ) );
		self::assertSame(
			array( AudienceSource::Explicit, AudienceSource::NamedList, AudienceSource::Role ),
			$recipient->sources()
		);
	}

	public function test_ineligible_registered_source_falls_back_to_subscriber(): void {
		$now     = $this->utc( '2026-09-26 20:00:00' );
		$user_id = self::factory()->user->create(
			array( 'user_email' => 'fallback@example.com' )
		);
		$this->preferences()->set( $user_id, RegisteredUserPreference::Subscribed );
		$subscriber_id = $this->subscribed_subscriber( 'fallback@example.com', $now );

		$resolution = $this->resolver( array( $user_id => VerificationStatus::Pending ) )
			->resolve(
				new AudienceResolutionRequest(
					included_user_ids: array( $user_id ),
					included_subscriber_ids: array( $subscriber_id )
				)
			);

		self::assertCount( 1, $resolution->recipients() );
		$recipient = $resolution->recipients()[0];
		self::assertSame( NamedListMemberType::Subscriber, $recipient->primary_type() );
		self::assertNull( $recipient->user_id() );
		self::assertSame( $subscriber_id, $recipient->subscriber_id() );
		self::assertSame( 0, $resolution->skipped( 'unverified' ) );
	}

	public function test_site_default_never_implies_opt_in_without_request_policy(): void {
		$user_id = self::factory()->user->create(
			array( 'user_email' => 'default@example.com' )
		);
		$resolver = $this->resolver( array( $user_id => VerificationStatus::Verified ) );

		$closed = $resolver->resolve(
			new AudienceResolutionRequest( included_user_ids: array( $user_id ) )
		);
		self::assertCount( 0, $closed->recipients() );
		self::assertSame( 1, $closed->skipped( 'unsubscribed' ) );

		$enabled = $resolver->resolve(
			new AudienceResolutionRequest(
				included_user_ids: array( $user_id ),
				site_default_user_subscribed: true
			)
		);
		self::assertCount( 1, $enabled->recipients() );
	}

	/**
	 * Construct an audience resolver with deterministic verification statuses.
	 *
	 * @param array<int,VerificationStatus> $statuses Verification status by user ID.
	 * @return AudienceResolver
	 */
	private function resolver( array $statuses ): AudienceResolver {
		$provider = new AudienceResolverVerificationProvider( $statuses );

		return new AudienceResolver(
			$this->lists(),
			$this->preferences(),
			new RegisteredUserEligibility( $provider ),
			new SubscriberRepository(),
			$this->suppression(),
			new EmailIdentity()
		);
	}

	/**
	 * Return the named-list repository.
	 *
	 * @return NamedListRepository
	 */
	private function lists(): NamedListRepository {
		return new NamedListRepository();
	}

	/**
	 * Return registered-user preference persistence.
	 *
	 * @return RegisteredUserPreferenceRepository
	 */
	private function preferences(): RegisteredUserPreferenceRepository {
		return new RegisteredUserPreferenceRepository();
	}

	/**
	 * Return global suppression policy.
	 *
	 * @return SuppressionService
	 */
	private function suppression(): SuppressionService {
		return new SuppressionService( new SuppressionRepository(), new EmailIdentity() );
	}

	/**
	 * Create and confirm one standalone subscriber.
	 *
	 * @param string            $email Destination email.
	 * @param DateTimeImmutable $now   Operation time.
	 * @return int
	 */
	private function subscribed_subscriber( string $email, DateTimeImmutable $now ): int {
		$identity    = new EmailIdentity();
		$suppression = new SuppressionService( new SuppressionRepository(), $identity );
		$service     = new SubscriberService(
			new SubscriberRepository(),
			$identity,
			$suppression
		);
		$signup      = $service->request_confirmation(
			$email,
			null,
			'Consent',
			'audience-test',
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
	 * Return a UTC immutable test time.
	 *
	 * @param string $value Timestamp value.
	 * @return DateTimeImmutable
	 */
	private function utc( string $value ): DateTimeImmutable {
		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}
}

/**
 * Deterministic healthy verification provider for audience-policy tests.
 */
final class AudienceResolverVerificationProvider implements VerificationProvider {
	/**
	 * Construct the provider.
	 *
	 * @param array<int,VerificationStatus> $statuses Status by WordPress user ID.
	 */
	public function __construct( private array $statuses ) {
	}

	public function is_available(): bool {
		return true;
	}

	public function status_for_user( int $user_id ): VerificationStatus {
		return $this->statuses[ $user_id ] ?? VerificationStatus::Unknown;
	}

	public function description(): string {
		return 'Audience resolver test provider';
	}

	public function health(): VerificationProviderHealth {
		return new VerificationProviderHealth(
			'audience-test',
			$this->description(),
			true,
			true,
			'1.0.0',
			'healthy',
			'Healthy test provider.'
		);
	}
}

// EOF: tests/Integration/AudienceResolverTest.php.
