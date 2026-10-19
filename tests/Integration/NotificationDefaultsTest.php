<?php
/**
 * File: tests/Integration/NotificationDefaultsTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\NotificationDefaultsPage;
use ArgentWolf\PostNotifier\Campaign\CampaignRepository;
use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Editor\PostNotificationMeta;
use ArgentWolf\PostNotifier\Plugin;
use ArgentWolf\PostNotifier\Recipient\AudienceRequestBuilder;
use ArgentWolf\PostNotifier\Recipient\AudienceResolver;
use ArgentWolf\PostNotifier\Recipient\NamedListRepository;
use ArgentWolf\PostNotifier\Settings\NotificationDefaults;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use ArgentWolf\PostNotifier\Suppression\SuppressionRepository;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use WP_UnitTestCase;

/**
 * Test global defaults, explicit audience precedence, and dynamic enrollment.
 */
final class NotificationDefaultsTest extends WP_UnitTestCase {
	/**
	 * Reset this test's site policy between cases.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( NotificationDefaults::OPTION );
	}

	/**
	 * Missing policy must not unexpectedly email a new post.
	 *
	 * @return void
	 */
	public function test_new_policy_defaults_off_and_all_subscribers(): void {
		$settings = new NotificationDefaults();
		self::assertSame( $settings->defaults(), $settings->get() );
		self::assertFalse( $settings->get()['send'] );
		self::assertSame( 'all', $settings->get()['audience'] );
		self::assertInstanceOf(
			NotificationDefaultsPage::class,
			Plugin::instance()->container()->get( NotificationDefaultsPage::class )
		);
	}

	/**
	 * A corrupt policy fails closed rather than broadening a named audience.
	 *
	 * @return void
	 */
	public function test_corrupt_policy_does_not_send_or_default_to_all(): void {
		update_option( NotificationDefaults::OPTION, array( 'send' => true ), false );
		$policy = new NotificationDefaults();
		self::assertFalse( $policy->get()['send'] );
		$this->expectException( RuntimeException::class );
		$policy->resolve_audience(
			PostNotificationMeta::default_audience_config(),
			new NamedListRepository()
		);
	}

	/**
	 * The configured list must still exist when the audience is frozen.
	 *
	 * @return void
	 */
	public function test_deleted_default_list_fails_closed(): void {
		$lists  = new NamedListRepository();
		$id     = $lists->create( 'Announcements', 'Only announcements', 0 );
		$policy = new NotificationDefaults();
		$policy->save(
			array(
				'send'     => true,
				'audience' => NotificationDefaults::AUDIENCE_LIST,
				'list_id'  => $id,
			),
			$lists
		);
		self::assertTrue( $policy->get()['send'] );
		$resolved = $policy->resolve_audience(
			PostNotificationMeta::default_audience_config(),
			$lists
		);
		self::assertSame( array( $id ), $resolved['named_list_ids'] );
		$lists->delete( $id );
		$this->expectException( RuntimeException::class );
		$policy->resolve_audience( PostNotificationMeta::default_audience_config(), $lists );
	}

	/**
	 * Invalid list IDs are rejected before persisting.
	 *
	 * @return void
	 */
	public function test_unknown_list_cannot_be_default(): void {
		$this->expectException( InvalidArgumentException::class );
		( new NotificationDefaults() )->save(
			array(
				'send'     => true,
				'audience' => NotificationDefaults::AUDIENCE_LIST,
				'list_id'  => 2000000000,
			),
			new NamedListRepository()
		);
	}

	/**
	 * An explicitly selected list replaces All Subscribers.
	 *
	 * @return void
	 */
	public function test_positive_explicit_targets_replace_default_audience(): void {
		$policy = new NotificationDefaults();
		$config = PostNotificationMeta::default_audience_config();

		$config['named_list_ids'] = array( 17 );

		$resolved = $policy->resolve_audience( $config, new NamedListRepository() );
		self::assertSame( array( 17 ), $resolved['named_list_ids'] );
		self::assertFalse( $resolved['include_all_subscribers'] );
	}

	/**
	 * An opted-in subscriber is discovered without manually joining a list.
	 *
	 * @return void
	 */
	public function test_all_subscribers_enumerates_confirmed_contacts_only(): void {
		$now      = new DateTimeImmutable( '2026-10-01 12:00:00', new DateTimeZone( 'UTC' ) );
		$identity = new EmailIdentity();
		$service  = new SubscriberService(
			new SubscriberRepository(),
			$identity,
			new SuppressionService( new SuppressionRepository(), $identity )
		);
		$signup   = $service->request_confirmation(
			'default-audience@example.com',
			'Default Subscriber',
			'Consent',
			'default-policy-test',
			null,
			$now
		);
		self::assertNotNull( $signup->confirmation_token() );
		$policy   = new NotificationDefaults();
		$builder  = new AudienceRequestBuilder();
		$resolver = Plugin::instance()->container()->get( AudienceResolver::class );
		self::assertInstanceOf( AudienceResolver::class, $resolver );
		$config  = $policy->resolve_audience(
			PostNotificationMeta::default_audience_config(),
			new NamedListRepository()
		);
		$request = $builder->from_config( $config );
		self::assertTrue( $request->include_all_subscribers() );
		self::assertNotContains(
			'default-audience@example.com',
			array_map(
				static fn ( $item ): string => $item->email(),
				$resolver->resolve( $request )->recipients()
			)
		);
		self::assertTrue(
			$service->confirm( (string) $signup->confirmation_token(), $now->modify( '+1 hour' ) )
		);
		$recipients = $resolver->resolve( $request )->recipients();
		self::assertContains(
			'default-audience@example.com',
			array_map( static fn ( $item ): string => $item->email(), $recipients )
		);
	}

	/**
	 * Enabling the global switch creates a campaign for a neutral-intent post.
	 *
	 * @return void
	 */
	public function test_site_default_send_reserves_campaign(): void {
		( new NotificationDefaults() )->save(
			array(
				'send'     => true,
				'audience' => NotificationDefaults::AUDIENCE_ALL,
				'list_id'  => 0,
			),
			new NamedListRepository()
		);
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => 'Default notification.',
			)
		);
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
		$key = CampaignRepository::initial_key( get_current_blog_id(), $post_id );
		$row = ( new CampaignRepository() )->find_by_key( $key );
		self::assertNotNull( $row );
		self::assertSame( 'building', $row['status'] );
	}
}

// EOF: tests/Integration/NotificationDefaultsTest.php.
