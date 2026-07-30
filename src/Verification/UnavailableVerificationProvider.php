<?php
/**
 * File: src/Verification/UnavailableVerificationProvider.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Verification;

/**
 * Fail-closed provider used when no authoritative integration is usable.
 */
final class UnavailableVerificationProvider implements VerificationProvider {
	/**
	 * Construct the unavailable provider.
	 *
	 * @param string $code    Stable health code.
	 * @param string $message Human-readable health message.
	 */
	public function __construct(
		private string $code = 'no_authoritative_provider',
		private string $message = 'No authoritative verification provider is available.'
	) {
	}

	/**
	 * Determine whether the provider API is present.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return false;
	}

	/**
	 * Return unknown so registered-user delivery fails closed.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return VerificationStatus
	 */
	public function status_for_user( int $user_id ): VerificationStatus {
		unset( $user_id );

		return VerificationStatus::Unknown;
	}

	/**
	 * Return a human-readable provider description.
	 *
	 * @return string
	 */
	public function description(): string {
		return 'Unavailable verification provider';
	}

	/**
	 * Return provider health details.
	 *
	 * @return VerificationProviderHealth
	 */
	public function health(): VerificationProviderHealth {
		return new VerificationProviderHealth(
			'unavailable',
			$this->description(),
			false,
			false,
			null,
			$this->code,
			$this->message
		);
	}
}

// EOF: src/Verification/UnavailableVerificationProvider.php.
