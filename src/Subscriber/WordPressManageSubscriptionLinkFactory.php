<?php
/**
 * File: src/Subscriber/WordPressManageSubscriptionLinkFactory.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

use InvalidArgumentException;

/**
 * WordPress-local subscription-management link generator.
 */
final class WordPressManageSubscriptionLinkFactory implements ManageSubscriptionLinkFactory {
	public const GET_ACTION = 'argentwolf_post_notifier_manage_subscription';

	/**
	 * Build one local management URL.
	 *
	 * @param string $plaintext_token Plaintext management token.
	 * @return string
	 * @throws InvalidArgumentException When the token is invalid.
	 */
	public function create( string $plaintext_token ): string {
		if ( null === ManageSubscriptionToken::hash_plaintext( $plaintext_token ) ) {
			throw new InvalidArgumentException( 'Management token is invalid.' );
		}

		return add_query_arg(
			array(
				'action' => self::GET_ACTION,
				'token'  => $plaintext_token,
			),
			admin_url( 'admin-post.php' )
		);
	}
}

// EOF: src/Subscriber/WordPressManageSubscriptionLinkFactory.php.
