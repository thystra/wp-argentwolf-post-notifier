<?php
/**
 * File: src/Mail/DeliveryResult.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Mail;

/**
 * Result of submitting one message to a transport.
 *
 * Submitted means only that the transport accepted the message for processing;
 * it never claims inbox delivery.
 */
final class DeliveryResult {
	private function __construct( private bool $submitted ) {
	}

	/**
	 * Create a submitted result.
	 *
	 * @return self
	 */
	public static function submitted(): self {
		return new self( true );
	}

	/**
	 * Create a failed submission result.
	 *
	 * @return self
	 */
	public static function failed(): self {
		return new self( false );
	}

	/**
	 * Whether the transport accepted the message for processing.
	 *
	 * @return bool
	 */
	public function is_submitted(): bool {
		return $this->submitted;
	}
}

// EOF: src/Mail/DeliveryResult.php.
