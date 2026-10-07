<?php
/**
 * File: src/Editor/ContentMode.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Editor;

/**
 * Per-post content-selection override.
 *
 * Site default leaves cutoff/excerpt policy to the later rendering milestone.
 */
enum ContentMode: string {
	case SiteDefault = 'site_default';
	case Excerpt     = 'excerpt';
	case Full        = 'full';

	/**
	 * Return allowed stored values.
	 *
	 * @return array<int,string>
	 */
	public static function values(): array {
		return array_map(
			static fn ( self $mode ): string => $mode->value,
			self::cases()
		);
	}

	/**
	 * Normalize an arbitrary stored value to a safe mode.
	 *
	 * @param mixed $value Candidate value.
	 * @return string
	 */
	public static function sanitize( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return self::SiteDefault->value;
		}

		return self::tryFrom( $value )?->value ?? self::SiteDefault->value;
	}
}

// EOF: src/Editor/ContentMode.php.
