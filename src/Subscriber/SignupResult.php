<?php
/**
 * File: src/Subscriber/SignupResult.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

/**
 * Internal result from a standalone-subscriber signup request.
 *
 * Public controllers must not expose whether a token was issued or which
 * subscriber row was involved. Those details exist only so the mail layer can
 * decide whether it has a confirmation message to send.
 */
final class SignupResult {
	/**
	 * Construct the result.
	 *
	 * @param int         $subscriber_id      Internal subscriber row ID.
	 * @param string      $normalized_email   Canonical normalized email address.
	 * @param string|null $confirmation_token Plaintext token when mail should be sent.
	 */
	public function __construct(
		private int $subscriber_id,
		private string $normalized_email,
		private ?string $confirmation_token
	) {
	}

	/**
	 * Internal subscriber row ID.
	 *
	 * @return int
	 */
	public function subscriber_id(): int {
		return $this->subscriber_id;
	}

	/**
	 * Canonical normalized email used by the confirmation mail layer.
	 *
	 * @return string
	 */
	public function normalized_email(): string {
		return $this->normalized_email;
	}

	/**
	 * Determine whether this request earned a confirmation send.
	 *
	 * @return bool
	 */
	public function should_send_confirmation(): bool {
		return null !== $this->confirmation_token;
	}

	/**
	 * Return the plaintext token when a confirmation message should be sent.
	 *
	 * @return string|null
	 */
	public function confirmation_token(): ?string {
		return $this->confirmation_token;
	}
}

// EOF: src/Subscriber/SignupResult.php.
