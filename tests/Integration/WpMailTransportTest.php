<?php
/**
 * File: tests/Integration/WpMailTransportTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Mail\MailMessage;
use ArgentWolf\PostNotifier\Mail\WpMailTransport;
use WP_UnitTestCase;

final class WpMailTransportTest extends WP_UnitTestCase {
	public function test_wp_mail_true_maps_to_submitted_only(): void {
		$captured = null;
		$filter   = static function ( $return, array $atts ) use ( &$captured ) {
			unset( $return );
			$captured = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );

		try {
			$result = ( new WpMailTransport() )->send(
				new MailMessage(
					'person@example.com',
					'Confirm',
					'Body',
					array( 'Content-Type: text/plain; charset=UTF-8' )
				)
			);
		} finally {
			remove_filter( 'pre_wp_mail', $filter, 10 );
		}

		self::assertTrue( $result->is_submitted() );
		self::assertIsArray( $captured );
		self::assertSame( array( 'person@example.com' ), $captured['to'] );
		self::assertSame( 'Confirm', $captured['subject'] );
		self::assertSame( 'Body', $captured['message'] );
	}

	public function test_wp_mail_false_maps_to_failed_submission(): void {
		$filter = static fn (): bool => false;
		add_filter( 'pre_wp_mail', $filter );

		try {
			$result = ( new WpMailTransport() )->send(
				new MailMessage( 'person@example.com', 'Confirm', 'Body' )
			);
		} finally {
			remove_filter( 'pre_wp_mail', $filter );
		}

		self::assertFalse( $result->is_submitted() );
	}
}

// EOF: tests/Integration/WpMailTransportTest.php.
