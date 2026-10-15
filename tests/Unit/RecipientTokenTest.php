<?php
/**
 * File: tests/Unit/RecipientTokenTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Campaign\RecipientToken;
use ArgentWolf\PostNotifier\Delivery\QueueWorker;
use ArgentWolf\PostNotifier\Mail\MailMessage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RecipientTokenTest extends TestCase {
	public function test_generated_tokens_are_random_and_only_hash_is_derived(): void {
		$first  = RecipientToken::generate();
		$second = RecipientToken::generate();

		self::assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/', $first->plaintext() );
		self::assertNotSame( $first->plaintext(), $second->plaintext() );
		self::assertSame( hash( 'sha256', $first->plaintext() ), $first->hash() );
		self::assertSame( $first->hash(), RecipientToken::hash_plaintext( $first->plaintext() ) );
		self::assertNull( RecipientToken::hash_plaintext( 'short' ) );
		self::assertNull( RecipientToken::hash_plaintext( str_repeat( 'G', 64 ) ) );
	}

	public function test_backoff_is_exponential_and_bounded(): void {
		self::assertSame( 60, QueueWorker::backoff_seconds( 1 ) );
		self::assertSame( 240, QueueWorker::backoff_seconds( 3 ) );
		self::assertSame( QueueWorker::BACKOFF_MAX_SECONDS, QueueWorker::backoff_seconds( 99 ) );
		self::assertSame( 60, QueueWorker::backoff_seconds( 0 ) );
	}

	public function test_message_rejects_shared_recipients(): void {
		$this->expectException( InvalidArgumentException::class );
		new MailMessage( 'a@example.com, b@example.com', 'Subject', 'Body' );
	}

	public function test_message_rejects_bcc_header(): void {
		$this->expectException( InvalidArgumentException::class );
		new MailMessage( 'a@example.com', 'Subject', 'Body', array( 'Bcc: b@example.com' ) );
	}

	public function test_message_rejects_header_injection(): void {
		$this->expectException( InvalidArgumentException::class );
		new MailMessage( 'a@example.com', 'Subject', 'Body', array( "X-A: 1\r\nBcc: b@example.com" ) );
	}

	public function test_message_retains_plain_text_alternative(): void {
		$message = new MailMessage( 'a@example.com', 'Subject', '<p>Body</p>', array(), 'Body' );
		self::assertSame( 'Body', $message->alt_body() );
	}
}

// EOF: tests/Unit/RecipientTokenTest.php.
