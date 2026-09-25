<?php
/**
 * File: tests/Unit/MailMessageTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Mail\DeliveryResult;
use ArgentWolf\PostNotifier\Mail\MailMessage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MailMessageTest extends TestCase {
	public function test_message_retains_transport_values(): void {
		$message = new MailMessage(
			' person@example.com ',
			' Confirmation ',
			"Body\n",
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);

		self::assertSame( 'person@example.com', $message->recipient() );
		self::assertSame( 'Confirmation', $message->subject() );
		self::assertSame( "Body\n", $message->body() );
		self::assertSame(
			array( 'Content-Type: text/plain; charset=UTF-8' ),
			$message->headers()
		);
	}

	public function test_empty_recipient_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new MailMessage( ' ', 'Subject', 'Body' );
	}

	public function test_delivery_result_uses_submitted_not_delivered_semantics(): void {
		self::assertTrue( DeliveryResult::submitted()->is_submitted() );
		self::assertFalse( DeliveryResult::failed()->is_submitted() );
	}
}

// EOF: tests/Unit/MailMessageTest.php.
