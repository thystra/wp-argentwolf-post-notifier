<?php
/**
 * File: src/Content/EmailMoreSettings.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Content;

use InvalidArgumentException;

/**
 * Store the site-wide policy for honoring core More blocks in emails.
 */
final class EmailMoreSettings {
	/** Site option for honoring the WordPress More block. */
	public const OPTION = 'argentwolf_post_notifier_honor_more';

	/**
	 * Read the configured policy, preserving the historical enabled default.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		try {
			return $this->validate( get_option( self::OPTION, '1' ) );
		} catch ( InvalidArgumentException ) {
			return true;
		}
	}

	/**
	 * Accept only explicit on/off form values, never arbitrary truthy input.
	 *
	 * @param mixed $value Raw form or stored option value.
	 * @return bool
	 * @throws InvalidArgumentException When the value is not a strict boolean flag.
	 */
	public function validate( mixed $value ): bool {
		if ( '1' === $value || true === $value ) {
			return true;
		}
		if ( '0' === $value || false === $value ) {
			return false;
		}
		throw new InvalidArgumentException( 'Invalid More-block setting.' );
	}

	/**
	 * Save a validated preference, keeping the selected value stable across requests.
	 *
	 * @param mixed $value Explicit administrator selection.
	 * @return void
	 * @throws InvalidArgumentException When the value is invalid.
	 */
	public function save( mixed $value ): void {
		update_option( self::OPTION, $this->validate( $value ) ? '1' : '0', false );
	}

	/**
	 * Restore the original honor-More default.
	 *
	 * @return void
	 */
	public function restore_defaults(): void {
		delete_option( self::OPTION );
	}
}

// EOF: src/Content/EmailMoreSettings.php.
