<?php
/**
 * File: src/Editor/EditorRequestThrottle.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Editor;

use WP_Error;

/**
 * Fixed-window per-user request budget for enumeration-sensitive editor routes.
 *
 * Exact-email lookup and audience estimates each answer a membership question
 * about one contact. They stay useful to an editor composing a notification
 * but must not become an unbounded address or eligibility oracle.
 */
final class EditorRequestThrottle {
	/**
	 * Window length in seconds.
	 */
	public const WINDOW_SECONDS = 600;

	/**
	 * Default contact lookups per user per window.
	 */
	public const LOOKUP_LIMIT = 30;

	/**
	 * Default audience estimates per user per window.
	 */
	public const ESTIMATE_LIMIT = 120;

	/**
	 * Prevent construction.
	 */
	private function __construct() {
	}

	/**
	 * Consume one request from the current user's budget for a route.
	 *
	 * @param string $route         Stable route key (lookup|estimate).
	 * @param int    $default_limit Default per-window limit.
	 * @return true|WP_Error WP_Error with status 429 when exhausted.
	 */
	public static function consume( string $route, int $default_limit ): true|WP_Error {
		$user_id = get_current_user_id();
		/**
		 * Filter the per-user, per-window request limit for an editor route.
		 *
		 * @param int    $limit Default limit.
		 * @param string $route Route key.
		 */
		$limit  = (int) apply_filters(
			'argentwolf_post_notifier_editor_request_limit',
			$default_limit,
			$route
		);
		$limit  = max( 1, $limit );
		$window = intdiv( time(), self::WINDOW_SECONDS );
		$key    = 'awpn_throttle_' . $route . '_' . $user_id . '_' . $window;
		$used   = (int) get_transient( $key );

		if ( $used >= $limit ) {
			return new WP_Error(
				'argentwolf_post_notifier_editor_rate_limited',
				__(
					'Too many lookups. Please wait a few minutes and try again.',
					'argentwolf-post-notifier'
				),
				array( 'status' => 429 )
			);
		}
		set_transient( $key, $used + 1, self::WINDOW_SECONDS );

		return true;
	}
}

// EOF: src/Editor/EditorRequestThrottle.php.
