<?php
/**
 * File: tests/Unit/ContainerTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Support\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContainerTest extends TestCase {
	public function test_service_is_lazy_and_shared(): void {
		$container = new Container();
		$count     = 0;

		$container->set(
			'service',
			static function () use ( &$count ): object {
				++$count;
				return new \stdClass();
			}
		);

		$first  = $container->get( 'service' );
		$second = $container->get( 'service' );

		self::assertSame( $first, $second );
		self::assertSame( 1, $count );
	}

	public function test_unknown_service_throws(): void {
		$this->expectException( RuntimeException::class );
		( new Container() )->get( 'missing' );
	}
}

// EOF: tests/Unit/ContainerTest.php
