<?php
/**
 * File: tests/Unit/VerificationStatusTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Verification\VerificationStatus;
use PHPUnit\Framework\TestCase;

final class VerificationStatusTest extends TestCase {
	public function test_provider_values_are_normalized(): void {
		self::assertSame(
			VerificationStatus::Verified,
			VerificationStatus::from_provider_value( ' verified ' )
		);
		self::assertSame(
			VerificationStatus::Pending,
			VerificationStatus::from_provider_value( 'PENDING' )
		);
		self::assertSame(
			VerificationStatus::Unknown,
			VerificationStatus::from_provider_value( 'other' )
		);
		self::assertSame(
			VerificationStatus::Unknown,
			VerificationStatus::from_provider_value( true )
		);
	}

	public function test_only_verified_is_eligible(): void {
		self::assertTrue( VerificationStatus::Verified->is_eligible() );
		self::assertFalse( VerificationStatus::Pending->is_eligible() );
		self::assertFalse( VerificationStatus::Unknown->is_eligible() );
	}
}

// EOF: tests/Unit/VerificationStatusTest.php.
