<?php
/**
 * File: src/Subscriber/WordPressConfirmationLinkFactory.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

use InvalidArgumentException;

/**
 * WordPress-local confirmation-link generator.
 *
 * The GET action is deliberately a display-only destination. ConfirmationController
 * owns the separate nonce-protected POST that can call confirm().
 */
final class WordPressConfirmationLinkFactory implements ConfirmationLinkFactory {
	public const GET_ACTION = 'argentwolf_post_notifier_confirm';

	/**
	 * Build the local confirmation link.
	 *
	 * @param string $plaintext_token Plaintext confirmation token.
	 * @return string
	 * @throws InvalidArgumentException When the confirmation token is invalid.
	 */
	public function create( string $plaintext_token ): string {
		if ( null === ConfirmationToken::hash_plaintext( $plaintext_token ) ) {
			throw new InvalidArgumentException( 'Confirmation token is invalid.' );
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

// EOF: src/Subscriber/WordPressConfirmationLinkFactory.php.
