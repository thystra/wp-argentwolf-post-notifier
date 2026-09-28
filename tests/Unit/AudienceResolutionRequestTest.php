<?php
/**
 * File: tests/Unit/AudienceResolutionRequestTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Recipient\AudienceResolutionRequest;
use PHPUnit\Framework\TestCase;

final class AudienceResolutionRequestTest extends TestCase {
	public function test_request_normalizes_positive_unique_ids(): void {
		$request = new AudienceResolutionRequest(
			array( 9, 3, 9, 0, -1 ),
			array( 7, 2, 7 ),
			array( 12, 12, 4 ),
			array( 6, 5, 6 ),
			array( 12, 8 ),
			array( 6, 1 ),
			true
		);

		self::assertSame( array( 3, 9 ), $request->role_user_ids() );
		self::assertSame( array( 2, 7 ), $request->named_list_ids() );
		self::assertSame( array( 4, 12 ), $request->included_user_ids() );
		self::assertSame( array( 5, 6 ), $request->included_subscriber_ids() );
		self::assertSame( array( 8, 12 ), $request->excluded_user_ids() );
		self::assertSame( array( 1, 6 ), $request->excluded_subscriber_ids() );
		self::assertTrue( $request->site_default_user_subscribed() );
	}

	public function test_site_default_is_fail_closed_unless_explicitly_enabled(): void {
		$request = new AudienceResolutionRequest();

		self::assertFalse( $request->site_default_user_subscribed() );
	}
}

// EOF: tests/Unit/AudienceResolutionRequestTest.php.
