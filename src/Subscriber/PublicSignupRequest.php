<?php
/**
 * File: src/Subscriber/PublicSignupRequest.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

/**
 * Untrusted public signup request values.
 */
final class PublicSignupRequest {
	/**
	 * Construct the request.
	 *
	 * @param string      $email          Submitted email address.
	 * @param string|null $display_name   Optional submitted display name.
	 * @param string      $consent_text   Consent text snapshot.
	 * @param string      $signup_source  Source identifier such as block.
	 * @param int|null    $source_post_id Optional source post ID.
	 * @param string      $honeypot       Hidden anti-bot field.
	 * @param string|null $remote_address Request network address, used only transiently.
	 */
	public function __construct(
		private string $email,
		private ?string $display_name,
		private string $consent_text,
		private string $signup_source,
		private ?int $source_post_id,
		private string $honeypot,
		private ?string $remote_address
	) {
	}

	/**
	 * Submitted email address.
	 *
	 * @return string
	 */
	public function email(): string {
		return $this->email;
	}

	/**
	 * Optional submitted display name.
	 *
	 * @return string|null
	 */
	public function display_name(): ?string {
		return $this->display_name;
	}

	/**
	 * Consent text snapshot.
	 *
	 * @return string
	 */
	public function consent_text(): string {
		return $this->consent_text;
	}

	/**
	 * Signup source identifier.
	 *
	 * @return string
	 */
	public function signup_source(): string {
		return $this->signup_source;
	}

	/**
	 * Optional source post ID.
	 *
	 * @return int|null
	 */
	public function source_post_id(): ?int {
		return $this->source_post_id;
	}

	/**
	 * Hidden anti-bot field.
	 *
	 * @return string
	 */
	public function honeypot(): string {
		return $this->honeypot;
	}

	/**
	 * Request network address used only to derive an ephemeral keyed bucket.
	 *
	 * @return string|null
	 */
	public function remote_address(): ?string {
		return $this->remote_address;
	}
}

// EOF: src/Subscriber/PublicSignupRequest.php.
