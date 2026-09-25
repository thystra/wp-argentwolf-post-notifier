<?php
/**
 * File: src/Subscriber/RateLimitStore.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

/**
 * Ephemeral counter storage used by public subscriber rate limits.
 */
interface RateLimitStore {
	/**
	 * Consume one request from a named rate-limit bucket.
	 *
	 * @param string $bucket         Opaque bucket identifier.
	 * @param int    $limit          Maximum requests allowed in the window.
	 * @param int    $window_seconds Window duration in seconds.
	 * @param int    $now            Current Unix timestamp.
	 * @return bool True when the request is allowed.
	 */
	public function consume(
		string $bucket,
		int $limit,
		int $window_seconds,
		int $now
	): bool;
}

// EOF: src/Subscriber/RateLimitStore.php.
