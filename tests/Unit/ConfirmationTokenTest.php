<?php
/**
 * File: tests/Unit/ConfirmationTokenTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Subscriber\ConfirmationToken;
use PHPUnit\Framework\TestCase;

final class ConfirmationTokenTest extends TestCase {
	public function test_generated_tokens_are_random_hex_and_hashable(): void {
		$first  = ConfirmationToken::generate();
		$second = ConfirmationToken::generate();

		self::assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/D', $first->plaintext() );
		self::assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/D', $first->hash() );
		self::assertNotSame( $first->plaintext(), $second->plaintext() );
		self::assertNotSame( $first->hash(), $second->hash() );
		self::assertSame(
			$first->hash(),
			ConfirmationToken::hash_plaintext( $first->plaintext() )
		);
	}

	public function test_malformed_tokens_are_rejected_before_lookup(): void {
		self::assertNull( ConfirmationToken::hash_plaintext( '' ) );
		self::assertNull( ConfirmationToken::hash_plaintext( 'not-a-token' ) );
		self::assertNull( ConfirmationToken::hash_plaintext( str_repeat( 'a', 63 ) ) );
		self::assertNull( ConfirmationToken::hash_plaintext( str_repeat( 'A', 64 ) ) );
		self::assertNull( ConfirmationToken::hash_plaintext( str_repeat( 'a', 65 ) ) );
	}
}

// EOF: tests/Unit/ConfirmationTokenTest.php.
