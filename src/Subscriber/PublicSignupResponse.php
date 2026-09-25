<?php
/**
 * File: src/Subscriber/PublicSignupResponse.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

/**
 * Deliberately generic public signup response.
 *
 * Existing subscriber state, cooldown state, honeypot handling, rate limits,
 * and confirmation-mail submission results must not alter this public code.
 */
final class PublicSignupResponse {
	/**
	 * Stable generic response code.
	 */
	public const CODE = 'request_received';

	/**
	 * Stable public response code.
	 *
	 * @return string
	 */
	public function code(): string {
		return self::CODE;
	}
}

// EOF: src/Subscriber/PublicSignupResponse.php.
