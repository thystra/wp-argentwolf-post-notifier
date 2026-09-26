<?php
/**
 * File: tests/Integration/SuppressionManagementTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Subscriber\ManageSubscriptionToken;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use ArgentWolf\PostNotifier\Subscriber\SubscriberStatus;
use ArgentWolf\PostNotifier\Suppression\SuppressionRepository;
use ArgentWolf\PostNotifier\Suppression\SuppressionService;
use DateTimeImmutable;
use DateTimeZone;
use WP_UnitTestCase;

final class SuppressionManagementTest extends WP_UnitTestCase {
	public function test_global_suppression_blocks_public_signup(): void {
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
		$now         = $this->utc( '2026-09-26 14:00:00' );

		$suppression->suppress(
			'person@example.com',
			SuppressionService::REASON_MANUAL,
			SuppressionService::SOURCE_SUBSCRIBER_ADMIN,
			$now
		);

		$result = $service->request_confirmation(
			' Person@Example.com ',
			null,
			'Consent',
			'block',
			null,
			$now
		);

		self::assertSame( 0, $result->subscriber_id() );
		self::assertFalse( $result->should_send_confirmation() );

		$table = TableNames::from_database( $wpdb )->subscribers();
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE email_hash = %s',
				$table,
				$identity->hash( 'person@example.com' )
			)
		);
		self::assertSame( 0, $count );
	}

	public function test_suppression_created_after_signup_blocks_confirmation(): void {
		$service = $this->service();
		$now     = $this->utc( '2026-09-26 14:00:00' );
		$signup  = $service->request_confirmation(
			'person@example.com',
			null,
			'Consent',
			'block',
			null,
			$now
		);

		$this->suppression()->suppress(
			'person@example.com',
			SuppressionService::REASON_MANUAL,
			SuppressionService::SOURCE_SUBSCRIBER_ADMIN,
			$now->modify( '+30 minutes' )
		);

		self::assertNull(
			$service->confirm_with_management(
				(string) $signup->confirmation_token(),
				$now->modify( '+1 hour' )
			)
		);
		self::assertSame(
			SubscriberStatus::Pending->value,
			$this->subscriber_status( $signup->subscriber_id() )
		);
	}


	public function test_confirmed_subscriber_receives_management_token(): void {
		global $wpdb;

		$service = $this->service();
		$now     = $this->utc( '2026-09-26 14:00:00' );
		$signup  = $service->request_confirmation(
			'person@example.com',
			null,
			'Consent',
			'block',
			null,
			$now
		);
		$manage_token = $service->confirm_with_management(
			(string) $signup->confirmation_token(),
			$now->modify( '+1 hour' )
		);

		self::assertNotNull( $manage_token );
		self::assertNotNull(
			ManageSubscriptionToken::hash_plaintext( (string) $manage_token )
		);

		$table = TableNames::from_database( $wpdb )->subscribers();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, manage_token_hash FROM %i WHERE id = %d',
				$table,
				$signup->subscriber_id()
			),
			ARRAY_A
		);
		self::assertIsArray( $row );
		self::assertSame( SubscriberStatus::Subscribed->value, $row['status'] );
		self::assertSame(
			ManageSubscriptionToken::hash_plaintext( (string) $manage_token ),
			$row['manage_token_hash']
		);
	}

	public function test_management_unsubscribe_and_verified_resubscribe(): void {
		global $wpdb;

		$service = $this->service();
		$now     = $this->utc( '2026-09-26 14:00:00' );
		$signup  = $service->request_confirmation(
			'person@example.com',
			null,
			'Consent',
			'block',
			null,
			$now
		);
		$manage_token = (string) $service->confirm_with_management(
			(string) $signup->confirmation_token(),
			$now->modify( '+1 hour' )
		);

		self::assertTrue(
			$service->unsubscribe_managed(
				$manage_token,
				$now->modify( '+2 hours' )
			)
		);
		self::assertTrue( $this->suppression()->is_suppressed( 'person@example.com' ) );
		self::assertSame(
			SubscriberStatus::Unsubscribed->value,
			$this->subscriber_status( $signup->subscriber_id() )
		);

		self::assertTrue(
			$service->resubscribe_managed(
				$manage_token,
				$now->modify( '+3 hours' )
			)
		);
		self::assertFalse( $this->suppression()->is_suppressed( 'person@example.com' ) );
		self::assertSame(
			SubscriberStatus::Subscribed->value,
			$this->subscriber_status( $signup->subscriber_id() )
		);
	}

	public function test_self_service_suppression_cannot_replace_administrator_source(): void {
		$suppression = $this->suppression();
		$now         = $this->utc( '2026-09-26 14:00:00' );

		$suppression->suppress(
			'person@example.com',
			SuppressionService::REASON_MANUAL,
			SuppressionService::SOURCE_SUBSCRIBER_ADMIN,
			$now
		);
		$suppression->suppress(
			'person@example.com',
			SuppressionService::REASON_UNSUBSCRIBE,
			SuppressionService::SOURCE_SUBSCRIBER_MANAGE,
			$now->modify( '+1 hour' )
		);

		self::assertSame(
			SuppressionService::SOURCE_SUBSCRIBER_ADMIN,
			$suppression->source_for( 'person@example.com' )
		);
	}

	public function test_administrator_suppression_cannot_be_removed_by_old_manage_link(): void {
		$service = $this->service();
		$now     = $this->utc( '2026-09-26 14:00:00' );
		$signup  = $service->request_confirmation(
			'person@example.com',
			null,
			'Consent',
			'block',
			null,
			$now
		);
		$manage_token = (string) $service->confirm_with_management(
			(string) $signup->confirmation_token(),
			$now->modify( '+1 hour' )
		);

		$this->suppression()->suppress(
			'person@example.com',
			SuppressionService::REASON_MANUAL,
			SuppressionService::SOURCE_SUBSCRIBER_ADMIN,
			$now->modify( '+2 hours' )
		);

		self::assertFalse(
			$service->resubscribe_managed(
				$manage_token,
				$now->modify( '+3 hours' )
			)
		);
		self::assertTrue( $this->suppression()->is_suppressed( 'person@example.com' ) );
	}

	private function service(): SubscriberService {
		global $wpdb;

		$identity = new EmailIdentity();
		return new SubscriberService(
			new SubscriberRepository( $wpdb ),
			$identity,
			new SuppressionService(
				new SuppressionRepository( $wpdb ),
				$identity
			)
		);
	}

	private function suppression(): SuppressionService {
		global $wpdb;

		return new SuppressionService(
			new SuppressionRepository( $wpdb ),
			new EmailIdentity()
		);
	}

	private function subscriber_status( int $subscriber_id ): string {
		global $wpdb;

		$table = TableNames::from_database( $wpdb )->subscribers();
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT status FROM %i WHERE id = %d',
				$table,
				$subscriber_id
			)
		);
	}

	private function utc( string $value ): DateTimeImmutable {
		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}
}

// EOF: tests/Integration/SuppressionManagementTest.php.
