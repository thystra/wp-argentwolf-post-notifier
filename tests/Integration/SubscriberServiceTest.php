<?php
/**
 * File: tests/Integration/SubscriberServiceTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Database\EmailIdentity;
use ArgentWolf\PostNotifier\Database\TableNames;
use ArgentWolf\PostNotifier\Subscriber\ConfirmationToken;
use ArgentWolf\PostNotifier\Subscriber\SubscriberRepository;
use ArgentWolf\PostNotifier\Subscriber\SubscriberService;
use ArgentWolf\PostNotifier\Subscriber\SubscriberStatus;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use WP_UnitTestCase;

final class SubscriberServiceTest extends WP_UnitTestCase {
	public function test_signup_normalizes_identity_and_stores_only_token_hash(): void {
		global $wpdb;

		$service = $this->service();
		$now     = $this->utc( '2026-09-25 12:00:00' );
		$result  = $service->request_confirmation(
			' Person@Example.COM ',
			' <b>Example Person</b> ',
			'<p>I agree to receive post notifications.</p>',
			'block',
			42,
			$now
		);

		self::assertTrue( $result->should_send_confirmation() );
		self::assertNotNull( $result->confirmation_token() );
		self::assertSame( 'person@example.com', $result->normalized_email() );

		$row = $this->subscriber_row( $result->subscriber_id() );
		self::assertSame( 'person@example.com', $row['email'] );
		self::assertSame( 'Example Person', $row['display_name'] );
		self::assertSame( 'I agree to receive post notifications.', $row['consent_text_snapshot'] );
		self::assertSame( 'block', $row['signup_source'] );
		self::assertSame( '42', (string) $row['source_post_id'] );
		self::assertSame( SubscriberStatus::Pending->value, $row['status'] );
		self::assertSame( '2026-09-26 12:00:00', $row['confirmation_expires_at_gmt'] );
		self::assertSame( '2026-09-25 12:00:00', $row['last_confirmation_sent_at_gmt'] );
		self::assertSame(
			( new EmailIdentity() )->hash( 'person@example.com' ),
			$row['email_hash']
		);
		self::assertSame(
			ConfirmationToken::hash_plaintext( (string) $result->confirmation_token() ),
			$row['confirmation_token_hash']
		);
		self::assertNotSame( $result->confirmation_token(), $row['confirmation_token_hash'] );
		self::assertStringNotContainsString(
			(string) $result->confirmation_token(),
			implode( '|', array_map( 'strval', $row ) )
		);

		$table = TableNames::from_database( $wpdb )->subscribers();
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE email_hash = %s',
				$table,
				$row['email_hash']
			)
		);
		self::assertSame( 1, $count );
	}

	public function test_repeated_signup_respects_cooldown_then_rotates_token(): void {
		$service = $this->service();
		$start   = $this->utc( '2026-09-25 12:00:00' );
		$first   = $service->request_confirmation(
			'person@example.com',
			'First Name',
			'Consent one',
			'block',
			1,
			$start
		);
		$first_row = $this->subscriber_row( $first->subscriber_id() );

		$within_cooldown = $service->request_confirmation(
			' PERSON@example.com ',
			'Second Name',
			'Consent two',
			'block',
			2,
			$start->modify( '+10 minutes' )
		);
		$cooldown_row = $this->subscriber_row( $within_cooldown->subscriber_id() );

		self::assertSame( $first->subscriber_id(), $within_cooldown->subscriber_id() );
		self::assertFalse( $within_cooldown->should_send_confirmation() );
		self::assertNull( $within_cooldown->confirmation_token() );
		self::assertSame(
			$first_row['confirmation_token_hash'],
			$cooldown_row['confirmation_token_hash']
		);
		self::assertSame( '2026-09-25 12:00:00', $cooldown_row['last_confirmation_sent_at_gmt'] );
		self::assertSame( 'Second Name', $cooldown_row['display_name'] );
		self::assertSame( 'Consent two', $cooldown_row['consent_text_snapshot'] );
		self::assertSame( '2', (string) $cooldown_row['source_post_id'] );

		$after_cooldown = $service->request_confirmation(
			'person@example.com',
			'Second Name',
			'Consent two',
			'block',
			2,
			$start->modify( '+15 minutes' )
		);
		$rotated_row = $this->subscriber_row( $after_cooldown->subscriber_id() );

		self::assertTrue( $after_cooldown->should_send_confirmation() );
		self::assertNotNull( $after_cooldown->confirmation_token() );
		self::assertNotSame(
			$first_row['confirmation_token_hash'],
			$rotated_row['confirmation_token_hash']
		);
		self::assertSame( '2026-09-25 12:15:00', $rotated_row['last_confirmation_sent_at_gmt'] );
		self::assertSame( '2026-09-26 12:15:00', $rotated_row['confirmation_expires_at_gmt'] );
	}

	/**
	 * @dataProvider non_pending_statuses
	 *
	 * @param SubscriberStatus $status Existing subscriber status.
	 * @return void
	 */
	public function test_public_signup_does_not_reactivate_non_pending_status(
		SubscriberStatus $status
	): void {
		global $wpdb;

		$service = $this->service();
		$start   = $this->utc( '2026-09-25 12:00:00' );
		$first   = $service->request_confirmation(
			'person@example.com',
			null,
			'Consent',
			'block',
			null,
			$start
		);
		$table = TableNames::from_database( $wpdb )->subscribers();
		$wpdb->update(
			$table,
			array( 'status' => $status->value ),
			array( 'id' => $first->subscriber_id() ),
			array( '%s' ),
			array( '%d' )
		);

		$repeat = $service->request_confirmation(
			'person@example.com',
			'Changed',
			'Changed consent',
			'block',
			9,
			$start->modify( '+2 days' )
		);
		$row = $this->subscriber_row( $first->subscriber_id() );

		self::assertSame( $first->subscriber_id(), $repeat->subscriber_id() );
		self::assertFalse( $repeat->should_send_confirmation() );
		self::assertSame( $status->value, $row['status'] );
		self::assertNull( $repeat->confirmation_token() );
		self::assertNull( $row['display_name'] );
		self::assertSame( 'Consent', $row['consent_text_snapshot'] );
	}

	/**
	 * @return array<string,array{0:SubscriberStatus}>
	 */
	public static function non_pending_statuses(): array {
		return array(
			'subscribed'   => array( SubscriberStatus::Subscribed ),
			'unsubscribed' => array( SubscriberStatus::Unsubscribed ),
			'suppressed'   => array( SubscriberStatus::Suppressed ),
		);
	}

	public function test_valid_confirmation_promotes_once_and_clears_bearer_hash(): void {
		$service = $this->service();
		$start   = $this->utc( '2026-09-25 12:00:00' );
		$signup  = $service->request_confirmation(
			'person@example.com',
			'Person',
			'Consent',
			'block',
			null,
			$start
		);
		$token = (string) $signup->confirmation_token();

		self::assertTrue( $service->confirm( $token, $start->modify( '+1 hour' ) ) );
		self::assertFalse( $service->confirm( $token, $start->modify( '+1 hour' ) ) );

		$row = $this->subscriber_row( $signup->subscriber_id() );
		self::assertSame( SubscriberStatus::Subscribed->value, $row['status'] );
		self::assertSame( '2026-09-25 13:00:00', $row['confirmed_at_gmt'] );
		self::assertNull( $row['confirmation_token_hash'] );
		self::assertNull( $row['confirmation_expires_at_gmt'] );
	}

	public function test_expired_or_malformed_confirmation_does_not_subscribe(): void {
		$service = $this->service();
		$start   = $this->utc( '2026-09-25 12:00:00' );
		$signup  = $service->request_confirmation(
			'person@example.com',
			null,
			'Consent',
			'block',
			null,
			$start
		);

		self::assertFalse( $service->confirm( 'not-a-token', $start ) );
		self::assertFalse(
			$service->confirm(
				(string) $signup->confirmation_token(),
				$start->modify( '+1 day +1 second' )
			)
		);

		$row = $this->subscriber_row( $signup->subscriber_id() );
		self::assertSame( SubscriberStatus::Pending->value, $row['status'] );
		self::assertNotNull( $row['confirmation_token_hash'] );
	}

	public function test_invalid_signup_input_is_rejected_before_persistence(): void {
		$service = $this->service();

		$this->expectException( InvalidArgumentException::class );
		$service->request_confirmation(
			'not-an-email',
			null,
			'Consent',
			'block',
			null,
			$this->utc( '2026-09-25 12:00:00' )
		);
	}

	private function service(): SubscriberService {
		global $wpdb;

		return new SubscriberService(
			new SubscriberRepository( $wpdb ),
			new EmailIdentity()
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function subscriber_row( int $subscriber_id ): array {
		global $wpdb;

		$table = TableNames::from_database( $wpdb )->subscribers();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$table,
				$subscriber_id
			),
			ARRAY_A
		);

		self::assertIsArray( $row );
		return $row;
	}

	private function utc( string $value ): DateTimeImmutable {
		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}
}

// EOF: tests/Integration/SubscriberServiceTest.php.
