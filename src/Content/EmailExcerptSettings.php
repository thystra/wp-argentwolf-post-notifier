<?php
/**
 * File: src/Content/EmailExcerptSettings.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Content;

use InvalidArgumentException;

/**
 * Store a bounded site-wide generated excerpt word count.
 *
 * This setting does not change full mode, the explicit Email Cutoff, the
 * WordPress More marker, or a manually written excerpt.
 */
final class EmailExcerptSettings {
	/** Site option for the generated excerpt word count. */
	public const OPTION = 'argentwolf_post_notifier_excerpt_words';

	/**
	 * Read a stored setting, falling back to the existing safe default.
	 *
	 * @return int
	 */
	public function get(): int {
		try {
			return $this->validate( get_option( self::OPTION, false ) );
		} catch ( InvalidArgumentException ) {
			return EmailContentSelector::DEFAULT_EXCERPT_WORDS;
		}
	}

	/**
	 * Require a strict positive integer within the selector's bound.
	 *
	 * @param mixed $value Raw saved option or administrator input.
	 * @return int
	 * @throws InvalidArgumentException When the value is malformed or out of range.
	 */
	public function validate( mixed $value ): int {
		if ( is_int( $value ) ) {
			$words = $value;
		} elseif ( is_string( $value ) && preg_match( '/^[1-9][0-9]{0,2}$/D', $value ) ) {
			$words = (int) $value;
		} else {
			throw new InvalidArgumentException( 'Invalid generated excerpt length.' );
		}

		if ( 1 > $words || EmailContentSelector::MAX_EXCERPT_WORDS < $words ) {
			throw new InvalidArgumentException( 'Generated excerpt length is out of range.' );
		}
		return $words;
	}

	/**
	 * Save only a validated word count.
	 *
	 * @param mixed $value Raw administrator input.
	 * @return void
	 * @throws InvalidArgumentException When the value is invalid.
	 */
	public function save( mixed $value ): void {
		update_option( self::OPTION, $this->validate( $value ), false );
	}

	/**
	 * Restore the original selector default.
	 *
	 * @return void
	 */
	public function restore_defaults(): void {
		delete_option( self::OPTION );
	}
}

// EOF: src/Content/EmailExcerptSettings.php.
