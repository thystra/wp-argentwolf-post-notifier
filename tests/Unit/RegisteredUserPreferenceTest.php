<?php
/**
 * File: tests/Unit/RegisteredUserPreferenceTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Recipient\RegisteredUserPreference;
use PHPUnit\Framework\TestCase;

final class RegisteredUserPreferenceTest extends TestCase {
	public function test_known_stored_values_round_trip(): void {
		self::assertSame(
			RegisteredUserPreference::SiteDefault,
			RegisteredUserPreference::from_stored_value( 'site_default' )
		);
		self::assertSame(
			RegisteredUserPreference::Subscribed,
			RegisteredUserPreference::from_stored_value( 'subscribed' )
		);
		self::assertSame(
			RegisteredUserPreference::Unsubscribed,
			RegisteredUserPreference::from_stored_value( 'unsubscribed' )
		);
	}

	public function test_missing_or_malformed_metadata_uses_site_default(): void {
		self::assertSame(
			RegisteredUserPreference::SiteDefault,
			RegisteredUserPreference::from_stored_value( '' )
		);
		self::assertSame(
			RegisteredUserPreference::SiteDefault,
			RegisteredUserPreference::from_stored_value( 'unexpected' )
		);
		self::assertSame(
			RegisteredUserPreference::SiteDefault,
			RegisteredUserPreference::from_stored_value( null )
		);
	}
}

// EOF: tests/Unit/RegisteredUserPreferenceTest.php.
