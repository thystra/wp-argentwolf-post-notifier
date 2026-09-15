<?php
/**
 * File: tests/Unit/UtcDateTimeTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Database\UtcDateTime;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UtcDateTimeTest extends TestCase {
	public function test_format_converts_an_arbitrary_timezone_to_utc(): void {
		$local = new DateTimeImmutable(
			'2026-09-15 08:30:00',
			new DateTimeZone( 'America/New_York' )
		);

		self::assertSame( '2026-09-15 12:30:00', UtcDateTime::format( $local ) );
	}

	public function test_parse_returns_a_utc_datetime(): void {
		$parsed = UtcDateTime::parse( '2026-09-15 12:30:00' );

		self::assertSame( 'UTC', $parsed->getTimezone()->getName() );
		self::assertSame( '2026-09-15 12:30:00', UtcDateTime::format( $parsed ) );
	}

	public function test_parse_rejects_an_invalid_database_datetime(): void {
		$this->expectException( InvalidArgumentException::class );
		UtcDateTime::parse( '2026-02-31 99:30:00' );
	}
}

// EOF: tests/Unit/UtcDateTimeTest.php.
