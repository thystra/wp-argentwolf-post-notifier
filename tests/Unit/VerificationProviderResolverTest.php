<?php
/**
 * File: tests/Unit/VerificationProviderResolverTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Verification\UnavailableVerificationProvider;
use ArgentWolf\PostNotifier\Verification\VerificationProvider;
use ArgentWolf\PostNotifier\Verification\VerificationProviderHealth;
use ArgentWolf\PostNotifier\Verification\VerificationProviderResolver;
use ArgentWolf\PostNotifier\Verification\VerificationStatus;
use PHPUnit\Framework\TestCase;

final class VerificationProviderResolverTest extends TestCase {
	public function test_alternate_provider_can_replace_default(): void {
		$default   = new UnavailableVerificationProvider();
		$alternate = $this->healthy_provider();

		$resolver = new VerificationProviderResolver(
			$default,
			static fn (): VerificationProvider => $alternate
		);

		self::assertSame( $alternate, $resolver->resolve() );
	}

	public function test_invalid_filter_result_fails_closed(): void {
		$resolver = new VerificationProviderResolver(
			$this->healthy_provider(),
			static fn (): string => 'invalid'
		);

		$provider = $resolver->resolve();
		self::assertSame( 'invalid_provider', $provider->health()->code() );
		self::assertSame(
			VerificationStatus::Unknown,
			$provider->status_for_user( 1 )
		);
	}

	private function healthy_provider(): VerificationProvider {
		return new class() implements VerificationProvider {
			public function is_available(): bool {
				return true;
			}

			public function status_for_user( int $user_id ): VerificationStatus {
				unset( $user_id );

				return VerificationStatus::Verified;
			}

			public function description(): string {
				return 'Test provider';
			}

			public function health(): VerificationProviderHealth {
				return new VerificationProviderHealth(
					'test',
					$this->description(),
					true,
					true,
					'1.0.0',
					'healthy',
					'Healthy.'
				);
			}
		};
	}
}

// EOF: tests/Unit/VerificationProviderResolverTest.php.
