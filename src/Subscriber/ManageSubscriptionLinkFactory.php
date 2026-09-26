<?php
/**
 * File: src/Subscriber/ManageSubscriptionLinkFactory.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

/**
 * Generates local standalone subscription-management links.
 */
interface ManageSubscriptionLinkFactory {
	/**
	 * Build a local management link for one plaintext bearer token.
	 *
	 * @param string $plaintext_token Plaintext management token.
	 * @return string
	 */
	public function create( string $plaintext_token ): string;
}

// EOF: src/Subscriber/ManageSubscriptionLinkFactory.php.
