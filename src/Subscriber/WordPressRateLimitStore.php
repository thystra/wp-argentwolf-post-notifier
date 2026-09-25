<?php
/**
 * File: src/Subscriber/WordPressRateLimitStore.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

use InvalidArgumentException;

/**
 * WordPress transient-backed ephemeral rate-limit storage.
 *
 * Bucket values are already opaque keyed identifiers. The transient name is
 * hashed again so no caller-provided value is exposed in the option/cache key.
 */
final class WordPressRateLimitStore implements RateLimitStore {
	private const TRANSIENT_PREFIX = 'argentwolf_pn_rl_';

	/**
	 * Consume one rate-limit request.
	 *
	 * @param string $bucket         Opaque bucket identifier.
	 * @param int    $limit          Maximum requests allowed in the window.
	 * @param int    $window_seconds Window duration in seconds.
	 * @param int    $now            Current Unix timestamp.
	 * @return bool True when the request is allowed.
	 * @throws InvalidArgumentException When rate-limit arguments are invalid.
	 */
	public function consume(
		string $bucket,
		int $limit,
		int $window_seconds,
		int $now
	): bool {
		if ( '' === $bucket || $limit < 1 || $window_seconds < 1 || $now < 0 ) {
			throw new InvalidArgumentException( 'Rate-limit arguments are invalid.' );
		}

		$key     = self::TRANSIENT_PREFIX . substr( hash( 'sha256', $bucket ), 0, 40 );
		$current = get_transient( $key );

		if (
			! is_array( $current )
			|| ! isset( $current['count'], $current['reset_at'] )
			|| ! is_int( $current['count'] )
			|| ! is_int( $current['reset_at'] )
			|| $current['reset_at'] <= $now
		) {
			set_transient(
				$key,
				array(
					'count'    => 1,
					'reset_at' => $now + $window_seconds,
				),
				$window_seconds
			);
			return true;
		}

		if ( $current['count'] >= $limit ) {
			return false;
		}

		++$current['count'];
		$remaining = max( 1, $current['reset_at'] - $now );
		set_transient( $key, $current, $remaining );

		return true;
	}
}

// EOF: src/Subscriber/WordPressRateLimitStore.php.
