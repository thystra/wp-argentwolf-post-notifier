<?php
/**
 * File: tests/Unit/SubscriberStatusTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Subscriber\SubscriberStatus;
use PHPUnit\Framework\TestCase;

final class SubscriberStatusTest extends TestCase {
	public function test_only_subscribed_is_notification_eligible(): void {
		self::assertFalse( SubscriberStatus::Pending->is_notification_eligible() );
		self::assertTrue( SubscriberStatus::Subscribed->is_notification_eligible() );
		self::assertFalse( SubscriberStatus::Unsubscribed->is_notification_eligible() );
		self::assertFalse( SubscriberStatus::Suppressed->is_notification_eligible() );
	}

	public function test_only_pending_accepts_public_confirmation_refresh(): void {
		self::assertTrue( SubscriberStatus::Pending->accepts_confirmation_refresh() );
		self::assertFalse( SubscriberStatus::Subscribed->accepts_confirmation_refresh() );
		self::assertFalse( SubscriberStatus::Unsubscribed->accepts_confirmation_refresh() );
		self::assertFalse( SubscriberStatus::Suppressed->accepts_confirmation_refresh() );
	}
}

// EOF: tests/Unit/SubscriberStatusTest.php.
