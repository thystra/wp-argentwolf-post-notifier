<?php
/**
 * File: src/Recipient/RegisteredUserPreference.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Recipient;

/**
 * Registered-user notification preference.
 */
enum RegisteredUserPreference: string {
	case SiteDefault  = 'site_default';
	case Subscribed   = 'subscribed';
	case Unsubscribed = 'unsubscribed';

	/**
	 * Resolve a persisted value without treating malformed metadata as opt-in.
	 *
	 * Missing or unknown values use the neutral site-default state.
	 *
	 * @param mixed $value Persisted metadata value.
	 * @return self
	 */
	public static function from_stored_value( mixed $value ): self {
		if ( ! is_string( $value ) ) {
			return self::SiteDefault;
		}

		return self::tryFrom( $value ) ?? self::SiteDefault;
	}
}

// EOF: src/Recipient/RegisteredUserPreference.php.
