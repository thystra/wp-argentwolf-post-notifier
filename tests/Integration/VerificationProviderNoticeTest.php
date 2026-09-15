<?php
/**
 * File: tests/Integration/VerificationProviderNoticeTest.php
 *
 * @package ArgentWolf\PostNotifier\Tests\Integration
 */

namespace ArgentWolf\PostNotifier\Tests\Integration;

use ArgentWolf\PostNotifier\Admin\VerificationProviderNotice;
use ArgentWolf\PostNotifier\Verification\VerificationProvider;
use ArgentWolf\PostNotifier\Verification\VerificationProviderHealth;
use ArgentWolf\PostNotifier\Verification\VerificationStatus;
use RuntimeException;
use WP_UnitTestCase;

final class VerificationProviderNoticeTest extends WP_UnitTestCase {
	public function test_provider_resolution_exception_renders_generic_warning(): void {
		$this->set_administrator();

		$notice = new VerificationProviderNotice(
			static function (): never {
				throw new RuntimeException( 'Sensitive provider detail.' );
			}
		);

		$this->assert_generic_warning( $notice );
	}

	public function test_provider_health_exception_renders_generic_warning(): void {
		$this->set_administrator();

		$provider = new class() implements VerificationProvider {
			public function is_available(): bool {
				return true;
			}

			public function status_for_user( int $user_id ): VerificationStatus {
				unset( $user_id );

				return VerificationStatus::Unknown;
			}

			public function description(): string {
				return 'Test provider';
			}

			public function health(): VerificationProviderHealth {
				throw new RuntimeException( 'Sensitive health detail.' );
			}
		};
		$notice   = new VerificationProviderNotice(
			static fn (): VerificationProvider => $provider
		);

		$this->assert_generic_warning( $notice );
	}

	private function set_administrator(): void {
		$administrator = self::factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $administrator );
	}

	private function assert_generic_warning( VerificationProviderNotice $notice ): void {
		ob_start();
		$notice->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString(
			'Registered-user delivery is disabled because verification could not be checked.',
			$output
		);
		self::assertStringNotContainsString( 'Sensitive provider detail.', $output );
		self::assertStringNotContainsString( 'Sensitive health detail.', $output );
	}
}

// EOF: tests/Integration/VerificationProviderNoticeTest.php.
