<?php
/**
 * File: src/Editor/SendIntent.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Editor;

/**
 * Per-post notification intent stored by the editor workflow.
 */
enum SendIntent: string {
	case SiteDefault = 'site_default';
	case Send        = 'send';
	case DoNotSend   = 'do_not_send';

	/**
	 * Return allowed stored values.
	 *
	 * @return array<int,string>
	 */
	public static function values(): array {
		return array_map(
			static fn ( self $intent ): string => $intent->value,
			self::cases()
		);
	}

	/**
	 * Normalize an arbitrary stored value to a safe intent.
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

// EOF: src/Editor/SendIntent.php.
