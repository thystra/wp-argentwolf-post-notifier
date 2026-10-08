<?php
/**
 * File: tests/Unit/CampaignRepositoryTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Campaign\CampaignRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CampaignRepositoryTest extends TestCase {
	public function test_initial_key_is_stable_and_site_scoped(): void {
		self::assertSame(
			'initial:7:42',
			CampaignRepository::initial_key( 7, 42 )
		);
	}

	public function test_initial_key_rejects_invalid_identifiers(): void {
		$this->expectException( InvalidArgumentException::class );

		CampaignRepository::initial_key( 0, 42 );
	}
}

// EOF: tests/Unit/CampaignRepositoryTest.php.
