<?php
/**
 * File: src/Subscriber/ConfirmationLinkFactory.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

/**
 * Generates local confirmation links for standalone subscribers.
 */
interface ConfirmationLinkFactory {
	/**
	 * Build a local confirmation link for one plaintext bearer token.
	 *
	 * @param string $plaintext_token Plaintext confirmation token.
	 * @return string
	 */
	public function create( string $plaintext_token ): string;
}

// EOF: src/Subscriber/ConfirmationLinkFactory.php.
