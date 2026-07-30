<?php
/**
 * File: src/Verification/VerificationStatus.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Verification;

/**
 * Authoritative verification result for a registered WordPress user.
 */
enum VerificationStatus: string {
	case Verified = 'verified';
	case Pending  = 'pending';
	case Unknown  = 'unknown';

	/**
	 * Convert a provider value into the closed internal status set.
	 *
	 * @param mixed $value Provider result.
	 * @return self
	 */
	public static function from_provider_value( mixed $value ): self {
		if ( ! is_string( $value ) ) {
			return self::Unknown;
		}

		return match ( strtolower( trim( $value ) ) ) {
			'verified' => self::Verified,
			'pending'  => self::Pending,
			default    => self::Unknown,
		};
	}

	/**
	 * Determine whether the status permits registered-user delivery.
	 *
	 * @return bool
	 */
	public function is_eligible(): bool {
		return self::Verified === $this;
	}
}

// EOF: src/Verification/VerificationStatus.php.
