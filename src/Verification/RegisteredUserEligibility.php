<?php
/**
 * File: src/Verification/RegisteredUserEligibility.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Verification;

/**
 * Fail-closed registered-user verification eligibility policy.
 */
final class RegisteredUserEligibility {
	/**
	 * Construct the eligibility policy.
	 *
	 * @param VerificationProvider $provider Authoritative provider.
	 */
	public function __construct( private VerificationProvider $provider ) {
	}

	/**
	 * Return the authoritative status for a registered user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return VerificationStatus
	 */
	public function status_for_user( int $user_id ): VerificationStatus {
		return $this->provider->status_for_user( $user_id );
	}

	/**
	 * Determine whether a registered user may receive delivery.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public function is_eligible( int $user_id ): bool {
		return $this->status_for_user( $user_id )->is_eligible();
	}

	/**
	 * Return the aggregate skip reason for an ineligible user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string|null
	 */
	public function skip_reason_for_user( int $user_id ): ?string {
		return match ( $this->status_for_user( $user_id ) ) {
			VerificationStatus::Verified => null,
			VerificationStatus::Pending  => 'unverified',
			VerificationStatus::Unknown  => 'verification_unknown',
		};
	}
}

// EOF: src/Verification/RegisteredUserEligibility.php.
