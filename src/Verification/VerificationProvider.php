<?php
/**
 * File: src/Verification/VerificationProvider.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Verification;

/**
 * Authoritative registered-user email-verification provider.
 */
interface VerificationProvider {
	/**
	 * Determine whether the provider API is present.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Return the authoritative status for a WordPress user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return VerificationStatus
	 */
	public function status_for_user( int $user_id ): VerificationStatus;

	/**
	 * Return a human-readable provider description.
	 *
	 * @return string
	 */
	public function description(): string;

	/**
	 * Return provider availability and compatibility details.
	 *
	 * @return VerificationProviderHealth
	 */
	public function health(): VerificationProviderHealth;
}

// EOF: src/Verification/VerificationProvider.php.
