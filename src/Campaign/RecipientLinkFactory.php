<?php
/**
 * File: src/Campaign/RecipientLinkFactory.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Campaign;

use ArgentWolf\PostNotifier\Admin\UserNotificationPreferenceProfile;
use ArgentWolf\PostNotifier\Recipient\NamedListMemberType;
use InvalidArgumentException;

/**
 * Build same-site recipient-specific subscriber-control URLs.
 *
 * Links never carry a redirect destination, email address, or database ID.
 * Registered users manage their preference from their WordPress profile;
 * standalone subscribers manage it from the token-scoped campaign page.
 */
final class RecipientLinkFactory {
	public const UNSUBSCRIBE_ACTION = 'argentwolf_post_notifier_recipient_unsubscribe';
	public const MANAGE_ACTION      = 'argentwolf_post_notifier_recipient_manage';
	public const ONE_CLICK_ACTION   = 'argentwolf_post_notifier_one_click_unsubscribe';
	public const CLICK_ACTION       = 'argentwolf_post_notifier_click';

	/**
	 * Build the three links for one recipient message.
	 *
	 * @param NamedListMemberType $type      Primary recipient identity type.
	 * @param string              $plaintext Plaintext recipient token.
	 * @param string|null         $click     Optional plaintext click token.
	 * @return array<string,string> Keys unsubscribe, manage, one_click and optionally click.
	 * @throws InvalidArgumentException When a token is malformed.
	 */
	public function create(
		NamedListMemberType $type,
		string $plaintext,
		?string $click = null
	): array {
		if ( null === RecipientToken::hash_plaintext( $plaintext ) ) {
			throw new InvalidArgumentException( 'Recipient token is invalid.' );
		}
		if ( null !== $click && null === RecipientToken::hash_plaintext( $click ) ) {
			throw new InvalidArgumentException( 'Click token is invalid.' );
		}

		$manage = NamedListMemberType::User === $type
			? admin_url( 'profile.php' ) . '#' . UserNotificationPreferenceProfile::SECTION_ID
			: $this->endpoint( self::MANAGE_ACTION, $plaintext );

		$links = array(
			'unsubscribe' => $this->endpoint( self::UNSUBSCRIBE_ACTION, $plaintext ),
			'manage'      => $manage,
			'one_click'   => $this->endpoint( self::ONE_CLICK_ACTION, $plaintext ),
		);
		if ( null !== $click ) {
			$links['click'] = $this->endpoint( self::CLICK_ACTION, $click );
		}

		return $links;
	}

	/**
	 * Build one local admin-post endpoint URL.
	 *
	 * @param string $action    Fixed admin-post action.
	 * @param string $plaintext Validated token.
	 * @return string
	 */
	private function endpoint( string $action, string $plaintext ): string {
		return add_query_arg(
			array(
				'action' => $action,
				'token'  => $plaintext,
			),
			admin_url( 'admin-post.php' )
		);
	}
}

// EOF: src/Campaign/RecipientLinkFactory.php.
