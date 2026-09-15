<?php
/**
 * File: tests/Unit/EmailIdentityTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Database\EmailIdentity;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EmailIdentityTest extends TestCase {
	public function test_normalization_and_hashing_are_deterministic(): void {
		$key      = str_repeat( "\x24", 32 );
		$identity = new EmailIdentity( $key );

		self::assertSame( 'person@example.com', $identity->normalize( ' Person@Example.COM ' ) );
		self::assertNull( $identity->normalize( 'invalid' ) );
		self::assertSame(
			hash_hmac( 'sha256', 'person@example.com', $key ),
			$identity->hash( 'Person@Example.COM' )
		);
	}

	public function test_invalid_injected_hash_key_fails_closed(): void {
		$this->expectException( RuntimeException::class );
		( new EmailIdentity( 'short' ) )->hash( 'person@example.com' );
	}
}

// EOF: tests/Unit/EmailIdentityTest.php.
