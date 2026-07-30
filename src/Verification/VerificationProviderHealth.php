<?php
/**
 * File: src/Verification/VerificationProviderHealth.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Verification;

/**
 * Immutable verification-provider health report.
 */
final readonly class VerificationProviderHealth {
	/**
	 * Construct a health report.
	 *
	 * @param string      $provider_id Provider identifier.
	 * @param string      $description Human-readable provider description.
	 * @param bool        $available   Whether the provider API is present.
	 * @param bool        $compatible  Whether the provider API version is supported.
	 * @param string|null $version     Detected provider version.
	 * @param string      $code        Stable health code.
	 * @param string      $message     Human-readable health message.
	 */
	public function __construct(
		private string $provider_id,
		private string $description,
		private bool $available,
		private bool $compatible,
		private ?string $version,
		private string $code,
		private string $message
	) {
	}

	/**
	 * Return the provider identifier.
	 *
	 * @return string
	 */
	public function provider_id(): string {
		return $this->provider_id;
	}

	/**
	 * Return the provider description.
	 *
	 * @return string
	 */
	public function description(): string {
		return $this->description;
	}

	/**
	 * Determine whether the provider API is present.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * Determine whether the provider version is compatible.
	 *
	 * @return bool
	 */
	public function is_compatible(): bool {
		return $this->compatible;
	}

	/**
	 * Return the detected provider version.
	 *
	 * @return string|null
	 */
	public function version(): ?string {
		return $this->version;
	}

	/**
	 * Return the stable health code.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Return the human-readable health message.
	 *
	 * @return string
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Determine whether the provider is authoritative and usable.
	 *
	 * @return bool
	 */
	public function is_healthy(): bool {
		return $this->available && $this->compatible;
	}
}

// EOF: src/Verification/VerificationProviderHealth.php.
