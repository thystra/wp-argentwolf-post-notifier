<?php
/**
 * File: tests/Unit/NamedListMemberTypeTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Recipient\NamedListMemberType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NamedListMemberTypeTest extends TestCase {
	public function test_member_keys_are_typed_and_stable(): void {
		self::assertSame( 'user:12', NamedListMemberType::User->member_key( 12 ) );
		self::assertSame(
			'subscriber:34',
			NamedListMemberType::Subscriber->member_key( 34 )
		);
	}

	public function test_member_key_rejects_non_positive_ids(): void {
		$this->expectException( InvalidArgumentException::class );
		NamedListMemberType::User->member_key( 0 );
	}
}

// EOF: tests/Unit/NamedListMemberTypeTest.php.
