<?php
/**
 * File: tests/Integration/WordPressRateLimitStoreTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Subscriber\WordPressRateLimitStore;
use WP_UnitTestCase;

final class WordPressRateLimitStoreTest extends WP_UnitTestCase {
	public function test_transient_store_enforces_and_resets_fixed_window(): void {
		$store  = new WordPressRateLimitStore();
		$bucket = 'test:' . wp_generate_uuid4();
		$now    = 1_800_000_000;

		self::assertTrue( $store->consume( $bucket, 2, 60, $now ) );
		self::assertTrue( $store->consume( $bucket, 2, 60, $now + 1 ) );
		self::assertFalse( $store->consume( $bucket, 2, 60, $now + 2 ) );
		self::assertTrue( $store->consume( $bucket, 2, 60, $now + 60 ) );
	}
}

// EOF: tests/Integration/WordPressRateLimitStoreTest.php.
