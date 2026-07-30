<?php
/**
 * File: tests/Unit/ArgentWolfEmailVerificationProviderTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Verification\ArgentWolfEmailVerificationProvider;
use ArgentWolf\PostNotifier\Verification\VerificationStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ArgentWolfEmailVerificationProviderTest extends TestCase {
	public function test_healthy_provider_maps_statuses(): void {
		$provider = new ArgentWolfEmailVerificationProvider(
			static fn ( int $user_id ): string => 10 === $user_id
				? 'verified'
				: 'pending',
			static fn (): bool => true,
			static fn (): string => '0.3.4'
		);

		self::assertTrue( $provider->health()->is_healthy() );
		self::assertSame(
			VerificationStatus::Verified,
			$provider->status_for_user( 10 )
		);
		self::assertSame(
			VerificationStatus::Pending,
			$provider->status_for_user( 11 )
		);
	}

	public function test_missing_provider_fails_closed(): void {
		$provider = new ArgentWolfEmailVerificationProvider(
			static fn (): string => 'verified',
			static fn (): bool => false,
			static fn (): ?string => null
		);

		self::assertSame( 'missing_api', $provider->health()->code() );
		self::assertSame(
			VerificationStatus::Unknown,
			$provider->status_for_user( 10 )
		);
	}

	public function test_obsolete_provider_fails_closed(): void {
		$provider = new ArgentWolfEmailVerificationProvider(
			static fn (): string => 'verified',
			static fn (): bool => true,
			static fn (): string => '0.3.3'
		);

		self::assertSame( 'obsolete_api', $provider->health()->code() );
		self::assertSame(
			VerificationStatus::Unknown,
			$provider->status_for_user( 10 )
		);
	}

	public function test_provider_exception_fails_closed(): void {
		$provider = new ArgentWolfEmailVerificationProvider(
			static function (): never {
				throw new RuntimeException( 'Provider failure.' );
			},
			static fn (): bool => true,
			static fn (): string => '0.3.4'
		);

		self::assertSame(
			VerificationStatus::Unknown,
			$provider->status_for_user( 10 )
		);
	}
}

// EOF: tests/Unit/ArgentWolfEmailVerificationProviderTest.php.
