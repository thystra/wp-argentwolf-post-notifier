<?php
/**
 * File: tests/Unit/VersionTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase {
	public function test_versions(): void {
		self::assertSame( '0.1.0-alpha.2', Version::PLUGIN );
		self::assertSame( '0', Version::SCHEMA );
	}
}

// EOF: tests/Unit/VersionTest.php
