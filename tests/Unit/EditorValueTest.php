<?php
/**
 * File: tests/Unit/EditorValueTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Editor\ContentMode;
use ArgentWolf\PostNotifier\Editor\SendIntent;
use PHPUnit\Framework\TestCase;

final class EditorValueTest extends TestCase {
	public function test_send_intent_values_and_fallback(): void {
		self::assertSame(
			array( 'site_default', 'send', 'do_not_send' ),
			SendIntent::values()
		);
		self::assertSame( 'send', SendIntent::sanitize( 'send' ) );
		self::assertSame( 'site_default', SendIntent::sanitize( 'unexpected' ) );
		self::assertSame( 'site_default', SendIntent::sanitize( array() ) );
	}

	public function test_content_mode_values_and_fallback(): void {
		self::assertSame(
			array( 'site_default', 'excerpt', 'full' ),
			ContentMode::values()
		);
		self::assertSame( 'full', ContentMode::sanitize( 'full' ) );
		self::assertSame( 'site_default', ContentMode::sanitize( 'unexpected' ) );
		self::assertSame( 'site_default', ContentMode::sanitize( null ) );
	}
}

// EOF: tests/Unit/EditorValueTest.php.
