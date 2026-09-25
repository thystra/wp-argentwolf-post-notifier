<?php
/**
 * File: src/Subscriber/SignupRateLimiter.php
 *
 * @package ArgentWolf\PostNotifier
 */

namespace ArgentWolf\PostNotifier\Subscriber;

use ArgentWolf\PostNotifier\Database\EmailIdentity;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Local rate limits for public standalone-subscriber signup requests.
 *
 * Raw network addresses are never written to the store. Valid addresses are
 * packed and HMACed into short-lived opaque bucket identifiers first.
 */
final class SignupRateLimiter {
	public const WINDOW_SECONDS = 900;
	public const EMAIL_LIMIT    = 5;
	public const NETWORK_LIMIT  = 20;

	/**
	 * Construct the limiter.
	 *
	 * @param RateLimitStore $store          Ephemeral counter storage.
	 * @param EmailIdentity  $identity       Canonical keyed email identity helper.
	 * @param string         $network_secret Secret used to key network fingerprints.
	 */
	public function __construct(
		private RateLimitStore $store,
		private EmailIdentity $identity,
		private string $network_secret
	) {
		if ( strlen( $this->network_secret ) < 32 ) {
			throw new InvalidArgumentException(
				'Network rate-limit secret must contain at least 32 bytes.'
			);
		}
	}

	/**
	 * Determine whether one public signup request is within local limits.
	 *
	 * Both available buckets are consumed even when one is already over limit,
	 * keeping the policy independent from subscriber existence or status.
	 *
	 * @param string                 $email          Candidate email address.
	 * @param string|null            $remote_address Request network address.
	 * @param DateTimeInterface|null $now            Optional testable current time.
	 * @return bool True when all available buckets permit the request.
	 */
	public function allow(
		string $email,
		?string $remote_address,
		?DateTimeInterface $now = null
	): bool {
		$timestamp = null === $now ? time() : $now->getTimestamp();
		$allowed   = true;

		$network_bucket = $this->network_bucket( $remote_address );
		if ( null !== $network_bucket ) {
			$allowed = $this->store->consume(
				$network_bucket,
				self::NETWORK_LIMIT,
				self::WINDOW_SECONDS,
				$timestamp
			) && $allowed;
		}

		$email_hash = $this->identity->hash( $email );
		if ( null !== $email_hash ) {
			$allowed = $this->store->consume(
				'email:' . $email_hash,
				self::EMAIL_LIMIT,
				self::WINDOW_SECONDS,
				$timestamp
			) && $allowed;
		}

		return $allowed;
	}

	/**
	 * Convert a valid IPv4/IPv6 address into an opaque keyed bucket.
	 *
	 * @param string|null $remote_address Candidate network address.
	 * @return string|null
	 */
	private function network_bucket( ?string $remote_address ): ?string {
		if ( null === $remote_address ) {
			return null;
		}

		$remote_address = trim( $remote_address );
		if ( false === filter_var( $remote_address, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$packed = inet_pton( $remote_address );
		if ( false === $packed ) {
			return null;
		}

		return 'network:' . hash_hmac( 'sha256', $packed, $this->network_secret );
	}
}

// EOF: src/Subscriber/SignupRateLimiter.php.
