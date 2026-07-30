<?php
/**
 * File: tests/Unit/RegisteredUserEligibilityTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Unit
 */

namespace ArgentWolf\PostNotifier\Tests\Unit;

use ArgentWolf\PostNotifier\Verification\RegisteredUserEligibility;
use ArgentWolf\PostNotifier\Verification\VerificationProvider;
use ArgentWolf\PostNotifier\Verification\VerificationProviderHealth;
use ArgentWolf\PostNotifier\Verification\VerificationStatus;
use PHPUnit\Framework\TestCase;

final class RegisteredUserEligibilityTest extends TestCase {
	/**
	 * @dataProvider provide_statuses
	 */
	public function test_eligibility_and_skip_reasons(
		VerificationStatus $status,
		bool $eligible,
		?string $skip_reason
	): void {
		$provider = new class( $status ) implements VerificationProvider {
			public function __construct( private VerificationStatus $status ) {
			}

			public function is_available(): bool {
				return true;
			}

			public function status_for_user( int $user_id ): VerificationStatus {
				unset( $user_id );

				return $this->status;
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
		$policy   = new RegisteredUserEligibility( $provider );

		self::assertSame( $eligible, $policy->is_eligible( 10 ) );
		self::assertSame( $skip_reason, $policy->skip_reason_for_user( 10 ) );
	}

	/**
	 * Return status policy cases.
	 *
	 * @return array<string, array{VerificationStatus, bool, string|null}>
	 */
	public static function provide_statuses(): array {
		return array(
			'verified' => array( VerificationStatus::Verified, true, null ),
			'pending'  => array( VerificationStatus::Pending, false, 'unverified' ),
			'unknown'  => array(
				VerificationStatus::Unknown,
				false,
				'verification_unknown',
			),
		);
	}
}

// EOF: tests/Unit/RegisteredUserEligibilityTest.php.
